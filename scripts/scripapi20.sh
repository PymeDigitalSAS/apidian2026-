#!/bin/bash

# ============================================================
# Instalacion del API (apidian) con Docker + dominio
# Ubuntu 20.04 | incluye extractor de CUFEs (cufes-worker)
#
# Uso: bash scripapi20.sh midominio.com
#   -> el API queda en http(s)://api.midominio.com
#
# Ejecutar desde un directorio padre (NO dentro de un repo clonado):
# crea ./apidian/, ./setup/ y el docker-compose.yml en este directorio.
# ============================================================

#-------------------------------------PARAMETRO OBLIGATORIO------------------------------------------------------------
HOST=${1:-'dominio'}
if [ "$HOST" = "dominio" ]; then
    echo no ha ingresado dominio, vuelva a ejecutar el script agregando un dominio como primer parametro
    exit 1
fi

DEFAULT_PORT='3306'
read -p "Ingrese el puerto de MySQL o Enter para 3306 por defecto [$DEFAULT_PORT]: " MYSQL_PORT_HOST
MYSQL_PORT_HOST=${MYSQL_PORT_HOST:-$DEFAULT_PORT}


#-------------------------------------URL DE REPOSITORIOS--------------------------------------------------------------
URL_API='https://git.buho.la/facturalatam/co-apidian2026.git'

#-------------------------------------RUTA DE INSTALACION (RUTA ACTUAL DEL SCRIPT)-------------------------------------
PATH_CURRENT=$(echo $PWD)

#-------------------------------------NOMBRE DE CARPETAS----------------------------------------------------------------
DIR_API='apidian'

#-------------------------------------SET DATA--------------------------------------------------------------------------
generate_password() {
    head /dev/urandom | tr -dc A-Za-z0-9 | head -c "$1" ; echo ''
}

MYSQL_ROOT_PASSWORD=$(generate_password 20)
MYSQL_API_PASSWORD=$(generate_password 20)

# URL con la que el API alcanza al worker del extractor de CUFEs (cufes-worker):
# comparten la red de Docker, asi que se resuelve por nombre de servicio.
EXTRACTOR_URL="http://cufes-worker:3001"

#-------------------------------------SET .env--------------------------------------------------------------------------
cat << EOF > $PATH_CURRENT/.env
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
MYSQL_PORT_HOST=$MYSQL_PORT_HOST
HOST=$HOST
EOF

#-------------------------------------INSTALACION DE DEPENDENCIAS-----------------------------------------------------------
apt-get -y update
apt-get -y install git-core letsencrypt zip unzip apt-transport-https ca-certificates curl gnupg-agent software-properties-common
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo apt-key add -
add-apt-repository "deb [arch=amd64] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable"
apt-get -y update
apt-get -y install docker-ce
systemctl start docker
systemctl enable docker
curl -L "https://github.com/docker/compose/releases/download/1.28.0/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose
docker network create proxynet

#-------------------------------------ESTRUCTURA DEL PROYECTO----------------------------------------------------------------
mkdir -p "$PATH_CURRENT/setup/certs" "$PATH_CURRENT/setup/mysql" "$PATH_CURRENT/setup/fpms/$DIR_API"

cat << EOF > $PATH_CURRENT/setup/mysql/init.sql
CREATE DATABASE IF NOT EXISTS $DIR_API;
CREATE USER '$DIR_API'@'%' IDENTIFIED BY '$MYSQL_API_PASSWORD';
GRANT ALL PRIVILEGES ON $DIR_API.* TO '$DIR_API'@'%';
FLUSH PRIVILEGES;
EOF

#-------------------------------------CLONANDO API--------------------------------------------------------------------------
echo "Clonando API"
git clone $URL_API $PATH_CURRENT/$DIR_API
cd $PATH_CURRENT/$DIR_API
cp .env.example .env

#-------------------------------------SET API .env--------------------------------------------------------------------------
sed -i "/APP_URL=/c\APP_URL=http://api.$HOST" .env
sed -i "/APP_DEBUG=/c\APP_DEBUG=false" .env
sed -i "/DB_HOST=/c\DB_HOST=mariadb" .env
# de contenedor a contenedor siempre se usa el puerto interno 3306
# (MYSQL_PORT_HOST es solo el puerto publicado en el servidor)
sed -i "/DB_PORT=/c\DB_PORT=3306" .env
sed -i "/DB_DATABASE=/c\DB_DATABASE=$DIR_API" .env
sed -i "/DB_USERNAME=/c\DB_USERNAME=$DIR_API" .env
sed -i "/DB_PASSWORD=/c\DB_PASSWORD=$MYSQL_API_PASSWORD" .env

