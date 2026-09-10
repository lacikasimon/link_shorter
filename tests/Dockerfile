FROM wordpress:php8.3-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite
RUN echo 'opcache.enable=0' > /usr/local/etc/php/conf.d/zz-test.ini
ENTRYPOINT []
CMD ["apache2-foreground"]
