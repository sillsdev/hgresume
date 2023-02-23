FROM php:7.4-apache
EXPOSE 80
# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
#todo use chorus version instead
RUN apt-get update && apt-get install -y mercurial
RUN a2enmod rewrite
COPY --chown=www-data api /var/www/html/api/v03/
