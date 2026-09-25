<?php
declare(strict_types=1);

/**
 * Backups: crear, verificar, restaurar, listar y eliminar.
 * Estructura del ZIP:
 *   database.sqlite
 *   photos/{id}.jpg (opcional)
 *   manifest.json
 */

function _safe_name_component(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? '';
    return trim($s, '_') ?: 'file';
}

function backup_list(): array {
    if (!is_dir(BACKUP_DIR)) return [];
    $out = [];
    foreach (glob(BACKUP_DIR . '/backup_*_*.zip') ?: [] as $p) {
        $name = basename($p);
        $type = str_starts_with($name, 'backup_manual_') ? 'manual'
              : (str_starts_with($name, 'backup_auto_') ? 'auto'
              : (str_starts_with($name, 'backup_seguridad_') ? 'security'
              : (str_starts_with($name, 'backup_app_') ? 'app' : 'otros')));
        $out[] = ['name' => $name, 'size' => filesize($p), 'mtime' => filemtime($p), 'type' => $type];
    }
    usort($out, fn($a,$b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

function backup_path(string $name): string {
    $safe = _safe_name_component($name);
    // Aceptar solo nombres que empiecen por backup_ y terminen en .zip
    if (!preg_match('/^backup_(manual|auto|seguridad|app)_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{4}(_[0-9]+)?\.zip$/', $safe)) return '';
    $p = BACKUP_DIR . '/' . $safe;
    return is_file($p) ? $p : '';
}

/**
 * Crea un backup. $prefix: manual|auto|seguridad
 * @return array{ok:bool, path?:string, name?:string, error?:string}
 */
function backup_create(string $prefix): array {
    if (!in_array($prefix, ['manual','auto','seguridad'], true)) return ['ok'=>false,'error'=>'Prefijo inválido'];
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);

    $stamp = gmdate('Y-m-d_Hi');
    $name  = "backup_{$prefix}_{$stamp}.zip";
    $path  = BACKUP_DIR . '/' . $name;

    // No sobrescribir: añadir sufijo _n si existe.
    $n = 1;
    while (is_file($path)) {
        $name = "backup_{$prefix}_{$stamp}_{$n}.zip";
        $path = BACKUP_DIR . '/' . $name;
        $n++;
        if ($n > 99) return ['ok'=>false,'error'=>'Demasiados backups con el mismo timestamp'];
    }

    // Fotos esperadas: contar registros con photo_path en BD.
    try {
        $expected = (int)db()->query('SELECT COUNT(*) FROM parts WHERE photo_path IS NOT NULL')->fetchColumn();
    } catch (Throwable $e) { $expected = 0; }

    // Copiar SQLite con VACUUM INTO para consistencia.
    $tmpDb = DATA_DIR . '/_tmp_backup_' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmpDb) . "'");
        $sha = hash_file('sha256', $tmpDb);
        if (!$sha) throw new RuntimeException('No se pudo calcular SHA-256 del SQLite');

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            @unlink($tmpDb); return ['ok'=>false, 'error'=>'No se pudo crear el ZIP'];
        }
        $zip->addFile($tmpDb, 'database.sqlite');
        $zip->setCompressionName('database.sqlite', ZipArchive::CM_DEFLATE);

        $included = 0;
        if (is_dir(DATA_DIR . '/photos')) {
            foreach (glob(DATA_DIR . '/photos/*.jpg') ?: [] as $ph) {
                $zip->addFile($ph, 'photos/' . basename($ph));
                $included++;
            }
        }

        $manifest = [
            'app_version'     => APP_VERSION,
            'schema_version'  => SCHEMA_VERSION,
            'created_at_utc'  => gmdate('c'),
            'sqlite_sha256'   => $sha,
            'photos_expected' => $expected,
            'photos_included' => $included,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $zip->close();
        @unlink($tmpDb);
        @chmod($path, 0640);
        return ['ok'=>true, 'path'=>$path, 'name'=>$name, 'manifest'=>$manifest];
    } catch (Throwable $e) {
        @unlink($tmpDb);
        if (is_file($path)) @unlink($path);
        return ['ok'=>false, 'error'=>$e->getMessage()];
    }
}

/**
 * Verifica la integridad de un backup.
 * @return array{ok:bool, error?:string, manifest?:array, notes?:array}
 */
