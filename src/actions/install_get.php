<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

$errors = [];
$checks = [
    'PHP 8.3 o superior'  => version_compare(PHP_VERSION, '8.3.0', '>='),
    'Extensión PDO'       => extension_loaded('PDO'),
    'Extensión pdo_sqlite'=> extension_loaded('pdo_sqlite'),
    'Directorio de datos escribible' => is_writable(DATA_DIR) || (is_dir(DATA_DIR) === false && is_writable(dirname(DATA_DIR))),
];

render('install', [
    'title'  => 'Instalación',
    'checks' => $checks,
    'errors' => $errors,
]);
