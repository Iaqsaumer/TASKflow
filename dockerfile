FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/tasks \
    && chmod -R 777 /var/www/html/uploads

RUN a2enmod rewrite

EXPOSE 80