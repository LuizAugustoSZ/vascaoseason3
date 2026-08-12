FROM php:8.3-apache

RUN a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork headers rewrite \
    && docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
