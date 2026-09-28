<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();

function api_user_row(array $u): array {
    return [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'],
        'role' => $u['role'],
        'boat_id' => $u['boat_id'] !== null ? (int)$u['boat_id'] : null,
        'is_active' => (int)$u['is_active'],
        'is_primary_admin' => (int)$u['is_primary_admin'],
    ];
}

function api_user_load(int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id=:id');
    $st->execute([':id' => $id]);
    $u = $st->fetch();
    return $u ?: null;
}

function api_user_can_manage(array $actor, array $target): bool {
    if (!can_manage_user($actor, $target)) return false;
    return true;
}

function api_user_validate(array $d, ?array $existing, array $allowedRoles): array {
    $errors = [];

    if ($d['first_name'] === '' || mb_strlen($d['first_name']) > 80) $errors[] = 'Nombre inválido.';
    if ($d['last_name'] === '' || mb_strlen($d['last_name']) > 120) $errors[] = 'Apellidos inválidos.';
    if ($d['username'] === '' || !preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $d['username'])) $errors[] = 'Usuario inválido.';
    if (!in_array($d['role'], $allowedRoles, true)) $errors[] = 'Rol no permitido para tu perfil.';

    $un = normalize_username($d['username']);
    if ($un !== '') {
        $q = db()->prepare('SELECT id FROM users WHERE username_norm=:u');
        $q->execute([':u'=>$un]);
        $r = $q->fetch();
        if ($r && (!$existing || (int)$r['id'] !== (int)$existing['id'])) {
            $errors[] = 'El nombre de usuario ya existe.';
        }
    }

    if ($d['boat_id'] !== null) {
        $q = db()->prepare('SELECT id FROM boats WHERE id=:id');
        $q->execute([':id'=>$d['boat_id']]);
        if (!$q->fetch()) $errors[] = 'Barco no válido.';
    }

    if ($existing === null) {
        if (mb_strlen($d['password']) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($d['password'] !== $d['password2']) $errors[] = 'Las contraseñas no coinciden.';
    } elseif ($d['password'] !== '' && mb_strlen($d['password']) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($d['password'] !== '' && $d['password'] !== $d['password2']) {
        $errors[] = 'Las contraseñas no coinciden.';
    }

    if (in_array($d['role'], [ROLE_CHIEF, ROLE_MECHANIC], true) && $d['boat_id'] === null) {
        $errors[] = 'Este rol requiere un barco asignado.';
    }

    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = 'SELECT * FROM users';
    $args = [];

    if ($actor['role'] === ROLE_CHIEF) {
        $sql .= ' WHERE role=:role AND boat_id=:boat_id';
        $args = [':role'=>ROLE_MECHANIC, ':boat_id'=>(int)($actor['boat_id'] ?? 0)];
    } elseif ($actor['role'] === ROLE_MECHANIC) {
        $sql .= ' WHERE id=:id';
        $args = [':id'=>(int)$actor['id']];
    }

    $sql .= ' ORDER BY username';
    $st = db()->prepare($sql);
    $st->execute($args);

    $users = [];
    while ($u = $st->fetch()) $users[] = api_user_row($u);

    echo json_encode(['ok'=>true, 'users'=>$users]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Método no permitido']);
    return;
}

if (!can_manage_users($actor)) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'No autorizado']);
    return;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'JSON inválido']);
    return;
}

$action = (string)($input['action'] ?? '');

if ($action === 'create') {
    $data = [
        'username'=>trim((string)($input['username'] ?? '')),
        'first_name'=>trim((string)($input['first_name'] ?? '')),
        'last_name'=>trim((string)($input['last_name'] ?? '')),
        'password'=>(string)($input['password'] ?? ''),
        'password2'=>(string)($input['password2'] ?? ''),
        'role'=>(string)($input['role'] ?? ''),
        'boat_id'=>array_key_exists('boat_id',$input) && $input['boat_id'] !== null ? (int)$input['boat_id'] : null,
        'is_active'=>!empty($input['is_active']) ? 1 : 0,
    ];

    if ($actor['role'] === ROLE_CHIEF) {
        $data['role'] = ROLE_MECHANIC;
        $data['boat_id'] = (int)($actor['boat_id'] ?? 0);
    }

    $errors = api_user_validate($data, null, manageable_roles_for($actor));
    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>implode(' ', $errors)]);
        return;
    }

    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    $st = db()->prepare(
        'INSERT INTO users (username,username_norm,first_name,last_name,password_hash,role,boat_id,is_active)
         VALUES (:u,:un,:fn,:ln,:ph,:r,:b,:a)'
    );
    $st->execute([
        ':u'=>$data['username'], ':un'=>normalize_username($data['username']),
        ':fn'=>$data['first_name'], ':ln'=>$data['last_name'],
        ':ph'=>$hash, ':r'=>$data['role'], ':b'=>$data['boat_id'], ':a'=>$data['is_active'],
    ]);

    $id = (int)db()->lastInsertId();
    $created = api_user_load($id);
    audit_log('user.create','user',$id,$data['boat_id'],null,audit_user_snapshot($data));

    echo json_encode(['ok'=>true,'user'=>api_user_row($created)]);
    return;
}

