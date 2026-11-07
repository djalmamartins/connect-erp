FROM php:8.2-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev libpng-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd intl mbstring opcache pdo_mysql zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/app/vhost.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
