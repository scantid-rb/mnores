<?php
declare(strict_types=1);

$actor = require_role([ROLE_ADMIN, ROLE_INSPECTOR]);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$errors = [];
if ($method === 'POST') {
    csrf_check();
    $new = [
        'app_name'              => trim((string)($_POST['app_name'] ?? '')),
        'app_title'             => trim((string)($_POST['app_title'] ?? '')),
        'company_name'          => trim((string)($_POST['company_name'] ?? '')),
        'backup_interval_days'  => (int)($_POST['backup_interval_days'] ?? 7),
        'backup_retention_days' => (int)($_POST['backup_retention_days'] ?? 7),
        'audit_retention_days'  => (int)($_POST['audit_retention_days'] ?? 90),
        'disk_warning_percent'  => (int)($_POST['disk_warning_percent'] ?? 20),
    ];
    if ($new['app_name'] === '' || mb_strlen($new['app_name']) > 80)   $errors[] = 'Nombre de aplicación inválido.';
    if ($new['app_title'] === '' || mb_strlen($new['app_title']) > 80) $errors[] = 'Título inválido.';
    if (mb_strlen($new['company_name']) > 120)                          $errors[] = 'Empresa demasiado larga.';
    foreach (['backup_interval_days'=>[1,365], 'backup_retention_days'=>[1,365], 'audit_retention_days'=>[1,3650]] as $k=>[$min,$max]) {
        if ($new[$k] < $min || $new[$k] > $max) $errors[] = ucfirst(str_replace('_',' ',$k)) . " fuera de rango ($min-$max).";
    }
    if ($new['disk_warning_percent'] < 0 || $new['disk_warning_percent'] > 90) $errors[] = 'Umbral de espacio 0-90.';
    if (!$errors) {
        $old = settings_all();
        settings_set($new);
        audit_log('settings.update', 'settings', null, null, $old, $new);
        $_SESSION['flash_success'] = 'Configuración guardada.';
        redirect('/settings');
    }
}

render('settings/index', [
    'title'  => 'Configuración',
    'actor'  => $actor,
    'values' => settings_all(),
    'errors' => $errors,
]);
