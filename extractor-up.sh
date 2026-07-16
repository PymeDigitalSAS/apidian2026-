#!/usr/bin/env bash
# ============================================================
# extractor-up.sh
#
# Levanta el worker "cufes-worker" en la MISMA red de Docker en la que ya corre
# el contenedor php_api, sin que tengas que saber cómo se llama esa red:
#   • Instalación API + facturador -> normalmente "proxynet"
#   • Instalación solo API         -> normalmente "apinet"
#
# El script inspecciona php_api, detecta su red real y se la pasa al compose.
#
# Uso:
#   ./extractor-up.sh              # levanta / recrea el worker
#   ./extractor-up.sh down         # lo detiene
#   ./extractor-up.sh logs         # ve los logs
# ============================================================
set -euo pipefail

cd "$(dirname "$0")"
COMPOSE_FILE="docker-compose.extractor.yml"

# --- Detectar la red del contenedor php_api -----------------------------------
# Tomamos la primera red "real" (descartando bridge/host/none).
NET="$(docker inspect php_api \
  --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' 2>/dev/null \
  | grep -vE '^(bridge|host|none|)$' | head -n1 || true)"

if [ -z "${NET:-}" ]; then
  echo "✖ No pude detectar la red del contenedor 'php_api'."
  echo "  ¿Está corriendo el API en Docker?  (docker ps | grep php_api)"
  echo "  Si sabes el nombre de la red, levántalo a mano:"
  echo "    EXTRACTOR_NETWORK=<tu-red> docker compose -f $COMPOSE_FILE up -d --build"
  exit 1
fi

export EXTRACTOR_NETWORK="$NET"
echo "==> php_api está en la red: $EXTRACTOR_NETWORK"

ACTION="${1:-up}"
case "$ACTION" in
  up)
    echo "==> Levantando cufes-worker en esa red..."
    docker compose -f "$COMPOSE_FILE" up -d --build
    echo "==> Verificando que el API lo alcance..."
    if docker compose exec -T php_api curl -fsS http://cufes-worker:3001/api/jobs >/dev/null 2>&1; then
      echo "✔ OK: php_api -> http://cufes-worker:3001 responde."
      echo "  Deja en el .env del API:  EXTRACTOR_URL=http://cufes-worker:3001"
      echo "  y luego:  docker compose exec php_api php artisan config:clear"
    else
      echo "⚠ El worker se levantó pero php_api todavía no lo alcanza."
      echo "  Espera unos segundos (arranque de Chrome) y reintenta el curl."
    fi
    ;;
  down)
    docker compose -f "$COMPOSE_FILE" down
    ;;
  logs)
    docker compose -f "$COMPOSE_FILE" logs -f
    ;;
  *)
    echo "Uso: $0 [up|down|logs]"
    exit 1
    ;;
esac
