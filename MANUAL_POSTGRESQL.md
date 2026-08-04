# Manual: instalar APIDIAN con PostgreSQL

Este manual explica cómo instalar la API usando **PostgreSQL** en lugar de
MySQL/MariaDB. Aplica para **instalaciones nuevas**. Migrar los *datos* de una
instalación MySQL existente a PostgreSQL es un proceso aparte (con
herramientas como `pgloader`) que este manual **no** cubre.

> El código soporta ambos motores desde agosto 2026: todo el SQL específico
> de MySQL fue centralizado en `app/Helpers/SqlDialect.php`, que devuelve la
> expresión correcta según el motor conectado. No hay que tocar código para
> cambiar de motor: solo el `.env`.

## 1. Requisitos

- PostgreSQL 13 o superior (probado con PostgreSQL 16).
- Extensiones PHP `pdo_pgsql` y `pgsql` habilitadas:
  - **Ubuntu/Debian:** `sudo apt install php8.2-pgsql` (ajustar versión de PHP).
  - **Windows (XAMPP/Laragon):** en `php.ini` descomentar las dos líneas
    (quitarles el `;` inicial):
    ```ini
    extension=pdo_pgsql
    extension=pgsql
    ```
    Las DLL ya vienen incluidas con PHP, solo hay que activarlas.
  - **Ojo con tener varios PHP instalados** (típico con Laragon + XAMPP):
    el `php.ini` que hay que editar es el del PHP que realmente usa la
    terminal. Ubicarlo con `php --ini` (línea "Loaded Configuration File").
    En Laragon cada versión tiene su propio `php.ini` en
    `C:\laragon\bin\php\php-X.X.X\`.
  - Verificar con: `php -m | grep pgsql` (Linux) o `php -m | findstr pgsql`
    (Windows) — debe listar `pdo_pgsql` y `pgsql`.
  - **Síntoma si falta la extensión:** `could not find driver (Connection:
    pgsql, ...)` al correr cualquier comando artisan que toque la BD.
  - El PHP de CLI relee el `php.ini` en cada comando, pero el del servidor
    web (Apache/FPM) solo al arrancar: **reiniciar Apache/PHP-FPM** después
    de habilitar las extensiones.
- Una base de datos creada y un usuario con permisos sobre ella:
  ```sql
  CREATE DATABASE apidian;
  -- opcional: usuario dedicado
  CREATE USER apidian_user WITH PASSWORD 'clave-segura';
  GRANT ALL PRIVILEGES ON DATABASE apidian TO apidian_user;
  ```

## 2. Configurar el `.env`

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=apidian
DB_USERNAME=postgres
DB_PASSWORD=clave-segura
```

Después de cualquier cambio en el `.env` **siempre** ejecutar:

```bash
php artisan config:clear
```

## 3. Migrar y sembrar

```bash
php artisan migrate --seed
```

Esto crea las ~88 tablas y carga los catálogos (municipios, tipos de
documento, etc.). En PostgreSQL la carga de CSVs usa inserts por PHP en vez
de `LOAD DATA` (automático), por lo que tarda un poco más que en MySQL. Al
final debe aparecer el mensaje `Secuencias de PostgreSQL resincronizadas.`

## 4. Si el `.env` "no se toma"

Diagnóstico en orden (fue la causa del problema original):

1. **Config cacheada** — Laravel ignora el `.env` si existe
   `bootstrap/cache/config.php`. Solución: `php artisan config:clear`.
2. **Variable de entorno real del sistema** — si el servidor define
   `DB_CONNECTION` a nivel de Apache/Nginx, systemd, Docker o panel del
   hosting, esa variable **pisa** al `.env`. Verificar con
   `printenv | grep DB_` (Linux) o `Get-ChildItem env:DB_*` (PowerShell).
3. ~~`DB::setDefaultConnection('mysql')` hardcodeado en
   `app/Providers/AppServiceProvider.php`~~ — **ya eliminado**. Esta línea
   forzaba mysql en cada arranque sin importar el `.env` (era la causa real
   de que "no tomara" el `DB_CONNECTION`). Si se trabaja sobre una copia
   vieja del código, verificar que no exista.

**Nunca** hardcodear el motor en `config/database.php`: rompe las demás
instalaciones y no ataca la causa.

