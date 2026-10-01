# PWA de producción: compatibilidad Safari/iOS

Fuente actualizada desde GitHub: `daf0edf29c4b2a15d7484cf12deff74905b84487`,
exclusivamente `scantid-rb/mnores:pwa`.

## Correcciones acotadas

- `app/part-edit.tsx` todavía importaba directamente
  `react-native-keyboard-controller`. Se sustituye por el wrapper existente
  `KeyboardAwareScrollViewCompat`, también en sus etiquetas JSX. El navegador
  utiliza ScrollView; se conserva el wrapper nativo del proyecto.
- `yarn.lock` añade el selector exacto `expo-constants@57.0.20` a la entrada
  existente `expo-constants@~57.0.20`. La resolución exacta de package.json no
  tenía entrada propia y la instalación congelada intentaba actualizar el lock.
  No cambian versiones, URLs, integridades ni dependencias del package.json.
- Se regenera `pwa/` con base `/pwa` y API predeterminada de producción.

No se cambian backend, API, roles/permisos, UX, IndexedDB, sync, fotos ni data.
Se verifican perfil/contraseña por /api/account, identidad local sin pérdida,
Jefe limitado a mecánicos del barco propio, Barcos para Admin/Inspector y
exclusión del Mecánico de Administración. No se añaden funciones.

## Validaciones finales, después de restaurar dependencias

| Comando/comprobación | Resultado |
| --- | --- |
| `yarn install --frozen-lockfile --offline` | Correcto, paquetes instalados y scripts ejecutados |
| `yarn typecheck` | Correcto |
| `yarn lint` | Correcto, sin advertencias |
| `yarn test` | 39 escenarios correctos: conectividad 4, offline/IndexedDB 23, SW 7, web/Alert 2, React Query/sync 3 |
| `npx --no-install expo-doctor` | 20/20 comprobaciones correctas |
| `PWA_BASE_PATH=/pwa EXPO_PUBLIC_API_BASE_URL=https://mnores.atwebpages.com yarn build:pwa` | Correcto, producción |
| Mapa de fuentes de export auxiliar de la misma compilación | Metro resuelve KeyboardProviderCompat.web.tsx y KeyboardAwareScrollViewCompat.web.tsx; ningún módulo de react-native-keyboard-controller |
| Bundle de producción | Sin KeyboardController ni APIs de esa biblioteca; App 0.1.1/API 1.4.5 y servidor mnores.atwebpages.com |
| HTTP local con router PHP existente | 27 recursos de precache idénticos al archivo generado; 7 rutas SPA válidas |
| Ejecución del bundle final en DOM simulado | Login, About/perfil para cuatro roles, Usuarios para Admin/Inspector/Jefe, Inventario, detalle y navegación a edición offline sin errores de render |
| Restricciones de navegación en DOM simulado | Jefe en /admin/boats y Mecánico en /admin redirigen a Inventario |
| Handshake en el bundle ejecutado | Reconexión muestra respuesta simulada y envía client_app_version=0.1.1/client_api_version=1.4.5 |
| Registro SW ejecutado | /pwa/sw.js, scope /pwa/ |

La descarga directa de Yarn inicialmente falló; para completar la restauración
se descargaron los 850 tarballs indicados en el lockfile desde el registro y
se verificaron sus SHA1 e integridades SHA512, sin discrepancias. Se alimentó
un mirror temporal externo al repositorio y después la caché de Yarn. La
instalación congelada final usa esa caché. Mirror, mapas y harness no se publican.

Yarn conserva advertencias de peer dependencies y de resoluciones forzadas del
proyecto; no son errores de instalación. También hay avisos no bloqueantes de
url.parse, npm http-proxy y NO_COLOR/FORCE_COLOR. No se alteran versiones para
ocultarlos. Expo Doctor no detecta problemas.

## Paquete final

28 archivos, 3.876.464 bytes sin comprimir, directamente en `pwa/`.
Primer nivel: `_expo/`, `assets/`, `favicon.ico`, `icon.png`, `icon.svg`,
`index.html`, `manifest.json`, `metadata.json`, `sw.js`.
ZIP sin carpeta envolvente, node_modules, fuentes TS/TSX, .git, mapas, cachés,
logs, frontend, data, backend PHP ni temporales.
Se excluye el bundle intermedio de Expo sin referencia en HTML/precache.

HTML referencia /pwa/_expo, /pwa/manifest.json, /pwa/icon.svg y favicon.
Manifest usa inicio/ámbito ./ e icon.png existente. SW incluye todos los assets
exportados, excluye API/PHP y admite login/inventory/profile/admin/users/about/
part y part-edit. Las siete rutas se comprobaron por HTTP local.
Sin URLs de API a devmn, Codespaces ni localhost. Un literal localhost del
parser genérico de Expo Linking no es un servidor ni una URL de recurso.

El harness utiliza JSDOM y fake-indexeddb con datos desechables; no llama a
AwardSpace ni usa data del proyecto. La suite prueba CRUD offline, colas,
fotos, sincronización, conservación de identidad y actualización del SW.
No sustituye Safari/iPhone real: queda confirmar teclado, cámara/galería,
instalación, actualización del SW y sincronización con el servidor real.

No se realiza ningún despliegue ni subida a AwardSpace. Subir el contenido del
ZIP directamente dentro del directorio público /pwa/, sin carpetas añadidas.
