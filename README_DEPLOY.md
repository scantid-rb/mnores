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

**Nginx:** `root` en `public/` (o la raíz elegida), `try_files $uri /index.php;`, denegar `/data/`, `/src/`, `/vendor/`.

## 7. Ruta base (subdirectorio)
Si la aplicación va en `https://ejemplo.com/inventario/` en lugar de la raíz, edita `src/config.php`:
```php
$BASE_PATH = '/inventario';
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