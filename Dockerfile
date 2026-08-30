FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY . /var/www/html/

RUN a2enmod rewrite

RUN mkdir -p /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads

EXPOSE 80