<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$actor = api_require_auth();
if (!in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
    return;
}

$dataDir = DATA_DIR;
$free = @disk_free_space($dataDir);
$total = @disk_total_space($dataDir);
$freePct = ($total && $total > 0) ? ($free / $total * 100.0) : null;
$dbSize = is_file(DB_FILE) ? (int)filesize(DB_FILE) : 0;

$backups = backup_list();
$lastAuto = null;
foreach ($backups as $b) {
    if (($b['type'] ?? '') === 'auto') {
        $lastAuto = $b;
        break;
    }
}

$lastRun = is_file(AUTOBACKUP_LAST) ? (int)@file_get_contents(AUTOBACKUP_LAST) : 0;
$lastFail = is_file(AUTOBACKUP_FAIL) ? (string)@file_get_contents(AUTOBACKUP_FAIL) : '';

$sqliteOk = 'desconocido';
try {
    $sqliteOk = (string)db()->query('PRAGMA integrity_check')->fetchColumn();
} catch (Throwable $e) {
    $sqliteOk = 'error';
}

$dirs = [
    'data' => is_dir(DATA_DIR) && is_writable(DATA_DIR),
    'data/photos' => is_dir(DATA_DIR . '/photos') && is_writable(DATA_DIR . '/photos'),
    'data/backups' => is_dir(BACKUP_DIR) && is_writable(BACKUP_DIR),
    'index.php' => is_file(APP_ROOT . '/index.php'),
    'assets' => is_dir(APP_ROOT . '/assets'),
    'vendor' => is_dir(APP_ROOT . '/vendor'),
];

echo json_encode([
    'ok' => true,
    'app_version' => APP_VERSION,
    'api_version' => API_VERSION,
    'schema_version' => SCHEMA_VERSION,
    'sqlite_integrity' => $sqliteOk,
    'database_size_bytes' => $dbSize,
    'disk' => [
        'free_bytes' => $free !== false ? (int)$free : null,
        'total_bytes' => $total !== false ? (int)$total : null,
        'free_percent' => $freePct !== null ? round($freePct, 2) : null,
        'warning_percent' => setting_int('disk_warning_percent'),
        'warning' => $freePct !== null && $freePct < setting_int('disk_warning_percent'),
    ],
    'backup' => [
        'last_auto' => $lastAuto ? [
            'name' => $lastAuto['name'],
            'mtime' => (int)$lastAuto['mtime'],
        ] : null,
        'last_run' => $lastRun ?: null,
        'last_failure' => $lastFail !== '' ? $lastFail : null,
    ],
    'directories' => $dirs,
]);
