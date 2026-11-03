# Local test environment: PHP 8.5 + Apache with mod_rewrite/headers (like shared hosting)
FROM php:8.5-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/srv/app/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!<Directory /var/www/>!<Directory /srv/app/public/>!; s!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf
