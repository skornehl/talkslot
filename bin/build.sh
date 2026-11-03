#!/usr/bin/env bash
# Builds the deployment package for bin/deploy.sh:
#   build/app/releases/<name>/   app/, bin/, config/ (with config.php) → <APP_DIR>/releases/<name>/
#   build/app/app/var/.htaccess  shared data directory                 → <APP_DIR>/app/var/
#   build/web/                   index.php, .htaccess, app_root.php …  → web directory
#   build/web/v/<name>/          CSS, JS, flags of this version         → web directory/v/<name>/
#
# Usage: RELEASE=<name> VERSION=<v1.2.3> COMMIT=<sha> APP_DIR=talkslot-app WEB_DIR=public_html bin/build.sh
# APP_DIR and WEB_DIR as for bin/deploy.sh (relative to the FTP root); they determine the path
# under which app_root.php finds the code. If not set: app directory talkslot-app/ next to the web directory.
# The configuration is generated from environment variables (bin/build-config.php); CONFIG_SOURCE=<file>
# copies a ready-made config.php instead (local tests). ALLOW_INSTALL=1 also ships install.php.
set -euo pipefail
cd "$(dirname "$0")/.."

RELEASE=${RELEASE:?RELEASE missing}
[[ $RELEASE =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "Invalid version name: $RELEASE" >&2; exit 1; }
OUT=${BUILD_DIR:-build}
R="$OUT/app/releases/$RELEASE"

rm -rf "$OUT"
mkdir -p "$R/config" "$OUT/app/app/var" "$OUT/web/v/$RELEASE"

# Code and configuration of this version (app/var is not part of it, it stays on the server)
cp -r app bin "$R/"
rm -rf "$R/app/var"
VERSION="${VERSION:?VERSION missing}" COMMIT="${COMMIT:?COMMIT missing}" php bin/build-version.php "$R/app/version.php"
cp config/.htaccess config/config.sample.php "$R/config/"
if [ -n "${CONFIG_SOURCE:-}" ]; then
  cp "$CONFIG_SOURCE" "$R/config/config.php"
else
  php bin/build-config.php "$R/config/config.php"
fi
cp app/var/.htaccess "$OUT/app/app/var/"

# Web directory: fixed files; assets under v/<name>/ so that every version has its own
cp -r public/. "$OUT/web/"
cp -r "$OUT/web/assets/." "$OUT/web/v/$RELEASE/"
rm -rf "$OUT/web/assets"
APP_FROM_WEB=../talkslot-app
if [ -n "${APP_DIR:-}" ] && [ -n "${WEB_DIR:-}" ]; then
  APP_FROM_WEB=$(realpath -m --relative-to="/${WEB_DIR%/}" "/${APP_DIR%/}")
fi
[[ $APP_FROM_WEB =~ ^[A-Za-z0-9._/-]+$ ]] || { echo "Invalid path to the app directory: $APP_FROM_WEB" >&2; exit 1; }
sed "s#__DIR__ . '/../talkslot-app')#__DIR__ . '/$APP_FROM_WEB')#" bin/app-root.php > "$OUT/web/app_root.php"
grep -qF "'/$APP_FROM_WEB')" "$OUT/web/app_root.php" || { echo "app_root.php: path not set" >&2; exit 1; }
cat > "$OUT/web/v/.htaccess" <<'EOF'
# Per-version assets (the path changes with every version): cacheable forever. Inside FilesMatch so
# that it overrides the .css/.js rule from the .htaccess above (Files sections are applied last).
Options -Indexes
<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|svg|png|ico|woff2?)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
EOF
# Installer only for the initial installation (ALLOW_INSTALL=1) – it deletes itself after success,
# but would come back with every deployment
if [ "${ALLOW_INSTALL:-}" != "1" ]; then rm -f "$OUT/web/install.php"; fi

echo "Build $RELEASE: $(find "$R" -type f | wc -l) code files, $(find "$OUT/web/v/$RELEASE" -type f | wc -l) assets"
