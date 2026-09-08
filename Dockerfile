FROM rash07/php-fpm:8.2

# Repos de bullseye archivados: apt apunta a archive.debian.org.
RUN printf 'deb http://archive.debian.org/debian bullseye main\n' > /etc/apt/sources.list && \
    rm -f /etc/apt/sources.list.d/*.list && \
    echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check

RUN apt-get update && \
    apt-get -y install poppler-utils procps

# Soporte PostgreSQL (la imagen base solo trae pdo_mysql). Permite usar
# docker-compose.postgres.yml.example sin cambiar de imagen.
# El apt-get update va en la MISMA capa: si se reutiliza la cache de una
# capa anterior, sus indices de paquetes viejos dan 404 en los mirrors.
RUN apt-get update && \
    apt-get -y install libpq-dev && \
    docker-php-ext-install pdo_pgsql pgsql

# pg_dump 16 para el respaldo diario de la BD (spatie backup:run). El cliente
# de Debian bullseye es v13 y NO puede dumpear un servidor Postgres 16, por
# eso se instala desde el repositorio oficial PGDG.
RUN apt-get update && \
    apt-get -y install curl ca-certificates gnupg && \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bullseye-pgdg main" > /etc/apt/sources.list.d/pgdg.list && \
    apt-get update && \
    apt-get -y install postgresql-client-16

# Corrige la propiedad de storage/ y bootstrap/cache en cada arranque, de modo
# que www-data siempre pueda escribir logs (self-healing). Ver docker/entrypoint.sh.
# El 'sed' elimina posibles CR (CRLF de Windows) para que el script sea valido
# dentro del contenedor Linux.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && \
    chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]