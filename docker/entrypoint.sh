#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# Entrypoint del contenedor PHP (php_api / scheduling_api).
#
# Objetivo: que www-data SIEMPRE pueda escribir en storage/ y bootstrap/cache,
# sin depender de chmod 777 ni de que el cliente cambie sus comandos.
#
# - Se ejecuta en cada arranque del contenedor.
# - php_api arranca como root: aprovecha para corregir la propiedad de los
#   archivos (repara cualquier log que haya quedado como root: self-healing).
# - scheduling_api corre como www-data (user: en el compose): el chown se omite
#   solo, y como ya es www-data no vuelve a crear archivos de root.
# ---------------------------------------------------------------------------

# Asegura que existan las carpetas escribibles que Laravel necesita.
mkdir -p storage/logs \
         storage/framework/cache \
         storage/framework/sessions \
         storage/framework/views \
         bootstrap/cache

# Solo el proceso root (php_api) puede y necesita corregir la propiedad.
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi

# Ejecuta el comando real del contenedor (php-fpm, o el sh -c del scheduler).
exec "$@"
