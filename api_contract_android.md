# API de sincronización — Inventario de repuestos
### Contrato técnico para la app Android (offline-first)

Este documento describe la API REST que ya existe, en funcionamiento y probada,
sobre el backend PHP actual. Sirve como especificación exacta para construir
la app Android (con Emergent) que consuma estos endpoints.

**URL base (entorno de desarrollo):** `http://devmn.atwebpages.com`
*(en producción cambiará al dominio real; ningún endpoint cambia de forma,
solo el dominio)*

**Formato:** todos los endpoints devuelven JSON (`Content-Type: application/json`),
salvo la descarga de fotos, que devuelve la imagen directamente
(`Content-Type: image/jpeg`).

**Nota sobre HTTPS:** el entorno actual funciona por HTTP (AwardSpace no
ofrece SSL en el plan gratuito). Es una decisión temporal y no afecta al
diseño de la API ni de la app: si en el futuro se añade HTTPS (p. ej. vía
Cloudflare), la app no necesita ningún cambio, solo la URL base.

---

## 1. Autenticación

La API usa un **token de acceso** (carnet), no cookies ni sesiones.
La app debe:
1. Pedir usuario/contraseña **una sola vez** (pantalla de login).
2. Guardar el token recibido de forma segura (EncryptedSharedPreferences o Keystore).
3. Enviarlo en **todas** las demás peticiones dentro de la cabecera:
   ```
   Authorization: Bearer <token>
   ```
4. Si una petición devuelve `401`, el token ya no es válido → volver a la
   pantalla de login.

### POST /api/login
Inicia sesión y obtiene el token.

**Request body (JSON):**
```json
{
  "username": "isidro",
  "password": "contraseña-del-usuario",
  "device_label": "Pixel 7 - Isidro"
}
```
`device_label` es opcional pero recomendable (identifica qué móvil generó el token, útil para revocar accesos concretos en el futuro).

**Response 200 (éxito):**
```json
{
  "ok": true,
  "token": "22e0bf09490e90844e32b7be7700ae1b758ed40e46ca7eeb552e79f4a5a952b2",
  "user": {
    "id": 2,
    "username": "Isidro",
    "role": "chief_engineer",
    "boat_id": 1
  }
}
```

**Response 401 (credenciales incorrectas):**
```json
{ "ok": false, "error": "Usuario o contraseña incorrectos" }
```

**Response 429 (demasiados intentos fallidos):**
```json
{ "ok": false, "error": "Demasiados intentos, inténtalo más tarde" }
```

### GET /api/me
Comprueba si el token sigue siendo válido y quién es el usuario.
Útil al abrir la app, para saber si hay que pedir login de nuevo.

**Headers:** `Authorization: Bearer <token>`

**Response 200:**
```json
{ "ok": true, "user": { "id": 2, "username": "Isidro", "role": "chief_engineer", "boat_id": 1 } }
```

**Response 401:** `{ "ok": false, "error": "No autorizado" }` → token inválido/caducado.

---

## 2. Roles y visibilidad (importante para la app)

| Rol | Ve piezas de |
|---|---|
| `admin`, `inspector` | Los 6 barcos |
| `chief_engineer`, `mechanic` | Solo su propio `boat_id` |

El servidor **ya aplica este filtro automáticamente** en `/api/sync` y
**ya rechaza** (con `status: "forbidden"`) intentos de crear/editar piezas
fuera del barco del usuario. La app no necesita reimplementar esta lógica,
pero sí debería ocultar en su interfaz la opción de elegir barco cuando el
rol no sea `admin`/`inspector` (para no confundir al usuario con acciones
que el servidor luego rechazará).

---

## 3. Descargar datos: GET /api/sync

Dos usos:

**a) Primera sincronización (sin conexión previa):** llamar sin parámetros.
```
GET /api/sync
```
Devuelve **todo** lo visible para ese usuario: barcos, categorías y piezas.

**b) Sincronizaciones siguientes:** llamar con la fecha de la última sync
(la app debe guardar el `server_time` que recibió la última vez y
mandarlo aquí):
```
GET /api/sync?since=2026-09-25T00:15:55.392Z
```
Devuelve solo lo que cambió desde esa fecha — incluidas las filas
borradas (`deleted_at` no nulo), para que la app las borre también de su
copia local.

**Headers:** `Authorization: Bearer <token>`

**Response 200:**
```json
{
  "ok": true,
  "server_time": "2026-09-25T00:57:23.975Z",
  "boats": [
    { "id": 1, "name": "Ivan Nores", "registration": "EBBR", "is_active": 1, "updated_at": "...", "deleted_at": null }
  ],
  "categories": [
    { "id": 26, "name": "Electricidad", "is_system": 0, "updated_at": "...", "deleted_at": null }
  ],
  "parts": [
    {
      "id": 1, "boat_id": 1, "name": "Disyuntor", "reference": "Gv2p14",
      "category_id": 26, "location": "Taquilla 1", "quantity": 4,
      "notes": "Disyuntor de 6 amperios",
      "photo_path": "/srv/disk18/.../1.jpg",
      "updated_at": "2026-09-24T19:54:33.136Z"
    }
  ]
}
```

