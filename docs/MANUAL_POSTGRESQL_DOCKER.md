# Manual: PostgreSQL con Docker

Guía para levantar la base de datos PostgreSQL de la API en un contenedor
Docker usando el script incluido en el repo. Ideal si su servidor ya usa
Docker (por ejemplo, si la API misma corre en contenedores).

## 1. Requisito

Docker instalado y corriendo en el servidor. Verificar con:

```bash
docker --version
```

## 2. Ejecutar el instalador

En la carpeta de la API:

```bash
bash instalar-postgres-docker.sh
```

El script le pregunta, una por una (Enter acepta el valor por defecto):

| Pregunta | Por defecto |
|---|---|
| Nombre del contenedor | `postgres_api` |
| Nombre de la base de datos | `apidian` |
| Usuario | `apidian_user` |
| Clave | (obligatoria, usted la elige) |
| Puerto en el servidor | `5432` |
| ¿La API corre en Docker? | `n` |

Si su API corre en Docker, responda `s`. El script **detecta la red
automáticamente**: busca el contenedor PHP de la API (`php_api` o
`php_api_<dominio>`) y toma la red en la que está; si no lo encuentra
corriendo, prueba con los nombres típicos de las instalaciones (`apinet`
en instalaciones solo API, `webnet` en API + facturador). La red detectada
se ofrece como valor por defecto — basta con presionar Enter. Así el
contenedor de PostgreSQL queda en la misma red y la API lo alcanza por su
nombre.

Si necesita verificarla manualmente, la red está en el `docker-compose.yml`
de la API (sección `networks`) o con:

```bash
docker inspect php_api --format '{{json .NetworkSettings.Networks}}'
```

Al terminar, el script:

- Genera **`docker-compose.postgres.yml`** con su configuración (queda como
  registro: ahí están el contenedor, credenciales y puerto elegidos).
- Deja PostgreSQL corriendo (arranca solo si el servidor se reinicia).
- **Imprime el bloque exacto para el `.env`**.

## 3. Configurar la API

1. Copie el bloque que imprimió el script en el `.env` de la API
   (reemplazando las variables `DB_*` actuales).
2. Ejecute los comandos que el script indica al final:
   - API nativa:
     ```bash
     php artisan config:clear
     php artisan migrate --seed
     ```
   - API en Docker:
     ```bash
     docker compose exec php_api php artisan config:clear
     docker compose exec php_api php artisan migrate --seed
     ```

Listo: la API queda funcionando con PostgreSQL. Para validar, siga la
sección 5 del `MANUAL_POSTGRESQL.md`.

## 4. Manejo del contenedor

Desde la carpeta donde quedó el `docker-compose.postgres.yml`:

```bash
docker compose -f docker-compose.postgres.yml stop      # apagar (los datos se conservan)
docker compose -f docker-compose.postgres.yml start     # encender
docker compose -f docker-compose.postgres.yml restart   # reiniciar
docker logs postgres_api                                # ver el log
```

**Para cambiar el puerto u otra configuración:** edite el
`docker-compose.postgres.yml` y ejecute
`docker compose -f docker-compose.postgres.yml up -d`. Si cambia la clave,
actualice también el `.env` de la API.

Los datos viven en un volumen de Docker: sobreviven a reinicios y a
recrear el contenedor. Solo se pierden si se elimina a propósito:

```bash
docker compose -f docker-compose.postgres.yml down -v   # ¡borra TODO, incluidos los datos!
```
