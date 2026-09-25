<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF, ROLE_MECHANIC]);
$path  = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/categories' && $method === 'GET') {
    $rows = db()->query('SELECT * FROM categories ORDER BY is_system DESC, name')->fetchAll();
    render('categories/index', ['title' => 'Categorías', 'actor' => $actor, 'rows' => $rows, 'errors' => [], 'input' => ['name' => '']]);
    return;
}

if ($path === '/categories' && $method === 'POST') {
    if (!can_manage_categories($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $errs = [];
    if ($name === '' || mb_strlen($name) > 80) $errs[] = 'Nombre inválido.';
    $nn = normalize_name($name);
    if ($nn !== '') {
        $q = db()->prepare('SELECT id FROM categories WHERE name_norm=:n'); $q->execute([':n' => $nn]);
        if ($q->fetch()) $errs[] = 'Ya existe una categoría con ese nombre.';
    }
    if ($errs) {
        $rows = db()->query('SELECT * FROM categories ORDER BY is_system DESC, name')->fetchAll();
        render('categories/index', ['title' => 'Categorías', 'actor' => $actor, 'rows' => $rows, 'errors' => $errs, 'input' => ['name' => $name]]);
        return;
    }
    $stmt = db()->prepare('INSERT INTO categories (name, name_norm, is_system) VALUES (:n,:nn,0)');
    $stmt->execute([':n' => $name, ':nn' => $nn]);
    $newId = (int)db()->lastInsertId();
    audit_log('category.create', 'category', $newId, null, null, ['name' => $name]);
    $_SESSION['flash_success'] = 'Categoría creada.';
    redirect('/categories');
}

if (preg_match('#^/categories/(\d+)/rename$#', $path, $m) && $method === 'POST') {
    if (!can_manage_categories($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    csrf_check();
    $c = _load_category((int)$m[1]);
    if (!$c) { http_response_code(404); echo 'No encontrado'; return; }
    if ((int)$c['is_system'] === 1) { $_SESSION['flash_error'] = 'La categoría del sistema no se puede renombrar.'; redirect('/categories'); }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) { $_SESSION['flash_error'] = 'Nombre inválido.'; redirect('/categories'); }
    $nn = normalize_name($name);
    $q = db()->prepare('SELECT id FROM categories WHERE name_norm=:n AND id != :id'); $q->execute([':n' => $nn, ':id' => $c['id']]);
    if ($q->fetch()) { $_SESSION['flash_error'] = 'Ya existe una categoría con ese nombre.'; redirect('/categories'); }
    db()->prepare('UPDATE categories SET name=:n, name_norm=:nn WHERE id=:id')->execute([':n' => $name, ':nn' => $nn, ':id' => $c['id']]);
    audit_log('category.rename', 'category', (int)$c['id'], null, ['name' => $c['name']], ['name' => $name]);
    $_SESSION['flash_success'] = 'Categoría renombrada.';
    redirect('/categories');
}

if (preg_match('#^/categories/(\d+)/delete$#', $path, $m) && $method === 'POST') {
    if (!can_manage_categories($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    csrf_check();
    $c = _load_category((int)$m[1]);
    if (!$c) { http_response_code(404); echo 'No encontrado'; return; }
    if ((int)$c['is_system'] === 1) { $_SESSION['flash_error'] = 'La categoría del sistema no se puede eliminar.'; redirect('/categories'); }
    // Mover repuestos a "Sin categoría" antes de borrar.
    $sysId = system_category_id();
    $mv = db()->prepare('UPDATE parts SET category_id=:sys, updated_at=strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\') WHERE category_id=:id');
    $mv->execute([':sys' => $sysId, ':id' => $c['id']]);
    $moved = $mv->rowCount();
    db()->prepare('DELETE FROM categories WHERE id=:id')->execute([':id' => $c['id']]);
    audit_log('category.delete', 'category', (int)$c['id'], null, ['name' => $c['name'], 'parts_moved_to_default' => $moved]);
    $_SESSION['flash_success'] = 'Categoría eliminada.';
    redirect('/categories');
}

http_response_code(404);
echo 'No encontrado';

function _load_category(int $id): ?array {
    $s = db()->prepare('SELECT * FROM categories WHERE id=:id');
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ?: null;
}
