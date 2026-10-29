#!/bin/sh
set -e
mkdir -p /var/www/storage/logs
chown -R www-data:www-data /var/www/storage
chmod -R u+rwX,g+rwX /var/www/storage
# Reintento de leads pendientes (CRM y Conversions API) cada minuto.
# EasyPanel no tiene cron para servicios App; corre como www-data para no
# dejar archivos de root en el volumen. retry.php usa un candado propio.
(
  while true; do
    sleep 60
    su -s /bin/sh -c "/usr/local/bin/php /var/www/html/app/retry.php >> /var/www/storage/logs/retry.log 2>&1" www-data || true
  done
) &

exec docker-php-entrypoint "$@"
