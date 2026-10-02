# PWA de producción: permisos por barco

Fuente: `9a20be13081776f71578470cff9bfea93a9f822c`, último commit de
`scantid-rb/mnores:pwa` al actualizar. Incluye `8d17edf7` y `9a20be13`.
Destino de la build: `https://mnores.atwebpages.com/pwa/`.

## Auditoría y correcciones acotadas

- Inventario mantiene selector para Admin/Inspector. Jefe/Mecánico no lo tienen.
  Creación del Jefe fija user.boat_id; Mecánico no crea, tampoco por ruta directa.
- useParts ahora impone el barco de sesión aunque otro consumidor pase un filtro
  distinto. Un usuario de barco sin asignación recibe inventario vacío.
- usePart filtra el detalle por el mismo permiso; así la ruta directa de otro
  barco no expone datos ni controles de cantidad/foto. Las claves de React Query
  incluyen identidad, rol y ámbito para no reutilizar datos de otro permiso.
- Las mutaciones locales validan creación/edición/borrado por rol y barco antes
  de escribir IndexedDB. Mecánico solo modifica cantidad en edición; el detalle
  permite cantidad/foto propias como autoriza el contrato existente del servidor.
- part-edit espera la lectura local y redirige si el repuesto no existe o está
  fuera del ámbito. No permite guardar un formulario antes de validar ese dato.
- El .htaccess raíz recibido desde GitHub bloquea manifest.json por nombre.
  frontend/public/.htaccess añade una excepción solo en la PWA y fallback SPA.
  Se exporta a pwa/.htaccess. El build excluye esta configuración del precache
  porque los dotfiles no se sirven por HTTP. No se cambia configuración raíz.
- La limpieza de create forbidden de 9a20be13 queda intacta. Pruebas reales de
  store.web + syncEngine confirman eliminación del repuesto, cola dependiente,
  cola de fotos y blobs. Los errores invalid/HTTP 500 y forbidden de update
  conservan el trabajo; un aborto de IndexedDB revierte toda la limpieza.

Backend sigue siendo autoridad final. Se revisaron sus permisos sin editarlos.
No hay cambios propios en PHP, API, data, base de datos ni otras ramas.
Perfil, account, updateIdentity, administración, categorías, sincronización,
service worker y wrappers Safari se conservan salvo lo descrito arriba.

## Resultados

| Validación | Resultado |
| --- | --- |
| Yarn install congelado con mirror offline externo | Correcto; yarn.lock sin cambios |
| yarn typecheck | Correcto |
| yarn lint | Correcto, sin avisos |
| yarn test | 47 escenarios correctos: conectividad 4, IndexedDB 26, SW 7, web 2, Query/sync/permisos 8 |
| npx --no-install expo-doctor | 20/20 correctos |
| PWA_BASE_PATH=/pwa EXPO_PUBLIC_API_BASE_URL=https://mnores.atwebpages.com yarn build:pwa | Correcto |
| Bundle ejecutado en JSDOM/fake-indexeddb | Login, About y perfil para cuatro roles; Usuarios Admin/Inspector/Jefe; inventario por barco; Jefe crea sin selector; detalle ajeno sin controles; Mecánico sin crear y con cantidad/foto propias |
| Bundle About online con API simulada | Handshake envía client_app_version=0.1.1 y client_api_version=1.4.5; muestra versión recibida |
| Export auxiliar con mapa de fuentes | Wrappers KeyboardProviderCompat.web.tsx y KeyboardAwareScrollViewCompat.web.tsx, sin módulos react-native-keyboard-controller; incluye permisos y limpieza forbidden |
| HTTP local con router existente | 27 recursos y 7 rutas SPA correctos |
| Apache 2.4 local con copia del .htaccess raíz | Manifest raíz sigue 403; manifest PWA 200 application/json; 27 recursos y 7 rutas SPA correctos |
| Service Worker | Sintaxis válida; precache completo de archivos públicos; registro /pwa/sw.js y scope /pwa/; configuración Apache excluida |

API requerida 1.4.5, APP 0.1.1, servidor por defecto mnores.atwebpages.com.
No hay devmn ni API 1.4.4 en bundle. Manifest standalone, icono ./icon.png.
Acerca de incluye MIT y copyright © 2026 José Isidro González.
Un literal localhost del parser genérico de Expo Linking no es servidor activo.

## Artefacto y límites

Build directamente en pwa/: 29 archivos, 3.878.849 bytes sin comprimir.
ZIP ShipInventory-PWA-production.zip con contenido directo, sin carpeta pwa
envolvente. Primer nivel: .htaccess, _expo/, assets/, favicon.ico, icon.png,
icon.svg, index.html, manifest.json, metadata.json, sw.js.
No incluye node_modules, frontend, src, data, PHP, vendor, .git, fuentes TS,
mapas, caches, logs ni documentación. Incluir .htaccess en la subida manual.

Yarn mantiene advertencias de resoluciones y peer dependencies existentes.
Restauración inicial sin mirror falló por caché incompleta; usando el mirror
externo ya verificado contra el lockfile terminó correctamente. No se cambian
versiones ni dependencias para ocultar avisos. Hay avisos de entorno de npm,
url.parse y NO_COLOR/FORCE_COLOR; no bloquean build ni validaciones.

Las pruebas de bundle usan DOM/IndexedDB simulados; las HTTP usan un servidor
local desechable. No se contacta ni despliega en AwardSpace ni se usa data.
Queda comprobar en dispositivo real instalación/actualización, cámara/galería,
teclado Safari y sincronización con API de producción tras la subida manual.
