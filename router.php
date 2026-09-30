<?php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
// php -S ignores Apache .htaccess: keep private directories private here too.
if (preg_match('#(?:^|/)(?:data|src|vendor|frontend|\.[^/]*)(?:/|$)#', $path)) {
    http_response_code(403);
    return;
}
$file = realpath(__DIR__ . $path);
if ($path !== '/' && $file && str_starts_with($file, __DIR__ . '/') && is_file($file)) return false;
if (($path === '/pwa' || str_starts_with($path, '/pwa/')) && is_file(__DIR__ . '/pwa/index.html')) {
    if ($path === '/pwa') { header('Location: /pwa/'); return; }
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/pwa/index.html');
    return;
}
require __DIR__ . '/index.php';
