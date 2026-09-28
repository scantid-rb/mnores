# Contrato API — Inventario de Repuestos

**API_VERSION:** 1.4.4  
**APP_VERSION del servidor:** 1.4.4  
**SCHEMA_VERSION:** 3  
**Estado:** contrato de referencia para el cliente Android offline-first  
**Última revisión:** 2026-09-28

Este documento define el contrato HTTP actualmente implementado por el servidor. La aplicación Android debe depender de este documento y no de detalles internos de PHP o SQLite.

> **Regla de compatibilidad:** un cambio incompatible en rutas, métodos, campos obligatorios, semántica de sincronización, permisos o estados de respuesta requiere una nueva versión de API. Los cambios compatibles pueden permanecer en la misma versión.

## 1. URL base y transporte

El dominio forma parte de la configuración del cliente y no del contrato. Ejemplo de desarrollo:

`https://devmn.atwebpages.com`

Las rutas son relativas a la URL base: `/api/...`.

Todas las respuestas son JSON con `Content-Type: application/json; charset=utf-8`, salvo `GET /api/photos/{id}`, que devuelve directamente un JPEG.

El entorno actual puede funcionar por HTTP. Si posteriormente se publica detrás de HTTPS, las rutas y formatos no cambian.

## 2. Autenticación

La API usa **Bearer tokens**, no cookies ni CSRF.

Cabecera:

`Authorization: Bearer <token>`

El token:
- se genera con 32 bytes aleatorios;
- se almacena en el servidor como SHA-256;
- puede identificarse mediante `device_label`;
- puede revocarse individualmente;
- deja de ser válido si el usuario está inactivo o el token está revocado.

El cliente debe guardar el token de forma segura.

Errores:
- `401`: token ausente, inválido, revocado o usuario inactivo.
- `429`: demasiados intentos fallidos de login.

## 3. Endpoints

### 3.0 GET /api/handshake

**Auth:** no. Este endpoint es público y se utiliza antes de guardar una nueva URL de servidor.

La respuesta identifica el servidor y permite al cliente comprobar que está inicializado y que utiliza la misma versión de API que el cliente.

Respuesta 200:
```json
{
  "ok": true,
  "app_name": "Inventario de Repuestos",
  "app_title": "Repuestos a bordo",
  "app_version": "1.4.4",
  "api_version": "1.4.4",
  "installed": true
}
```

El cliente debe rechazar el cambio de servidor si `installed` es `false` o si `api_version` no coincide con la versión de API que requiere la aplicación.

### 3.1 POST /api/login

Obtiene un token.

Request:
```json
{
  "username": "isidro",
  "password": "contraseña",
  "device_label": "Android - Isidro"
}
```

`device_label` es opcional.

Respuesta 200:
```json
{
  "ok": true,
  "token": "<token>",
  "user": {
    "id": 2,
    "username": "Isidro",
    "role": "chief_engineer",
    "boat_id": 1
  }
}
```

Errores: `400` datos requeridos, `401` credenciales incorrectas, `429` bloqueo por fuerza bruta.

### 3.2 GET /api/me

**Auth:** sí.

Respuesta 200:
```json
{
  "ok": true,
  "user": {
    "id": 2,
    "username": "Isidro",
    "role": "chief_engineer",
    "boat_id": 1
  }
}
```

### 3.3 GET /api/sync

**Auth:** sí.

Sin `since`: sincronización completa.

Con `since`:
`GET /api/sync?since=2026-09-25T00:15:55.392Z`

El servidor captura `server_time` **antes de leer los datos**. El cliente debe guardar exactamente ese valor y usarlo como `since` en la siguiente sincronización.

Respuesta:
```json
{
  "ok": true,
  "server_time": "2026-09-28T00:57:23.975Z",
  "boats": [],
  "categories": [],
  "parts": []
}
```

#### Boats

Los barcos se entregan como **snapshot completo en cada llamada**, incluso con `since`. Esto es intencionado porque su borrado es físico y no existe un tombstone persistente.

Campos:
```json
{
  "id": 1,
  "name": "Ivan Nores",
  "registration": "EBBR",
  "is_active": 1,
  "updated_at": "2026-09-28T00:00:00.000Z",
  "deleted_at": null
}
```

#### Categories

En sincronización completa se entrega el catálogo completo.

En incremental se entregan categorías con:
`since < updated_at <= server_time`

Campos:
```json
{
  "id": 26,
  "name": "Electricidad",
  "is_system": 0,
  "updated_at": "2026-09-28T00:00:00.000Z",
  "deleted_at": null
}
```