# EXTRACTOR_URL: apunta al worker cufes-worker que se levanta junto al API.
# Si la clave ya existe en el .env se reemplaza; si no, se agrega al final.
if grep -q '^EXTRACTOR_URL=' .env; then
    sed -i "s#^EXTRACTOR_URL=.*#EXTRACTOR_URL=$EXTRACTOR_URL#" .env
else
    echo "EXTRACTOR_URL=$EXTRACTOR_URL" >> .env
fi

unzip storage.zip

#-------------------------------------SET EXTRACTOR (worker de CUFEs)--------------------------------------------------------
# El codigo del worker vive en extractcode/ dentro del repo del API. Su .env esta
# gitignored (no existe tras el clone): se genera desde el ejemplo, que ya trae
# CHROME_HEADLESS=false (anti-bot DIAN) y las pausas anti-bloqueo por defecto.
echo "Configurando worker del extractor (extractcode)"
cp extractcode/.env.example extractcode/.env

#-------------------------------------SET docker-compose PRINCIPAL-------------------------------------------------------
echo "Seteando data inicial"
cd $PATH_CURRENT
cat << EOF > $PATH_CURRENT/docker-compose.yml
version: '3.3'

services:
  proxy:
    image: rash07/nginx-proxy:2.0
    container_name: proxy
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - $PATH_CURRENT/setup/certs:/etc/nginx/certs
      - /var/run/docker.sock:/tmp/docker.sock:ro
    restart: always
    privileged: true
    networks:
      - webnet

  nginx_api:
    image: rash07/nginx-api
    container_name: nginx_api
    working_dir: /var/www/html
    environment:
      - VIRTUAL_HOST=api.$HOST
    volumes:
      - $PATH_CURRENT/$DIR_API:/var/www/html
      - $PATH_CURRENT/setup/fpms/$DIR_API:/etc/nginx/sites-available
    restart: always
    networks:
      - webnet

  php_api:
    build:
      context: ./$DIR_API
      dockerfile: Dockerfile
    container_name: php_api
    working_dir: /var/www/html
    volumes:
      - $PATH_CURRENT/$DIR_API:/var/www/html
    restart: always
    networks:
      - webnet

  scheduling_api:
    build:
      context: ./$DIR_API
      dockerfile: Dockerfile
    container_name: scheduling_api
    working_dir: /var/www/html
    user: "www-data"
    command: sh -c "(while true; do php artisan queue:work database --tries=1 --timeout=3600 --max-time=3600 --no-interaction; sleep 5; done) & while true; do php artisan monitor:snapshot --no-interaction; sleep 10; done"
    pid: "host"
    volumes:
      - $PATH_CURRENT/$DIR_API:/var/www/html
    restart: always
    networks:
      - webnet

  mariadb:
    image: mariadb:10.5.6
    container_name: mariadb
    environment:
      - MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
    ports:
      - "$MYSQL_PORT_HOST:3306"
    volumes:
      - mysqldata:/var/lib/mysql
      - $PATH_CURRENT/setup/mysql/init.sql:/docker-entrypoint-initdb.d/init.sql
    restart: always
    networks:
      - webnet

  cufes-worker:
    build:
      context: ./$DIR_API/extractcode
      dockerfile: Dockerfile
    image: cufes-worker:latest
    container_name: cufes-worker
    restart: unless-stopped
    shm_size: "1gb"
    mem_limit: "2g"
    environment:
      - NODE_OPTIONS=--openssl-legacy-provider
      - CHROME_HEADLESS=false
    ports:
      - "127.0.0.1:3001:3001"
    networks:
      - webnet
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:3001/api/jobs || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 25s

networks:
  webnet:
    external:
      name: proxynet

volumes:
  mysqldata:
    driver: "local"
EOF

#-------------------------------------SET API .conf--------------------------------------------------------------
cat << EOF > $PATH_CURRENT/setup/fpms/$DIR_API/default
server {
    listen 80 default_server;
    root /var/www/html/public;
    index index.html index.htm index.php;
    server_name api.$HOST;
    charset utf-8;
    server_tokens off;

    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }
    location = /robots.txt {
        log_not_found off;
        access_log off;
    }
    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }
    location ~ \.php$\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass php_api:9000;
    }
    error_page 404 /index.php;
    location ~ /\.ht {
        deny all;
    }
}
EOF

echo "Iniciando servicios"
docker-compose -f $PATH_CURRENT/docker-compose.yml up -d

