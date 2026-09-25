<?php
declare(strict_types=1);

csrf_check();
require_guest();

$username_raw = trim((string)($_POST['username'] ?? ''));
$password     = (string)($_POST['password'] ?? '');
$username_norm = normalize_username($username_raw);
$ip = client_ip();

$generic = 'Usuario o contraseña incorrectos';

// Validación mínima de entrada.
if ($username_raw === '' || $password === '') {
    $_SESSION['flash_error'] = $generic;
    redirect('/login');
}

// Comprobar bloqueo por fuerza bruta.
$remaining = brute_is_locked($ip, $username_norm);
if ($remaining > 0) {
    $mins = (int)ceil($remaining / 60);
    $_SESSION['flash_error'] = 'Demasiados intentos. Inténtalo de nuevo en ' . $mins . ' minuto(s).';
    redirect('/login');
}

$stmt = db()->prepare('SELECT * FROM users WHERE username_norm = :u LIMIT 1');
$stmt->execute([':u' => $username_norm]);
$user = $stmt->fetch();

$ok = false;
if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
    $ok = true;
}

if (!$ok) {
    brute_register_failure($ip, $username_norm);
    $_SESSION['flash_error'] = $generic;
    redirect('/login');
}

// Login correcto.
brute_reset($ip, $username_norm);

// Regenerar ID de sesión para evitar fijación.
session_regenerate_id(true);
$_SESSION['user_id']       = (int)$user['id'];
$_SESSION['last_activity'] = time();
// Renovar token CSRF tras autenticarse.
unset($_SESSION['csrf_token']);

redirect('/home');
