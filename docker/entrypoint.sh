#!/bin/sh
set -e
mkdir -p /var/www/storage/logs
chown -R www-data:www-data /var/www/storage
chmod -R u+rwX,g+rwX /var/www/storage
exec docker-php-entrypoint "$@"