**Particularidad actual:** el borrado administrativo de una categoría es físico, después de mover sus piezas a “Sin categoría”. Por ello la desaparición de una categoría no se representa como tombstone en un delta. Para reconciliar el catálogo tras un borrado se puede usar `GET /api/categories` o una sincronización completa.

#### Parts

En completa se entregan todas las piezas visibles.

En incremental:
`since < updated_at <= server_time`

Las piezas eliminadas mediante `/api/parts/push` permanecen como **tombstones**, con `deleted_at != null`. El cliente debe eliminar su copia local.

Campos:
```json
{
  "id": 58,
  "boat_id": 1,
  "name": "Filtro de aceite",
  "reference": "FA-220",
  "category_id": 32,
  "location": "Sala de máquinas",
  "quantity": 2,
  "notes": "",
  "photo_path": "/ruta/interna/58.jpg",
  "updated_at": "2026-09-28T00:00:00.000Z",
  "deleted_at": null,
  "client_local_id": "uuid-del-movil"
}
```

`photo_path` es una ruta interna y **no debe utilizarse como URL**. La foto se obtiene con `GET /api/photos/{id}`.

### 3.4 POST /api/parts/push

**Auth:** sí. **Content-Type:** `application/json`.

`changes` es un array y cada cambio se procesa independientemente.

#### update completo

Campos:
- `action: "update"`
- `id`
- `base_updated_at`
- `name`
- `reference`
- `category_id`
- `location`
- `quantity`
- `notes`

#### update parcial de quantity

```json
{
  "action": "update",
  "id": 9,
  "base_updated_at": "2026-09-24T19:54:33.136Z",
  "quantity": 4
}
```

Cuando solo se proporciona `quantity`, se modifica únicamente esa columna y `updated_at`. Es la modalidad destinada especialmente a `mechanic` y a los botones +/−.

#### Conflictos

`base_updated_at` representa la versión que tenía el cliente antes de editar.

Si no coincide con el `updated_at` actual, el servidor aplica el cambio igualmente y devuelve `conflict_overwritten`. La política actual prioriza el último cambio recibido por simplicidad operativa.

#### create

```json
{
  "action": "create",
  "local_id": "uuid-generado-en-el-movil",
  "boat_id": 1,
  "name": "Filtro de aceite",
  "reference": "FA-220",
  "category_id": 32,
  "location": "Sala de máquinas",
  "quantity": 2,
  "notes": ""
}
```

`local_id` es obligatorio para altas offline. La combinación `boat_id + local_id` es única cuando `local_id` no es nulo.

Repetir un alta después de perder la respuesta devuelve la pieza existente en lugar de crear un duplicado.

#### delete

```json
{
  "action": "delete",
  "id": 12,
  "base_updated_at": "2026-09-24T19:54:33.136Z"
}
```

El borrado de piezas es lógico: se establece `deleted_at`, se actualiza `updated_at`, se elimina la foto y la fila permanece como tombstone.

Un DELETE repetido sobre una pieza ya eliminada devuelve `ok`.

Respuesta 200:
```json
{
  "ok": true,
  "server_time": "2026-09-28T01:00:00.123Z",
  "results": [
    {
      "action": "update",
      "id": 7,
      "status": "ok",
      "updated_at": "2026-09-28T01:00:00.123Z"
    }
  ]
}
```

Estados:
| Estado | Significado | Acción del cliente |
|---|---|---|
| `ok` | Aplicado | Retirar de la cola |
| `conflict_overwritten` | Aplicado pese a conflicto | Retirar de la cola; informar opcionalmente |
| `forbidden` | Sin permiso | No reintentar |
| `not_found` | Pieza inexistente | Retirar de la cola |
| `invalid` | Datos inválidos | Retirar de la cola y mostrar error |
| `unknown_action` | Acción no soportada | Tratar como error de protocolo |

### 3.5 GET /api/photos/{id}

**Auth:** sí.

Devuelve JPEG directamente con HTTP 200.

Errores:
- `403`: sin permiso para ver la pieza.
- `404`: pieza inexistente o sin foto.

### 3.6 POST /api/photos/{id}

**Auth:** sí. **Content-Type:** `multipart/form-data`.

Campo: `photo`.

No usa JSON ni base64.

Límites de entrada: máximo 8 MB; JPEG/PNG/GIF/WEBP. El servidor convierte a JPEG y redimensiona como la interfaz web.

