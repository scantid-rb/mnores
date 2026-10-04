<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$user = api_require_auth();

function api_account_payload(array $u): array {
    return [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'],
        'role' => $u['role'],
        'boat_id' => $u['boat_id'] !== null ? (int)$u['boat_id'] : null,
        'is_active' => (int)$u['is_active'],
    ];
}

function api_account_reload(int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id=:id');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok'=>false, 'error'=>'Usuario no encontrado']);
        exit;
    }
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok'=>true, 'user'=>api_account_payload($user)]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Método no permitido']);
    return;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'JSON inválido']);
    return;
}

$action = (string)($input['action'] ?? '');

if ($action === 'update_profile') {
    $username = trim((string)($input['username'] ?? ''));
    $firstName = trim((string)($input['first_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $errors = [];

    if ($firstName === '' || mb_strlen($firstName) > 80) $errors[] = 'Nombre inválido.';
    if ($lastName === '' || mb_strlen($lastName) > 120) $errors[] = 'Apellidos inválidos.';
    if ($username === '' || !preg_match('/^[A-Za-z0-9._\\-]{1,40}$/', $username)) $errors[] = 'Usuario inválido.';

    $usernameNorm = normalize_username($username);
    if ($usernameNorm !== '') {
        $q = db()->prepare('SELECT id FROM users WHERE username_norm=:u AND id<>:id');
        $q->execute([':u'=>$usernameNorm, ':id'=>(int)$user['id']]);
        if ($q->fetch()) $errors[] = 'El nombre de usuario ya existe.';
    }

    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok'=>false, 'error'=>implode(' ', $errors)]);
        return;
    }

    $old = audit_user_snapshot($user);
    db()->prepare('UPDATE users SET username=:u, username_norm=:un, first_name=:fn, last_name=:ln WHERE id=:id')
        ->execute([
            ':u'=>$username,
            ':un'=>$usernameNorm,
            ':fn'=>$firstName,
            ':ln'=>$lastName,
            ':id'=>(int)$user['id'],
        ]);

    $updated = api_account_reload((int)$user['id']);
    audit_log('user.profile_update', 'user', (int)$user['id'], $user['boat_id'], $old, audit_user_snapshot($updated));
    echo json_encode(['ok'=>true, 'user'=>api_account_payload($updated)]);
    return;
}

if ($action === 'change_password') {
    $current = (string)($input['current_password'] ?? '');
    $password = (string)($input['password'] ?? '');
    $password2 = (string)($input['password2'] ?? '');
    $errors = [];

    if (!password_verify($current, $user['password_hash'])) $errors[] = 'La contraseña actual es incorrecta.';
    if (mb_strlen($password) < 8) $errors[] = 'La contraseña nueva debe tener al menos 8 caracteres.';
    if ($password !== $password2) $errors[] = 'Las contraseñas nuevas no coinciden.';

    if ($errors) {
        http_response_code(422);
        echo json_encode(['ok'=>false, 'error'=>implode(' ', $errors)]);
        return;
    }

    db()->prepare('UPDATE users SET password_hash=:h WHERE id=:id')
        ->execute([':h'=>password_hash($password, PASSWORD_DEFAULT), ':id'=>(int)$user['id']]);
    audit_log('user.password_change', 'user', (int)$user['id'], $user['boat_id']);
    echo json_encode(['ok'=>true]);
    return;
}

http_response_code(400);
echo json_encode(['ok'=>false, 'error'=>'Acción no válida']);