function backup_verify(string $path): array {
    if (!is_file($path)) return ['ok'=>false, 'error'=>'Archivo no encontrado'];

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return ['ok'=>false,'error'=>'ZIP corrupto o ilegible'];

    $manifestRaw = $zip->getFromName('manifest.json');
    if ($manifestRaw === false) { $zip->close(); return ['ok'=>false,'error'=>'Falta manifest.json']; }
    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest)) { $zip->close(); return ['ok'=>false,'error'=>'manifest.json no válido']; }
    foreach (['sqlite_sha256','schema_version','photos_expected','photos_included','created_at_utc'] as $k) {
        if (!array_key_exists($k, $manifest)) { $zip->close(); return ['ok'=>false,'error'=>"manifest.json sin campo $k"]; }
    }
    if ((int)$manifest['schema_version'] > SCHEMA_VERSION) {
        $zip->close();
        return ['ok'=>false,'error'=>'Esquema del backup ('.$manifest['schema_version'].') más nuevo que el actual ('.SCHEMA_VERSION.')'];
    }

    // Extraer SQLite a un tmp para comprobar SHA-256 e integrity_check.
    $tmpDir = DATA_DIR . '/_tmp_verify_' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0700, true);
    $ok = $zip->extractTo($tmpDir, 'database.sqlite');
    if (!$ok) { $zip->close(); _rrm($tmpDir); return ['ok'=>false,'error'=>'No se pudo extraer database.sqlite']; }
    $tmpDb = $tmpDir . '/database.sqlite';
    $sha = hash_file('sha256', $tmpDb);
    if ($sha !== $manifest['sqlite_sha256']) { $zip->close(); _rrm($tmpDir); return ['ok'=>false,'error'=>'SHA-256 del SQLite no coincide']; }

    try {
        $pdo = new PDO('sqlite:' . $tmpDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $chk = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($chk !== 'ok') { $pdo = null; $zip->close(); _rrm($tmpDir); return ['ok'=>false,'error'=>'PRAGMA integrity_check: '.$chk]; }
        // Comprobar que existen tablas básicas.
        $need = ['users','boats','categories','parts','audit_log'];
        foreach ($need as $t) {
            $s = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:n");
            $s->execute([':n'=>$t]);
            if (!$s->fetch()) { $pdo=null; $zip->close(); _rrm($tmpDir); return ['ok'=>false,'error'=>'Falta tabla '.$t]; }
        }
        $pdo = null;
    } catch (Throwable $e) {
        $zip->close(); _rrm($tmpDir);
        return ['ok'=>false,'error'=>'SQLite no válido: '.$e->getMessage()];
    }

    // Contar fotos incluidas.
    $found = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        if (str_starts_with($entry['name'], 'photos/') && str_ends_with($entry['name'], '.jpg')) $found++;
    }
    $zip->close();
    _rrm($tmpDir);

    $notes = [];
    if ($found !== (int)$manifest['photos_included']) {
        $notes[] = "El manifest declara {$manifest['photos_included']} fotos pero el ZIP contiene $found.";
    }
    if ((int)$manifest['photos_included'] < (int)$manifest['photos_expected']) {
        $notes[] = 'Faltan '.((int)$manifest['photos_expected'] - (int)$manifest['photos_included']).' fotografías respecto a las esperadas (no invalida el backup).';
    }
    return ['ok'=>true, 'manifest'=>$manifest, 'notes'=>$notes];
}

function _rrm(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) _rrm($p); else @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Restaura un backup. Crea un backup de seguridad primero.
 * @return array{ok:bool, error?:string, security_backup?:string}
 */
