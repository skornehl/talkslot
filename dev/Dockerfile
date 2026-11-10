# Local test environment (dev/compose.yml): database, Mailpit and PHP + Apache with mod_rewrite/headers (like
# shared hosting). Every image is pinned by its digest, so a moved tag cannot change what CI runs; Dependabot
# (.github/dependabot.yml) proposes new digests. Versions that CI tests:
#   PHP_VERSION      8.5 (default, current) or 8.3 (the minimum, App\Platform)
#   MARIADB_VERSION  11.8 (default, current) or 10.6 (the minimum, App\Health::MIN_MARIADB)
ARG PHP_VERSION=8.5
ARG MARIADB_VERSION=11.8

FROM mariadb:11.8@sha256:c4124d1afa875e47f2892edf26b482fa54f796e2f342d3717a04ca091a22a9bd AS mariadb-11.8
FROM mariadb:10.6@sha256:f1c56f035ed268a0d7c2c2e06dc879f6319b997cb434cf822a4cfd72a7aa184d AS mariadb-10.6
FROM mariadb-${MARIADB_VERSION} AS db

# Web interface showing all mails sent by the app; its sendmail mode is the mail() transport of the app container
FROM axllent/mailpit:v1.27@sha256:e22dce5b36f93c77082e204a3942fb6b283b7896e057458400a4c88344c3df68 AS mail

FROM php:8.5-apache@sha256:304eac8c3e14c194d05e1d67e756b641051d31d6848dd74db47dfae95f3142b8 AS php-8.5
FROM php:8.3-apache@sha256:df17bb6139ae1667c9d9c042a4f0bf9e4e3767e0a940fadc7b17727e6e55c418 AS php-8.3
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
