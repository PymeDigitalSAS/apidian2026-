#!/bin/bash
# ============================================================================
# Instalador de PostgreSQL 16 en Docker para la API
#
# Genera un docker-compose.postgres.yml con las credenciales y puerto que
# usted elija (queda como registro de la configuracion), levanta el
# contenedor y al final imprime el bloque listo para pegar en el .env.
#
# Uso:      bash instalar-postgres-docker.sh
# Manejo:   docker compose -f docker-compose.postgres.yml stop|start|restart
# ============================================================================
set -e

COMPOSE_FILE=docker-compose.postgres.yml

# --- valores por defecto ----------------------------------------------------
CONTAINER=postgres_api
DB_NAME=apidian
DB_USER=apidian_user
DB_PASS=""
PORT=5432
NETWORK=""
DB_HOST=127.0.0.1
DB_PORT_ENV=""

echo "==================================================="
echo "  PostgreSQL 16 en Docker para la API"
echo "==================================================="
echo ""

# --- verificaciones ---------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    echo "ERROR: Docker no esta instalado en este servidor."
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo "ERROR: el servicio de Docker no esta corriendo (o falta permiso; pruebe con sudo)."
    exit 1
fi

if docker compose version >/dev/null 2>&1; then
    DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    DC="docker-compose"
else
    echo "ERROR: no se encontro 'docker compose' (plugin) ni 'docker-compose'."
    exit 1
fi

if [ -f "$COMPOSE_FILE" ]; then
    echo "ERROR: ya existe $COMPOSE_FILE en esta carpeta."
    echo "Si quiere manejar ese PostgreSQL: $DC -f $COMPOSE_FILE start|stop|restart"
    echo "Si quiere reinstalar desde cero:  $DC -f $COMPOSE_FILE down && rm $COMPOSE_FILE"
    exit 1
fi

# --- preguntas --------------------------------------------------------------
read -p "Nombre del contenedor [$CONTAINER]: " x; CONTAINER=${x:-$CONTAINER}

if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "ERROR: ya existe un contenedor llamado '$CONTAINER'."
    exit 1
fi

read -p "Nombre de la base de datos [$DB_NAME]: " x; DB_NAME=${x:-$DB_NAME}
read -p "Usuario de la base de datos [$DB_USER]: " x; DB_USER=${x:-$DB_USER}

while [ -z "$DB_PASS" ]; do
    read -s -p "Clave de la base de datos (obligatoria): " DB_PASS; echo ""
done

read -p "Puerto en este servidor [$PORT]: " x; PORT=${x:-$PORT}

echo ""
read -p "La API tambien corre en Docker en este servidor? (s/n) [n]: " API_DOCKER
API_DOCKER=${API_DOCKER:-n}

if [ "$API_DOCKER" = "s" ] || [ "$API_DOCKER" = "S" ]; then
    echo ""
    echo "Redes de Docker disponibles:"
    docker network ls --format '  - {{.Name}}'
    echo ""
    read -p "Nombre de la red donde corre la API: " NETWORK
    if [ -n "$NETWORK" ] && ! docker network ls --format '{{.Name}}' | grep -qx "$NETWORK"; then
        echo "ERROR: la red '$NETWORK' no existe."
        exit 1
    fi
    # de contenedor a contenedor se usa el nombre y el puerto interno 5432
    DB_HOST=$CONTAINER
    DB_PORT_ENV=5432
else
    DB_HOST=127.0.0.1
    DB_PORT_ENV=$PORT
fi

# --- generar el docker-compose ----------------------------------------------
echo ""
echo "Generando $COMPOSE_FILE ..."

{
    echo "# PostgreSQL de la API - generado por instalar-postgres-docker.sh"
    echo "# Manejo: docker compose -f $COMPOSE_FILE stop|start|restart"
    echo "# Eliminar TODO (incluye datos): docker compose -f $COMPOSE_FILE down -v"
    echo ""
    echo "services:"
    echo "  postgres:"
    echo "    image: postgres:16"
    echo "    container_name: $CONTAINER"
    echo "    restart: unless-stopped"
    echo "    environment:"
    echo "      - \"POSTGRES_DB=$DB_NAME\""
    echo "      - \"POSTGRES_USER=$DB_USER\""
    echo "      - \"POSTGRES_PASSWORD=$DB_PASS\""
    echo "    ports:"
    echo "      # solo accesible desde este servidor (127.0.0.1)"
    echo "      - \"127.0.0.1:$PORT:5432\""
    echo "    volumes:"
    echo "      - pgdata:/var/lib/postgresql/data"
    if [ -n "$NETWORK" ]; then
        echo "    networks:"
        echo "      - apired"
        echo ""
        echo "networks:"
        echo "  apired:"
        echo "    external: true"
        echo "    name: $NETWORK"
    fi
    echo ""
    echo "volumes:"
    echo "  pgdata:"
    echo "    driver: local"
} > "$COMPOSE_FILE"

chmod 600 "$COMPOSE_FILE" 2>/dev/null || true

# --- levantar ---------------------------------------------------------------
echo "Levantando el contenedor..."
$DC -f "$COMPOSE_FILE" up -d

echo -n "Esperando a que PostgreSQL este listo"
for i in $(seq 1 30); do
    if docker exec "$CONTAINER" pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; then
        READY=1
        break
    fi
    echo -n "."
    sleep 2
done
echo ""

if [ -z "$READY" ]; then
    echo "ERROR: PostgreSQL no respondio a tiempo. Revise: docker logs $CONTAINER"
    exit 1
fi

# --- resumen ----------------------------------------------------------------
echo ""
echo "==================================================="
echo "  PostgreSQL quedo listo"
echo "==================================================="
echo ""
echo "La configuracion quedo guardada en: $COMPOSE_FILE"
echo ""
echo "Copie este bloque en el .env de la API:"
echo ""
echo "  DB_CONNECTION=pgsql"
echo "  DB_HOST=$DB_HOST"
echo "  DB_PORT=$DB_PORT_ENV"
echo "  DB_DATABASE=$DB_NAME"
echo "  DB_USERNAME=$DB_USER"
echo "  DB_PASSWORD=$DB_PASS"
echo ""
echo "Y luego ejecute (en la carpeta de la API):"
if [ "$DB_HOST" = "$CONTAINER" ]; then
    echo ""
    echo "  docker compose exec php_api php artisan config:clear"
    echo "  docker compose exec php_api php artisan migrate --seed"
    echo ""
    echo "NOTA: la imagen PHP de la API debe tener la extension pdo_pgsql."
    echo "Si no la tiene, reconstruya con: docker compose build php_api scheduling_api"
else
    echo ""
    echo "  php artisan config:clear"
    echo "  php artisan migrate --seed"
fi
echo ""
echo "Manejo del contenedor (desde esta carpeta):"
echo "  $DC -f $COMPOSE_FILE stop       (apagar; los datos se conservan)"
echo "  $DC -f $COMPOSE_FILE start      (encender)"
echo "  $DC -f $COMPOSE_FILE restart    (reiniciar)"
echo "  docker logs $CONTAINER            (ver el log)"
echo ""
echo "Para cambiar puerto o credenciales: edite $COMPOSE_FILE y ejecute"
echo "  $DC -f $COMPOSE_FILE up -d"
echo "(si cambia la clave, actualice tambien el .env de la API)"
echo ""
