#!/usr/bin/env bash
# Coding standard with PHP-CS-Fixer (.php-cs-fixer.dist.php; CI workflow "Coding standard", expert review P3-4).
#   dev/cs.sh        check: lists the files that do not follow it, with the diff, and fails
#   dev/cs.sh --fix  change those files
# PHP-CS-Fixer is a development tool, never part of the app or of a build: the pinned PHAR is downloaded from the
# PHP-CS-Fixer releases on GitHub into dev/data/ (not in Git) and its checksum checked on every run. It runs with
# the local php, or in Docker (php:8.3-cli, the oldest supported version) if there is none or CS_DOCKER=1 is set.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=3.95.27
SHA256=47231757eefdd08778e2bd047d98a63dfaad881006c8c1d1bc870d63be7f6a9b
PHAR=dev/data/php-cs-fixer-$VERSION.phar
if [ ! -f "$PHAR" ]; then
  mkdir -p dev/data
  curl -sSfL --retry 3 -o "$PHAR.part" "https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v$VERSION/php-cs-fixer.phar"
  mv "$PHAR.part" "$PHAR"
fi
if ! echo "$SHA256  $PHAR" | sha256sum -c --status; then
  rm -f "$PHAR"
  echo "$PHAR: checksum mismatch, file removed" >&2
  exit 1
fi
ARGS=(fix --config=.php-cs-fixer.dist.php --show-progress=none)
if [ "${1:-}" != --fix ]; then
  ARGS+=(--dry-run --diff)
fi
if [ -z "${CS_DOCKER:-}" ] && command -v php > /dev/null; then
  php "$PHAR" "${ARGS[@]}"
else
  docker run --rm -v "$PWD:/app" -w /app -u "$(id -u):$(id -g)" php:8.3-cli php "$PHAR" "${ARGS[@]}"
fi
