FROM rash07/php-fpm:8.2

RUN apt-get update && \
    apt-get -y install poppler-utils procps

# Soporte PostgreSQL (la imagen base solo trae pdo_mysql). Permite usar
# docker-compose.postgres.yml.example sin cambiar de imagen.
# El apt-get update va en la MISMA capa: si se reutiliza la cache de una
# capa anterior, sus indices de paquetes viejos dan 404 en los mirrors.
RUN apt-get update && \
    apt-get -y install libpq-dev && \
    docker-php-ext-install pdo_pgsql pgsql

# Corrige la propiedad de storage/ y bootstrap/cache en cada arranque, de modo
# que www-data siempre pueda escribir logs (self-healing). Ver docker/entrypoint.sh.
# El 'sed' elimina posibles CR (CRLF de Windows) para que el script sea valido
# dentro del contenedor Linux.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && \
    chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]