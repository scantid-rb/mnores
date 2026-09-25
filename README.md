# Inventario de Repuestos para Barcos — V1 - este es un proyecto personal, el objetivo de este git y las explicaciones es dejar un punto de partida para mis compañeros de trabajo el día que yo marche de la empresa u otra persona decida continuar donde quedo el proyecto

Aplicación web sencilla para gestionar el inventario de repuestos de una flota pequeña de barcos (~6 barcos, ~18 usuarios). Escrita en PHP 8.3 con SQLite. Sin frameworks JS, sin servicios externos, sin dependencias en la nube.

## Estado actual del proyecto (léeme si vas a continuar este trabajo)

- **Producción real**: alojada en AwardSpace (hosting compartido gratuito).
- **Entorno de desarrollo/pruebas**: subdominio separado `devmn.atwebpages.com`,
  usado para probar cambios sin tocar el sitio en uso real. Se recomienda
  seguir usándolo así para cualquier cambio futuro.
- **AwardSpace no ofrece HTTPS** en el plan gratuito: toda la aplicación
  (web y API) funciona por HTTP. Es una decisión consciente y documentada,
  no un descuido — ver la sección "API para la app Android" más abajo para
  el razonamiento.
- **Estructura de despliegue real**: por cómo funciona AwardSpace (no se
  puede fijar el `DocumentRoot` a una subcarpeta, ni hay nada accesible por
  encima de la raíz web), esta instalación usa una variante de la
  estructura "clásica" descrita más abajo: `index.php`, `.htaccess` y
  `assets/` están directamente en la raíz web, con `src/`, `vendor/` y
  `data/` como carpetas hermanas (cada una protegida con su propio
  `.htaccess`), en vez de una carpeta `public/` como `DocumentRoot`. La
  sección "Instalación" de este documento explica ambas variantes.
- **Hay una API REST añadida** (`/api/...`) para dar servicio a una app
  Android nativa en desarrollo (offline-first, hecha con Emergent). Ver la
  sección dedicada más abajo y el archivo `api_contract_android.md`
  (documento vivo con el contrato exacto de la API — actualizarlo si se
  añaden/cambian endpoints).

## Requisitos

- Linux, macOS o Windows con:
  - **PHP 8.3** o superior en línea de comandos
  - Extensiones PHP: `pdo`, `pdo_sqlite`, `gd`, `zip`, `xml`, `mbstring`
- **SQLite 3** (para consultas manuales, opcional)
- **Composer** (solo para instalar PhpSpreadsheet la primera vez)
- Cualquier servidor web capaz de ejecutar PHP:
  - Servidor embebido de PHP (`php -S`) — suficiente para uso local a bordo
  - Apache/Nginx tradicional con PHP-FPM (recomendado en hosting)
- 100 MB libres iniciales (crece con las fotografías)

## Instalación

1. **Copiar el proyecto** a la máquina destino, por ejemplo `/var/www/inventario`.
   - **Si el hosting permite fijar el `DocumentRoot`** a una subcarpeta (caso general, VPS/servidor propio): usar la estructura "clásica" con `public/` como raíz web (ver diagrama en "Estructura de carpetas").
   - **Si el hosting NO lo permite** (hosting compartido gratuito típico, ej. AwardSpace — el caso de esta instalación): usar la variante plana, con `index.php`, `.htaccess` y `assets/` directamente en la raíz web, y `src/`, `vendor/`, `data/` como hermanas. En ese caso, dentro de `index.php` los `require` deben apuntar a `__DIR__.'/src/...'` en vez de `__DIR__.'/../src/...'`. Añadir además un `.htaccess` propio (vacío o con `Deny from all`) dentro de `src/` y `vendor/`, ya que en esta variante quedan dentro de la raíz web.
2. **Instalar dependencias** (solo si `vendor/` no viene incluido):
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. **Permisos**: la carpeta `data/` y sus subdirectorios (`photos/`, `backups/`) deben ser escribibles por el usuario del servidor web (`www-data`, `apache`, etc.):
   ```bash
   chown -R www-data:www-data data/
   chmod 750 data/ data/photos data/backups
   ```
