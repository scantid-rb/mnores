# PWA: revisión de propietario, migraciones y barcos eliminados

Ámbito: `scantid-rb/mnores`, rama `pwa`, PR #2 sin fusionar. Cliente 0.1.1,
API 1.4.5 y servidor https://mnores.atwebpages.com conservados.

## P1: propietario de caché y colas

Antes, logout eliminaba `SessionRow` y dejaba inventario y colas. El siguiente
login, sin propietario previo, no limpiaba esos datos. Además, publicaba el
nuevo token antes de guardar su metadata.

Ahora `SessionRow` permanece como **propietario no secreto** de inventario,
`pending_changes`, `photo_queue`, blobs y cursor. Sin credencial independiente
no existe sesión autenticada. Logout elimina únicamente la credencial.

Login compara por ID, retira primero el token anterior y publica el nuevo
solo después de completar las escrituras persistentes:

- Otro ID, o propietario desconocido: limpiar datos/colas/blobs antes de guardar
  la nueva identidad.
- Mismo ID y mismos permisos/barco: conservar datos y cursor; actualizar username.
- Mismo ID con rol o barco distinto: limpiar la caché cuyo ámbito dejó de ser válido.
- Cambio de servidor: retirar token, limpiar datos y propietario/cursor antes de
  cambiar la URL; IDs iguales en distintos servidores no comparten identidad.

`sessionLifecycle.ts` serializa login/logout, sincronización y escrituras de
pantallas mediante Web Locks, también entre pestañas. Las escrituras locales
comprueban el ID propietario y la presencia de credencial, sin consultar Internet.
Una pantalla antigua de A no puede añadir trabajo a la nueva caché de B.

Antes de push/pull/fotos, el motor comprueba que el token invocado sigue siendo
el almacenado y que `/api/me` devuelve el ID propietario. Una discrepancia o
error de autenticación/red termina antes de enviar trabajo. Un fallo antiguo
no puede cerrar la sesión de una credencial nueva.

No se guarda ningún token en IndexedDB ni se añade otra copia: continúa el
almacenamiento de credenciales ya existente. En web, sus helpers `secure*`
usan AsyncStorage/localStorage; el nombre no implica cifrado de navegador.
Las colas y blobs continúan en las transacciones IndexedDB existentes, sin
cambios de esquema. Web Locks requiere un navegador moderno y HTTPS; si no
está disponible, las operaciones sensibles se rechazan antes de ejecutar
trabajo, para no sustituir la garantía entre pestañas por un bloqueo solo local.

## P1: migración del esquema

Antes, `CREATE TABLE IF NOT EXISTS` dejaba tablas antiguas intactas, pero se
normalizaban timestamps y creaban índices/triggers antes de añadir columnas.

Orden final:

1. Crear tablas ausentes.
2. Inspeccionar `boats`, `categories`, `parts`; añadir `updated_at`/`deleted_at`
   ausentes e inicializar timestamps recién añadidos.
3. Crear índices que ya pueden referenciar columnas garantizadas, insertar la
   categoría de sistema y establecer triggers de inicialización.
4. Ejecutar migraciones compatibles de usuarios/client_local_id, normalizar
   timestamps, actualizar triggers ISO y crear índices de tombstones.

No se reconstruyen tablas ni se eliminan filas. `tests/migration.php` ejecuta
inicialización y dos migraciones sobre bases SQLite en memoria: instalación
nueva, antigua sin tombstones y antigua sin timestamps ni tombstones. Verifica
columnas, índices, consultas, datos conservados y triggers insert/update.

## P2: barcos eliminados

Los validadores y selectores basados solo en existencia aceptaban barcos con
`deleted_at`. Se corrigieron:

- `src/actions/users.php`: selectores y POST de alta/edición de usuarios.
- `src/actions/api_users.php`: validación de alta/edición API, incluido barco
  forzado para mecánicos creados por un Jefe.
- `src/actions/parts.php`: selectores y validación POST de destino.
- `src/parts_util.php`: disponibilidad del barco antes de crear, editar,
  modificar cantidad, gestionar fotografía o borrar inventario. Estos guards
  también protegen `/api/parts/push` y `/api/photos/{id}`.