function backup_restore(string $path): array {
    $v = backup_verify($path);
    if (!$v['ok']) return ['ok'=>false, 'error'=>'Integridad: '.$v['error']];

    // 1. Backup de seguridad del estado actual.
    $sec = backup_create('seguridad');
    if (!$sec['ok']) return ['ok'=>false, 'error'=>'No se pudo crear backup de seguridad: '.$sec['error']];

    // 2. Cerrar PDO actual y borrar WAL/SHM residuales.
    db_reset();
    foreach ([DB_FILE . '-wal', DB_FILE . '-shm'] as $f) if (is_file($f)) @unlink($f);

    // 3. Extraer nueva BD y fotos a un directorio staging.
    $stage = DATA_DIR . '/_tmp_restore_' . bin2hex(random_bytes(4));
    mkdir($stage, 0700, true);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) { _rrm($stage); return ['ok'=>false,'error'=>'ZIP ilegible en restauración']; }
    $zip->extractTo($stage);
    $zip->close();

    try {
        // 4. Sustituir database.sqlite.
        if (!is_file($stage . '/database.sqlite')) throw new RuntimeException('No hay database.sqlite en el backup');
        if (!copy($stage . '/database.sqlite', DB_FILE)) throw new RuntimeException('No se pudo copiar la BD');
        @chmod(DB_FILE, 0640);

        // 5. Sustituir fotos: borrar existentes y copiar las del backup.
        $photoDir = DATA_DIR . '/photos';
        if (is_dir($photoDir)) {
            foreach (glob($photoDir . '/*.jpg') ?: [] as $f) @unlink($f);
        } else { mkdir($photoDir, 0750, true); }
        if (is_dir($stage . '/photos')) {
            foreach (glob($stage . '/photos/*.jpg') ?: [] as $f) {
                $target = $photoDir . '/' . basename($f);
                copy($f, $target); @chmod($target, 0640);
            }
        }

        // 6. Comprobar SQLite tras restaurar.
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $chk = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $pdo = null;
        if ($chk !== 'ok') throw new RuntimeException('SQLite integrity_check tras restauración: '.$chk);

        _rrm($stage);
        return ['ok'=>true, 'security_backup'=>$sec['name']];
    } catch (Throwable $e) {
        _rrm($stage);
        // Intentar restaurar el backup de seguridad.
        $rollback = _restore_from_zip($sec['path']);
        if (!$rollback['ok']) {
            return ['ok'=>false, 'error'=>'ERROR grave: '.$e->getMessage().' — y falló el rollback: '.$rollback['error']];
        }
        return ['ok'=>false, 'error'=>'Restauración fallida y revertida al estado anterior: '.$e->getMessage(), 'security_backup'=>$sec['name']];
    }
}

/**
 * Crea un backup COMPLETO de la aplicación (código + datos + docs de despliegue).
 * @return array{ok:bool, path?:string, name?:string, error?:string}
 */
