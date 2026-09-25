<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

csrf_check();

if (is_installed()) {
    redirect('/');
}

$checks = [
    'PHP 8.3 o superior'  => version_compare(PHP_VERSION, '8.3.0', '>='),
    'Extensión PDO'       => extension_loaded('PDO'),
    'Extensión pdo_sqlite'=> extension_loaded('pdo_sqlite'),
    'Directorio de datos escribible' => is_writable(DATA_DIR),
];

$errors = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $errors[] = 'Requisito no cumplido: ' . $label;
    }
}

$first_name = trim((string)($_POST['first_name'] ?? ''));
$last_name  = trim((string)($_POST['last_name']  ?? ''));
$username   = trim((string)($_POST['username']   ?? ''));
$password   = (string)($_POST['password']  ?? '');
$password2  = (string)($_POST['password2'] ?? '');

if ($first_name === '' || mb_strlen($first_name) > 80)  $errors[] = 'Nombre inválido.';
if ($last_name  === '' || mb_strlen($last_name)  > 120) $errors[] = 'Apellidos inválidos.';
if ($username === '' || !preg_match('/^[A-Za-z0-9._\-]{1,40}$/', $username)) $errors[] = 'Nombre de usuario inválido.';
if (mb_strlen($password) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
if ($password !== $password2) $errors[] = 'Las contraseñas no coinciden.';

if ($errors) {
    render('install', ['title' => 'Instalación', 'checks' => $checks, 'errors' => $errors]);
    return;
}

try {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    $pdo = db();
    db_init_schema($pdo);

    // Comprobar que no existe ya un usuario (seguridad extra).
    $exists = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($exists > 0) {
        $errors[] = 'Ya existen usuarios. La instalación no puede continuar.';
        render('install', ['title' => 'Instalación', 'checks' => $checks, 'errors' => $errors]);
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $pdo->prepare('INSERT INTO users
        (username, username_norm, first_name, last_name, password_hash, role, is_active, is_primary_admin)
        VALUES (:u, :un, :fn, :ln, :ph, :role, 1, 1)');
    $ins->execute([
        ':u'    => $username,
        ':un'   => normalize_username($username),
        ':fn'   => $first_name,
        ':ln'   => $last_name,
        ':ph'   => $hash,
        ':role' => 'admin',
    ]);

    // Marcar instalación como completada.
    file_put_contents(INSTALL_LOCK, date('c'));
    @chmod(DB_FILE, 0640);

    $_SESSION['flash_success'] = 'Instalación completada. Inicia sesión con el administrador.';
    redirect('/login');
} catch (Throwable $e) {
    error_log('Install error: ' . $e->getMessage());
    $errors[] = 'Error durante la instalación: ' . $e->getMessage();
    render('install', ['title' => 'Instalación', 'checks' => $checks, 'errors' => $errors]);
}
