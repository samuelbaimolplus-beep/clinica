#!/bin/sh
set -eu
cd /var/www/html
# libpq busca certificados opcionales en HOME. Los procesos www-data deben poder recorrerlo.
export HOME=/var/www

case "${PORT:-10000}" in
    ''|*[!0-9]*) echo 'PORT debe ser un número.' >&2; exit 1 ;;
esac
export PORT="${PORT:-10000}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:10000>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:clear
php deploy/validate.php
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
runuser -u www-data -- php deploy/check-database.php
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/clinica.conf
