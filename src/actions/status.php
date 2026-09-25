<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR]);

$dataDir = DATA_DIR;
$free    = @disk_free_space($dataDir);
$total   = @disk_total_space($dataDir);
$freePct = ($total && $total > 0) ? ($free / $total * 100.0) : null;

$dbSize  = is_file(DB_FILE) ? filesize(DB_FILE) : 0;

$backups = backup_list();
$lastAuto = null;
foreach ($backups as $b) { if ($b['type'] === 'auto') { $lastAuto = $b; break; } }

$lastRun  = is_file(AUTOBACKUP_LAST) ? (int)@file_get_contents(AUTOBACKUP_LAST) : 0;
$lastFail = is_file(AUTOBACKUP_FAIL) ? (string)@file_get_contents(AUTOBACKUP_FAIL) : '';

// Comprobación SQLite
$sqliteOk = 'desconocido';
try { $sqliteOk = (string)db()->query('PRAGMA integrity_check')->fetchColumn(); } catch (Throwable $e) { $sqliteOk = 'error: ' . $e->getMessage(); }

// Directorios
// NOTA: estructura adaptada para hosting sin acceso por encima del docroot
// (ej. AwardSpace): index.php y assets/ viven en APP_ROOT en vez de en public/.
$dirs = [
    'data'             => is_dir(DATA_DIR)             && is_writable(DATA_DIR),
    'data/photos'      => is_dir(DATA_DIR.'/photos')   && is_writable(DATA_DIR.'/photos'),
    'data/backups'     => is_dir(BACKUP_DIR)           && is_writable(BACKUP_DIR),
    'index.php'        => is_file(APP_ROOT.'/index.php'),
    'assets'           => is_dir(APP_ROOT.'/assets'),
    'vendor'           => is_dir(APP_ROOT.'/vendor'),
];

render('status/index', [
    'title'    => 'Estado del sistema',
    'actor'    => $actor,
    'app_ver'  => APP_VERSION,
    'sch_ver'  => SCHEMA_VERSION,
    'free'     => $free, 'total' => $total, 'freePct' => $freePct,
    'db_size'  => $dbSize, 'sqliteOk' => $sqliteOk,
    'lastAuto' => $lastAuto, 'lastRun' => $lastRun, 'lastFail' => $lastFail,
    'dirs'     => $dirs,
    'warn_pct' => setting_int('disk_warning_percent'),
]);
