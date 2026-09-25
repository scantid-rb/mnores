<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_auth.php';

$path   = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Rutas de la API para la app Android (van antes que todo lo demás,
// y no usan sesión/cookies ni CSRF, sino el carnet de acceso).
if (str_starts_with($path, '/api/')) {
    if (preg_match('#^/api/photos/(\d+)$#', $path, $m)) {
        $api_photo_id = (int)$m[1];
        require __DIR__ . '/src/actions/api_photos.php';
        return;
    }

    switch ($path) {
        case '/api/login':
            if ($method === 'POST') { require __DIR__ . '/src/actions/api_login.php'; return; }
            break;
        case '/api/me':
            if ($method === 'GET') { require __DIR__ . '/src/actions/api_me.php'; return; }
            break;
        case '/api/sync':
            if ($method === 'GET') { require __DIR__ . '/src/actions/api_sync.php'; return; }
            break;
        case '/api/parts/push':
            if ($method === 'POST') { require __DIR__ . '/src/actions/api_parts_push.php'; return; }
            break;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Ruta de API no encontrada']);
    return;
}

if (!is_installed()) {
    if ($path === '/install') {
        require __DIR__ . '/src/actions/' . ($method === 'POST' ? 'install_post.php' : 'install_get.php');
        return;
    }
    redirect('/install');
}
if ($path === '/install') redirect('/');

// Rutas estáticas simples primero.
switch ($path) {
    case '/':
    case '/home':
        if ($method === 'GET') { require __DIR__ . '/src/actions/home.php'; return; }
        break;
    case '/login':
        require __DIR__ . '/src/actions/' . ($method === 'POST' ? 'login_post.php' : 'login_get.php');
        return;
    case '/logout':
        require __DIR__ . '/src/actions/logout.php';
        return;
    case '/account':
        require __DIR__ . '/src/actions/account.php';
        return;
}

// Rutas de recursos con posibles subrutas.
$resources = [
    '/users'      => 'users.php',
    '/boats'      => 'boats.php',
    '/categories' => 'categories.php',
    '/parts'      => 'parts.php',
    '/backups'    => 'backups.php',
    '/export'     => 'export.php',
    '/settings'   => 'settings.php',
    '/status'     => 'status.php',
    '/audit'      => 'audit_view.php',
];
foreach ($resources as $prefix => $file) {
    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        require __DIR__ . '/src/actions/' . $file;
        return;
    }
}

http_response_code(404);
echo '<!doctype html><meta charset="utf-8"><title>404</title>';
echo '<p style="font-family:sans-serif;padding:2rem">Página no encontrada. <a href="' . e(url('/')) . '">Inicio</a></p>';
