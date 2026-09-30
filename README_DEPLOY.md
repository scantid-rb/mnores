# Despliegue automático — ShipInventory servidor 1.4.4

Este documento describe el método de despliegue mediante deploy.php y despliegue.zip para la instalación plana utilizada en hosting compartido como AwardSpace.

## Objetivo

El procedimiento permite actualizar el código del servidor sin descomprimir manualmente el ZIP.

Se utilizan dos archivos:
- despliegue.zip: contiene el código de la versión que se quiere instalar.
- deploy.php: instalador temporal que valida y descomprime el ZIP.

Cuando el despliegue termina correctamente, deploy.php y despliegue.zip se eliminan automáticamente. La carpeta data/ no se modifica.
Si el despliegue falla, ambos archivos se conservan para revisar el problema y reintentar.

## 1. Estructura del ZIP

El archivo debe llamarse exactamente despliegue.zip y contener la aplicación directamente en su raíz:

    despliegue.zip
    ├── index.php
    ├── router.php
    ├── .htaccess
    ├── assets/
    ├── src/
    ├── vendor/
    ├── composer.json
    ├── composer.lock
    ├── README.md
    ├── README_DEPLOY.md
    ├── api_contract_android.md
    └── ...

NO debe incluirse data/, deploy.php ni despliegue.zip.

La carpeta data/ contiene la base de datos, fotografías, backups y otros datos persistentes. El instalador la protege expresamente para evitar que una actualización de código sobrescriba datos de producción.

## 2. Preparar el ZIP

El ZIP debe generarse desde la raíz del proyecto de la versión que se quiere desplegar, respetando la estructura plana actual.

Antes de generarlo, comprobar que existen index.php, src/, assets/ y vendor/, y que no se incluye data/.

Ejemplo desde Linux/macOS/WSL:

    zip -r despliegue.zip index.php router.php .htaccess assets src vendor composer.json composer.lock README.md README_DEPLOY.md api_contract_android.md CHANGELOG.md

Comprobar el contenido antes de subirlo:

    unzip -l despliegue.zip

Debe comprobarse que index.php está en la raíz, que no aparece data/, que no aparece deploy.php y que no aparece otro despliegue.zip.

## 3. Subir a AwardSpace

Subir únicamente estos dos archivos a la raíz web:

    deploy.php
    despliegue.zip

No es necesario borrar manualmente los archivos actuales.

## 4. Ejecutar el despliegue

Abrir en el navegador:

    https://TU-DOMINIO/deploy.php

o, si el servidor utiliza HTTP:

    http://TU-DOMINIO/deploy.php

El instalador valida ZipArchive, comprueba el ZIP, rechaza rutas inseguras y rutas reservadas, extrae primero en un directorio temporal, comprueba que existe index.php y después copia el código sobre la instalación actual.

Si todo termina correctamente elimina automáticamente deploy.php y despliegue.zip.

## 5. Resultado esperado

El navegador mostrará:

    DESPLIEGUE COMPLETADO CORRECTAMENTE

Después de cargar la aplicación de nuevo, deploy.php y despliegue.zip ya no deben existir.

## 6. Si el despliegue falla

Si se produce un error, deploy.php y despliegue.zip se conservan. El directorio temporal se limpia. Se puede corregir el problema y volver a ejecutar el despliegue.

No borrar manualmente los archivos de la aplicación para intentar solucionar un fallo.

## 7. Datos que se conservan

El despliegue protege expresamente:

    data/
    ├── app.sqlite
    ├── installed.lock
    ├── photos/
    └── backups/

Por tanto, una actualización de código no debe eliminar usuarios, barcos, categorías, repuestos, fotografías, auditoría, configuración ni backups.

## 8. Después del despliegue

1. Entrar en la aplicación.
2. Abrir Estado del sistema.
3. Comprobar versión de aplicación, versión de API, versión de esquema, SQLite integrity_check y directorios/permisos.
4. Probar el acceso desde Android.
5. Probar una sincronización si el cambio afecta a la API.

Para la rama 1.4.4 se esperan:

    APP_VERSION    = 1.4.4
    API_VERSION    = 1.4.4
    SCHEMA_VERSION = 3

## 9. Backups

El despliegue de código no sustituye al sistema de backups. Antes de una actualización importante se recomienda disponer de un backup reciente de la base de datos y fotografías.

## 10. Seguridad del instalador

deploy.php está diseñado como instalador de un solo uso. Valida ZipArchive, el ZIP, las rutas internas y la presencia de index.php; rechaza rutas absolutas, traversal, data/, deploy.php y despliegue.zip dentro del paquete; extrae primero en un directorio temporal y solo se elimina a sí mismo y al ZIP después de completar correctamente el despliegue.

No dejar deploy.php en el servidor de forma permanente.

## 11. Cliente PWA en la rama pwa

Compilar `frontend/` con `PWA_BASE_PATH=/pwa yarn build:pwa` siguiendo
[frontend/README_PWA.md](frontend/README_PWA.md). Copiar `frontend/dist/` a una
carpeta `pwa/` de preparación e incluir esa carpeta y el `.htaccess` actualizado
en el ZIP si se quiere publicar el cliente junto al backend. No incluir
`frontend/node_modules/`, fuentes de desarrollo ni `data/`. Publicar primero
los recursos y después HTML/SW; conservar los recursos anteriores durante
la actualización. Se requiere HTTPS para instalar y usar el cliente offline.
La publicación de cambios Git en la rama pwa no despliega el hosting.