## 5. Diferencias de comportamiento a tener en cuenta

| Tema | MySQL | PostgreSQL |
|---|---|---|
| `LIKE` | Insensible a mayúsculas (collation `_ci`) | Sensible. Los puntos críticos ya usan `ILIKE`/`UPPER()` vía `SqlDialect`, pero los `LIKE` de Eloquent genéricos distinguen mayúsculas. |
| Secuencias de id | `AUTO_INCREMENT` se ajusta solo al insertar ids explícitos | La secuencia NO avanza. El seeder y `RegularizeDataHelper` las resincronizan; si se cargan datos a mano con ids explícitos, ejecutar después: `SELECT setval(pg_get_serial_sequence('tabla','id'), (SELECT COALESCE(MAX(id),0)+1 FROM tabla), false);` |
| `INSERT IGNORE` | Ignora duplicados **y** FKs inválidas | `insertOrIgnore` solo ignora duplicados; una FK inválida lanza error. |
| Transacciones en migraciones | Los DDL no son transaccionales | Cada migración corre en transacción; un error la revierte completa. |

## 6. Validar la instalación

```bash
php artisan tinker --execute="echo DB::connection()->getDriverName(), ' / ', App\Department::count(), ' departamentos';"
```

Debe imprimir `pgsql / 33 departamentos`. Luego probar login y emitir un
documento de prueba en ambiente de habilitación.

## 7. Postgres con Docker

### 7a. Stack dockerizado completo (VPS / producción)

El repo incluye `docker-compose.postgres.yml.example`: el mismo stack de
`docker-compose.yml.example` pero con PostgreSQL 16 en lugar de MariaDB.

```bash
cp docker-compose.postgres.yml.example docker-compose.yml
# .env: DB_CONNECTION=pgsql, DB_HOST=postgres, DB_PORT=5432,
#       DB_DATABASE=db_api, DB_USERNAME=user_api, DB_PASSWORD=<clave>
docker network create apinet   # si no existe
docker compose build           # el Dockerfile ya compila pdo_pgsql/pgsql
docker compose up -d
docker compose exec php_api php artisan config:clear
docker compose exec php_api php artisan migrate --seed
```

Notas:
- No necesita `docker/init.sql`: la imagen oficial de Postgres crea el
  usuario y la base desde las variables `POSTGRES_*` del compose.
- El puerto 5432 queda publicado solo en `127.0.0.1` del servidor; para un
  cliente gráfico desde tu máquina usar túnel SSH:
  `ssh -L 5432:127.0.0.1:5432 usuario@vps`.
- Los datos viven en el volumen `api_pgdata` (sobreviven a recrear el
  contenedor; se borran solo con `docker volume rm`).

### 7b. Solo la base para desarrollo local (opcional)

```bash
docker run -d --name pg-apidian \
  -e POSTGRES_PASSWORD=secret \
  -e POSTGRES_DB=apidian2026_pg \
  -p 5432:5432 \
  postgres:16
```

HeidiSQL, DBeaver o pgAdmin sirven como cliente gráfico (en HeidiSQL elegir
tipo de red "PostgreSQL (TCP/IP)"). Nota: en Postgres cada conexión entra a
una sola base; las tablas están dentro del esquema `public` (expandir ese
nodo y refrescar con F5 tras migrar).

## 8. Archivos que soportan la compatibilidad dual

- `app/Helpers/SqlDialect.php` — expresiones SQL por dialecto (casts, JSON,
  epoch, `CHR`, `ILIKE`). **Todo SQL crudo nuevo debe construirse con este
  helper**, nunca con funciones exclusivas de un motor.
- `app/Helpers/RegularizeDataHelper.php` — carga de CSVs portable +
  resincronización de secuencias.
- `database/seeders/DatabaseSeeder.php` — resincronización global de
  secuencias al final del seed.
- Consultas ajustadas en: `app/Document.php`, `app/RipsAppointment.php`,
  `app/Http/Controllers/MonitoringController.php`,
  `app/Http/Controllers/Api/InvoiceController.php`,
  `app/Http/Controllers/Api/MiscelaneousController.php`,
  `app/Services/RutExtractorV2.php` y 4 migraciones.