4. **Configurar el servidor web**:
   - **Servidor embebido (rápido para probar):**
     ```bash
     cd /var/www/inventario
     php -S 0.0.0.0:8080 -t public/ router.php
     ```
   - **Apache**: apuntar `DocumentRoot` a `public/`. El `.htaccess` incluido en `data/` bloquea el acceso HTTP a la BD y a las fotografías.
   - **Nginx**: apuntar `root` a `public/` y usar `try_files $uri /index.php;`. Denegar `location ~ ^/(data|src|vendor)/`.
   - **Hosting compartido sin DocumentRoot configurable** (variante plana, ver paso 1): el `.htaccess` de la raíz debe reescribir todo hacia `index.php`:
     ```apache
     RewriteEngine On
     RewriteCond %{HTTP:Authorization} .
     RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
     RewriteCond %{REQUEST_FILENAME} !-f
     RewriteCond %{REQUEST_FILENAME} !-d
     RewriteRule ^ index.php [L]
     ```
     La línea de `Authorization` es necesaria para que la API (ver más abajo) reciba el carnet de acceso del móvil; algunos hostings PHP-CGI la ocultan si no se fuerza así.
5. **Abrir la aplicación en el navegador**. Al ser primera ejecución mostrará **CONFIGURACIÓN INICIAL → Nueva instalación**. Rellenar el formulario para crear el primer administrador. Se marcará como “administrador principal” (no podrá ser eliminado ni desactivado).
6. **Iniciar sesión** con las credenciales creadas.

### Ruta base / subdirectorio

Si la aplicación se aloja en `https://ejemplo.com/inventario/` en lugar de la raíz, editar `src/config.php` y cambiar:

```php
$BASE_PATH = '/inventario';
```

Debe empezar por `/` y **no** terminar en `/`. Cadena vacía = dominio raíz.

## Configuración

Menú **Configuración** (Administrador/Inspector). Se guardan los valores:

| Clave                  | Descripción                                 | Valor inicial |
|------------------------|---------------------------------------------|---------------|
| `app_name`             | Nombre de la aplicación (título HTML)       | Inventario de Repuestos |
| `app_title`            | Marca visible en la cabecera                | Repuestos a bordo |
| `company_name`         | Empresa/organización (opcional)             | *(vacío)*     |
| `backup_interval_days` | Intervalo del backup automático (días)      | 7             |
| `backup_retention_days`| Retención de backups de seguridad (días)    | 7             |
| `audit_retention_days` | Conservación de registros de auditoría (d)  | 90            |
| `disk_warning_percent` | Umbral para aviso de poco espacio (%)       | 20            |

Los cambios se aplican inmediatamente en la siguiente petición.

## API para la app Android (offline)

Además de la web, la aplicación expone un pequeño conjunto de rutas
`/api/...` pensadas exclusivamente para dar servicio a una app Android
nativa que funciona **offline-first** (ve/edita/crea repuestos sin
conexión, y sincroniza al recuperarla).

**El contrato completo y detallado está en `api_contract_android.md`**
(formato exacto de cada petición/respuesta, reglas de permisos, flujo de
sincronización recomendado). Esa es la referencia a mantener actualizada
si se añade o cambia algún endpoint — este README solo da el resumen.

| Endpoint | Método | Qué hace |
|---|---|---|
| `/api/login` | POST | Da un carnet de acceso (token) a partir de usuario/contraseña |
| `/api/me` | GET | Confirma si el token sigue siendo válido y quién es el usuario |
| `/api/sync` | GET | Baja barcos/categorías/piezas — todo, o solo lo cambiado desde una fecha (`?since=`) |
| `/api/parts/push` | POST | Sube piezas creadas/editadas offline |
| `/api/photos/{id}` | GET/POST | Descarga o sube la foto de una pieza |

**Autenticación**: por token (`Authorization: Bearer <token>`), no por
cookie de sesión — un móvil no gestiona bien cookies. Los tokens se
guardan (hasheados, igual que las contraseñas) en la tabla `api_tokens`,
uno por dispositivo, para poder revocar el acceso de un móvil concreto sin
afectar a los demás.

**Permisos**: la API reutiliza exactamente las mismas funciones de
permisos que la web (`parts_can_view`, `parts_can_edit_all`,
`parts_can_create`, `parts_can_manage_photo`, `parts_visible_boat_id` en
`parts_util.php`). Un jefe de máquinas o mecánico solo ve/edita las
piezas de su propio barco también desde el móvil; admin/inspector ven los
6 barcos. La auditoría (`audit_log`) registra igual las acciones hechas
desde la API que desde la web.

**Sincronización**: `parts` ya traía su propio campo `updated_at` (con
precisión de milisegundos, usado también para detectar ediciones
simultáneas). Para que `boats` y `categories` pudieran participar en la
misma sincronización por fecha, se les añadieron las columnas
`updated_at` y `deleted_at` (borrado lógico, no físico — así el móvil se
entera de qué se borró). Estas columnas y la tabla `api_tokens` están
integradas en `db_init_schema()`/`db_migrate()` (`src/db.php`): se crean
solas tanto en una instalación nueva como al actualizar una existente, no
requieren ningún paso manual.