Respuesta 200:
```json
{
  "ok": true,
  "updated_at": "2026-09-28T01:10:00.000Z"
}
```

Errores: `403`, `404`, `422`, `405`.

### 3.7 GET /api/boats

Devuelve el catálogo de barcos.

Respuesta 200:
```json
{
  "ok": true,
  "boats": [
    {
      "id": 1,
      "name": "Ivan Nores",
      "registration": "EBBR",
      "is_active": 1,
      "updated_at": "...",
      "deleted_at": null
    }
  ]
}
```

### 3.8 POST /api/boats

Solo `admin` e `inspector`.

Acciones:
- `create`: `name`, `registration`, `is_active`.
- `update`: `id`, `name`, `registration`, `is_active`.
- `toggle`: `id`.
- `delete`: solo `admin`; se rechaza si existen usuarios o piezas activas asociadas.

Errores principales: `403`, `404`, `409`, `422`, `405`.

### 3.9 GET /api/categories

Devuelve categorías no eliminadas.

### 3.10 POST /api/categories

Solo `admin` e `inspector`.

Acciones:
- `create`
- `rename`
- `delete`

“Sin categoría” es una categoría de sistema y no puede renombrarse ni eliminarse.

Al eliminar una categoría normal, sus piezas se trasladan a “Sin categoría” y la categoría se elimina físicamente.

Errores principales: `403`, `404`, `409`, `422`, `500`, `405`.

### 3.11 GET /api/users

Devuelve los usuarios visibles para el actor:
- admin/inspector: según las reglas generales de gestión;
- chief_engineer: mechanics de su barco;
- mechanic: él mismo.

### 3.12 POST /api/users

Gestiona usuarios según permisos.

Roles: `admin`, `inspector`, `chief_engineer`, `mechanic`.

Contraseñas: mínimo 8 caracteres.

El administrador principal no puede eliminarse ni desactivarse. El jefe de máquinas queda limitado a mechanics de su propio barco; el servidor fuerza ese rol y barco.

### 3.13 GET /api/settings

**Auth:** sí. **Roles:** `admin`, `inspector`.

Devuelve la configuración dinámica del servidor que también se puede modificar desde la web.

Respuesta 200:
```json
{
  "ok": true,
  "settings": {
    "app_name": "Inventario de Repuestos",
    "app_title": "Repuestos a bordo",
    "company_name": "",
    "backup_interval_days": "7",
    "backup_retention_days": "7",
    "audit_retention_days": "90",
    "disk_warning_percent": "20"
  }
}
```

Los valores se devuelven como cadenas porque la tabla de configuración del servidor utiliza pares clave/valor. El cliente debe convertir a número los cuatro parámetros numéricos.

### 3.14 POST /api/settings

**Auth:** sí. **Roles:** `admin`, `inspector`.

**Content-Type:** `application/json`.

El cliente debe enviar los 7 parámetros. No se admiten actualizaciones parciales.

Validaciones:
- `app_name`: obligatorio, máximo 80 caracteres.
- `app_title`: obligatorio, máximo 80 caracteres.
- `company_name`: máximo 120 caracteres.
- `backup_interval_days`: 1–365.
- `backup_retention_days`: 1–365.
- `audit_retention_days`: 1–3650.
- `disk_warning_percent`: 0–90.

Respuesta 200: `{ "ok": true, "settings": { ... } }`.

Errores: `400` JSON/parámetros inválidos, `401` no autenticado, `403` rol no autorizado, `422` valores inválidos.

Cada modificación genera una entrada de auditoría `settings.update`.

### 3.15 GET /api/status

**Auth:** sí. **Roles:** `admin`, `inspector`.

Devuelve el estado técnico del servidor para la pantalla de diagnóstico de Android.

Respuesta 200:
```json
{
  "ok": true,
  "app_version": "1.4.4",
  "api_version": "1.4.4",
  "schema_version": 3,
  "sqlite_integrity": "ok",
  "database_size_bytes": 123456,
  "disk": {
    "free_bytes": 123456789,
    "total_bytes": 5000000000,
    "free_percent": 2.47,
    "warning_percent": 20,
    "warning": true
  },
  "backup": {
    "last_auto": { "name": "backup_auto_20260928.zip", "mtime": 1780000000 },
    "last_run": 1780000000,
    "last_failure": null
  },
  "directories": {
    "data": true,
    "data/photos": true,
    "data/backups": true,
    "index.php": true,
    "assets": true,
    "vendor": true
  }
}
```

Los tamaños están expresados en bytes. `free_percent` es un porcentaje numérico y `warning` usa el umbral configurado en el servidor.

