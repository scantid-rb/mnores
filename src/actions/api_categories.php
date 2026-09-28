<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();

if (!in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    return;
}

function api_category_row(int $id): ?array {
    $st = db()->prepare('SELECT id, name, is_system, updated_at, deleted_at FROM categories WHERE id=:id');
    $st->execute([':id' => $id]);
    $c = $st->fetch();
    if (!$c) return null;
    $c['id'] = (int)$c['id'];
    $c['is_system'] = (int)$c['is_system'];
    return $c;
}

function api_category_validate(string $name, ?int $id = null): array {
    $errors = [];
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'Nombre inválido.';
        return $errors;
    }

    $st = db()->prepare('SELECT id FROM categories WHERE name_norm=:n');
    $st->execute([':n' => normalize_name($name)]);
    $row = $st->fetch();
    if ($row && ($id === null || (int)$row['id'] !== $id)) {
        $errors[] = 'Ya existe una categoría con ese nombre.';
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db()->query(
        'SELECT id, name, is_system, updated_at, deleted_at
         FROM categories
         WHERE deleted_at IS NULL
         ORDER BY is_system DESC, name COLLATE NOCASE'
    )->fetchAll();

    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_system'] = (int)$row['is_system'];
    }
    unset($row);

    echo json_encode(['ok' => true, 'categories' => $rows]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    return;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    return;
}

$action = (string)($input['action'] ?? '');

if ($action === 'create') {
    $name = trim((string)($input['name'] ?? ''));
    $errors = api_category_validate($name);
    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
        return;
    }

    $st = db()->prepare(
        'INSERT INTO categories (name, name_norm, is_system)
         VALUES (:n, :nn, 0)'
    );
    $st->execute([
        ':n' => $name,
        ':nn' => normalize_name($name),
    ]);

    $id = (int)db()->lastInsertId();
    audit_log('category.create', 'category', $id, null, null, ['name' => $name]);

    echo json_encode(['ok' => true, 'category' => api_category_row($id)]);
    return;
}

$id = (int)($input['id'] ?? 0);
$old = $id > 0 ? api_category_row($id) : null;
if (!$old) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Categoría no encontrada']);
    return;
}

if ($action === 'rename') {
    if ((int)$old['is_system'] === 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'La categoría del sistema no se puede renombrar.']);
        return;
    }

    $name = trim((string)($input['name'] ?? ''));
    $errors = api_category_validate($name, $id);
    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
        return;
    }

    db()->prepare(
        'UPDATE categories
         SET name=:n, name_norm=:nn, updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')
         WHERE id=:id'
    )->execute([
        ':n' => $name,
        ':nn' => normalize_name($name),
        ':id' => $id,
    ]);

    audit_log('category.rename', 'category', $id, null, ['name' => $old['name']], ['name' => $name]);
    echo json_encode(['ok' => true, 'category' => api_category_row($id)]);
    return;
}

if ($action === 'delete') {
    if ((int)$old['is_system'] === 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'La categoría del sistema no se puede eliminar.']);
        return;
    }

    $sysId = system_category_id();
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $mv = $pdo->prepare(
            'UPDATE parts
             SET category_id=:sys,
                 updated_at=strftime('%Y-%m-%dT%H:%M:%fZ','now')
             WHERE category_id=:id'
        );
        $mv->execute([':sys' => $sysId, ':id' => $id]);
        $moved = $mv->rowCount();

        $del = $pdo->prepare('DELETE FROM categories WHERE id=:id');
        $del->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar la categoría.']);
        return;
    }

    audit_log('category.delete', 'category', $id, null, [
        'name' => $old['name'],
        'parts_moved_to_default' => $moved,
    ]);

    echo json_encode([
        'ok' => true,
        'deleted' => true,
        'moved_parts' => $moved,
    ]);
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