**Importante sobre `photo_path`:** ese valor es una ruta interna del
servidor, **no** una URL descargable. Para la foto, la app debe construir
la URL ella misma así:
```
GET {URL_BASE}/api/photos/{id}
```
usando el `id` de la pieza (no el `photo_path`). Si el campo `photo_path`
es `null`, la pieza no tiene foto.

**Guardar tras cada sync:** la app debe guardar el `server_time` recibido,
para usarlo como `since` en la siguiente llamada.

---

## 4. Subir cambios: POST /api/parts/push

Para piezas **creadas o editadas offline**. Se puede mandar un lote con
varios cambios de golpe al recuperar conexión.

**Headers:** `Authorization: Bearer <token>`, `Content-Type: application/json`

**Request body:**
```json
{
  "changes": [
    {
      "action": "update",
      "id": 7,
      "base_updated_at": "2026-09-24T19:54:33.136Z",
      "name": "Disyuntor 6A",
      "reference": "Gv2p14",
      "category_id": 26,
      "location": "Taquilla 1",
      "quantity": 3,
      "notes": "Quedan pocos"
    },
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
  ]
}
```

- `action: "update"` → requiere `id` (de la pieza) y `base_updated_at`
  (el `updated_at` que la app tenía guardado de esa pieza **antes** de
  editarla offline; sirve para detectar si alguien más la cambió mientras
  tanto).
- `action: "create"` → requiere `local_id` (un identificador que la app
  se inventa, tipo UUID, para poder emparejar la respuesta) y `boat_id`.
  **No** manda fotos aquí (ver sección 5).
  `local_id` es idempotente dentro de cada `boat_id`: si el servidor ya
  recibió ese mismo alta pero la respuesta se perdió, un reintento devuelve
  el mismo `id` real con `status: "ok"` y no crea un duplicado.

**Response 200:**
```json
{
  "ok": true,
  "server_time": "...",
  "results": [
    { "action": "update", "id": 7, "status": "ok", "updated_at": "..." },
    { "action": "create", "local_id": "uuid-generado-en-el-movil", "id": 58, "status": "ok", "updated_at": "..." }
  ]
}
```

**Valores posibles de `status` por cada cambio:**
| status | Significado | Qué debe hacer la app |
|---|---|---|
| `ok` | Se aplicó correctamente | Marcar como sincronizado; si era `create`, sustituir el `local_id` por el `id` real |
| `conflict_overwritten` | Alguien más había cambiado la pieza mientras estabas offline, pero tu cambio se aplicó igualmente (equipo pequeño, se prioriza simplicidad) | Opcional: avisar al usuario, no bloqueante |
| `forbidden` | El usuario no tiene permiso sobre esa pieza/barco | Mostrar error, no reintentar |
| `not_found` | La pieza (`id`) ya no existe | Descartar el cambio local |
| `invalid` | Faltan datos obligatorios (nombre o barco) | Mostrar error de validación al usuario |

---

## 5. Fotos: GET/POST /api/photos/{id}

`{id}` es el `id` de la pieza (no un id de foto separado — cada pieza
tiene como mucho una foto).

### Descargar
```
GET /api/photos/7
Authorization: Bearer <token>
```
Devuelve la imagen JPEG directamente. `404` si la pieza no tiene foto.

### Subir / reemplazar
```
POST /api/photos/7
Authorization: Bearer <token>
Content-Type: multipart/form-data
```
Campo del formulario: **`photo`** (el archivo de imagen). Igual que un
formulario HTML normal — no se manda en JSON ni en base64.

Límites: máx. 8 MB, formatos admitidos JPEG/PNG/GIF/WEBP (el servidor la
convierte y comprime a JPEG automáticamente).

**Response 200:**
```json
{ "ok": true, "updated_at": "2026-09-25T01:10:00.000Z" }
```

**Response 403:** sin permiso sobre esa pieza (rol/barco).
**Response 422:** imagen inválida o demasiado grande, con `"error"` describiendo el motivo.

**Comportamiento offline decidido:** cuando el usuario haga/cambie una
foto sin conexión, la app debe guardarla localmente y subirla
**automáticamente** en cuanto detecte conexión (sin pedir confirmación al
usuario).

---

## 6. Resumen de errores comunes

| HTTP | Significado |
|---|---|
| 401 | Token ausente, inválido o caducado → pedir login de nuevo |
| 403 | Token válido, pero sin permiso para esa acción/barco |
| 404 | Recurso no encontrado (pieza, foto) |
| 422 | Datos inválidos en la petición |
| 429 | Demasiados intentos de login fallidos |

---

## 7. Flujo recomendado para la app

1. Login → guardar token.
2. `GET /api/sync` (sin `since`) → guardar todo localmente (SQLite/Room) + guardar `server_time`.
3. Uso normal offline: leer/editar/crear desde la base local; cada cambio se encola.
4. Al detectar conexión:
   - `POST /api/parts/push` con la cola de cambios de texto pendientes.
   - Para cada pieza con foto pendiente de subir → `POST /api/photos/{id}`.
   - `GET /api/sync?since=<último server_time>` → aplicar cambios entrantes, guardar el nuevo `server_time`.
