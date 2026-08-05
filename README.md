# **APIDIAN 2026**

## Acerca de FACTURALATAM

Estamos enfocados en crear herramientas y soluciones para desarrolladores de software, empresas medianas y pequeñas, tenemos un sueño, queremos democratizar la facturación electrónica.

## 1. Instalación

- ###  Despliegue en local (Windows)
  * Video de instalación. [Aquí](https://www.youtube.com/watch?v=9Ds2DR3QLGY)
  * [Pasos aquí](docs/Comandos%20Instalacion%20API%202025%20Windows.md)
- ### Despliegue en linux
  *  Exclusivo para Ubuntu **20.04** | entorno LAMP | ejecucion de comandos de forma manual.
     * [Video aquí](https://www.youtube.com/watch?v=rEgrHADjsCY)
     * [Comandos aquí](docs/Comandos%20Instalacion%20API%202025%20Linux%20Ubuntu%2020.md)
  * Exclusivo para Ubuntu **20.04** | Entorno LAMP | ejecución de script.
     * [Pasos y script aquí](https://git.buho.la/facturalatam/co-apidian2026/-/snippets/47)
  * Linux | Entorno **Docker** | *Aporte de usuario de la comunidad*
     * [Descarga aquí](https://git.buho.la/facturalatam/co-apidian2026/-/blob/master/api_docker.zip?ref_type=heads)
- ### Despliegue con Docker
  * Ubuntu 20.04 , 22.04 y 24.04 | sólo API | sin dominio | proceso manual
     * [Pasos aquí](https://git.buho.la/-/snippets/31)
- ### Base de datos PostgreSQL (opcional)
  * El API funciona con MySQL/MariaDB (por defecto) o **PostgreSQL**; el motor se elige solo con el `.env`.
     * **Conexión y puesta en marcha** (requisitos, `.env`, migraciones): [docs/MANUAL_POSTGRESQL.md](docs/MANUAL_POSTGRESQL.md)
     * **PostgreSQL en Docker** con instalador interactivo (`instalar-postgres-docker.sh`, genera su `docker-compose.postgres.yml`): [docs/MANUAL_POSTGRESQL_DOCKER.md](docs/MANUAL_POSTGRESQL_DOCKER.md)
- ### Extractor DIAN (Factura de compras)
  * La documentación del extractor está incluida en este repositorio, en la carpeta `docs/`:
    * **Despliegue e integración** — cómo conectar el worker del extractor a una instalación del API (Docker o LAMP): [docs/EXTRACTOR_INTEGRACION.md](docs/EXTRACTOR_INTEGRACION.md)
    * **API del extractor** — rutas, autenticación y ejemplos para consumirlo desde sistemas externos: [docs/API_EXTRACTOR.md](docs/API_EXTRACTOR.md)
- ### CPanel
  * [Ver manual aquí](https://git.buho.la/facturalatam/co-apidian2026/-/snippets/48)
- ### Migracion Api2025 a Api2026 windows:
  *  [Pasos aquí](https://git.buho.la/facturalatam/co-apidian2026/-/snippets/49)  
- ### Migracion Api2025 a Api2026 Docker:
  *  [Pasos aquí](https://git.buho.la/facturalatam/co-apidian2026/-/snippets/50)

## 2. Actualización

- Actualizar repositorio de gitlab de una versión antigua a la actual. [Video aquí](https://www.youtube.com/watch?v=6lwLKQCYvNY)
- Comandos para actualizar repositorio **Pto. 1** y comandos para actualizar **Pto.2**. [Aquí](docs/Proceso%20de%20actualizacion%20APIDIAN.md)

## 3. Ejemplos para la API / Collection POSTMAN

- Descargar Postman [Aquí](https://www.postman.com/downloads/)
- Ver ejemplos en sitio web. [Aquí](https://documenter.getpostman.com/view/1431398/2sAY4uCido) 
(Puede descargar los ejemplos a Postman desde el boton superior derecho "RUN in postman")


## 4. Proceso de habilitación DIAN Validación Previa UBL 2.1

- Ver video explicativo [Aquí](https://www.youtube.com/watch?v=csTmbd1Ere8)

## 5.- Configuración / Habilitación / Producción API
* Ver video explicativo [Aquí](https://www.youtube.com/watch?v=TSF2nHN4W1I)

## 6.- Cómo pasar a producción
* Ver video explicativo [Aquí](https://www.youtube.com/watch?v=gBtd4XqwWtg)

## Errores frecuentes

* Soluciones a los problemas más comunes de instalación y operación del API. [Ver manual aquí](docs/Errores%20frecuentes%20APIDIAN.md)

## APP
* Aplicación movil en formato web [visitar URL](https://facturalatam.com/app/)

## Extras

* Para habilitar la carga de **Fichas RUT** al crear empresas (autocompleta el formulario desde el PDF del RUT): [ver manual aquí](docs/Configurar%20PDF%20Fichas%20RUT.md)
* Para deshabilitar registro de nuevas empresas por API:
  * ubicarse en la raiz del proyecto
  * editar el archivo .env (*utilizar nano o un editor en consola*)
  * añadir si no existe el parametro `ENABLE_API_REGISTER` con el valor en `false`
  * eliminar la cache de la aplicacion `php artisan config:cache & php artisan cache:clear`
Los cambios podrán decargarse a partir de enero del 2025 progresivamente, con la nueva documentación respectiva.
* Para añadir un correo para envio de los documentos a los clientes:
  * ubicarse en la raiz del proyecto y editar el archivo .env (*utilizar nano o un editor en consola*)
  * ajustar los siguientes valores de acuerdo a su proveedor de correos
  ```
  MAIL_DRIVER=smtp
  MAIL_HOST=smtp.gmail.com
  MAIL_PORT=465
  MAIL_USERNAME=username@gmail.com
  MAIL_PASSWORD=password
  MAIL_ENCRYPTION=ssl
  MAIL_FROM_ADDRESS=username@gmail.com
  MAIL_FROM_NAME=username
  ```
  * eliminar la cache de la aplicacion `php artisan config:clear && php artisan config:cache & php artisan optimize:clear`