function backup_create_app(): array {
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);
    $stamp = gmdate('Y-m-d_Hi');
    $name  = "backup_app_{$stamp}.zip";
    $path  = BACKUP_DIR . '/' . $name;
    $n = 1;
    while (is_file($path)) {
        $name = "backup_app_{$stamp}_{$n}.zip"; $path = BACKUP_DIR . '/' . $name; $n++;
        if ($n > 99) return ['ok'=>false,'error'=>'Demasiados backups con el mismo timestamp'];
    }

    // Copia consistente de la BD.
    $tmpDb = DATA_DIR . '/_tmp_appbk_' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmpDb) . "'");
        $sha = hash_file('sha256', $tmpDb) ?: '';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            @unlink($tmpDb); return ['ok'=>false, 'error'=>'No se pudo crear el ZIP'];
        }

        // 1) Datos actuales (misma estructura que un backup de datos).
        $zip->addFile($tmpDb, 'data/database.sqlite');
        $photos_included = 0; $photos_expected = 0;
        try {
            $photos_expected = (int)db()->query('SELECT COUNT(*) FROM parts WHERE photo_path IS NOT NULL')->fetchColumn();
        } catch (Throwable $e) {}
        if (is_dir(DATA_DIR . '/photos')) {
            foreach (glob(DATA_DIR . '/photos/*.jpg') ?: [] as $ph) {
                $zip->addFile($ph, 'data/photos/' . basename($ph));
                $photos_included++;
            }
        }

        // 2) Código de la aplicación (recursivo, con exclusiones).
        $excluded_data = ['photos','backups','app.sqlite','installed.lock']; // data/* controlado arriba
        $addDir = function(string $srcAbs, string $inZip) use (&$addDir, $zip): void {
            foreach (scandir($srcAbs) ?: [] as $f) {
                if ($f === '.' || $f === '..' || $f === '.git') continue;
                $abs = $srcAbs . '/' . $f;
                $rel = $inZip . '/' . $f;
                if (is_dir($abs)) $addDir($abs, $rel);
                elseif (is_file($abs)) $zip->addFile($abs, $rel);
            }
        };
        // NOTA: estructura adaptada para hosting sin acceso por encima del docroot
        // (ej. AwardSpace): index.php, .htaccess y assets/ viven en APP_ROOT
        // en vez de dentro de una carpeta 'public/'.
        foreach (['assets','src','vendor'] as $dir) {
            if (is_dir(APP_ROOT . '/' . $dir)) $addDir(APP_ROOT . '/' . $dir, $dir);
        }
        foreach (['index.php','.htaccess','router.php','composer.json','composer.lock','README.md','README_DEPLOY.md'] as $f) {
            if (is_file(APP_ROOT . '/' . $f)) $zip->addFile(APP_ROOT . '/' . $f, $f);
        }
        // Estructura mínima de directorios de datos (vacíos) para que el instalador pueda crear todo.
        $zip->addEmptyDir('data');
        $zip->addEmptyDir('data/photos');
        $zip->addEmptyDir('data/backups');
        // Incluir el .htaccess protector aunque data/ vaya vacío.
        if (is_file(DATA_DIR . '/.htaccess'))            $zip->addFile(DATA_DIR . '/.htaccess',            'data/.htaccess');
        if (is_file(DATA_DIR . '/photos/.htaccess'))     $zip->addFile(DATA_DIR . '/photos/.htaccess',     'data/photos/.htaccess');
        if (is_file(DATA_DIR . '/backups/.htaccess'))    $zip->addFile(DATA_DIR . '/backups/.htaccess',    'data/backups/.htaccess');

        // 3) README de despliegue.
        $zip->addFromString('README_DEPLOY.md', _app_deploy_readme());

        // 4) Manifest.
        $manifest = [
            'kind'            => 'app_full',
            'app_version'     => APP_VERSION,
            'schema_version'  => SCHEMA_VERSION,
            'created_at_utc'  => gmdate('c'),
            'sqlite_sha256'   => $sha,
            'photos_expected' => $photos_expected,
            'photos_included' => $photos_included,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

        $zip->close();
        @unlink($tmpDb);
        @chmod($path, 0640);
        return ['ok'=>true, 'path'=>$path, 'name'=>$name, 'manifest'=>$manifest];
    } catch (Throwable $e) {
        @unlink($tmpDb);
        if (is_file($path)) @unlink($path);
        return ['ok'=>false, 'error'=>$e->getMessage()];
    }
}

