<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();
if (!in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    return;
}

function api_boat_row(int $id): ?array {
    $st = db()->prepare('SELECT id, name, registration, is_active, updated_at, deleted_at FROM boats WHERE id=:id');
    $st->execute([':id' => $id]);
    $b = $st->fetch();
    if (!$b) return null;
    $b['id'] = (int)$b['id'];
    $b['is_active'] = (int)$b['is_active'];
    return $b;
}

function api_boat_validate(string $name, string $registration, ?int $id = null): array {
    $errors = [];
    if ($name === '' || mb_strlen($name) > 120) $errors[] = 'Nombre inválido.';
    if ($registration === '' || mb_strlen($registration) > 60) $errors[] = 'Matrícula inválida.';

    if ($name !== '') {
        $st = db()->prepare('SELECT id FROM boats WHERE name_norm=:n');
        $st->execute([':n' => normalize_name($name)]);
        $r = $st->fetch();
        if ($r && ($id === null || (int)$r['id'] !== $id)) $errors[] = 'Ya existe un barco con ese nombre.';
    }
    if ($registration !== '') {
        $st = db()->prepare('SELECT id FROM boats WHERE registration_norm=:r');
        $st->execute([':r' => normalize_name($registration)]);
        $r = $st->fetch();
        if ($r && ($id === null || (int)$r['id'] !== $id)) $errors[] = 'Ya existe un barco con esa matrícula.';
    }
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db()->query('SELECT id, name, registration, is_active, updated_at, deleted_at FROM boats ORDER BY name COLLATE NOCASE')->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
    }
    unset($row);
    echo json_encode(['ok' => true, 'boats' => $rows]);
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
    $registration = trim((string)($input['registration'] ?? ''));
    $active = !empty($input['is_active']) ? 1 : 0;
    $errors = api_boat_validate($name, $registration);
    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
        return;
    }

    $st = db()->prepare('INSERT INTO boats (name,name_norm,registration,registration_norm,is_active) VALUES (:n,:nn,:r,:rn,:a)');
    $st->execute([
        ':n' => $name, ':nn' => normalize_name($name),
        ':r' => $registration, ':rn' => normalize_name($registration), ':a' => $active,
    ]);
    $id = (int)db()->lastInsertId();
    audit_log('boat.create', 'boat', $id, $id, null, ['name'=>$name,'registration'=>$registration,'is_active'=>$active]);
    echo json_encode(['ok' => true, 'boat' => api_boat_row($id)]);
    return;
}

$id = (int)($input['id'] ?? 0);
$old = $id > 0 ? api_boat_row($id) : null;
if (!$old) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Barco no encontrado']);
    return;
}

if ($action === 'update') {
    $name = trim((string)($input['name'] ?? ''));
    $registration = trim((string)($input['registration'] ?? ''));
    $active = !empty($input['is_active']) ? 1 : 0;
    $errors = api_boat_validate($name, $registration, $id);
    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
        return;
    }

    db()->prepare('UPDATE boats SET name=:n,name_norm=:nn,registration=:r,registration_norm=:rn,is_active=:a WHERE id=:id')->execute([
        ':n'=>$name, ':nn'=>normalize_name($name), ':r'=>$registration,
        ':rn'=>normalize_name($registration), ':a'=>$active, ':id'=>$id,
    ]);
    audit_log('boat.update', 'boat', $id, $id, $old, ['name'=>$name,'registration'=>$registration,'is_active'=>$active]);
    echo json_encode(['ok' => true, 'boat' => api_boat_row($id)]);
    return;
}

if ($action === 'toggle') {
    $active = $old['is_active'] === 1 ? 0 : 1;
    db()->prepare('UPDATE boats SET is_active=:a WHERE id=:id')->execute([':a'=>$active, ':id'=>$id]);
    audit_log($active ? 'boat.activate' : 'boat.deactivate', 'boat', $id, $id);
    echo json_encode(['ok' => true, 'boat' => api_boat_row($id)]);
    return;
}

if ($action === 'delete') {
    if ($actor['role'] !== ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No autorizado']);
        return;
    }

    $users = (int)db()->query('SELECT COUNT(*) FROM users WHERE boat_id = ' . $id)->fetchColumn();
    $parts = (int)db()->query('SELECT COUNT(*) FROM parts WHERE boat_id = ' . $id . ' AND deleted_at IS NULL')->fetchColumn();

    if ($users > 0 || $parts > 0) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'No se puede eliminar el barco porque todavía tiene elementos asociados.',
            'users_count' => $users,
            'parts_count' => $parts,
        ]);
        return;
    }

    db()->prepare('DELETE FROM boats WHERE id=:id')->execute([':id' => $id]);
    audit_log('boat.delete', 'boat', $id, $id, [
        'name' => $old['name'],
        'registration' => $old['registration'],
    ]);
    echo json_encode(['ok' => true, 'deleted' => true, 'boat' => $old]);
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
