FROM php:8.4-fpm-bookworm

RUN apt-get update && apt-get install -y \
    git unzip curl libpng-dev libonig-dev libxml2-dev zip \
    && docker-php-ext-install pdo_mysql mbstring bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/log/php /var/log/php-fpm \
    && chown -R www-data:www-data /var/log/php /var/log/php-fpm

COPY deploy/configs/php-production.ini /usr/local/etc/php/conf.d/99-production.ini
COPY deploy/configs/php-fpm-www.conf /usr/local/etc/php-fpm.d/zz-email-server.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
