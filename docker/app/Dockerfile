FROM php:8.2-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git libicu-dev libzip-dev unzip \
    && docker-php-ext-install intl opcache pdo_mysql zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/app/vhost.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
