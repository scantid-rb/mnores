<?php
declare(strict_types=1);

$BASE_PATH = '';

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('DB_FILE', DATA_DIR . '/app.sqlite');
define('INSTALL_LOCK', DATA_DIR . '/installed.lock');
define('BASE_PATH', $BASE_PATH);

define('SESSION_IDLE_SECONDS', 2 * 60 * 60);
define('BRUTE_MAX_ATTEMPTS', 10);
define('BRUTE_LOCK_SECONDS', 5 * 60);

// Fase 4
define('APP_VERSION',      '1.4.1');
define('SCHEMA_VERSION',   3);
define('BACKUP_DIR',       DATA_DIR . '/backups');
define('AUTO_BACKUP_INTERVAL_SECONDS', 7 * 24 * 3600);
define('SECURITY_BACKUP_RETAIN_SECONDS', 7 * 24 * 3600);
define('AUTOBACKUP_LOCK',  BACKUP_DIR . '/.autobackup.lock');
define('AUTOBACKUP_LAST',  BACKUP_DIR . '/.autobackup.last');
define('AUTOBACKUP_FAIL',  BACKUP_DIR . '/.autobackup.fail');
