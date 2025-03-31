FROM php:8.2-fpm

# Instalar dependencias
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    intl \
    zip \
    opcache

# Configurar timezone
RUN ln -sf /usr/share/zoneinfo/Europe/Madrid /etc/localtime

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /var/www/html

# Copiar el código fuente
COPY . /var/www/html/

# Establecer permisos para Symfony
RUN chmod -R 777 /var/www/html/var
RUN chmod -R 777 /var/www/html/public

# Instalar dependencias con Composer
RUN composer install --no-interaction --optimize-autoloader

# Configurar Symfony para producción si es necesario
# RUN composer dump-env prod

# Exponer puerto PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]