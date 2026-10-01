# Build PWA con Acerca de

Fuente actualizada desde GitHub: `0a9530fb0b67e414e0c17f7b086657ac603ee8d4`,
exclusivamente `scantid-rb/mnores:pwa`.

## Cambios

- Se recompila el frontend actual con `PWA_BASE_PATH=/pwa` y
  `EXPO_PUBLIC_API_BASE_URL=https://devmn.atwebpages.com`.
- `public/sw.js` incluye `about` entre las rutas de navegación offline.
  `tests/sw.cjs` comprueba `/pwa/about` y la ruta equivalente en ámbito raíz.
- Se actualiza el build publicable en `pwa/`. No se cambia el backend, data,
  API, permisos, versiones ni la pantalla Acerca de del código recibido.
- Se excluye el bundle intermedio de Expo sin referencias en HTML/precache.

## Comprobaciones

| Validación | Resultado |
| --- | --- |
| `yarn install --frozen-lockfile --ignore-scripts` | No completa Fetching packages en 45 segundos; timeout. No se cambia yarn.lock |
| `yarn check --verify-tree` | Folder in sync: dependencias instaladas verificadas |
| `yarn typecheck` | Correcto |
| `yarn lint` | Correcto, sin advertencias |
| `yarn test` | 39 escenarios correctos: conectividad 4, IndexedDB/offline 23, SW 7, web 2, React Query/sync 3 |
| `PWA_BASE_PATH=/pwa yarn build:pwa` | Correcto, export de producción |
| HTTP local con router PHP del proyecto | 26 recursos del precache idénticos a los archivos; login/inventario/about/perfil/admin-users devuelven index.html |
| Bundle ejecutado en JSDOM con IndexedDB simulado | Login arranca desde /pwa/ y redirige a /pwa/login sin errores de render |
| Acerca de en el bundle ejecutado | Se muestra offline para Admin, Inspector, Jefe y Mecánico |
| Handshake en la pantalla compilada | Al reconectar muestra versión de servidor de una respuesta simulada y envía client_app_version=0.1.1/client_api_version=1.4.5 |
| Registro SW ejecutado | /pwa/sw.js y scope /pwa/ |

La prueba DOM usa el bundle real, JSDOM y fake-indexeddb con datos desechables;
no inicia sesión ni hace peticiones a AwardSpace. No sustituye la prueba en
Safari/Chrome reales de instalación, fotos y actualización del SW.

## Contenido y rutas

27 archivos, 3.955.403 bytes. HTML, manifest, SW, metadata, favicon/icon,
_expo y assets directamente en pwa/. ZIP sin carpeta envolvente, sin fuentes,
node_modules, backend, frontend, data, PHP, mapas, logs ni cachés de desarrollo.
HTML apunta a /pwa/_expo, /pwa/manifest.json, /pwa/icon.svg y /pwa/favicon.ico.
Manifest con inicio/ámbito relativos ./ y recursos del SW relativos a /pwa/.

El bundle contiene la ruta/pestaña Acerca de, información de ShipInventory,
cliente navegador/PWA instalada, App 0.1.1, API requerida 1.4.5, dirección del
servidor, estado online/offline, versiones obtenidas del handshake, MIT y
copyright © 2026 José Isidro González. Acerca de no restringe roles.

No hay configuración ni URLs de recursos a Codespaces, localhost u otros
servidores API. Expo Linking conserva un literal genérico 'localhost' en su
parser de rutas sin hostname; no es una URL de recurso o servidor configurado.
No se elimina código del framework. No hay API 1.4.4 en la lógica del bundle.

Advertencia no bloqueante de Expo: el proceso de export termina forzando su
salida tras escribir los archivos. El script termina con éxito y el resultado
se verifica por contenido, HTTP y ejecución del bundle.

No se despliega en AwardSpace. Subir el contenido del ZIP directamente dentro
del directorio público /pwa/, sin crear otra carpeta pwa en su interior.
Queda validar instalación/actualización y sincronización con el servidor real.
