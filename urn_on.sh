#!/bin/sh
# Este paso ahora se ejecuta automaticamente con "composer install" / "composer update".
# Se conserva este script solo por compatibilidad.
php "$(dirname "$0")/urn_on.php"