function _app_deploy_readme(): string {
    return <<<MD
# Despliegue en otro servidor PHP

Este archivo ZIP es un **backup completo de la aplicación**. Contiene el código, las dependencias y los datos actuales.

## 1. Requisitos del servidor
- **PHP 8.3** o superior en línea de comandos y en el servidor web.
- Extensiones PHP: `pdo`, `pdo_sqlite`, `gd`, `zip`, `xml`, `mbstring`.
- Un servidor web capaz de ejecutar PHP (Apache + mod_php, Nginx + PHP-FPM, o el servidor embebido `php -S`).
- ~100 MB libres (crece con las fotografías).

## 2. Descomprimir el backup
```bash
mkdir /var/www/inventario
unzip backup_app_YYYY-MM-DD_HHMM.zip -d /var/www/inventario
```
Estructura resultante:
```
/var/www/inventario/
├── index.php       front controller / DocumentRoot
├── .htaccess       reglas de reescritura (todo -> index.php)
├── assets/         CSS y estáticos públicos
├── src/            código PHP (bloqueado por .htaccess a peticiones HTTP)
├── vendor/         dependencias Composer (ya incluidas, bloqueado por .htaccess)
├── data/           BD y fotos actuales (ver punto 5)
├── composer.json / composer.lock
├── README.md
├── README_DEPLOY.md (este archivo)
└── manifest.json   metadatos del backup
```

**Nota:** esta estructura corresponde a hostings donde no se puede fijar el
DocumentRoot en una subcarpeta ni acceder a un nivel por encima de la raíz web
(caso típico de hosting compartido/gratuito, ej. AwardSpace). `index.php`,
`.htaccess` y `assets/` van directamente en la raíz web, junto a `src/` y
`vendor/` (protegidos de acceso HTTP con su propio `.htaccess`). Si tu
servidor sí permite un DocumentRoot separado, puedes adaptarlo a la
estructura clásica con carpeta `public/` moviendo estos mismos archivos ahí
y ajustando las rutas `require` en `index.php` de vuelta a `../src/...`.

## 3. Dependencias Composer (opcional)
`vendor/` ya viene incluido. Si prefieres reinstalar:
```bash
cd /var/www/inventario
composer install --no-dev --optimize-autoloader
```

## 4. Permisos
El usuario del servidor web debe poder escribir en `data/`:
```bash
chown -R www-data:www-data data/
chmod 750 data data/photos data/backups
```

## 5. Base de datos y fotografías
El ZIP incluye la BD (`data/database.sqlite`) y las fotos (`data/photos/*.jpg`). Muévelas a los sitios definitivos:
```bash
mv data/database.sqlite data/app.sqlite
touch data/installed.lock
```
- Si prefieres una instalación limpia, borra `data/app.sqlite` y `data/installed.lock`; al primer acceso el instalador te pedirá crear el primer administrador.
- Las fotos ya deberían estar en `data/photos/`.

## 6. Configuración del servidor web

**Hosting compartido sin DocumentRoot configurable (este backup):**
`index.php`, `.htaccess` y `assets/` van en la raíz web; `src/`, `vendor/` y
`data/` como hermanos suyos, cada uno con su propio `.htaccess` de bloqueo.
El `.htaccess` de la raíz reescribe todo hacia `index.php`:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

**Apache/Nginx con DocumentRoot configurable (estructura clásica):**
Si tu servidor te permite fijar el DocumentRoot en una subcarpeta, puedes
crear una carpeta `public/`, mover ahí `index.php` y `assets/`, cambiar en
`index.php` los `require __DIR__ . '/...'` de vuelta a
`require __DIR__ . '/../...'`, y apuntar el DocumentRoot a `public/`.
El `.htaccess` en `data/` bloquea igualmente el acceso HTTP a la BD, fotos y
backups.

**Nginx:** `root` en `public/` (o la raíz elegida), `try_files \$uri /index.php;`, denegar `/data/`, `/src/`, `/vendor/`.

## 7. Ruta base (subdirectorio)
Si la aplicación va en `https://ejemplo.com/inventario/` en lugar de la raíz, edita `src/config.php`:
```php
\$BASE_PATH = '/inventario';
```

## 8. Comprobar que funciona
1. Abre la URL en el navegador.
2. Si es una instalación nueva verás **CONFIGURACIÓN INICIAL → Nueva instalación** para crear el primer administrador.
3. Si es una restauración de datos, entra con las credenciales del backup.
4. Menú *Estado del sistema*: `PRAGMA integrity_check = ok`, directorios OK.

## 9. Problemas habituales
- **500 / pantalla en blanco:** revisa el `error_log` del servidor y los permisos de `data/`.
- **No se puede subir foto:** `upload_max_filesize` y `post_max_size` ≥ 8 MB, extensión `gd` cargada.
- **Sesión no persiste:** cookies bloqueadas o `session.save_path` sin permisos de escritura.

## 10. Qué **NO** debe copiarse tal cual del servidor original
- Rutas absolutas de la máquina origen: usa las tuyas propias (`chown`, ubicación del `DocumentRoot`).
- Certificados TLS, sockets, configuración de proxy o firewall: son propios del servidor destino.
- Ficheros de log del servidor (`/var/log/...`) o binarios del sistema.
- Cualquier variable de entorno específica de Emergent, contenedores, orquestadores, etc.

El backup **no incluye** contraseñas en texto plano ni secretos del servidor; solo hashes bcrypt de las cuentas.
MD;
}

/**
 * Restaura desde un archivo ZIP subido por el usuario.
 * Comprueba: extensión, tamaño, ZIP válido, estructura, path traversal.
 * Soporta backup de datos (raíz con database.sqlite) y backup completo (data/database.sqlite).
 */
