FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    wireguard-tools \
    && docker-php-ext-install \
    pdo \
    zip \
    intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/var && chmod -R 777 /var/www/html/var

CMD ["php-fpm"]
