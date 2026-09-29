<?php
declare(strict_types=1);

const DEPLOY_ZIP = 'despliegue.zip';

$root = __DIR__;
$zipPath = $root . DIRECTORY_SEPARATOR . DEPLOY_ZIP;
$scriptPath = __FILE__;
$tmpDir = $root . DIRECTORY_SEPARATOR . '.deploy_' . bin2hex(random_bytes(8));
$success = false;

register_shutdown_function(static function () use (&$success, $zipPath, $scriptPath, $tmpDir): void {
    if ($success) {
        @unlink($zipPath);
        @unlink($scriptPath);
    }
    if (is_dir($tmpDir)) {
        deploy_remove_tree($tmpDir);
    }
});

function deploy_fail(string $message, int $httpCode = 500): never
{
    http_response_code($httpCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "DESPLIEGUE NO COMPLETADO\n\n";
    echo $message . "\n\n";
    echo "deploy.php y despliegue.zip se han conservado para revisar el problema y reintentar.\n";
    exit;
}

function deploy_remove_tree(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && !is_link($path)) {
            deploy_remove_tree($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function deploy_is_safe_entry(string $name): bool
{
    if ($name === '' || str_contains($name, "\0")) return false;
    if ($name[0] === '/' || $name[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $name)) return false;
    $parts = explode('/', str_replace('\\', '/', $name));
    foreach ($parts as $part) {
        if ($part === '..') return false;
    }
    return true;
}

function deploy_copy_tree(string $source, string $destination): void
{
    if (!is_dir($source)) throw new RuntimeException('No existe el directorio temporal.');
    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException('No se pudo crear el directorio de destino.');
    }

    foreach (scandir($source) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $src = $source . DIRECTORY_SEPARATOR . $name;
        $dst = $destination . DIRECTORY_SEPARATOR . $name;

        if ($name === 'data' || $name === 'deploy.php' || $name === DEPLOY_ZIP) {
            throw new RuntimeException("El ZIP contiene un elemento reservado: {$name}");
        }

        if (is_dir($src) && !is_link($src)) {
            deploy_copy_tree($src, $dst);
        } elseif (is_file($src)) {
            if (!copy($src, $dst)) throw new RuntimeException("No se pudo copiar: {$name}");
        } else {
            throw new RuntimeException("Tipo de entrada no permitido: {$name}");
        }
    }
}

header('Content-Type: text/plain; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    deploy_fail('Solo se permite ejecutar el despliegue mediante GET.', 405);
}

if (!class_exists('ZipArchive')) {
    deploy_fail('La extensión PHP ZipArchive no está disponible.');
}

if (!is_file($zipPath)) {
    deploy_fail('No se encuentra despliegue.zip junto a deploy.php.', 404);
}

if (!is_readable($zipPath) || @filesize($zipPath) === 0) {
    deploy_fail('despliegue.zip no se puede leer o está vacío.');
}

if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
    deploy_fail('No se pudo crear el directorio temporal.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
    deploy_fail('despliegue.zip no es un ZIP válido o no se puede abrir.');
}

if ($zip->numFiles === 0) {
    $zip->close();
    deploy_fail('despliegue.zip no contiene archivos.');
}

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false || !deploy_is_safe_entry($name)) {
        $zip->close();
        deploy_fail('El ZIP contiene una ruta no segura.');
    }

    $normalized = str_replace('\\', '/', $name);
    $trimmed = rtrim($normalized, '/');
    if ($trimmed !== '') {
        $top = explode('/', $trimmed, 2)[0];
        if ($top === 'data' || $top === 'deploy.php' || $top === DEPLOY_ZIP) {
            $zip->close();
            deploy_fail("El ZIP contiene una ruta reservada: {$name}");
        }
    }
}

if (!$zip->extractTo($tmpDir)) {
    $zip->close();
    deploy_fail('No se pudo descomprimir despliegue.zip.');
}
$zip->close();

if (!is_file($tmpDir . DIRECTORY_SEPARATOR . 'index.php')) {
    deploy_fail('El paquete no contiene index.php en su raíz.');
}

try {
    deploy_copy_tree($tmpDir, $root);
    $success = true;

    echo "DESPLIEGUE COMPLETADO CORRECTAMENTE\n\n";
    echo "La aplicación se ha actualizado desde despliegue.zip.\n";
    echo "deploy.php y despliegue.zip se eliminarán automáticamente al finalizar esta petición.\n";
    echo "La carpeta data/ y sus datos persistentes no se han modificado.\n";
} catch (Throwable $e) {
    deploy_fail('Error durante la instalación: ' . $e->getMessage());
}