Errores: `401` no autenticado, `403` rol no autorizado.

### 3.13 GET /api/audit

Solo `admin` e `inspector`.

Parámetros:
- `page`: mínimo 1.
- `per_page`: 10–100, por defecto 50.
- `operation`: filtro exacto.
- `object_type`: filtro exacto.
- `actor_username`: filtro exacto.

Respuesta:
```json
{
  "ok": true,
  "rows": [],
  "page": 1,
  "per_page": 50,
  "total": 0,
  "pages": 1,
  "operations": [],
  "object_types": [],
  "actors": []
}
```

## 4. Roles y permisos

| Operación | admin | inspector | chief_engineer | mechanic |
|---|---:|---:|---:|---:|
| Login / me / sync | ✓ | ✓ | ✓ | ✓ |
| Ver piezas | Todos | Todos | Su barco | Su barco |
| Crear pieza | ✓ | ✓ | Su barco | — |
| Editar campos | ✓ | ✓ | Su barco | — |
| Cambiar quantity | ✓ | ✓ | Su barco | Su barco |
| Eliminar pieza | ✓ | ✓ | Su barco | — |
| Ver/subir foto | ✓ | ✓ | Su barco | Su barco |
| Gestionar barcos | ✓ | ✓ | — | — |
| Eliminar barco | ✓ | — | — | — |
| Gestionar categorías | ✓ | ✓ | — | — |
| Gestionar usuarios | Según reglas | Según reglas | Mechanics de su barco | — |
| Consultar auditoría API | ✓ | ✓ | — | — |

El servidor es la autoridad final de permisos.

## 5. Modelo de sincronización

1. `POST /api/login`
2. Guardar token.
3. `GET /api/sync` sin `since`.
4. Guardar datos y `server_time`.
5. Trabajar offline sobre la base local.
6. Encolar altas, cambios y borrados.
7. Al recuperar conexión:
   1. `POST /api/parts/push`;
   2. procesar cada resultado;
   3. subir fotografías pendientes;
   4. `GET /api/sync?since=<último server_time>`;
   5. aplicar cambios entrantes;
   6. guardar el nuevo `server_time`.

Nunca avanzar el cursor antes de haber procesado correctamente la respuesta.

Nunca generar el `id` global de una pieza en el cliente: solo `local_id`.

## 6. Timestamps

Formato:
`YYYY-MM-DDTHH:mm:ss.SSSZ`

Todos son UTC. `base_updated_at` debe conservar exactamente el valor recibido.

## 7. Errores generales

Forma habitual:
```json
{
  "ok": false,
  "error": "Descripción"
}
```

El cliente debe basar la lógica en el código HTTP y en la estructura de respuesta, no en textos concretos de error.

## 8. Evolución y compatibilidad

No se deben cambiar sin aumentar `API_VERSION`:
- rutas;
- métodos;
- campos obligatorios;
- semántica de `since`/ `server_time`;
- semántica de `local_id`;
- estados de `/api/parts/push`;
- reglas de conflicto;
- estructura básica de respuestas.

Se pueden añadir campos opcionales a respuestas si los clientes antiguos pueden ignorarlos.

La versión Android (`APP_VERSION`) es independiente de `API_VERSION`.

## 9. Resumen de rutas

| Método | Ruta | Auth | Función |
|---|---|---|---|
| POST | `/api/login` | No | Token |
| GET | `/api/me` | Sí | Identidad |
| GET | `/api/sync` | Sí | Sincronización |
| POST | `/api/parts/push` | Sí | Cambios offline |
| GET | `/api/photos/{id}` | Sí | Descargar foto |
| POST | `/api/photos/{id}` | Sí | Subir/reemplazar foto |
| GET | `/api/boats` | Sí | Catálogo barcos |
| POST | `/api/boats` | Sí | Gestionar barcos |
| GET | `/api/categories` | Sí | Catálogo categorías |
| POST | `/api/categories` | Sí | Gestionar categorías |
| GET | `/api/users` | Sí | Consultar usuarios |
| POST | `/api/users` | Sí | Gestionar usuarios |
| GET | `/api/audit` | Sí | Auditoría |
| GET | `/api/settings` | Sí | Configuración del servidor (admin/inspector) |
| POST | `/api/settings` | Sí | Modificar configuración del servidor (admin/inspector) |
| GET | `/api/status` | Sí | Estado técnico del servidor (admin/inspector) |

**Fin del contrato API 1.4.4.**
