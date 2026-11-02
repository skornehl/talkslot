# Lokale Testumgebung: PHP 8.5 + Apache mit mod_rewrite/headers (wie Shared Hosting)
FROM php:8.5-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/srv/app/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!<Directory /var/www/>!<Directory /srv/app/public/>!; s!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf
