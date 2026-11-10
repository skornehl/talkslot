# PHP on the command line for CI, the deploy workflow and dev/phpstan.sh: lint, unit tests, static analysis.
# One stage per version, pinned by digest (Dependabot, .github/dependabot.yml, proposes new digests):
#   docker build -q -f dev/php-cli.Dockerfile --target php-8.3 -t talkslot-php:8.3 dev
# 8.3 to 8.5 are supported (App\Platform); 7.4 and 8.2 only check that old versions get a clear message.
FROM php:8.5-cli@sha256:50956f094e90a716d43bea28087d447555ad80510e386ab8c9c10d8c70d781a9 AS php-8.5
FROM php:8.4-cli@sha256:d63e8b80cd4c6cfd01cfa7c910522e299cdc7ca1a2b6d6563db48df3c17e6c55 AS php-8.4
FROM php:8.3-cli@sha256:628102249d93494233b54490b785f12db9cd60f4dc89416dfca15973f2125f10 AS php-8.3
FROM php:8.2-cli@sha256:604de2f0cba9e9dde16cf717c2f03b0948c99949bdf7c11cdf76326e0da7d2ea AS php-8.2
FROM php:7.4-cli@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5 AS php-7.4
