#!/usr/bin/env bash
# Baut das Deployment-Paket für bin/deploy.sh:
#   build/app/releases/<Name>/   app/, bin/, config/ (mit config.php) → <APP_DIR>/releases/<Name>/
#   build/app/app/var/.htaccess  gemeinsames Datenverzeichnis       → <APP_DIR>/app/var/
#   build/web/                   index.php, .htaccess, app_root.php … → Webverzeichnis
#   build/web/v/<Name>/          CSS, JS, Flaggen dieser Version     → Webverzeichnis/v/<Name>/
#
# Aufruf: RELEASE=<Name> VERSION=<v1.2.3> COMMIT=<sha> APP_DIR=talkslot-app WEB_DIR=public_html bin/build.sh
# APP_DIR und WEB_DIR wie bei bin/deploy.sh (relativ zum FTP-Stammverzeichnis); daraus ergibt sich der
# Pfad, unter dem app_root.php den Code findet. Ohne Angabe: App-Verzeichnis talkslot-app/ neben dem Webverzeichnis.
# Die Konfiguration entsteht aus Umgebungsvariablen (bin/build-config.php); CONFIG_SOURCE=<Datei>
# kopiert stattdessen eine fertige config.php (lokale Tests). ALLOW_INSTALL=1 liefert install.php mit aus.
set -euo pipefail
cd "$(dirname "$0")/.."

RELEASE=${RELEASE:?RELEASE fehlt}
[[ $RELEASE =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "Ungültiger Versionsname: $RELEASE" >&2; exit 1; }
OUT=${BUILD_DIR:-build}
R="$OUT/app/releases/$RELEASE"

rm -rf "$OUT"
mkdir -p "$R/config" "$OUT/app/app/var" "$OUT/web/v/$RELEASE"

# Code und Konfiguration dieser Version (app/var gehört nicht dazu, es bleibt auf dem Server bestehen)
cp -r app bin "$R/"
rm -rf "$R/app/var"
VERSION="${VERSION:?VERSION fehlt}" COMMIT="${COMMIT:?COMMIT fehlt}" php bin/build-version.php "$R/app/version.php"
cp config/.htaccess config/config.sample.php "$R/config/"
if [ -n "${CONFIG_SOURCE:-}" ]; then
  cp "$CONFIG_SOURCE" "$R/config/config.php"
else
  php bin/build-config.php "$R/config/config.php"
fi
cp app/var/.htaccess "$OUT/app/app/var/"

# Webverzeichnis: feste Dateien; Assets unter v/<Name>/, damit jede Version ihre eigenen hat
cp -r public/. "$OUT/web/"
cp -r "$OUT/web/assets/." "$OUT/web/v/$RELEASE/"
rm -rf "$OUT/web/assets"
APP_FROM_WEB=../talkslot-app
if [ -n "${APP_DIR:-}" ] && [ -n "${WEB_DIR:-}" ]; then
  APP_FROM_WEB=$(realpath -m --relative-to="/${WEB_DIR%/}" "/${APP_DIR%/}")
fi
[[ $APP_FROM_WEB =~ ^[A-Za-z0-9._/-]+$ ]] || { echo "Ungültiger Pfad zum App-Verzeichnis: $APP_FROM_WEB" >&2; exit 1; }
sed "s#__DIR__ . '/../talkslot-app')#__DIR__ . '/$APP_FROM_WEB')#" bin/app-root.php > "$OUT/web/app_root.php"
grep -qF "'/$APP_FROM_WEB')" "$OUT/web/app_root.php" || { echo "app_root.php: Pfad nicht gesetzt" >&2; exit 1; }
cat > "$OUT/web/v/.htaccess" <<'EOF'
# Assets je Version (Pfad ändert sich mit jeder Version): dauerhaft cachebar. In FilesMatch, damit
# es die Regel für .css/.js aus der .htaccess darüber überschreibt (Files-Abschnitte gelten zuletzt).
Options -Indexes
<IfModule mod_headers.c>
  <FilesMatch "\.(css|js|svg|png|ico|woff2?)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
EOF
# Installer nur für die Erstinstallation (ALLOW_INSTALL=1) – er löscht sich nach Erfolg zwar selbst,
# käme aber mit jedem Deployment zurück
if [ "${ALLOW_INSTALL:-}" != "1" ]; then rm -f "$OUT/web/install.php"; fi

echo "Build $RELEASE: $(find "$R" -type f | wc -l) Dateien Code, $(find "$OUT/web/v/$RELEASE" -type f | wc -l) Assets"
