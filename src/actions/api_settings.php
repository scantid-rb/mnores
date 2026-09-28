<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();
if (!in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    return;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'ok' => true,
        'settings' => settings_all(),
    ]);
    return;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    return;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    return;
}

$new = [
    'app_name' => trim((string)($input['app_name'] ?? '')),
    'app_title' => trim((string)($input['app_title'] ?? '')),
    'company_name' => trim((string)($input['company_name'] ?? '')),
    'backup_interval_days' => (int)($input['backup_interval_days'] ?? 7),
    'backup_retention_days' => (int)($input['backup_retention_days'] ?? 7),
    'audit_retention_days' => (int)($input['audit_retention_days'] ?? 90),
    'disk_warning_percent' => (int)($input['disk_warning_percent'] ?? 20),
];

$errors = [];
if ($new['app_name'] === '' || mb_strlen($new['app_name']) > 80) $errors[] = 'Nombre de aplicación inválido.';
if ($new['app_title'] === '' || mb_strlen($new['app_title']) > 80) $errors[] = 'Título inválido.';
if (mb_strlen($new['company_name']) > 120) $errors[] = 'Empresa demasiado larga.';

foreach ([
    'backup_interval_days' => [1, 365],
    'backup_retention_days' => [1, 365],
    'audit_retention_days' => [1, 3650],
] as $key => [$min, $max]) {
    if ($new[$key] < $min || $new[$key] > $max) {
        $errors[] = ucfirst(str_replace('_', ' ', $key)) . " fuera de rango ($min-$max).";
    }
}

if ($new['disk_warning_percent'] < 0 || $new['disk_warning_percent'] > 90) {
    $errors[] = 'Umbral de espacio 0-90.';
}

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos', 'errors' => $errors]);
    return;
}

$old = settings_all();
settings_set($new);
audit_log('settings.update', 'settings', null, null, $old, $new);

echo json_encode([
    'ok' => true,
    'settings' => settings_all(),
]);
