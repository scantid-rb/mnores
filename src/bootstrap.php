<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/parts_util.php';
require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/backup.php';
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Cabeceras de seguridad razonables (aplicables a todas las respuestas HTML).
// NOTA: frame-ancestors omitido/permisivo para permitir la vista previa por iframe.
// En despliegue real, restringir con X-Frame-Options: SAMEORIGIN si el servidor no se embebe en otros orígenes.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; base-uri 'self'; form-action 'self'");
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0, 'path' => BASE_PATH === '' ? '/' : BASE_PATH,
    'httponly' => true, 'samesite' => 'Lax', 'secure' => $secure,
]);
session_name('INVAPPSID');
session_start();

if (is_installed()) {
    try { db_migrate(db()); } catch (Throwable $e) { error_log('Migrate error: ' . $e->getMessage()); }
}

if (isset($_SESSION['user_id'])) {
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_SECONDS) {
        $_SESSION = []; session_destroy(); session_start();
        $_SESSION['flash_error'] = 'Sesión expirada por inactividad. Inicia sesión de nuevo.';
    } else { $_SESSION['last_activity'] = $now; }
}

if (is_installed() && isset($_SESSION['user_id'])) {
    $ap  = current_path();
    $am  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (autobackup_should_check($ap, $am) && autobackup_due()) {
        if (autobackup_in_progress()) {
            http_response_code(503);
            header('Retry-After: 5');
            echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="4">'
               . '<title>Mantenimiento</title>'
               . '<link rel="stylesheet" href="' . e(url('/assets/style.css')) . '">'
               . '<main class="container"><section class="card card-narrow"><h1 class="title">Mantenimiento</h1>'
               . '<p>Realizando mantenimiento del sistema, por favor espere…</p></section></main>';
            exit;
        }
        $res = autobackup_run();
        if ($res['ok']) {
            $_SESSION['flash_success'] = 'El mantenimiento automático ha concluido. Ya puede utilizar la aplicación.';
        } else {
            $_SESSION['flash_error'] = 'El mantenimiento automático ha fallado. Se reintentará en el próximo acceso.';
        }
    }
}
