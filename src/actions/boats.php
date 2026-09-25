<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF, ROLE_MECHANIC]);
$path  = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Todos ven la lista; solo admin/inspector pueden mutar (chequeado en cada acción).
if ($path === '/boats' && $method === 'GET') {
    $rows = db()->query('SELECT b.*, (SELECT COUNT(*) FROM users u WHERE u.boat_id = b.id) AS users_count FROM boats b ORDER BY b.name')->fetchAll();
    render('boats/index', ['title' => 'Barcos', 'actor' => $actor, 'rows' => $rows]);
    return;
}

if ($path === '/boats/new' && $method === 'GET') {
    if (!can_manage_boats($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    render('boats/form', ['title' => 'Nuevo barco', 'actor' => $actor, 'boat' => null, 'errors' => []]);
    return;
}

if ($path === '/boats' && $method === 'POST') {
    if (!can_manage_boats($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    csrf_check();
    $d = _read_boat_form();
    $errs = _validate_boat_form($d, null);
    if ($errs) {
        render('boats/form', ['title' => 'Nuevo barco', 'actor' => $actor, 'boat' => null, 'errors' => $errs, 'input' => $d]);
        return;
    }
    $stmt = db()->prepare('INSERT INTO boats (name, name_norm, registration, registration_norm, is_active) VALUES (:n,:nn,:r,:rn,:a)');
    $stmt->execute([':n' => $d['name'], ':nn' => normalize_name($d['name']), ':r' => $d['registration'], ':rn' => normalize_name($d['registration']), ':a' => $d['is_active']]);
    $newId = (int)db()->lastInsertId();
    audit_log('boat.create', 'boat', $newId, $newId, null, $d);
    $_SESSION['flash_success'] = 'Barco creado.';
    redirect('/boats');
}

if (preg_match('#^/boats/(\d+)/edit$#', $path, $m)) {
    if (!can_manage_boats($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    $b = _load_boat((int)$m[1]);
    if (!$b) { http_response_code(404); echo 'No encontrado'; return; }
    if ($method === 'POST') {
        csrf_check();
        $d = _read_boat_form();
        $errs = _validate_boat_form($d, $b);
        if ($errs) {
            render('boats/form', ['title' => 'Editar barco', 'actor' => $actor, 'boat' => $b, 'errors' => $errs, 'input' => $d]);
            return;
        }
        $stmt = db()->prepare('UPDATE boats SET name=:n, name_norm=:nn, registration=:r, registration_norm=:rn, is_active=:a WHERE id=:id');
        $stmt->execute([':n' => $d['name'], ':nn' => normalize_name($d['name']), ':r' => $d['registration'], ':rn' => normalize_name($d['registration']), ':a' => $d['is_active'], ':id' => $b['id']]);
        audit_log('boat.update', 'boat', (int)$b['id'], (int)$b['id'], ['name'=>$b['name'],'registration'=>$b['registration'],'is_active'=>(int)$b['is_active']], $d);
        $_SESSION['flash_success'] = 'Barco actualizado.';
        redirect('/boats');
    }
    render('boats/form', ['title' => 'Editar barco', 'actor' => $actor, 'boat' => $b, 'errors' => []]);
    return;
}

if (preg_match('#^/boats/(\d+)/toggle$#', $path, $m) && $method === 'POST') {
    if (!can_manage_boats($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    csrf_check();
    $b = _load_boat((int)$m[1]);
    if (!$b) { http_response_code(404); echo 'No encontrado'; return; }
    $new = (int)$b['is_active'] === 1 ? 0 : 1;
    db()->prepare('UPDATE boats SET is_active=:a WHERE id=:id')->execute([':a' => $new, ':id' => $b['id']]);
    audit_log($new ? 'boat.activate' : 'boat.deactivate', 'boat', (int)$b['id'], (int)$b['id']);
    $_SESSION['flash_success'] = $new ? 'Barco activado.' : 'Barco desactivado.';
    redirect('/boats');
}

if (preg_match('#^/boats/(\d+)/delete$#', $path, $m) && $method === 'POST') {
    if (!can_delete_boat($actor)) { http_response_code(403); echo 'No autorizado'; return; }
    csrf_check();
    $b = _load_boat((int)$m[1]);
    if (!$b) { http_response_code(404); echo 'No encontrado'; return; }
    $users = (int)db()->query('SELECT COUNT(*) FROM users WHERE boat_id = ' . (int)$b['id'])->fetchColumn();
    // Aquí también se verificaría inventario en fases posteriores.
    if ($users > 0) {
        $_SESSION['flash_error'] = 'No se puede eliminar: el barco tiene ' . $users . ' usuario(s) asignado(s).';
        redirect('/boats');
    }
    db()->prepare('DELETE FROM boats WHERE id=:id')->execute([':id' => $b['id']]);
    audit_log('boat.delete', 'boat', (int)$b['id'], (int)$b['id'], ['name'=>$b['name'],'registration'=>$b['registration']]);
    $_SESSION['flash_success'] = 'Barco eliminado.';
    redirect('/boats');
}

http_response_code(404);
echo 'No encontrado';


function _load_boat(int $id): ?array {
    $s = db()->prepare('SELECT * FROM boats WHERE id=:id');
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ?: null;
}
function _read_boat_form(): array {
    return [
        'name'         => trim((string)($_POST['name']         ?? '')),
        'registration' => trim((string)($_POST['registration'] ?? '')),
        'is_active'    => isset($_POST['is_active']) ? 1 : 0,
    ];
}
function _validate_boat_form(array $d, ?array $existing): array {
    $errs = [];
    if ($d['name']         === '' || mb_strlen($d['name'])         > 120) $errs[] = 'Nombre inválido.';
    if ($d['registration'] === '' || mb_strlen($d['registration']) > 60)  $errs[] = 'Matrícula inválida.';
    $nn = normalize_name($d['name']);
    $rn = normalize_name($d['registration']);
    if ($nn !== '') {
        $q = db()->prepare('SELECT id FROM boats WHERE name_norm=:n'); $q->execute([':n' => $nn]);
        $r = $q->fetch();
        if ($r && (!$existing || (int)$r['id'] !== (int)$existing['id'])) $errs[] = 'Ya existe un barco con ese nombre.';
    }
    if ($rn !== '') {
        $q = db()->prepare('SELECT id FROM boats WHERE registration_norm=:r'); $q->execute([':r' => $rn]);
        $r = $q->fetch();
        if ($r && (!$existing || (int)$r['id'] !== (int)$existing['id'])) $errs[] = 'Ya existe un barco con esa matrícula.';
    }
    return $errs;
}
