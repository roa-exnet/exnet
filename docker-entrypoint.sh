#!/bin/sh
set -e

mkdir -p /var/www/html/var/cache
mkdir -p /var/www/html/var/log
mkdir -p /var/www/html/var/data
mkdir -p /var/www/html/public

if [ -d "/var/www/html" ]; then
    chown -R www-data:www-data /var/www/html
    
    find /var/www/html -type d -exec chmod 775 {} \;
    find /var/www/html -type f -exec chmod 664 {} \;
    
    chmod -R 777 /var/www/html/var
    
    [ -d "/var/www/html/var/cache" ] && chmod -R 777 /var/www/html/var/cache
    [ -d "/var/www/html/var/log" ] && chmod -R 777 /var/www/html/var/log
    [ -d "/var/www/html/var/data" ] && chmod -R 777 /var/www/html/var/data
    
    if [ -f "/var/www/html/bin/console" ]; then
        chmod +x /var/www/html/bin/console
    fi
    
    [ -d "/var/www/html/public" ] && chmod -R 775 /var/www/html/public
    
    echo "Permisos establecidos correctamente para Symfony"
fi

exec "$@"