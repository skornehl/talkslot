#!/usr/bin/env bash
# Static analysis with PHPStan (tests/phpstan.neon; CI job "Static analysis", expert review P1-14).
#   dev/phpstan.sh                       analyse: fails on every error that is not in tests/phpstan-baseline.neon
#   dev/phpstan.sh --generate-baseline   write the current errors to the baseline (e.g. after fixing some of them)
# PHPStan is a development tool, never part of the app or of a build: the pinned PHAR is downloaded from the
# PHPStan releases on GitHub into dev/data/ (not in Git) and its checksum checked on every run. It runs with the
# local php, or in Docker (PHP 8.3, dev/php.sh) if there is none or PHPSTAN_DOCKER=1 is set.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=2.3.1
SHA256=85157dba3d95d77ca603d2b7303aa78a538f9e3b56ccad07dd794c53aa96d1ae
PHAR=dev/data/phpstan-$VERSION.phar
if [ ! -f "$PHAR" ]; then
  mkdir -p dev/data
  PART=$(mktemp dev/data/phpstan.XXXXXX) # a file of its own: two runs at once do not write into the same one
  # The same file (the checksum below decides) from the repository of the package if the release download fails
  curl -sSfL --retry 3 --retry-delay 5 -o "$PART" "https://github.com/phpstan/phpstan/releases/download/$VERSION/phpstan.phar" \
    || curl -sSfL --retry 3 --retry-delay 5 -o "$PART" "https://raw.githubusercontent.com/phpstan/phpstan/$VERSION/phpstan.phar" \
    || { rm -f "$PART"; exit 1; }
  mv "$PART" "$PHAR"
fi
if ! echo "$SHA256  $PHAR" | sha256sum -c --status; then
  rm -f "$PHAR"
  echo "$PHAR: checksum mismatch, file removed" >&2
  exit 1
fi
ARGS=(analyse -c tests/phpstan.neon --no-progress --memory-limit=1G)
if [ "${1:-}" = --generate-baseline ]; then
  ARGS+=(--generate-baseline tests/phpstan-baseline.neon)
elif [ -n "${GITHUB_ACTIONS:-}" ]; then
  ARGS+=(--error-format=github) # annotations on the lines of the pull request
fi
if [ -z "${PHPSTAN_DOCKER:-}" ] && command -v php > /dev/null; then
  php "$PHAR" "${ARGS[@]}"
else
  dev/php.sh 8.3 php "$PHAR" "${ARGS[@]}"
fi
