FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork headers rewrite \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
COPY docker-entrypoint.sh /usr/local/bin/vascao-entrypoint
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/vascao-uploads.ini

RUN chmod +x /usr/local/bin/vascao-entrypoint \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["vascao-entrypoint"]
CMD ["apache2-foreground"]
