ARG PHP_VERSION=8.5

FROM composer:2 AS vendor
WORKDIR /app
COPY LavaLust-dev-v4/ /app/
RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN php vendor/tecnickcom/tc-lib-pdf-font/util/build_fonts.php
RUN test -d vendor/tecnickcom/tc-lib-pdf-font/target/fonts

FROM php:${PHP_VERSION}-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite headers

RUN sed -ri 's!^(\s*AllowOverride)\s+None!\1 All!g' /etc/apache2/apache2.conf

COPY LavaLust-dev-v4/ /var/www/html/
COPY --from=vendor /app/vendor/ /var/www/html/vendor/

COPY LavaLust-dev-v4/docker/apache-smarttrack.conf /etc/apache2/sites-available/000-default.conf
COPY LavaLust-dev-v4/docker/php-smarttrack.ini /usr/local/etc/php/conf.d/zz-smarttrack.ini

RUN rm -f /var/www/html/.env /var/www/html/.htaccess

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R u+rwX,go+rX /var/www/html \
    && mkdir -p /var/www/html/runtime/cache /var/www/html/runtime/logs /var/www/html/runtime/session /var/www/html/runtime/uploads/private \
    && chown -R www-data:www-data /var/www/html/runtime \
    && chmod -R 770 /var/www/html/runtime

EXPOSE 80

CMD ["apache2-foreground"]
