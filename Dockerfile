FROM serversideup/php:8.3-fpm-nginx

USER root
RUN install-php-extensions mongodb
USER www-data

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

COPY --chown=www-data:www-data . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative
