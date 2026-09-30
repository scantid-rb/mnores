# Validación del build con conectividad web corregida

Base: `cda0eeb8a4b6f6f6b1bdda96b3b8d15c5e5c370f` de la rama `pwa`.
Se comprobó que el commit está presente antes de compilar.

## Cambios de esta entrega

- `connectivity.ts` conserva exactamente la corrección de `cda0eeb8`.
- Se añade `tests/connectivity.cjs` y se incorpora al comando `test`. Comprueba
  el estado inicial online/offline, eventos y limpieza de listeners en web,
  ausencia de llamadas a NetInfo en web, y fetch/eventos/unsubscribe de NetInfo
  en Android/iOS, incluida la respuesta de alcanzabilidad desconocida.
- Se regenera el contenido publicable de `pwa/`, directamente en la raíz del
  repositorio. Se excluye el bundle intermedio de Expo que no está referenciado
  por HTML ni por el precache. No se cambia la lógica del cliente ni del backend.

## Resultados

| Comprobación | Resultado |
| --- | --- |
| `npm run typecheck` | Correcto |
| `npm run lint` | Correcto, también después de añadir la regresión |
| `node tests/connectivity.cjs` | 4 escenarios correctos |
| `node tests/offline.cjs` (ejecutado por `npm test`) | 22 casos correctos |
| `node tests/sw.cjs` (ejecutado por `npm test`) | 5 casos correctos |
| `node tests/web.cjs` (ejecutado por `npm test`) | Falla: `window is not defined` en la simulación de Alert |
| `node tests/query-offline.cjs` (ejecutado por separado) | 3 casos correctos |
| `npm run test:api` (PHP 8.3/Python, instalación temporal) | 14 casos correctos; falla P09, que espera un 409 para el comportamiento antiguo de borrado de barcos |
| `PWA_BASE_PATH=/pwa corepack yarn build:pwa` | Correcto, Yarn 1.22.22 |
| Bundle exportado | Incluye navigator.onLine y listeners online/offline; App 0.1.1/API 1.4.5; sin 1.4.4 |
| Manifest y registro del SW emitido | Manifest bajo /pwa/; registro /pwa/sw.js, ámbito /pwa/ |
| HTTP local con router PHP del proyecto | 26 recursos de precache servidos idénticos a sus archivos y 3 rutas internas devuelven index.html |
| Árbol publicable | 27 archivos, 3.944.134 bytes; sin node_modules, deploy/pwa, dist intermedio ni temporales |

Los fallos de `web.cjs` y P09 son previos a `cda0eeb8`: ese commit solo cambia
`connectivity.ts`, y se confirmó que la prueba de Alert y la implementación de
barcos no cambian respecto a su padre. No se corrigen en esta entrega por ser
ajenos a la detección de conectividad. La suite completa NO está en verde.

El build está preparado para copiarse al directorio público `/pwa/` junto al
backend. No se ha desplegado ni se ha accedido a los hostings del proyecto.
En navegador/dispositivo real queda verificar login con Internet, estado al
entrar/salir de modo avión, cierre/reapertura offline, actualización del SW,
instalación en Safari/Chrome y sincronización al reconectar. navigator.onLine
indica el estado del navegador; no garantiza que el servidor responda.
