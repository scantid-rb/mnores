<?php
/**
 * Router para el servidor embebido de PHP.
 * - Sirve estáticos existentes tal cual.
 * - Todo lo demás delega a public/index.php.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$file = __DIR__ . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // servir archivo estático
}
require __DIR__ . '/public/index.php';
