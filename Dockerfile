FROM php:8.3-apache

RUN a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork headers rewrite \
    && docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html
COPY docker-entrypoint.sh /usr/local/bin/vascao-entrypoint

RUN chmod +x /usr/local/bin/vascao-entrypoint \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["vascao-entrypoint"]
CMD ["apache2-foreground"]
