# Changelog

Todos los cambios relevantes del servidor se documentan aquí. Esta versión describe el estado acumulado de la rama **1.4.3**.

## [1.4.3] — 2026-09-28

### Versionado
- Se fija `APP_VERSION` del servidor en **1.4.3**.
- Se incorpora `API_VERSION` explícito con valor **1.4.3**.
- Se mantiene `SCHEMA_VERSION = 3`.
- Se documenta la relación entre versión de aplicación, API y esquema.

### API Android offline-first
- Se consolida la API REST para el cliente Android offline-first.
- Autenticación mediante Bearer token independiente de las sesiones web.
- Tokens almacenados mediante hash SHA-256 y con posibilidad de revocación individual.
- Reutilización de la autenticación, roles y permisos existentes.
- Endpoint de identidad `GET /api/me`.
- Sincronización completa e incremental mediante `GET /api/sync`.
- Cursor de sincronización capturado antes de leer los datos.
- Sincronización de barcos, categorías y piezas.
- Tombstones para piezas eliminadas.
- Idempotencia de altas offline mediante `parts.client_local_id` e índice único parcial `(boat_id, client_local_id)`.
- Reintentos de altas offline no generan duplicados.
- `POST /api/parts/push` admite creación, actualización y eliminación.
- Se admite actualización parcial de `quantity`.
- Detección de conflictos mediante `base_updated_at`, con política actual de aplicar el cambio y devolver `conflict_overwritten`.
- Soporte de fotografías mediante `GET/POST /api/photos/{id}`.
- Endpoints API para barcos, categorías, usuarios y auditoría.

### Base de datos y sincronización
- `boats` y `categories` disponen de `updated_at` y `deleted_at` para soporte de sincronización.
- Se normalizan timestamps antiguos al formato ISO UTC con milisegundos.
- Se añaden índices necesarios para consultas incrementales.
- Se mantienen migraciones automáticas para instalaciones existentes.
- Las piezas conservan `updated_at` para control de concurrencia.
- Los borrados de piezas se conservan como tombstones.

### Seguridad y permisos
- La API aplica los mismos permisos de inventario que la aplicación web.
- Chief Engineer queda restringido a su barco.
- Mechanic queda restringido a su barco y a las operaciones permitidas.
- La API de auditoría queda restringida a administrador e inspector.
- La protección contra fuerza bruta del login web se reutiliza en el login API.
- Se mantiene la protección del administrador principal.

### Auditoría
- Las operaciones realizadas mediante API quedan registradas en `audit_log`.
- Se mantiene la corrección de la comprobación de rol en el endpoint de auditoría.

### Documentación
- Se crea este changelog consolidado de la rama 1.4.3.
- Se actualiza `api_contract_android.md` como contrato técnico de referencia de API 1.4.3.
- Se actualiza `README.md` para reflejar las rutas API reales, el versionado y el modelo de sincronización.

### Observaciones conocidas
- Los barcos se sincronizan como snapshot completo porque su borrado es físico.
- Las categorías eliminadas administrativamente también se eliminan físicamente; por ello una sincronización incremental no representa su desaparición mediante tombstone. El contrato documenta esta particularidad y recomienda reconciliar el catálogo mediante `GET /api/categories` o sincronización completa.
- El intervalo del auto-backup se configura mediante `backup_interval_days`; el valor inicial/documentado actualmente es 7 días. No se modifica en esta revisión.

## [1.4.0] y anteriores

Los cambios históricos anteriores a esta rama permanecen en la historia de Git y no se reconstruyen aquí a partir de suposiciones.
