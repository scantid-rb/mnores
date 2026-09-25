<?php
declare(strict_types=1);

$actor = require_login();
$path  = current_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Exención: POST /backups/restore-upload es gestionado por su propio guard (mensaje distinto).
$exempt = ($path === '/backups/restore-upload' && $method === 'POST');
if (!$exempt && !in_array($actor['role'], [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    $_SESSION['flash_error'] = 'Solo un Administrador o Inspector puede acceder a Backups.';
    redirect('/home');
}

if ($path === '/backups' && $method === 'GET') {
    render('backups/index', [
        'title'  => 'Backups',
        'actor'  => $actor,
        'items'  => backup_list(),
        'last'   => is_file(AUTOBACKUP_LAST) ? (int)@file_get_contents(AUTOBACKUP_LAST) : 0,
        'fail'   => is_file(AUTOBACKUP_FAIL) ? (string)@file_get_contents(AUTOBACKUP_FAIL) : '',
        'interval_days' => (int)setting_int('backup_interval_days'),
    ]);
    return;
}

if ($path === '/backups/create' && $method === 'POST') {
    csrf_check();
    $res = backup_create('manual');
    if ($res['ok']) {
        audit_log('backup.create','backup',null,null,null,['type'=>'manual','name'=>$res['name']]);
        $_SESSION['flash_success'] = 'Backup de datos creado: ' . $res['name'];
    } else {
        audit_log('backup.fail','backup',null,null,null,['type'=>'manual','error'=>$res['error']]);
        $_SESSION['flash_error'] = 'Error al crear backup: ' . $res['error'];
    }
    redirect('/backups');
}

if ($path === '/backups/create-app' && $method === 'POST') {
    csrf_check();
    $res = backup_create_app();
    if ($res['ok']) {
        audit_log('backup.create','backup',null,null,null,['type'=>'app','name'=>$res['name']]);
        $_SESSION['flash_success'] = 'Backup completo de aplicación creado: ' . $res['name'];
    } else {
        audit_log('backup.fail','backup',null,null,null,['type'=>'app','error'=>$res['error']]);
        $_SESSION['flash_error'] = 'Error al crear backup completo: ' . $res['error'];
    }
    redirect('/backups');
}

if ($path === '/backups/restore-upload' && $method === 'POST') {
    csrf_check();
    if ($actor['role'] !== ROLE_ADMIN) { $_SESSION['flash_error'] = 'Solo un Administrador puede restaurar.'; redirect('/home'); }
    $file = $_FILES['backup'] ?? [];
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $_SESSION['flash_error'] = 'No se ha seleccionado ningún archivo.'; redirect('/backups');
    }
    $max = 200 * 1024 * 1024;
    if (($file['size'] ?? 0) > $max) { $_SESSION['flash_error'] = 'El archivo supera el límite (200 MB).'; redirect('/backups'); }

    $res = restore_from_uploaded_zip($file);
    if ($res['ok']) {
        audit_log('backup.restore','backup',null,null,null,['from'=>'archivo_local:'.basename((string)($file['name'] ?? '')),'security_backup'=>$res['security_backup']]);
        $_SESSION = [];
        $_SESSION['flash_success'] = 'Restauración completada. Inicia sesión de nuevo con las credenciales del backup.';
        redirect('/login');
    } else {
        audit_log('backup.restore_fail','backup',null,null,null,['from'=>'archivo_local:'.basename((string)($file['name'] ?? '')),'error'=>$res['error']]);
        $_SESSION['flash_error'] = 'Restauración fallida: ' . $res['error'];
        redirect('/backups');
    }
}

if (preg_match('#^/backups/([^/]+)/download$#', $path, $m) && $method === 'GET') {
    $p = backup_path($m[1]);
    if (!$p) { http_response_code(404); echo 'No encontrado'; return; }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($p);
    return;
}

if (preg_match('#^/backups/([^/]+)/delete$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $name = $m[1];
    $p = backup_path($name);
    if (!$p) { $_SESSION['flash_error'] = 'Backup no encontrado'; redirect('/backups'); }
    if (backup_delete($name)) {
        audit_log('backup.delete','backup',null,null,['name'=>$name],null);
        $_SESSION['flash_success'] = 'Backup eliminado: ' . $name;
    } else {
        $_SESSION['flash_error'] = 'No se pudo eliminar el backup.';
    }
    redirect('/backups');
}

if (preg_match('#^/backups/([^/]+)/restore$#', $path, $m)) {
    $name = $m[1];
    $p = backup_path($name);
    if (!$p) { http_response_code(404); echo 'Backup no encontrado'; return; }

    if ($method === 'GET') {
        $v = backup_verify($p);
        render('backups/confirm_restore', [
            'title' => 'Confirmar restauración',
            'actor' => $actor,
            'name'  => $name,
            'verify'=> $v,
        ]);
        return;
    }
    csrf_check();
    if ($actor['role'] !== ROLE_ADMIN) { $_SESSION['flash_error'] = 'Solo un Administrador puede restaurar.'; redirect('/backups'); }
    $res = backup_restore($p);
    if ($res['ok']) {
        audit_log('backup.restore','backup',null,null,null,['from'=>$name,'security_backup'=>$res['security_backup']]);
        $_SESSION = [];
        $_SESSION['flash_success'] = 'Restauración completada. Inicia sesión de nuevo con las credenciales del backup.';
        redirect('/login');
    } else {
        audit_log('backup.restore_fail','backup',null,null,null,['from'=>$name,'error'=>$res['error']]);
        $_SESSION['flash_error'] = 'Restauración fallida: ' . $res['error'];
        redirect('/backups');
    }
}

http_response_code(404);
echo 'No encontrado';
