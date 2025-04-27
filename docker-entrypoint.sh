#!/bin/sh
set -e

if [ -d "/var/www/html" ]; then
    chown -R www-data:www-data /var/www/html
    find /var/www/html -type d -exec chmod 755 {} \;
    find /var/www/html -type f -exec chmod 644 {} \;
    chmod -R 777 /var/www/html/var

    if [ -d "/var/www/html/public" ]; then
        chmod -R 775 /var/www/html/public
    fi

    if [ -d "/var/www/html/var/cache" ]; then
        chmod -R 777 /var/www/html/var/cache
    fi

    if [ -d "/var/www/html/var/log" ]; then
        chmod -R 777 /var/www/html/var/log
    fi
fi

# -----------------------------------------------------------
# NUEVO: Activar WireGuard si existe configuración
# -----------------------------------------------------------

if [ -f /etc/wireguard/wg0.conf ]; then
    echo "[+] Archivo WireGuard encontrado, levantando VPN..."
    wg-quick up wg0 || { echo "[-] Error al levantar WireGuard"; exit 1; }
else
    echo "[!] No se encontró /etc/wireguard/wg0.conf. Continuando sin VPN..."
fi

# Ejecutar el comando original (php-fpm)
exec "$@"