#-------------------------------------ajustes api-------------------------------------------------------
echo "Ejecutando ajustes en el APIDIAN"
docker-compose exec -T php_api composer self-update 2.8.0
docker-compose exec -T php_api rm -rf vendor composer.lock
docker-compose exec -T php_api composer install
cd "$PATH_CURRENT/$DIR_API"
cp resources/templates/xml/urn/*.* resources/templates/xml
cp vendor/ubl21dian/torresoftware/src/XAdES/urn/*.* vendor/ubl21dian/torresoftware/src/XAdES
cd "$PATH_CURRENT"
docker-compose exec -T php_api chmod -R 777 vendor/mpdf/mpdf storage bootstrap/cache
docker-compose exec -T php_api php artisan migrate:refresh --seed
docker-compose exec -T php_api php artisan key:generate
docker-compose exec -T php_api php artisan storage:link
docker-compose exec -T php_api php artisan optimize:clear
docker-compose exec -T php_api chmod 700 urn_on.sh
docker-compose exec -T php_api bash -c "./urn_on.sh"
docker-compose exec -T php_api chown -R www-data:www-data storage bootstrap/cache

#-------------------------------------verificacion extractor-------------------------------------------------------
echo "Verificando conexion API -> extractor (cufes-worker)"
if docker-compose exec -T php_api curl -fsS http://cufes-worker:3001/api/jobs >/dev/null 2>&1; then
    echo "OK: php_api alcanza al worker en $EXTRACTOR_URL"
else
    echo "AVISO: el worker del extractor aun no responde (el arranque de Chrome puede tardar)."
    echo "       Verificar en unos segundos con:"
    echo "       docker-compose exec php_api curl -s http://cufes-worker:3001/api/jobs"
fi

#----------------------------------- SSL-----------------------------------------------------------------
read -p "instalar SSL gratuito? si[s] no[n]: " ssl
if [ "$ssl" = "s" ]; then

    echo "--IMPORTANTE--"
    echo "--------------"
    echo "Copiar los TXT sin usar [ctrl+c] ya que cancelará el proceso"
    echo "Ingresar correo electronico y aceptar las preguntas"
    echo "--------------"

    certbot certonly --manual -d api.$HOST --agree-tos --no-bootstrap --manual-public-ip-logging-ok --preferred-challenges dns-01 --server https://acme-v02.api.letsencrypt.org/directory

    echo "Configurando SSL"

    if ! [ -f /etc/letsencrypt/live/api.$HOST/privkey.pem ]; then

        echo "No se ha generado el certificado gratuito"
    else
        cp /etc/letsencrypt/live/api.$HOST/privkey.pem $PATH_CURRENT/setup/certs/api.$HOST.key
        cp /etc/letsencrypt/live/api.$HOST/fullchain.pem $PATH_CURRENT/setup/certs/api.$HOST.crt

        cd "$PATH_CURRENT/$DIR_API"
        sed -i "/APP_URL=/c\APP_URL=https://api.$HOST" .env
        sed -i '/FORCE_HTTPS=/c\FORCE_HTTPS=true' .env
        cd "$PATH_CURRENT"
        docker-compose exec -T php_api php artisan config:cache
        docker-compose exec -T php_api php artisan cache:clear

        echo "Reiniciando servicios de Nginx"
        docker restart proxy

    fi

fi

echo "Finalizando despliegue"

cat << EOF > $PATH_CURRENT/setup/private.txt
MYSQL
----------------------------------
DB_PORT     = $MYSQL_PORT_HOST
DB_USERNAME = root
DB_PASSWORD = $MYSQL_ROOT_PASSWORD

API
----------------------------------
PATH = $PATH_CURRENT/$DIR_API
URL  = api.$HOST

EXTRACTOR (worker de CUFEs)
----------------------------------
CODIGO        = $PATH_CURRENT/$DIR_API/extractcode
CONTENEDOR    = cufes-worker (puerto 3001, solo localhost)
EXTRACTOR_URL = $EXTRACTOR_URL  (configurado en el .env del API)
ENV WORKER    = $PATH_CURRENT/$DIR_API/extractcode/.env

NOTAS EXTRACTOR
----------------------------------
- El API expone las rutas del extractor con el token de cada empresa
  (ver docs/API_EXTRACTOR.md); el worker no se expone a internet.
- Chrome corre headful bajo Xvfb (CHROME_HEADLESS=false, anti-bot DIAN).
- Documentacion de integracion: docs/EXTRACTOR_INTEGRACION.md

EOF

echo "----------------------------------"
cat $PATH_CURRENT/setup/private.txt

ADMIN_FILE="$PATH_CURRENT/$DIR_API/usuario_admin.txt"
if [ -f "$ADMIN_FILE" ]; then
    echo "USUARIO ADMIN"
    echo "----------------------------------"
    cat "$ADMIN_FILE"
fi
