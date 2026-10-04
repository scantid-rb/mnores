# ShipInventory

ShipInventory es un proyecto de gestión de inventario de repuestos para una pequeña flota de buques. El repositorio contiene un backend web en PHP con base de datos SQLite, una API REST para clientes externos y una PWA instalable diseñada para seguir siendo útil cuando el dispositivo pierde temporalmente la conexión.

El proyecto nació para cubrir una necesidad práctica: mantener un inventario común de repuestos, cantidades, ubicaciones, fotografías y movimientos asociados a distintos barcos, con permisos diferenciados por usuario y con capacidad de trabajar desde zonas con conectividad irregular.

La rama `pwa` integra el backend tradicional y el cliente PWA dentro del mismo repositorio.

---

## Características principales

- Gestión de repuestos por barco.
- Categorías globales.
- Ubicación, referencia, cantidad, notas y fotografía por repuesto.
- Cantidad igual a cero permitida.
- Fotografías redimensionadas manteniendo proporción.
- Gestión de barcos y usuarios según permisos.
- Auditoría de cambios.
- Copias de seguridad de la base de datos y fotografías.
- API REST versionada.
- Cliente PWA instalable.
- Funcionamiento offline-first en la PWA.
- Cola local de operaciones pendientes.
- Sincronización automática o manual cuando vuelve la conexión.
- Persistencia local mediante IndexedDB.
- Service Worker para funcionamiento sin conexión.
- Compatibilidad con navegadores modernos de escritorio y móviles.
- Interfaz adaptada a los distintos roles de usuario.

---

## Arquitectura general

ShipInventory está dividido en tres capas principales:

```text
┌───────────────────────────────────────┐
│                PWA                    │
│  React / Expo Web / IndexedDB / SW    │
└───────────────────┬───────────────────┘
                    │ HTTPS
                    │ API REST
                    ▼
┌───────────────────────────────────────┐
│             Backend PHP               │
│ autenticación · permisos · sync · API │
└───────────────────┬───────────────────┘
                    │
                    ▼
┌───────────────────────────────────────┐
│                SQLite                 │
│ inventario · usuarios · auditoría     │
└───────────────────────────────────────┘
```

El backend es la fuente de verdad. La PWA mantiene una copia local del inventario y una cola de operaciones para poder seguir trabajando cuando no existe conexión con el servidor.

Cuando la conexión vuelve a estar disponible, el cliente reconcilia los cambios locales con el servidor mediante la API.

---

## Requisitos

### Servidor

- PHP 8.3 o superior.
- PDO.
- PDO SQLite.
- GD.
- mbstring.
- ZIP.
- XML.
- Servidor web compatible con PHP.
- Apache recomendado si se utiliza el archivo `.htaccess` incluido.
- Permisos de escritura sobre el directorio `data/`.

Composer se utiliza para las dependencias PHP incluidas en `vendor/`.

### PWA

Para desarrollar o reconstruir la PWA:

- Node.js 22 o superior.
- Yarn 1.22.x.
- Navegador moderno con soporte para:
  - Service Workers.
  - IndexedDB.
  - Fetch API.
  - Cache API.

---

# HTTPS es obligatorio en producción

> **La PWA debe desplegarse mediante HTTPS.**

Esto no es opcional para un despliegue normal en producción.

Los navegadores solo habilitan determinadas funciones necesarias para una PWA dentro de un **contexto seguro**. Entre ellas se encuentra el Service Worker utilizado por ShipInventory para almacenar la aplicación y permitir su apertura sin conexión.

`localhost` constituye una excepción durante el desarrollo y puede utilizar HTTP.

En producción debe utilizarse:

```text
https://servidor.example.com/
```

y no:

```text
http://servidor.example.com/
```

También debe evitarse una configuración donde la PWA se sirva mediante HTTPS pero la API utilice HTTP, ya que los navegadores modernos bloquearán esas solicitudes como contenido mixto.

Por tanto:

```text
PWA  -> HTTPS
API  -> HTTPS
```

Ambas deben encontrarse disponibles mediante una conexión segura.

---

# Estructura del proyecto

El proyecto utiliza actualmente una **estructura plana**.

No se presupone una carpeta `public/` como DocumentRoot. Los archivos públicos, el front controller PHP y los directorios internos conviven bajo la misma raíz del proyecto.

Las áreas que no deben quedar accesibles directamente desde Internet se protegen mediante reglas de Apache y archivos `.htaccess`.

Una estructura simplificada es:

```text
mnores/
│
├── index.php
├── router.php
├── .htaccess
│
├── assets/
│
├── src/
│   ├── .htaccess
│   ├── actions/
│   ├── views/
│   ├── auth.php
│   ├── api_auth.php
│   ├── audit.php
│   ├── backup.php
│   ├── bootstrap.php
│   ├── config.php
│   ├── csrf.php
│   ├── db.php
│   ├── helpers.php
│   ├── parts_util.php
│   ├── photos.php
│   └── settings.php
│
├── data/
│   ├── .htaccess
│   ├── app.sqlite
│   ├── photos/
│   └── backups/
│
├── vendor/
│
├── frontend/
│
├── pwa/
│   ├── index.html
│   ├── sw.js
│   └── assets/
│
├── composer.json
├── composer.lock
├── api_contract_android.md
├── CHANGELOG.md
├── LICENSE
└── README.md
```

## `index.php`

Es el front controller principal del backend.

Las solicitudes que no corresponden a un archivo o directorio físico son redirigidas hacia este archivo mediante `.htaccess`.

## `src/`

Contiene la mayor parte de la lógica PHP:

- autenticación;
- autorización;
- acceso a SQLite;
- auditoría;
- configuración;
- tratamiento de fotografías;
- backups;
- acciones web;
- endpoints de API;
- vistas HTML.

El acceso web directo a este directorio debe permanecer bloqueado.

## `data/`

Contiene datos persistentes de la instalación:

- base de datos SQLite;
- fotografías;
- backups;
- ficheros internos de estado.

**Este directorio no debe ser accesible directamente desde Internet.**

El archivo `.htaccess` incluido forma parte de la protección de estos datos.

## `vendor/`

Contiene dependencias PHP instaladas mediante Composer.

No debe utilizarse como directorio público.

## `frontend/`

Contiene el código fuente de la PWA.

Está basado en Expo/React para web y contiene la lógica de interfaz, persistencia offline y sincronización.

## `pwa/`

Contiene la build web lista para ser servida.

La instalación habitual mantiene el backend en la raíz y la aplicación PWA en:

```text
/pwa/
```

Por ejemplo:

```text
https://example.com/pwa/
```

mientras la API continúa disponible en:

```text
https://example.com/api/...
```

---

# Protección mediante .htaccess

La estructura plana implica que existen directorios internos físicamente situados bajo la raíz web.

Por este motivo las reglas `.htaccess` forman parte de la seguridad de la instalación y no deben eliminarse sin sustituirlas por reglas equivalentes en el servidor web.

El archivo `.htaccess` de la raíz realiza actualmente tres funciones importantes.

## 1. Conservación de la cabecera Authorization

Algunos entornos PHP ejecutados mediante CGI/FastCGI no entregan automáticamente la cabecera HTTP `Authorization` a PHP.

ShipInventory la necesita para la autenticación de la API.

La configuración conserva explícitamente esta cabecera para que los tokens enviados por los clientes lleguen correctamente al backend.

## 2. Enrutamiento de la PWA

Las rutas internas de la PWA deben poder resolverse aunque no correspondan a archivos físicos.

Por ejemplo:

```text
/pwa/inventory
/pwa/part/123
```

deben cargar la aplicación PWA y dejar que el router del cliente interprete la ruta.

Las reglas actuales redirigen estas rutas hacia:

```text
/pwa/index.html
```

si la build de la PWA está presente.

## 3. Front controller PHP

Las solicitudes que no corresponden a archivos o directorios reales terminan en:

```text
index.php
```

Esto permite mantener las rutas de la aplicación web y de la API sin necesidad de crear archivos PHP públicos para cada endpoint.

---

# PWA y funcionamiento offline

La PWA está diseñada para escenarios donde la conexión puede desaparecer durante periodos de tiempo.

El navegador mantiene localmente:

- inventario sincronizado;
- información necesaria para la sesión;
- cambios pendientes;
- fotografías pendientes;
- metadatos de sincronización.

Los datos se almacenan principalmente mediante IndexedDB.

El Service Worker almacena los recursos estáticos necesarios para volver a abrir la aplicación sin conexión.

## Flujo típico

```text
Usuario conectado
      │
      ▼
Sincronización inicial
      │
      ▼
Inventario almacenado localmente
      │
      ▼
Se pierde la conexión
      │
      ▼
Crear / editar / borrar repuestos
      │
      ▼
Operaciones guardadas en cola
      │
      ▼
Vuelve la conexión
      │
      ▼
Sincronización
      │
      ▼
Servidor actualizado
```

La primera autenticación y la primera descarga de datos requieren acceso al servidor.

Una vez inicializada correctamente, la aplicación puede continuar trabajando temporalmente con los datos almacenados en el dispositivo.

---

# Sincronización

La API utiliza sincronización incremental.

Las operaciones principales incluyen:

- descarga inicial del inventario;
- sincronización mediante cursor;
- creación offline;
- edición offline;
- borrado lógico;
- subida independiente de fotografías.

Los registros eliminados permanecen temporalmente como tombstones para permitir que otros clientes conozcan el borrado durante la sincronización.

Las creaciones offline utilizan identificadores locales para evitar duplicados si una petición se reintenta después de una conexión incierta.

La lógica de permisos siempre se aplica nuevamente en el servidor. Ocultar una acción en la interfaz no sustituye la autorización del backend.

---

# API

La API actual utiliza el contrato:

```text
API_VERSION = 1.4.5
```

El contrato completo se documenta en:

```text
api_contract_android.md
```

Entre los endpoints principales se encuentran:

| Método | Endpoint | Función |
|---|---|---|
| POST | `/api/login` | Autenticación |
| GET | `/api/me` | Identidad y sesión |
| GET | `/api/handshake` | Compatibilidad cliente/servidor |
| GET | `/api/sync` | Sincronización |
| POST | `/api/parts/push` | Envío de cambios locales |
| GET/POST | `/api/photos/{id}` | Fotografías |
| GET/POST | `/api/boats` | Barcos |
| GET/POST | `/api/categories` | Categorías |
| GET/POST | `/api/users` | Usuarios |
| GET | `/api/audit` | Auditoría |
| POST | `/api/account` | Gestión de la cuenta propia |

Antes de realizar cambios incompatibles en la API debe revisarse el contrato y decidir si es necesario incrementar `API_VERSION`.

---

# Roles y permisos

ShipInventory utiliza varios niveles de acceso.

## Administrador

Puede administrar globalmente:

- inventario;
- barcos;
- usuarios;
- categorías;
- auditoría;
- configuración;
- backups.

## Inspector

Tiene acceso global al inventario y a funciones de supervisión según los permisos definidos por el backend.

## Jefe de Máquinas

Opera sobre el barco que tiene asignado.

Puede gestionar el inventario de ese barco y administrar los usuarios mecánicos correspondientes a su propio barco.

No dispone de administración global de barcos.

## Mecánico

Opera sobre el inventario del barco al que pertenece dentro de los permisos concedidos por el backend.

No dispone de acceso a la administración global.

---

# Fotografías

Los repuestos pueden incluir una fotografía.

La aplicación procesa las imágenes antes de almacenarlas para evitar mantener fotografías innecesariamente grandes.

Las imágenes conservan su relación de aspecto y no deben recortarse automáticamente para ajustarse a un tamaño fijo.

En la PWA las fotografías pendientes también forman parte del proceso de sincronización offline.

---

# Base de datos

El backend utiliza SQLite.

Esta elección simplifica la instalación y el mantenimiento de una aplicación de tamaño pequeño o medio sin requerir un servidor de base de datos independiente.

La base de datos se encuentra dentro de:

```text
data/
```

y nunca debe exponerse como archivo descargable mediante HTTP.

El backend se encarga de crear y migrar el esquema necesario.

---

# Backups

ShipInventory dispone de mecanismos para generar copias de seguridad que pueden incluir:

- base de datos SQLite;
- fotografías;
- metadatos de la copia.

Las copias de seguridad deben tratarse como datos sensibles, ya que pueden contener una copia completa del inventario y de otros datos de la instalación.

El directorio de backups debe permanecer protegido frente al acceso HTTP directo.

---

# Desarrollo de la PWA

Desde:

```bash
cd frontend
```

instalar dependencias:

```bash
yarn install --frozen-lockfile
```

Las comprobaciones disponibles pueden incluir:

```bash
yarn typecheck
yarn lint
yarn test
yarn test:api
```

Para generar la PWA destinada a funcionar bajo `/pwa/`:

```bash
PWA_BASE_PATH=/pwa yarn build:pwa
```

La salida final se genera en:

```text
pwa/
```

El valor de `PWA_BASE_PATH` debe coincidir con la ruta pública real donde se sirva la aplicación.

---

# Pruebas recomendadas

Antes de considerar estable una versión se recomienda verificar como mínimo:

### Online

- inicio de sesión;
- cierre de sesión;
- handshake;
- descarga del inventario;
- creación de repuestos;
- edición;
- eliminación;
- fotografías;
- cambio de perfil;
- cambio de contraseña;
- permisos por rol.

### Offline

- abrir la aplicación ya inicializada sin conexión;
- consultar inventario;
- crear un repuesto;
- modificar un repuesto;
- borrar un repuesto;
- adjuntar una fotografía;
- cerrar y volver a abrir la PWA;
- comprobar que las operaciones siguen en cola;
- recuperar conexión;
- sincronizar;
- verificar el resultado en el servidor.

### Actualización de la PWA

También se recomienda probar:

- instalación limpia;
- actualización desde una build anterior;
- actualización interrumpida;
- navegación directa a una ruta interna;
- funcionamiento en Chrome/Android;
- funcionamiento en Safari/iPhone.

---

# Consideraciones de seguridad

ShipInventory contiene información operacional y por ello debe desplegarse aplicando medidas básicas de seguridad.

Como mínimo:

- utilizar HTTPS;
- mantener PHP actualizado;
- proteger `data/`, `src/` y cualquier otro directorio interno;
- no exponer SQLite;
- no exponer backups;
- conservar las reglas `.htaccess` o implementar equivalentes;
- utilizar contraseñas adecuadas;
- revisar permisos de archivos y directorios;
- mantener las dependencias actualizadas;
- limitar el acceso administrativo únicamente a quien lo necesite;
- realizar copias de seguridad periódicas.

Si el proyecto se despliega detrás de Nginx, Caddy u otro servidor distinto de Apache, las protecciones proporcionadas por `.htaccess` **no se aplicarán automáticamente** y deberán implementarse mediante reglas equivalentes del servidor utilizado.

---

# Disclaimer

ShipInventory es un proyecto de software proporcionado como herramienta de gestión de inventario.

El software se entrega **tal cual**, sin garantías de disponibilidad, integridad de datos, adecuación a un propósito concreto ni funcionamiento ininterrumpido.

El usuario o administrador que despliegue este proyecto es responsable de:

- verificar la configuración de seguridad del servidor;
- utilizar HTTPS;
- proteger la base de datos, fotografías y backups;
- configurar correctamente permisos y autenticación;
- validar el software antes de utilizarlo en un entorno real;
- realizar y comprobar copias de seguridad;
- evaluar cualquier requisito legal, contractual, de privacidad o de protección de datos aplicable a su instalación;
- comprobar que la información del inventario es correcta antes de utilizarla para tomar decisiones operativas.

El proyecto no debe considerarse un sistema de seguridad marítima, navegación, mantenimiento predictivo, clasificación, certificación o gestión de emergencias.

La información almacenada en ShipInventory no sustituye los procedimientos oficiales de mantenimiento, documentación técnica del fabricante, sistemas reglamentarios, inspecciones, certificados ni decisiones de personal cualificado.

Los autores y colaboradores no asumen responsabilidad por pérdida de datos, interrupciones de servicio, configuraciones inseguras, errores de inventario, fallos derivados de modificaciones de terceros ni daños directos o indirectos derivados del uso del software.

---

# Licencia

ShipInventory se distribuye bajo la **MIT License**.

Copyright (c) 2026 José Isidro González

La licencia permite, entre otras cosas:

- usar el software;
- copiarlo;
- modificarlo;
- fusionarlo;
- publicarlo;
- distribuirlo;
- sublicenciarlo;
- vender copias.

Siempre que se conserve el aviso de copyright y el texto de la licencia.

El software se proporciona **"AS IS"**, sin garantía de ningún tipo.

El texto legal completo se encuentra en:

```text
LICENSE
```

---

# Continuidad del proyecto

El repositorio pretende ser suficientemente comprensible para que otra persona pueda mantener o continuar el proyecto en el futuro.

Antes de realizar cambios importantes es recomendable revisar:

- `README.md`;
- `CHANGELOG.md`;
- `api_contract_android.md`;
- `frontend/README_PWA.md`;
- esquema y migraciones de SQLite;
- reglas `.htaccess`;
- comportamiento de sincronización offline.

Los cambios que afecten al contrato entre clientes y servidor deben mantenerse coordinados para evitar incompatibilidades entre versiones.