function restore_from_uploaded_zip(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok'=>false,'error'=>'Error al subir el archivo (código ' . ($file['error'] ?? -1) . ').'];
    $name = (string)($file['name'] ?? '');
    if (!preg_match('/\.zip$/i', $name)) return ['ok'=>false,'error'=>'El archivo debe tener extensión .zip'];

    // Copiar el fichero a un temp en /app/data para poder verificarlo y reutilizarlo con backup_verify().
    $tmp = DATA_DIR . '/_tmp_upload_' . bin2hex(random_bytes(4)) . '.zip';
    if (!move_uploaded_file($file['tmp_name'], $tmp)) {
        if (!@copy($file['tmp_name'], $tmp)) return ['ok'=>false,'error'=>'No se pudo procesar el archivo subido'];
    }
    @chmod($tmp, 0640);

    // Validar path traversal y estructura.
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::RDONLY) !== true) { @unlink($tmp); return ['ok'=>false,'error'=>'ZIP corrupto o ilegible']; }
    $numFiles = $zip->numFiles;
    $isFullApp = false;
    for ($i = 0; $i < $numFiles; $i++) {
        $nm = $zip->getNameIndex($i);
        if ($nm === false) continue;
        if (str_contains($nm, '..') || (isset($nm[0]) && ($nm[0] === '/' || $nm[0] === '\\'))
            || preg_match('#^[A-Za-z]:#', $nm)) {
            $zip->close(); @unlink($tmp);
            return ['ok'=>false,'error'=>'Ruta peligrosa dentro del ZIP: ' . $nm];
        }
        if (str_starts_with($nm, 'data/database.sqlite')) $isFullApp = true;
    }
    $zip->close();

    // Si es un backup completo, extraer solo data/database.sqlite y data/photos/* a un tmp con estructura de backup de datos y verificar.
    if ($isFullApp) {
        $sub = DATA_DIR . '/_tmp_uploadsub_' . bin2hex(random_bytes(4));
        mkdir($sub, 0700, true);
        $z1 = new ZipArchive(); $z1->open($tmp, ZipArchive::RDONLY);
        $z1->extractTo($sub);
        $z1->close();
        // Construir un mini-ZIP con database.sqlite + photos + manifest.json (regenerado si falta).
        $newTmp = DATA_DIR . '/_tmp_uploaddata_' . bin2hex(random_bytes(4)) . '.zip';
        $z2 = new ZipArchive();
        if ($z2->open($newTmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { _rrm($sub); @unlink($tmp); return ['ok'=>false,'error'=>'No se pudo preparar el archivo para restaurar']; }
        if (!is_file($sub . '/data/database.sqlite')) { $z2->close(); _rrm($sub); @unlink($tmp); @unlink($newTmp); return ['ok'=>false,'error'=>'El backup completo no contiene data/database.sqlite']; }
        $z2->addFile($sub . '/data/database.sqlite', 'database.sqlite');
        $incl = 0;
        if (is_dir($sub . '/data/photos')) foreach (glob($sub . '/data/photos/*.jpg') ?: [] as $ph) { $z2->addFile($ph, 'photos/' . basename($ph)); $incl++; }
        // Manifest: usar el del ZIP si existe (compatible), si no fabricar uno consistente.
        $manifest = null;
        if (is_file($sub . '/manifest.json')) $manifest = json_decode((string)file_get_contents($sub . '/manifest.json'), true);
        if (!is_array($manifest) || !isset($manifest['sqlite_sha256'])) {
            $manifest = [
                'app_version'     => APP_VERSION,
                'schema_version'  => SCHEMA_VERSION,
                'created_at_utc'  => gmdate('c'),
                'sqlite_sha256'   => hash_file('sha256', $sub . '/data/database.sqlite') ?: '',
                'photos_expected' => $incl,
                'photos_included' => $incl,
            ];
        } else {
            // Recalcular sha para el manifest interno del sub-zip.
            $manifest['sqlite_sha256']   = hash_file('sha256', $sub . '/data/database.sqlite') ?: '';
            $manifest['photos_included'] = $incl;
            $manifest['photos_expected'] = $manifest['photos_expected'] ?? $incl;
        }
        $z2->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $z2->close();
        _rrm($sub);
        @unlink($tmp);
        $tmp = $newTmp; // Ahora $tmp es un backup de datos verificable.
    }

    // Verificar e invocar el flujo estándar (incluye crear backup de seguridad automáticamente).
    $res = backup_restore($tmp);
    @unlink($tmp);
    return $res;
}

/** Restauración interna sin generar backup de seguridad (para rollback). */
function _restore_from_zip(string $path): array {
    if (!is_file($path)) return ['ok'=>false, 'error'=>'Backup no encontrado'];
    db_reset();
    foreach ([DB_FILE . '-wal', DB_FILE . '-shm'] as $f) if (is_file($f)) @unlink($f);
    $stage = DATA_DIR . '/_tmp_rollback_' . bin2hex(random_bytes(4));
    mkdir($stage, 0700, true);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) { _rrm($stage); return ['ok'=>false,'error'=>'ZIP ilegible']; }
    $zip->extractTo($stage);
    $zip->close();
    try {
        copy($stage . '/database.sqlite', DB_FILE);
        $photoDir = DATA_DIR . '/photos';
        if (is_dir($photoDir)) foreach (glob($photoDir . '/*.jpg') ?: [] as $f) @unlink($f);
        if (is_dir($stage . '/photos')) foreach (glob($stage . '/photos/*.jpg') ?: [] as $f) copy($f, $photoDir . '/' . basename($f));
        _rrm($stage);
        return ['ok'=>true];
    } catch (Throwable $e) {
        _rrm($stage);
        return ['ok'=>false, 'error'=>$e->getMessage()];
    }
}

