# Manual: conexión de la API con PostgreSQL

La API soporta **MySQL/MariaDB y PostgreSQL**. No hay que tocar código para
usar uno u otro: el motor se elige únicamente con la variable
`DB_CONNECTION` del archivo `.env`.

Esta guía aplica para **instalaciones nuevas** con PostgreSQL.

> ¿Prefiere PostgreSQL en Docker? Use el instalador incluido — vea
> `MANUAL_POSTGRESQL_DOCKER.md`.

## 1. Requisitos

- PostgreSQL 13 o superior (probado con PostgreSQL 16).
- Extensiones PHP `pdo_pgsql` y `pgsql` habilitadas:
  - **Ubuntu/Debian:** `sudo apt install php8.2-pgsql` (ajustar a su versión
    de PHP) y luego reiniciar el servidor web:
    `sudo systemctl restart apache2` (o `php8.2-fpm` si usa Nginx).
  - **Windows (XAMPP/Laragon):** en el `php.ini` descomentar las dos líneas
    (quitarles el `;` inicial):
    ```ini
    extension=pdo_pgsql
    extension=pgsql
    ```
    Las DLL ya vienen incluidas con PHP, solo hay que activarlas.
  - Verificar con: `php -m | grep pgsql` (Linux) o `php -m | findstr pgsql`
    (Windows) — debe listar `pdo_pgsql` y `pgsql`.
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
DB_USERNAME=apidian_user
DB_PASSWORD=clave-segura
```

Si la clave contiene caracteres especiales (`#`, espacios, `$`...), debe ir
entre comillas: `DB_PASSWORD="clave#con.simbolos"`.

Después de cualquier cambio en el `.env` **siempre** ejecutar:

```bash
php artisan config:clear
```

## 3. Migrar y sembrar

```bash
php artisan migrate --seed
```

Esto crea las ~88 tablas y carga los catálogos (municipios, tipos de
documento, etc.). En PostgreSQL la carga tarda un poco más que en MySQL; es
normal. Al final debe aparecer el mensaje
`Secuencias de PostgreSQL resincronizadas.`

## 4. Si el `.env` "no se toma"

Diagnóstico en orden:

1. **Config cacheada** — Laravel ignora el `.env` si existe
   `bootstrap/cache/config.php`. Solución: `php artisan config:clear`.
2. **Copia vieja del código** — versiones anteriores traían un
   `DB::setDefaultConnection('mysql')` hardcodeado en
   `app/Providers/AppServiceProvider.php` que forzaba MySQL sin importar el
   `.env` (ya fue eliminado). Si aparece, actualizar el código.

**Nunca** hardcodear el motor en `config/database.php`: rompe las demás
instalaciones y no ataca la causa.

## 5. Validar la instalación

```bash
php artisan tinker --execute="echo DB::connection()->getDriverName(), ' / ', App\Department::count(), ' departamentos';"
```

Debe imprimir `pgsql / 33 departamentos`. Luego probar login y emitir un
documento de prueba en ambiente de habilitación.

## Volver a MySQL

Revertir el bloque `DB_*` del `.env` a los valores de MySQL y ejecutar
`php artisan config:clear`. Cada motor conserva su propia base de datos:
cambiar la conexión no borra nada.
