# Local test environment (dev/compose.yml): database, Mailpit and PHP + Apache with mod_rewrite/headers (like
# shared hosting). Every image is pinned by its digest, so a moved tag cannot change what CI runs; Dependabot
# (.github/dependabot.yml) proposes new digests. Versions that CI tests:
#   PHP_VERSION      8.5 (default, current) or 8.3 (the minimum, App\Platform)
#   MARIADB_VERSION  11.8 (default, current) or 10.6 (the minimum, App\Health::MIN_MARIADB)
ARG PHP_VERSION=8.5
ARG MARIADB_VERSION=11.8

FROM mariadb:11.8@sha256:d36f18d65a0459510b8b0f643b226a6d299e3206486e2b64ff20754a6d291707 AS mariadb-11.8
FROM mariadb:10.6@sha256:c7d726a1c0d29e486a1ab4ef0c76c75431a11428e76c50e99ba32106cea04114 AS mariadb-10.6
FROM mariadb-${MARIADB_VERSION} AS db

# Web interface showing all mails sent by the app; its sendmail mode is the mail() transport of the app container
FROM axllent/mailpit:v1.33@sha256:aef2aacc11f075ec0fb6874275ee4a421e688e11a96ebb296134d1735c7761ce AS mail

FROM php:8.5-apache@sha256:08af36df144462d84c7f16190557eec06cb9f656882685610b712a15eec54d46 AS php-8.5
FROM php:8.3-apache@sha256:a800bbd2c0aa322702259ed475556b2cbc311076a8d3c4bba9bfe96838745472 AS php-8.3
FROM php-${PHP_VERSION} AS app
# mail(): PHP hands the mail to Mailpit's sendmail replacement (transport "mail", CI job "Minimum platform")
COPY --from=mail /mailpit /usr/local/bin/mailpit
# COVERAGE=1 (CI): line coverage with PCOV, collected by tests/coverage/prepend.php for every request and
# CLI script, merged by tests/coverage/report.php. Development only, never part of the app.
ARG COVERAGE=0
RUN docker-php-ext-install pdo_mysql \
 && printf '%s\n' 'sendmail_path = "/usr/local/bin/mailpit sendmail -S mail:1025"' > /usr/local/etc/php/conf.d/zz-mail.ini \
 && if [ "$COVERAGE" = 1 ]; then \
      pecl install pcov-1.0.12 && docker-php-ext-enable pcov \
      && printf '%s\n' 'pcov.enabled=1' 'pcov.directory=/srv/app' 'pcov.exclude="~/(vendor|tests|dev|build)/~"' \
           'auto_prepend_file=/srv/app/tests/coverage/prepend.php' 'opcache.enable=0' > /usr/local/etc/php/conf.d/zz-coverage.ini; \
    fi \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/srv/app/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!<Directory /var/www/>!<Directory /srv/app/public/>!; s!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf
