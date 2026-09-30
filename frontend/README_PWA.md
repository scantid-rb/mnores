# PWA ShipInventory

Esta rama incorpora un cliente Expo/React web que consume la API PHP del
repositorio. El cliente guarda inventario, operaciones y fotos en IndexedDB;
el service worker conserva los recursos necesarios para abrirlo sin conexión.
La primera entrada, el inicio de sesión y la primera sincronización requieren
conexión. Los cambios offline se envían al recuperar la conexión.

## Comprobación y compilación

Desde `frontend/`, con Node.js 22 o superior y Yarn 1.22.22:

```bash
yarn install --frozen-lockfile
yarn typecheck
yarn lint
yarn test
yarn test:api
PWA_BASE_PATH=/pwa yarn build:pwa
```

`test:api` requiere Python 3 y PHP 8.3 en PATH, con PDO SQLite, GD y mbstring.
Crea una instalación temporal y comprueba la API real con usuarios de distintos
roles; no utiliza la base de datos del proyecto. `test` comprueba transacciones,
colas, reconciliación, fotos y el ciclo de actualización del service worker.
Los tests usan IndexedDB en memoria y dobles de la Cache API; no sustituyen
la comprobación de instalación en dispositivos reales.

La salida compilada está en `frontend/dist/`. `PWA_BASE_PATH` debe coincidir con
la ruta pública de despliegue; usar cadena vacía si la PWA ocupa la raíz de un
servidor estático. El servidor PHP de este repositorio ocupa la raíz, por lo que
la ruta recomendada es `/pwa/`. El directorio `public/` de Expo es una plantilla;
no basta con copiarlo al servidor: `build:pwa` añade todas las dependencias y un
identificador de caché calculado a partir del contenido exportado.

## Prueba local junto al servidor PHP

Desde la raíz del repositorio, después de compilar con `/pwa`:

```bash
mkdir -p pwa
cp -R frontend/dist/. pwa/
php -S 127.0.0.1:8080 router.php
```

Abrir `http://localhost:8080/pwa/` y configurar la URL del servidor como
`http://localhost:8080`. Para habilitar el service worker local hay que acceder
por localhost; una IP HTTP de la red no constituye un contexto seguro.

## Producción y actualizaciones

Servir tanto la PWA como su API mediante HTTPS. El PHP tradicional y Android
pueden funcionar por HTTP, pero los navegadores necesitan HTTPS (o localhost)
para habilitar el service worker. Publicar la PWA HTTPS con una API HTTP provoca
bloqueo por contenido mixto. El plan HTTP del hosting descrito en el README no
permite verificar la PWA offline en producción sin añadir HTTPS.

Copiar el contenido completo de `dist/` a `/pwa/` junto al backend. Mantener las
reglas `.htaccess` del repositorio: las rutas `/pwa/part/123` y `/pwa/inventory`
deben devolver `pwa/index.html`, mientras `/api/...` sigue entrando en PHP.
En Nginx añadir `location /pwa/ { try_files $uri $uri/ /pwa/index.html; }`.
Servir `sw.js` con revalidación (`Cache-Control: no-cache`) y los JS con hash con
caché larga. Subir primero los recursos y publicar `index.html` y `sw.js` al
final; conservar los recursos de la versión anterior durante la transición.

El SW activa una versión solo después de descargar el precache completo,
conserva una versión previa por ámbito, actualiza el HTML desde la red y excluye
la API. Si falla una actualización, continúa disponible la versión anterior.
Una nueva versión se registra y comprueba al abrir la aplicación.

## Recuperación de operaciones fallidas

La pantalla Sincronización muestra las operaciones y fotos fallidas por
separado de las pendientes. Reintentar vuelve a encolarlas. Descartar requiere
confirmación y fuerza una descarga completa para recuperar los valores del
servidor. Corregir una creación rechazada por datos inválidos o permisos
actualiza su solicitud pendiente; una creación de resultado incierto mantiene
su identidad y su contenido enviado, y encola las ediciones posteriores.
Las fotos se confirman por operación: una respuesta antigua no elimina una
foto elegida más recientemente.

Antes de desplegar, comprobar en Chrome y Safari/iPhone: iniciar sesión,
sincronizar, editar y fotografiar sin conexión, cerrar/reabrir, reconectar,
confirmar un borrado, reintentar un error y abrir una ruta interna. Probar una
actualización completa y otra interrumpida. No se ha automatizado aquí una
instalación real en iPhone.
