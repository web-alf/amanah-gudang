FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring gd zip curl \
    && a2enmod rewrite headers \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
