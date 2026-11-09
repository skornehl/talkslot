#!/usr/bin/env bash
# Runs a command with PHP of the given version: the pinned command-line image of dev/php-cli.Dockerfile, with
# the project directory mounted at /app as the working directory. CI, the deploy workflow and dev/phpstan.sh
# use it; locally it stands in for a PHP installation.
#   dev/php.sh 8.3 php tests/unit.php
#   dev/php.sh 7.4 php -l app/bootstrap.php
#   PHP_USER="$(id -u):$(id -g)" dev/php.sh 8.3 ...   files it writes belong to that user, not to root
set -euo pipefail
V=${1:?usage: dev/php.sh <version> <command> [arguments]}
shift
cd "$(dirname "$0")/.."
# Built from stdin without a build context: the file only selects and pins an image
docker build -q --target "php-$V" -t "talkslot-php:$V" - < dev/php-cli.Dockerfile > /dev/null
exec docker run --rm ${PHP_USER:+-u "$PHP_USER"} -v "$PWD:/app" -w /app "talkslot-php:$V" "$@"
