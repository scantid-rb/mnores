<?php
declare(strict_types=1);

$user   = require_login();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'change_password');
    $errs = [];

    if ($action === 'update_profile') {
        $username  = trim((string)($_POST['username'] ?? ''));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName  = trim((string)($_POST['last_name'] ?? ''));

        if ($firstName === '' || mb_strlen($firstName) > 80) $errs[] = 'Nombre inválido.';
        if ($lastName === '' || mb_strlen($lastName) > 120) $errs[] = 'Apellidos inválidos.';
        if ($username === '' || !preg_match('/^[A-Za-z0-9._\\-]{1,40}$/', $username)) $errs[] = 'Usuario inválido.';

        $usernameNorm = normalize_username($username);
        if ($usernameNorm !== '') {
            $q = db()->prepare('SELECT id FROM users WHERE username_norm=:u AND id<>:id');
            $q->execute([':u'=>$usernameNorm, ':id'=>(int)$user['id']]);
            if ($q->fetch()) $errs[] = 'El nombre de usuario ya existe.';
        }

        if ($errs) {
            $input = $user;
            $input['username'] = $username;
            $input['first_name'] = $firstName;
            $input['last_name'] = $lastName;
            render('account', ['title'=>'Mi cuenta', 'user'=>$input, 'errors'=>$errs, 'error_section'=>'profile']);
            return;
        }

        $old = audit_user_snapshot($user);
        db()->prepare('UPDATE users SET username=:u, username_norm=:un, first_name=:fn, last_name=:ln WHERE id=:id')
            ->execute([
                ':u'=>$username, ':un'=>$usernameNorm, ':fn'=>$firstName, ':ln'=>$lastName, ':id'=>(int)$user['id']
            ]);
        $new = $user;
        $new['username'] = $username;
        $new['first_name'] = $firstName;
        $new['last_name'] = $lastName;
        audit_log('user.profile_update', 'user', (int)$user['id'], $user['boat_id'], $old, audit_user_snapshot($new));
        $_SESSION['flash_success'] = 'Datos personales actualizados.';
        redirect('/account');
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $p1      = (string)($_POST['password'] ?? '');
        $p2      = (string)($_POST['password2'] ?? '');

        if (!password_verify($current, $user['password_hash'])) $errs[] = 'La contraseña actual es incorrecta.';
        if (mb_strlen($p1) < 8) $errs[] = 'La contraseña nueva debe tener al menos 8 caracteres.';
        if ($p1 !== $p2) $errs[] = 'Las contraseñas nuevas no coinciden.';

        if ($errs) {
            render('account', ['title'=>'Mi cuenta', 'user'=>$user, 'errors'=>$errs, 'error_section'=>'password']);
            return;
        }

        $hash = password_hash($p1, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash=:h WHERE id=:id')->execute([':h'=>$hash, ':id'=>$user['id']]);
        audit_log('user.password_change', 'user', (int)$user['id'], $user['boat_id']);
        $_SESSION['flash_success'] = 'Contraseña cambiada.';
        redirect('/account');
    }

    http_response_code(400);
    $errs[] = 'Acción no válida.';
    render('account', ['title'=>'Mi cuenta', 'user'=>$user, 'errors'=>$errs, 'error_section'=>'profile']);
    return;
}

render('account', ['title'=>'Mi cuenta', 'user'=>$user, 'errors'=>[], 'error_section'=>null]);