**Por qué HTTP y no HTTPS**: el hosting actual (AwardSpace, plan
gratuito) no ofrece SSL. Se decidió asumir el riesgo conscientemente: el
uso principal previsto es desde la red de a bordo de los barcos, un
entorno de bajo riesgo de interceptación. Si en el futuro se cambia de
hosting o se añade HTTPS (p. ej. con Cloudflare por delante), no hace
falta cambiar nada del código — `bootstrap.php` ya detecta HTTPS
automáticamente (incluida la cabecera `X-Forwarded-Proto` que usa
Cloudflare).

## Estructura de carpetas

Diagrama de la estructura "clásica" (con `public/` como DocumentRoot).
**Esta instalación en concreto usa la variante plana** (ver "Instalación"):
mismo contenido, pero `index.php`, `.htaccess` y `assets/` van sueltos en la
raíz en vez de dentro de `public/`, y `src/`/`vendor/` llevan cada uno su
propio `.htaccess` de bloqueo.

```
inventario/
├── public/                    # DocumentRoot (único directorio expuesto)
│   ├── index.php              # Front controller
│   └── assets/style.css
├── src/
│   ├── config.php             # Constantes básicas y BASE_PATH
│   ├── bootstrap.php          # Sesión, cabeceras seguridad, auto-backup
│   ├── db.php                 # PDO + esquema + migraciones
│   ├── auth.php               # Roles y permisos
│   ├── csrf.php               # Token CSRF
│   ├── audit.php              # Registro de auditoría
│   ├── settings.php           # Configuración dinámica
│   ├── photos.php             # Procesado de imágenes (GD)
│   ├── backup.php             # Backups y restauración
│   ├── parts_util.php         # Utilidades y permisos del inventario
│   ├── helpers.php            # e(), url(), redirect(), render()
│   ├── api_auth.php           # Carnet de acceso (token) para la app Android
│   ├── actions/               # Un archivo por recurso
│   │   ├── install_get.php / install_post.php
│   │   ├── login_get.php / login_post.php / logout.php / account.php
│   │   ├── home.php
│   │   ├── users.php / boats.php / categories.php
│   │   ├── parts.php / export.php
│   │   ├── backups.php / settings.php / status.php
│   │   ├── api_login.php / api_me.php / api_sync.php   # ver "API para Android"
│   │   └── api_parts_push.php / api_photos.php
│   └── views/                 # Plantillas HTML
├── data/                      # NO accesible por HTTP (.htaccess)
│   ├── app.sqlite             # Base de datos
│   ├── installed.lock         # Marcador de instalación completada
│   ├── photos/                # Fotografías (JPEG)
│   └── backups/               # Backups automáticos, manuales y de seguridad
├── vendor/                    # PhpSpreadsheet + dependencias
├── router.php                 # Router para `php -S` (no usado en la variante plana)
├── composer.json
├── api_contract_android.md    # Contrato de la API para la app Android
└── README.md
```

## Creación de backup

- **Manual**: menú *Backups → “Crear backup manual”*. El ZIP se nombra `backup_manual_YYYY-MM-DD_HHMM.zip` y se guarda en `data/backups/`.
- **Automático**: se ejecuta cada `backup_interval_days` días al recibir una petición dinámica (nunca durante descargas de fotos, exportaciones o assets estáticos). Aparece un mensaje _“Realizando mantenimiento del sistema, por favor espere…”_ si otro proceso ya está ejecutándolo y, al terminar, _“El mantenimiento automático ha concluido. Ya puede utilizar la aplicación.”_ Los fallos se registran en `data/backups/.autobackup.fail` y en `audit_log`.
- **Descargar/eliminar**: desde la misma pantalla, listados por tipo (Manual, Automático, Seguridad).

### Contenido del ZIP

```
database.sqlite
photos/<id>.jpg
manifest.json  ← app_version, schema_version, created_at_utc, sqlite_sha256, photos_expected, photos_included
```

## Restauración

Menú *Backups → Restaurar* (solo Administrador). El proceso:

