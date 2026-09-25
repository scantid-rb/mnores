<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR, ROLE_CHIEF]);
$path  = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$allowed_roles = manageable_roles_for($actor);
$isChief       = ($actor['role'] === ROLE_CHIEF);
$chiefBoatId   = $isChief ? (int)($actor['boat_id'] ?? 0) : 0;

function _load_user(int $id): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE id = :id');
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ?: null;
}

function _boats_list(): array {
    return db()->query('SELECT id, name, is_active FROM boats ORDER BY name')->fetchAll();
}

// Rutas
if ($path === '/users' && $method === 'GET') {
    if ($isChief) {
        if ($chiefBoatId <= 0) { render('users/index', ['title'=>'Mecánicos','actor'=>$actor,'rows'=>[],'allowed_roles'=>$allowed_roles]); return; }
        $stmt = db()->prepare('SELECT u.*, b.name AS boat_name FROM users u LEFT JOIN boats b ON b.id = u.boat_id
                               WHERE u.role = :r AND u.boat_id = :b ORDER BY u.username');
        $stmt->execute([':r'=>ROLE_MECHANIC, ':b'=>$chiefBoatId]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = db()->query('SELECT u.*, b.name AS boat_name FROM users u LEFT JOIN boats b ON b.id = u.boat_id ORDER BY u.username')->fetchAll();
    }
    render('users/index', ['title' => $isChief ? 'Mecánicos de mi barco' : 'Usuarios', 'actor' => $actor, 'rows' => $rows, 'allowed_roles' => $allowed_roles]);
    return;
}

if ($path === '/users/new' && $method === 'GET') {
    render('users/form', [
        'title' => 'Nuevo usuario',
        'actor' => $actor,
        'user'  => null,
        'boats' => _boats_list(),
        'allowed_roles' => $allowed_roles,
        'errors' => [],
    ]);
    return;
}

if ($path === '/users' && $method === 'POST') {
    csrf_check();
    $data = _read_user_form();
    if ($isChief) {
        // Chief: forzar rol Mecánico y su propio barco.
        $data['role']    = ROLE_MECHANIC;
        $data['boat_id'] = $chiefBoatId;
    }
    $errors = _validate_user_form($data, null, $allowed_roles);
    if ($errors) {
        render('users/form', ['title' => 'Nuevo usuario', 'actor' => $actor, 'user' => null, 'boats' => _boats_list(), 'allowed_roles' => $allowed_roles, 'errors' => $errors, 'input' => $data]);
        return;
    }
    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (username, username_norm, first_name, last_name, password_hash, role, boat_id, is_active)
                           VALUES (:u,:un,:fn,:ln,:ph,:r,:b,:a)');
    $stmt->execute([
        ':u' => $data['username'], ':un' => normalize_username($data['username']),
        ':fn' => $data['first_name'], ':ln' => $data['last_name'],
        ':ph' => $hash, ':r' => $data['role'],
        ':b' => $data['boat_id'], ':a' => $data['is_active'],
    ]);
    $newId = (int)db()->lastInsertId();
    audit_log('user.create', 'user', $newId, $data['boat_id'], null, audit_user_snapshot($data));
    $_SESSION['flash_success'] = 'Usuario creado.';
    redirect('/users');
}

if (preg_match('#^/users/(\d+)/edit$#', $path, $m)) {
    $u = _load_user((int)$m[1]);
    if (!$u) { http_response_code(404); echo 'No encontrado'; return; }
    if (!can_manage_user($actor, $u)) { http_response_code(403); echo 'No autorizado'; return; }

    if ($method === 'POST') {
        csrf_check();
        $data = _read_user_form();
        if ($isChief) {
            // Chief: solo puede editar mecánicos de su barco, sin cambiarles el rol ni el barco.
            $data['role']    = ROLE_MECHANIC;
            $data['boat_id'] = $chiefBoatId;
        }
        $errors = _validate_user_form($data, $u, $allowed_roles);
        if ($errors) {
            render('users/form', ['title' => 'Editar usuario', 'actor' => $actor, 'user' => $u, 'boats' => _boats_list(), 'allowed_roles' => $allowed_roles, 'errors' => $errors, 'input' => $data]);
            return;
        }
        $old = audit_user_snapshot($u);
        // Al admin principal no se le puede cambiar el rol ni desactivar.
        if ((int)$u['is_primary_admin'] === 1) {
            $data['role']      = ROLE_ADMIN;
            $data['is_active'] = 1;
        }
        $stmt = db()->prepare('UPDATE users SET username=:u, username_norm=:un, first_name=:fn, last_name=:ln, role=:r, boat_id=:b, is_active=:a WHERE id=:id');
        $stmt->execute([
            ':u' => $data['username'], ':un' => normalize_username($data['username']),
            ':fn' => $data['first_name'], ':ln' => $data['last_name'],
            ':r' => $data['role'], ':b' => $data['boat_id'], ':a' => $data['is_active'],
            ':id' => $u['id'],
        ]);
        audit_log('user.update', 'user', (int)$u['id'], $data['boat_id'], $old, audit_user_snapshot($data));
        $_SESSION['flash_success'] = 'Usuario actualizado.';
        redirect('/users');
    }

    render('users/form', ['title' => 'Editar usuario', 'actor' => $actor, 'user' => $u, 'boats' => _boats_list(), 'allowed_roles' => $allowed_roles, 'errors' => []]);
    return;
}

if (preg_match('#^/users/(\d+)/password$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $u = _load_user((int)$m[1]);
    if (!$u || !can_manage_user($actor, $u)) { http_response_code(403); echo 'No autorizado'; return; }
    $p1 = (string)($_POST['password']  ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (mb_strlen($p1) < 8) { $_SESSION['flash_error'] = 'La contraseña debe tener al menos 8 caracteres.'; redirect('/users/' . $u['id'] . '/edit'); }
    if ($p1 !== $p2)         { $_SESSION['flash_error'] = 'Las contraseñas no coinciden.';                 redirect('/users/' . $u['id'] . '/edit'); }
    $hash = password_hash($p1, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash=:h WHERE id=:id')->execute([':h' => $hash, ':id' => $u['id']]);
    audit_log('user.password_change', 'user', (int)$u['id'], (int)($u['boat_id'] ?? 0) ?: null);
    $_SESSION['flash_success'] = 'Contraseña actualizada.';
    redirect('/users/' . $u['id'] . '/edit');
}

if (preg_match('#^/users/(\d+)/toggle$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $u = _load_user((int)$m[1]);
    if (!$u || !can_manage_user($actor, $u)) { http_response_code(403); echo 'No autorizado'; return; }
    if ((int)$u['is_primary_admin'] === 1) { $_SESSION['flash_error'] = 'No se puede desactivar al administrador principal.'; redirect('/users'); }
    if ((int)$u['id'] === (int)$actor['id']) { $_SESSION['flash_error'] = 'No puedes desactivarte a ti mismo.'; redirect('/users'); }
    $new = (int)$u['is_active'] === 1 ? 0 : 1;
    db()->prepare('UPDATE users SET is_active=:a WHERE id=:id')->execute([':a' => $new, ':id' => $u['id']]);
    audit_log($new ? 'user.activate' : 'user.deactivate', 'user', (int)$u['id'], $u['boat_id']);
    $_SESSION['flash_success'] = $new ? 'Usuario activado.' : 'Usuario desactivado.';
    redirect('/users');
}

if (preg_match('#^/users/(\d+)/delete$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $u = _load_user((int)$m[1]);
    if (!$u || !can_manage_user($actor, $u)) { http_response_code(403); echo 'No autorizado'; return; }
    if ((int)$u['is_primary_admin'] === 1) { $_SESSION['flash_error'] = 'No se puede eliminar al administrador principal.'; redirect('/users'); }
    if ((int)$u['id'] === (int)$actor['id']) { $_SESSION['flash_error'] = 'No puedes eliminarte a ti mismo.'; redirect('/users'); }
    db()->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $u['id']]);
    audit_log('user.delete', 'user', (int)$u['id'], $u['boat_id'], audit_user_snapshot($u));
    $_SESSION['flash_success'] = 'Usuario eliminado.';
    redirect('/users');
}

http_response_code(404);
echo 'No encontrado';


/* ---------- Helpers de formulario ---------- */

function _read_user_form(): array {
    $boat = trim((string)($_POST['boat_id'] ?? ''));
    return [
        'username'   => trim((string)($_POST['username']   ?? '')),
        'first_name' => trim((string)($_POST['first_name'] ?? '')),
        'last_name'  => trim((string)($_POST['last_name']  ?? '')),
        'password'   => (string)($_POST['password']  ?? ''),
        'password2'  => (string)($_POST['password2'] ?? ''),
        'role'       => (string)($_POST['role']      ?? ''),
        'boat_id'    => ($boat === '' ? null : (int)$boat),
        'is_active'  => isset($_POST['is_active']) ? 1 : 0,
    ];
}

function _validate_user_form(array $d, ?array $existing, array $allowed_roles): array {
    $errs = [];
    if ($d['first_name'] === '' || mb_strlen($d['first_name']) > 80) $errs[] = 'Nombre inválido.';
    if ($d['last_name']  === '' || mb_strlen($d['last_name'])  > 120) $errs[] = 'Apellidos inválidos.';
    if ($d['username'] === '' || !preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $d['username'])) $errs[] = 'Usuario inválido.';
    if (!in_array($d['role'], $allowed_roles, true)) $errs[] = 'Rol no permitido para tu perfil.';

    // Unicidad de username.
    $un = normalize_username($d['username']);
    if ($un !== '') {
        $q = db()->prepare('SELECT id FROM users WHERE username_norm = :u');
        $q->execute([':u' => $un]);
        $r = $q->fetch();
        if ($r && (!$existing || (int)$r['id'] !== (int)$existing['id'])) $errs[] = 'El nombre de usuario ya existe.';
    }

    // Barco existe si se indica.
    if ($d['boat_id'] !== null) {
        $q = db()->prepare('SELECT id FROM boats WHERE id = :id');
        $q->execute([':id' => $d['boat_id']]);
        if (!$q->fetch()) $errs[] = 'Barco no válido.';
    }

    if ($existing === null) {
        // Al crear se exige contraseña.
        if (mb_strlen($d['password']) < 8) $errs[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($d['password'] !== $d['password2']) $errs[] = 'Las contraseñas no coinciden.';
    }
    return $errs;
}
