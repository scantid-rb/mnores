<?php
declare(strict_types=1);

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string {
    if ($path !== '' && $path[0] !== '/') $path = '/' . $path;
    return BASE_PATH . $path;
}

function redirect(string $path): void {
    header('Location: ' . url($path));
    exit;
}

function current_path(): string {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (BASE_PATH !== '' && str_starts_with($uri, BASE_PATH)) {
        $uri = substr($uri, strlen(BASE_PATH));
    }
    if ($uri === '' || $uri === false) $uri = '/';
    return rtrim($uri, '/') ?: '/';
}

function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return $ip;
}

function render(string $view, array $data = []): void {
    extract($data, EXTR_SKIP);
    $__view = $view;
    require __DIR__ . '/views/layout.php';
}
