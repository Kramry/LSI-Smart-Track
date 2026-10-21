ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-apache

# The SmartTrack schema and every query in SmartTrack_model are MySQL specific.
RUN docker-php-ext-install pdo pdo_mysql

# mod_rewrite drives the front controller routes (/login, /manage/..., /documents/...).
RUN a2enmod rewrite headers

# Allow .htaccess overrides so public/.htaccess is honoured.
RUN sed -ri 's!^(\s*AllowOverride)\s+None!\1 All!g' /etc/apache2/apache2.conf

# Application files
COPY . /var/www/html/

# Apache vhost: document root is public/, everything else is routed to the front controller.
COPY docker/apache-smarttrack.conf /etc/apache2/sites-available/000-default.conf

# PHP tuning
COPY docker/php-smarttrack.ini /usr/local/etc/php/conf.d/zz-smarttrack.ini

# Never ship a .env inside the image. The framework loads .env with putenv(), which would
# override the environment variables configured in Render.
# The project root .htaccess only applies when the document root is the project root,
# which is not the case here, so it is removed to keep the deployed surface minimal.
RUN rm -f /var/www/html/.env /var/www/html/.htaccess

# Readable application tree, writable runtime directories (sessions, cache, logs, uploads).
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R u+rwX,go+rX /var/www/html \
    && mkdir -p runtime/cache runtime/logs runtime/session runtime/uploads/private \
    && chown -R www-data:www-data runtime \
    && chmod -R 770 runtime

EXPOSE 80

CMD ["apache2-foreground"]
