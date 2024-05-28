FROM php:8-apache-bookworm
EXPOSE 80
# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
#todo use chorus version instead
RUN apt-get update  \
    && apt-get install -y mercurial time \
    && apt-get autoremove --purge -y  \
    && apt-get clean  \
    && rm -rf /var/lib/apt/lists
RUN a2enmod rewrite
RUN mkdir -p /var/cache/hgresume/ /var/vcs/ && chown -R www-data:www-data /var/cache/hgresume/ /var/vcs/
COPY --chown=www-data api /var/www/html/api/v03/
# cache volume
VOLUME /var/cache/hgresume
# repos volume
VOLUME /var/vcs/public