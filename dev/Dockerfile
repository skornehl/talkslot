# Local test environment: PHP 8.5 + Apache with mod_rewrite/headers (like shared hosting)
FROM php:8.5-apache
# COVERAGE=1 (CI): line coverage with PCOV, collected by tests/coverage/prepend.php for every request and
# CLI script, merged by tests/coverage/report.php. Development only, never part of the app.
ARG COVERAGE=0
RUN docker-php-ext-install pdo_mysql \
 && if [ "$COVERAGE" = 1 ]; then \
      pecl install pcov-1.0.12 && docker-php-ext-enable pcov \
      && printf '%s\n' 'pcov.enabled=1' 'pcov.directory=/srv/app' 'pcov.exclude="~/(vendor|tests|dev|build)/~"' \
           'auto_prepend_file=/srv/app/tests/coverage/prepend.php' 'opcache.enable=0' > /usr/local/etc/php/conf.d/zz-coverage.ini; \
    fi \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/srv/app/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!<Directory /var/www/>!<Directory /srv/app/public/>!; s!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf
