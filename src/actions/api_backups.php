<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();
if (!in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    return;
}

$path = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$labels = [
    'manual' => 'Datos (manual)',
    'auto' => 'Datos (automático)',
    'security' => 'Seguridad',
    'app' => 'Aplicación completa',
    'otros' => 'Otros',
];

function backup_api_item(array $item): array {
    global $labels;
    return [
        'name' => (string)$item['name'],
        'type' => (string)$item['type'],
        'label' => $labels[$item['type']] ?? (string)$item['type'],
        'size' => (int)$item['size'],
        'mtime' => (int)$item['mtime'],
    ];
}

if ($path === '/api/backups' && $method === 'GET') {
    $items = array_map('backup_api_item', backup_list());
    echo json_encode([
        'ok' => true,
        'items' => $items,
        'interval_days' => setting_int('backup_interval_days'),
        'last_run' => is_file(AUTOBACKUP_LAST) ? ((int)@file_get_contents(AUTOBACKUP_LAST) ?: null) : null,
        'last_failure' => is_file(AUTOBACKUP_FAIL) ? ((string)@file_get_contents(AUTOBACKUP_FAIL) ?: null) : null,
    ]);
    return;
}

if ($path === '/api/backups' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
        return;
    }

    $action = (string)$input['action'];

    if ($action === 'create_data' || $action === 'create_app') {
        $res = $action === 'create_app' ? backup_create_app() : backup_create('manual');
        if (!$res['ok']) {
            audit_log('backup.fail', 'backup', null, null, null, [
                'type' => $action === 'create_app' ? 'app' : 'manual',
                'error' => $res['error'] ?? 'Error desconocido',
            ]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'No se pudo crear el backup']);
            return;
        }

        audit_log('backup.create', 'backup', null, null, null, [
            'type' => $action === 'create_app' ? 'app' : 'manual',
            'name' => $res['name'],
        ]);
        echo json_encode(['ok' => true, 'name' => $res['name']]);
        return;
    }

    if ($action === 'delete') {
        $name = (string)($input['name'] ?? '');
        $pathFile = backup_path($name);
        if ($pathFile === '') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Backup no encontrado']);
            return;
        }

        if (!backup_delete($name)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo eliminar el backup']);
            return;
        }

        audit_log('backup.delete', 'backup', null, null, ['name' => $name], null);
        echo json_encode(['ok' => true, 'deleted' => true]);
        return;
    }

    if ($action === 'restore') {
        if ($actor['role'] !== ROLE_ADMIN) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Solo un Administrador puede restaurar']);
            return;
        }

        $name = (string)($input['name'] ?? '');
        $pathFile = backup_path($name);
        if ($pathFile === '') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Backup no encontrado']);
            return;
        }

        $itemType = null;
        foreach (backup_list() as $item) {
            if ($item['name'] === $name) {
                $itemType = $item['type'];
                break;
            }
        }
        if ($itemType === 'app') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Los backups completos de aplicación no se restauran desde la lista']);
            return;
        }

        $res = backup_restore($pathFile);
        if (!$res['ok']) {
            audit_log('backup.restore_fail', 'backup', null, null, null, [
                'from' => $name,
                'error' => $res['error'] ?? 'Error desconocido',
            ]);
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Restauración fallida']);
            return;
        }

        audit_log('backup.restore', 'backup', null, null, null, [
            'from' => $name,
            'security_backup' => $res['security_backup'] ?? null,
        ]);
        echo json_encode([
            'ok' => true,
            'security_backup' => $res['security_backup'] ?? null,
            'session_invalidated' => true,
        ]);
        return;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción no soportada']);
    return;
}

if (preg_match('#^/api/backups/([^/]+)/download$#', $path, $m) && $method === 'GET') {
    $name = rawurldecode($m[1]);
    $file = backup_path($name);
    if ($file === '') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Backup no encontrado']);
        return;
    }

    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return;
}

if ($path === '/api/backups/restore-upload' && $method === 'POST') {
    if ($actor['role'] !== ROLE_ADMIN) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo un Administrador puede restaurar']);
        return;
    }

    $file = $_FILES['backup'] ?? [];
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No se ha seleccionado ningún archivo']);
        return;
    }

    $max = 200 * 1024 * 1024;
    if (($file['size'] ?? 0) > $max) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'El archivo supera el límite de 200 MB']);
        return;
    }

    $res = restore_from_uploaded_zip($file);
    if (!$res['ok']) {
        audit_log('backup.restore_fail', 'backup', null, null, null, [
            'from' => 'archivo_local:' . basename((string)($file['name'] ?? '')),
            'error' => $res['error'] ?? 'Error desconocido',
        ]);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Restauración fallida']);
        return;
    }

    audit_log('backup.restore', 'backup', null, null, null, [
        'from' => 'archivo_local:' . basename((string)($file['name'] ?? '')),
        'security_backup' => $res['security_backup'] ?? null,
    ]);
    echo json_encode([
        'ok' => true,
        'security_backup' => $res['security_backup'] ?? null,
        'session_invalidated' => true,
    ]);
    return;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Ruta de API no encontrada']);