function backup_delete(string $name): bool {
    $path = backup_path($name);
    if (!$path) return false;
    return @unlink($path);
}

/** Limpia backups de seguridad más antiguos que la retención configurada. */
function backup_cleanup_security(): void {
    $days   = max(1, setting_int('backup_retention_days'));
    $cutoff = time() - $days * 86400;
    foreach (glob(BACKUP_DIR . '/backup_seguridad_*.zip') ?: [] as $p) {
        if (filemtime($p) < $cutoff) @unlink($p);
    }
}

/** Purga registros de auditoría más antiguos que audit_retention_days. */
function audit_cleanup(): int {
    try {
        $days = max(1, setting_int('audit_retention_days'));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $st = db()->prepare('DELETE FROM audit_log WHERE at_utc < :c');
        $st->execute([':c' => $cutoff]);
        return $st->rowCount();
    } catch (Throwable $e) { error_log('audit_cleanup: '.$e->getMessage()); return 0; }
}

/* ---------- Auto backup ---------- */

/** Determina si toca autobackup. */
function autobackup_due(): bool {
    if (!is_dir(BACKUP_DIR)) return true;
    if (!is_file(AUTOBACKUP_LAST)) return true;
    $last = (int)@file_get_contents(AUTOBACKUP_LAST);
    $interval = max(1, setting_int('backup_interval_days')) * 86400;
    return $last === 0 || (time() - $last) >= $interval;
}

/** Comprueba si otro proceso está ya haciendo backup (lock < 5 min). */
function autobackup_in_progress(): bool {
    if (!is_file(AUTOBACKUP_LOCK)) return false;
    $age = time() - (int)@filemtime(AUTOBACKUP_LOCK);
    if ($age > 300) { @unlink(AUTOBACKUP_LOCK); return false; }
    return true;
}

function autobackup_run(): array {
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0750, true);
    @file_put_contents(AUTOBACKUP_LOCK, (string)getmypid());
    try {
        backup_cleanup_security();
        audit_cleanup();
        $res = backup_create('auto');
        if ($res['ok']) {
            file_put_contents(AUTOBACKUP_LAST, (string)time());
            @unlink(AUTOBACKUP_FAIL);
            @audit_log('backup.create','backup',null,null,null,['type'=>'auto','name'=>$res['name']]);
        } else {
            @file_put_contents(AUTOBACKUP_FAIL, gmdate('c') . ' - ' . $res['error']);
            @audit_log('backup.fail','backup',null,null,null,['type'=>'auto','error'=>$res['error']]);
            error_log('Autobackup fail: ' . $res['error']);
        }
        return $res;
    } finally {
        @unlink(AUTOBACKUP_LOCK);
    }
}

/**
 * Decide si esta ruta admite el chequeo de autobackup.
 * NO ejecutar en descargas de fotos, backups o assets estáticos (estos últimos no llegan aquí).
 */
function autobackup_should_check(string $path, string $method): bool {
    if ($method !== 'GET') return false;
    if (str_starts_with($path, '/backups')) return false;
    if (str_starts_with($path, '/export'))  return false;
    if (preg_match('#^/parts/\d+/photo$#', $path)) return false;
    if ($path === '/login' || $path === '/logout' || $path === '/install') return false;
    return true;
}
