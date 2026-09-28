<?php
declare(strict_types=1);

/**
 * Public API handshake.
 * No authentication is required: the mobile client uses this endpoint
 * before changing server configuration or attempting a login.
 */

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok'         => true,
    'app_name'   => setting('app_name', 'Inventario de Repuestos'),
    'app_title'  => setting('app_title', 'Repuestos a bordo'),
    'app_version'=> APP_VERSION,
    'api_version'=> API_VERSION,
    'installed'  => is_installed(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