- `src/actions/api_parts_push.php`: rechaza intentos de reasignar mediante
  `boat_id` en UPDATE, operación que el protocolo no soporta.

`/api/boats` y administración ya excluían tombstones y permanecen sin cambios.
`/api/sync` conserva tombstones para reconciliación. Lecturas históricas de
usuarios, auditoría y exportación siguen disponibles; no se reescriben usuarios
ya asignados. La semántica de barcos inactivos no eliminados se conserva: nuevos
selectores de repuestos requieren activos; los validadores/API que antes
permitían inactivos mantienen esa política.

## Validaciones ejecutadas

Desde `frontend/`:

- `yarn install --frozen-lockfile`: correcto; sin cambios de dependencias/lockfile.
- `yarn test`: 47 comprobaciones de las suites previas + 17 regresiones de sesión,
  todas aprobadas.
- `yarn typecheck`: 0 errores.
- `yarn lint`: 0 errores ni warnings.
- `npx expo-doctor`: 20/20.
- `yarn test:api`: 39 comprobaciones HTTP + 3 escenarios de migración, aprobados.
- Sintaxis PHP de todos los archivos bajo `src/`: correcta.

API: el script copia únicamente `src/`, `index.php` y router a un directorio
`tempfile.TemporaryDirectory`, genera su SQLite/usuarios/tokens/foto ficticios,
arranca `php -S 127.0.0.1:<puerto libre>` y prueba HTTP/API y formularios con
sesión cookie y CSRF. Al terminar, detiene el servidor y elimina ese directorio.
Nunca usa `data/` del repositorio ni datos/servidores de producción.

En Work se utilizó PHP 8.3.6 con PDO SQLite, mbstring, GD, XML/DOM e iconv.
No se han ejecutado pruebas manuales en dispositivos Safari/Android; las
regresiones de IndexedDB/Web Locks se ejecutan con fake-indexeddb y fixtures.

## Build y ZIP

Comando oficial, desde `frontend/`:

```sh
PWA_BASE_PATH=/pwa EXPO_PUBLIC_API_BASE_URL=https://mnores.atwebpages.com yarn build:pwa
```

Build ID: `e0f77b91d46e1e4c`. Bundle:
`entry-ee2668215cf1e2c9566367c46742e91a.js`. Se verificaron su hash MD5, enlace
HTML, presencia de Acerca de/MIT y las rutas de todos los recursos precacheados.

`shipinventory-pwa-production.zip`: 29 archivos directamente en raíz, incluyendo
`index.html`, `manifest.json`, `sw.js`, iconos, favicon, `.htaccess`, metadata,
`_expo/static/...` y assets/fuentes. 27 entradas precache. Sin carpeta
contenedora, node_modules, fuentes TS, .git, .env, PHP, datos, DB, backups ni
`deploy.php`. ZIP íntegro, 1.335.701 bytes.

SHA-256: `fc827306c91ea21b5b0fcf382f0d56ba6f66fc3e3f30738af8c22c5fe87beec7`.

El ZIP contiene **solo PWA**. Para aplicar las correcciones PHP en producción
hay que actualizar manualmente los seis archivos PHP modificados:
cuatro actions, `src/parts_util.php` y `src/db.php`. No se ha
desplegado nada ni tocado datos reales.

## Archivos fuente y pruebas modificados

- `frontend/app/part/[id].tsx`
- `frontend/src/database/store.types.ts`
- `frontend/src/hooks/usePartMutations.ts`
- `frontend/src/repositories/sessionRepository.ts`
- `frontend/src/repositories/sessionLifecycle.ts` (nuevo)
- `frontend/src/services/sync/syncEngine.ts`
- `frontend/src/state/SessionContext.tsx`
- `frontend/package.json` (solo scripts de pruebas)
- `frontend/tests/offline.cjs`, `query-offline.cjs`, `api.py`
- `frontend/tests/session.cjs`, `migration.php` (nuevos)
- `src/db.php`, `src/parts_util.php`
- `src/actions/users.php`, `api_users.php`, `parts.php`, `api_parts_push.php`
- Esta documentación y salida generada de `pwa/` (index, SW y bundle sustituido).
