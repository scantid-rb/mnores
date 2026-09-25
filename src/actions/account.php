<?php
declare(strict_types=1);

$user   = require_login();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $p1      = (string)($_POST['password']  ?? '');
    $p2      = (string)($_POST['password2'] ?? '');

    $errs = [];
    if (!password_verify($current, $user['password_hash'])) $errs[] = 'La contraseña actual es incorrecta.';
    if (mb_strlen($p1) < 8) $errs[] = 'La contraseña nueva debe tener al menos 8 caracteres.';
    if ($p1 !== $p2)         $errs[] = 'Las contraseñas nuevas no coinciden.';

    if ($errs) {
        render('account', ['title' => 'Mi cuenta', 'user' => $user, 'errors' => $errs]);
        return;
    }
    $hash = password_hash($p1, PASSWORD_DEFAULT);
    db()->prepare('UPDATE users SET password_hash=:h WHERE id=:id')->execute([':h' => $hash, ':id' => $user['id']]);
    audit_log('user.password_change', 'user', (int)$user['id'], $user['boat_id']);
    $_SESSION['flash_success'] = 'Contraseña cambiada.';
    redirect('/account');
}

render('account', ['title' => 'Mi cuenta', 'user' => $user, 'errors' => []]);