$id = (int)($input['id'] ?? 0);
$target = $id > 0 ? api_user_load($id) : null;
if (!$target || !api_user_can_manage($actor, $target)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'No autorizado']);
    return;
}

if ($action === 'update') {
    $data = [
        'username'=>trim((string)($input['username'] ?? '')),
        'first_name'=>trim((string)($input['first_name'] ?? '')),
        'last_name'=>trim((string)($input['last_name'] ?? '')),
        'password'=>(string)($input['password'] ?? ''),
        'password2'=>(string)($input['password2'] ?? ''),
        'role'=>(string)($input['role'] ?? ''),
        'boat_id'=>array_key_exists('boat_id',$input) && $input['boat_id'] !== null ? (int)$input['boat_id'] : null,
        'is_active'=>!empty($input['is_active']) ? 1 : 0,
    ];

    if ($actor['role'] === ROLE_CHIEF) {
        $data['role'] = ROLE_MECHANIC;
        $data['boat_id'] = (int)($actor['boat_id'] ?? 0);
    }

    if ((int)$target['is_primary_admin'] === 1) {
        $data['role'] = ROLE_ADMIN;
        $data['is_active'] = 1;
        $data['boat_id'] = null;
    }

    $errors = api_user_validate($data, $target, manageable_roles_for($actor));
    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>implode(' ', $errors)]);
        return;
    }

    $old = audit_user_snapshot($target);

    if ($data['password'] !== '') {
        $st = db()->prepare(
            'UPDATE users
             SET username=:u,username_norm=:un,first_name=:fn,last_name=:ln,
                 password_hash=:ph,role=:r,boat_id=:b,is_active=:a
             WHERE id=:id'
        );
        $st->execute([
            ':u'=>$data['username'], ':un'=>normalize_username($data['username']),
            ':fn'=>$data['first_name'], ':ln'=>$data['last_name'],
            ':ph'=>password_hash($data['password'], PASSWORD_DEFAULT),
            ':r'=>$data['role'], ':b'=>$data['boat_id'], ':a'=>$data['is_active'], ':id'=>$id,
        ]);
    } else {
        $st = db()->prepare(
            'UPDATE users
             SET username=:u,username_norm=:un,first_name=:fn,last_name=:ln,
                 role=:r,boat_id=:b,is_active=:a
             WHERE id=:id'
        );
        $st->execute([
            ':u'=>$data['username'], ':un'=>normalize_username($data['username']),
            ':fn'=>$data['first_name'], ':ln'=>$data['last_name'],
            ':r'=>$data['role'], ':b'=>$data['boat_id'], ':a'=>$data['is_active'], ':id'=>$id,
        ]);
    }

    audit_log('user.update','user',$id,$data['boat_id'],$old,audit_user_snapshot($data));
    echo json_encode(['ok'=>true,'user'=>api_user_row(api_user_load($id))]);
    return;
}

if ($action === 'toggle') {
    if ((int)$target['is_primary_admin'] === 1 || (int)$target['id'] === (int)$actor['id']) {
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'No se puede cambiar el estado de este usuario.']);
        return;
    }

    $new = (int)$target['is_active'] === 1 ? 0 : 1;
    db()->prepare('UPDATE users SET is_active=:a WHERE id=:id')
        ->execute([':a'=>$new,':id'=>$id]);

    audit_log($new ? 'user.activate' : 'user.deactivate','user',$id,$target['boat_id']);
    echo json_encode(['ok'=>true,'user'=>api_user_row(api_user_load($id))]);
    return;
}

if ($action === 'delete') {
    if ((int)$target['is_primary_admin'] === 1 || (int)$target['id'] === (int)$actor['id']) {
        http_response_code(409);
        echo json_encode(['ok'=>false,'error'=>'No se puede eliminar este usuario.']);
        return;
    }

    db()->prepare('DELETE FROM users WHERE id=:id')->execute([':id'=>$id]);
    audit_log('user.delete','user',$id,$target['boat_id'],audit_user_snapshot($target));

    echo json_encode(['ok'=>true,'deleted'=>true,'user'=>api_user_row($target)]);
    return;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'Acción no válida']);
