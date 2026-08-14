# Instalación API DIAN — VPS Linux Ubuntu 22

> 🛟 Si algo falla durante la instalación, consulte **[Errores frecuentes APIDIAN](Errores%20frecuentes%20APIDIAN.md)**
> (en especial el error #6 si los certificados `.p12` dan "datos corruptos" — en Ubuntu 22 el paso 9 de este manual lo previene).

> ⚠️ **Este manual requiere Ubuntu 22.04.** Ya **no** se puede instalar en Ubuntu 20.04:
> el repositorio `ppa:ondrej/php` (de donde sale PHP 8.3) **dejó de publicar paquetes para
> Ubuntu 20.04 (focal) en junio 2025**, y los paquetes de Ubuntu 22 no funcionan en 20
> (requieren `libc6 >= 2.33`, que Ubuntu 20 no tiene). Si su servidor sigue en Ubuntu 20,
> actualícelo a 22.04 antes de continuar.

## Requisitos

- **VPS con Ubuntu 22.04** (⚠️ no sirven hostings con cPanel). Proveedores probados:
  - [hetzner.com](https://hetzner.com)
  - [contabo.com](https://contabo.com)
  - Amazon AWS
  - Google GCP
  - [digitalocean.com](https://digitalocean.com)
- **Putty** (cliente SSH)

---

## 1. Actualizar sistema, repositorios, PHP y servidor web

```bash
apt-get update
apt-get -y install software-properties-common dirmngr ca-certificates lsb-release apt-transport-https
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get -y install php8.3 php8.3-mbstring php8.3-soap php8.3-zip php8.3-mysql php8.3-curl php8.3-gd php8.3-xml php8.3-intl php8.3-imap git curl zip unzip
apt-get -y install apache2 libapache2-mod-php8.3
apt-get -y install poppler-utils
```

### Cambiar puerto de Apache (por defecto 80) — opcional

```bash
nano /etc/apache2/ports.conf
```

> Cambiar el puerto en la línea que hace mención a `Listen 80`.

```bash
service apache2 restart
```

## 2. Instalar MySQL (MariaDB)

> En Ubuntu 22 ya no existen los paquetes de MariaDB 10.3; se instala la versión
> de los repositorios oficiales (10.6), totalmente compatible con la API.

```bash
apt-get -y install mariadb-server mariadb-client
```

### Cambiar puerto de MySQL (por defecto 3306) — opcional

```bash
nano /etc/mysql/mariadb.conf.d/50-server.cnf
```

> Descomentar la línea que hace referencia a `port` y asignar el puerto de preferencia.

```bash
service mysql restart
```

## 3. Crear usuario y base de datos MySQL

```bash
mysql -u root
```

> ⚠️ **Cambie la contraseña** en lugar de `uZ78R` y guárdela.

```sql
CREATE USER 'apidian'@'%' IDENTIFIED BY 'uZ78R';
GRANT ALL PRIVILEGES ON * . * TO 'apidian'@'%';
FLUSH PRIVILEGES;
CREATE DATABASE apidian CHARACTER SET utf8 COLLATE utf8_spanish_ci;
exit
```

## 4. Instalar Composer

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

## 5. Instalar APIDIAN

```bash
cd /var/www/html/
git config --global http.sslverify false
git clone https://git.buho.la/facturalatam/co-apidian2026.git apidian
cd apidian
cp .env.example .env
nano .env
```

Cambiar la cadena de conexión en el archivo `.env`, asignando el puerto configurado anteriormente:

```ini
DB_PORT=3306
DB_DATABASE=apidian
DB_USERNAME=apidian
DB_PASSWORD=uZ78R
```

## 6. Dependencias, migraciones y storage

```bash
rm -rf vendor composer.lock
composer self-update 2.8.0
composer install
php artisan key:generate
unzip storage.zip
# aceptar los cambios al descomprimir
chmod -R 777 storage bootstrap/cache vendor/mpdf/mpdf
php artisan config:cache && php artisan cache:clear
php artisan storage:link
php artisan migrate --seed
```

> 📌 Las plantillas **"urn"** ya se aplican automáticamente con `composer install` / `composer update`.
> Solo si necesita re-aplicarlas manualmente: `php urn_on.php`

## 7. Configurar Apache (VirtualHost)

```bash
cd /etc/apache2/sites-available/
touch api.conf
nano api.conf
```

Pegar el siguiente contenido — **cambiar el puerto** si se modificó anteriormente:

```apache
<VirtualHost *:80>
    ServerAdmin admin@example.com
    DocumentRoot /var/www/html/apidian/public

    <Directory /var/www/html/apidian/public>
        Options +FollowSymlinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

Activar el sitio y reiniciar:

```bash
a2dissite 000-default.conf
a2ensite api.conf
a2enmod rewrite
service apache2 restart
```

## 8. Limpiar cachés

```bash
cd /var/www/html/apidian
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

## 9. Activar OpenSSL legacy provider (certificados `.p12`) — OBLIGATORIO en Ubuntu 22

Ubuntu 22 trae **OpenSSL 3.0**, que quitó del proveedor por defecto los algoritmos
legacy (RC2-40, 3DES+SHA1) con los que vienen cifrados los certificados `.p12` de la
DIAN. Sin este paso, al configurar el certificado la API responde
`"Error al leer la fecha del certificado - datos corruptos"` (el certificado **no**
está dañado). Este ajuste no toca los certificados, solo la configuración de OpenSSL.

```bash
cp /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf.bak
nano /etc/ssl/openssl.cnf
```

1. Cerca del inicio del archivo, asegurar que esta línea exista y esté **sin** `#`:

```ini
openssl_conf = openssl_init
```

2. Al final del archivo, agregar (o ajustar si ya existen las secciones):

```ini
[openssl_init]
providers = provider_sect

[provider_sect]
default = default_sect
legacy = legacy_sect

[default_sect]
activate = 1

[legacy_sect]
activate = 1
```

Reiniciar Apache:

```bash
service apache2 restart
```

Para verificar, un certificado de prueba debe leerse sin error:

```bash
openssl pkcs12 -info -in /ruta/al/certificado.p12 -noout
```