1. Verifica el ZIP (firma, `manifest.json`, `sqlite_sha256`, `PRAGMA integrity_check`, tablas mínimas, `schema_version ≤ actual`).
2. Pide confirmación.
3. Crea automáticamente un backup de seguridad del estado actual (`backup_seguridad_YYYY-MM-DD_HHMM.zip`).
4. Sustituye `data/app.sqlite` y `data/photos/`.
5. Vuelve a comprobar `PRAGMA integrity_check`.
6. Si algo falla, se intenta revertir al backup de seguridad. Si la reversión también falla, se muestra un error inequívoco al Administrador.
7. Cierra la sesión actual y redirige a `/login` (se debe iniciar sesión con las credenciales de la BD restaurada).

## Actualización manual

1. Detener el servidor web / `php -S`.
2. Sustituir los archivos de `public/`, `src/`, `router.php`, `composer.json` por los de la versión nueva. **No tocar** `data/` ni `vendor/` a menos que la versión nueva lo pida explícitamente.
3. Si cambian dependencias:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
4. Reiniciar el servidor. La aplicación aplicará automáticamente cualquier migración de esquema al primer acceso.
5. Comprobar en *Estado* que `Versión aplicación`, `Versión esquema` y `SQLite integrity_check` son correctos.

## Solución de problemas habituales

**“ERR_TOO_MANY_REDIRECTS” o bucle a `/install`**
- Falta el fichero `data/installed.lock`. Si la instalación se completó, crearlo manualmente con `date > data/installed.lock`.

**Errores 500 o pantalla en blanco**
- Revisar `error_log` del servidor (Apache/Nginx) o `stderr` de `php -S`.
- Comprobar permisos: `data/` debe ser escribible por PHP.
- Ejecutar `php -l src/bootstrap.php` para validar sintaxis tras un cambio.

**No se puede subir fotografía**
- Comprobar que `php.ini` permite subidas de al menos 8 MB (`upload_max_filesize`, `post_max_size`).
- Extensión `gd` habilitada (`php -m | grep -i gd`).

**Auto-backup no se ejecuta**
- Precisa un usuario autenticado navegando por rutas dinámicas. Si nadie entra durante el intervalo configurado, el siguiente acceso lo desencadenará.
- Revisar `data/backups/.autobackup.fail` para el último error.

**Restauración fallida**
- La aplicación intenta revertir al backup de seguridad. Si la reversión también falla, existe un ZIP en `data/backups/backup_seguridad_*.zip` que se puede aplicar manualmente:
  ```bash
  unzip -o backup_seguridad_YYYY-MM-DD_HHMM.zip -d /tmp/rest
  cp /tmp/rest/database.sqlite data/app.sqlite
  cp /tmp/rest/photos/*.jpg data/photos/ 2>/dev/null || true
  ```

**Baja el rendimiento con muchos accesos concurrentes**
- Este proyecto está pensado para 3–20 usuarios. Si se supera, usar Apache/Nginx + PHP-FPM en lugar del servidor embebido.

## Seguridad implementada

- Prepared statements (PDO) en todas las consultas.
- Escape con `htmlspecialchars` en todas las plantillas.
- Validación de entrada por longitud y patrón.
- Autorización backend en cada acción (403 al saltarse un permiso).
- CSRF token en todas las operaciones que modifican datos (POST).
- Contraseñas con `password_hash` (bcrypt), mínimo 8 caracteres.
- Sesiones con cookie `HttpOnly` + `SameSite=Lax`; `Secure` automático si la petición llega por HTTPS.
- Protección de fuerza bruta: 10 fallos por IP+usuario → bloqueo 5 min.
- SQLite y fotografías fuera del `DocumentRoot`; `.htaccess` con `Deny from all` y `php_flag engine off`.
- Uploads: validados con `getimagesize()` + `imagecreatefrom*` (rechaza contenido falso). Máximo 8 MB. Reescritos siempre como JPEG con redimensión a 1600×1200.
- Cabeceras HTTP: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`, `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; ...`.

## Portabilidad

- No hay rutas absolutas en el código: todas se construyen con `url()` sobre `BASE_PATH`.
- Funciona en HTTP puro; usa HTTPS si el proxy o servidor lo termina (ver nota sobre HTTPS en "API para la app Android").
- Sin dependencia de Emergent ni de servicios en la nube para la aplicación web (Emergent solo se usa para construir la app Android cliente, que consume la API descrita arriba).
- Todos los datos residen en `data/`. Copiar esa carpeta = migrar la aplicación. Los tokens de la app Android viajan dentro de la base de datos, así que siguen siendo válidos tras migrar — solo hay que cambiar la URL del servidor en los "Ajustes de conexión" de la app.

## Licencia y créditos

Aplicación desarrollada como proyecto interno para gestión de repuestos de barcos. Dependencia externa: [PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/) (MIT).
