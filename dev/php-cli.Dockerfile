# PHP on the command line for CI, the deploy workflow and dev/phpstan.sh: lint, unit tests, static analysis.
# One stage per version, pinned by digest (Dependabot, .github/dependabot.yml, proposes new digests):
#   docker build -q -f dev/php-cli.Dockerfile --target php-8.3 -t talkslot-php:8.3 dev
# 8.3 to 8.5 are supported (App\Platform); 7.4 and 8.2 only check that old versions get a clear message.
FROM php:8.5-cli@sha256:7e06a1ead42d3225c83569c2049fd343dc0587df09e8656dad551ecc115d046c AS php-8.5
FROM php:8.4-cli@sha256:ff91dd4b2730676b1673c4cb033b1c0702e1654798ab9bd264db05dab2927228 AS php-8.4
FROM php:8.3-cli@sha256:7d334491cf2f46f6c93ac63f5e16664d8edb46bd016b40a67e089fe9a6c893cd AS php-8.3
FROM php:8.2-cli@sha256:e6bdf20d1af4c15f83013c550d1a73d8113b4f14d4bfcfed399cefdb6bf0712d AS php-8.2
FROM php:7.4-cli@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5 AS php-7.4
