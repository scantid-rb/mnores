# Validación de producción PWA: perfil y permisos

Fuente actualizada desde GitHub: `7c332e0999b3c733a1e2576cc85f851b49aedfad`, rama `pwa`.
No se modifican otras ramas ni se despliega en AwardSpace.

## Correcciones adicionales

- `app/(tabs)/admin.tsx`: redirige a Inventario al Mecánico y usuarios sin rol
  administrativo, también ante acceso por URL directa.
- `app/admin/users.tsx`: filtra la caché del Jefe a mecánicos de su propio
  `boat_id`; sin barco asignado la lista está vacía. Los payloads ya forzaban
  mecánico/barco propio. Admin e Inspector conservan sus opciones y Barcos
  continúa limitado a ambos.
- `app/(tabs)/profile.tsx`: estabiliza `effectiveUser` con `useMemo` para corregir
  la advertencia de dependencias de hooks, sin cambiar el flujo del perfil.
- `tests/web.cjs`: actualiza la simulación al wrapper actual `window.alert/confirm`.
- `tests/offline.cjs`: verifica que `updateSessionIdentity` cambia username y
  conserva exactamente inventario, cursor, colas y blobs de fotografías.
- `tests/sw.cjs`: verifica rutas offline de perfil/usuarios/repuesto en ámbito
  raíz y exclusión del backend API/PHP.

No se modifica IndexedDB, SessionContext, conectividad, sincronización, CRUD,
fotos, Alert ni versiones. No se añade acceso a Categorías.

## Resultados

| Comprobación | Resultado |
| --- | --- |
| `corepack yarn check --verify-tree` | Correcto: Folder in sync |
| Yarn install congelado/offline | Caché incompleta: falta expo-constants 57.0.20 |
| Yarn install congelado por red, con/sin proxy explícito | Descarga no completada; cancelada al permanecer en Fetching packages. Sin cambios de lockfile/versiones |
| `corepack yarn typecheck` | Correcto |
| `corepack yarn lint` | Correcto, sin advertencias |
| `corepack yarn test` | Correcto: conectividad 4, offline 23, SW 6, web 2, React Query/sync 3 |
| `corepack yarn expo-doctor` | 20/20 comprobaciones correctas |
| `corepack yarn expo install --check` | Timeout del proxy en comprobación online |
| `EXPO_OFFLINE=1 corepack yarn expo install --check` | Dependencias actualizadas según metadatos locales; Expo advierte de menor fiabilidad offline |
| `corepack yarn test:api` | 14 correctos; falla P09 (espera 409; API actual devuelve 200 con borrado lógico de barco) |
| `PWA_BASE_PATH= corepack yarn build:pwa` | Correcto, raíz pública `/` |
| HTTP local | 26 recursos de precache servidos idénticos al archivo generado |

P09 es un fallo previo y ajeno a perfil/permisos; no se altera el backend ni se
cambia su expectativa para ocultarlo. La suite adicional API no está en verde.
Las dependencias existentes se verifican con Yarn y todas las validaciones PWA
pasan; no se afirma una instalación limpia completada desde cero.

## Paquete

Build en `pwa/`: 27 archivos, 3.951.102 bytes sin comprimir. El ZIP incluye
su contenido directamente, sin carpeta envolvente. Primer nivel:
`_expo/`, `assets/`, `favicon.ico`, `icon.svg`, `index.html`, `manifest.json`,
`metadata.json`, `sw.js`. Sin node_modules, .git, TS/TSX, mapas, logs ni cachés.
Se excluye el bundle intermedio de Expo sin referencias en HTML/precache.
Manifest con inicio/ámbito `./`; registro `/sw.js`, ámbito `/`; HTML referencia
`/manifest.json` y `/_expo/...`. App 0.1.1/API 1.4.5 y handshake alineado;
ningún 1.4.4 en el bundle; compatibilidad exacta por igualdad con API_VERSION.

La API sigue siendo configurable. Se conserva el valor inicial del código
(`https://devmn.atwebpages.com`) y las preferencias persistidas; seleccionar
la URL de producción correspondiente en Configuración. El ZIP no reemplaza
reglas del backend ni incluye credenciales.

Pendiente en navegador/hosting real: HTTPS, rutas SPA nuevas junto a `/api`
sin interceptar PHP, instalación Chrome/Safari, actualización del SW,
perfil/contraseña y permisos por rol, reapertura offline y sincronización de
cambios/fotos al reconectar. No se accede ni se despliega en AwardSpace.
