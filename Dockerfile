# Crea este archivo como C:\Users\ruptur\Desktop\exnet-core-31-03-2025-v2\exnet-core\Dockerfile
FROM php:8.2-fpm

# Instalar dependencias
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    && docker-php-ext-install \
    pdo \
    zip \
    intl

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /var/www/html

# Establecer permisos para Symfony
RUN mkdir -p /var/www/html/var && chmod -R 777 /var/www/html/var

CMD ["php-fpm"]