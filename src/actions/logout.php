<?php
declare(strict_types=1);

// Logout requiere POST + CSRF.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/home');
}
csrf_check();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']);
}
session_destroy();

session_start();
$_SESSION['flash_success'] = 'Sesión cerrada.';
redirect('/login');
