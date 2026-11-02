#!/usr/bin/env bash
# Test für das atomare Deployment (bin/build.sh + bin/deploy.sh) ohne echten FTP-Server: lftp lädt per
# file:// in ein Temp-Verzeichnis, das ein PHP-Testserver ausliefert. Prüft erstes Deployment, Umschalten
# unter Last, Rollback („Schalter“), automatisches Zurückschalten, Zeiger-Notfälle, Aufräumen.
#
# Aufruf: tests/deploy.sh   Voraussetzung: lftp, curl und die lokale Datenbank (wie tests/smoke.sh);
# die Konfiguration kommt aus config/config.php.
set -uo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
command -v lftp > /dev/null || { echo "lftp fehlt (apt install lftp)"; exit 2; }
T=$(mktemp -d)
PORT=${DEPLOY_TEST_PORT:-8093}
ROOT=$T/server
BASE=$ROOT/talkslot-app
WEB=$ROOT/public_html
URL=http://127.0.0.1:$PORT
export FTP_URL=file://$ROOT APP_DIR=talkslot-app/ WEB_DIR=public_html/ BASE_URL=$URL BUILD_DIR=$T/build FTP_USER=
SERVER_PID=''
cleanup() { rm -f "$T/load.on"; [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2> /dev/null; wait 2> /dev/null; rm -rf "$T"; }
trap cleanup EXIT

php -r '$c = require $argv[1]; $c["base_url"] = $argv[2]; $c["debug"] = false; $c["force_https"] = false;
  file_put_contents($argv[3], "<?php\nreturn " . var_export($c, true) . ";\n");' "$REPO/config/config.php" "$URL" "$T/config.php"
# Router: vorhandene Dateien direkt, interne Dateien gesperrt (wie .htaccess), sonst index.php
cat > "$T/router.php" <<'EOF'
<?php
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#(^|/)\.|app_root\.php$#', $path)) { http_response_code(403); return true; }
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) { return false; }
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
EOF

FAIL=0
ok() { if eval "$2"; then echo "  ✓ $1"; else echo "  ✗ $1"; FAIL=$((FAIL + 1)); fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$URL$1"; }
html() { curl -s "$URL$1"; }
build() { (cd "$REPO" && RELEASE=$1 VERSION=$2 COMMIT=abcdef1 CONFIG_SOURCE=$T/config.php bin/build.sh > /dev/null); }
dep() { (cd "$REPO" && timeout 120 bin/deploy.sh "$@"); }
# Last während Upload/Umschalten: jede Antwort ≠ 200 ist ein Fehler
load_start() { : > "$T/codes"; touch "$T/load.on"; for p in /admin/login / /admin/login; do
  ( while [ -f "$T/load.on" ]; do curl -s -o /dev/null -w '%{http_code}\n' "$URL$p" >> "$T/codes"; done ) & done; }
load_stop() { rm -f "$T/load.on"; sleep 0.5; }
only200() { [ -s "$T/codes" ] && ! grep -qv '^200$' "$T/codes"; }

# Leerer Server; bis zum ersten Deployment liefert das Webverzeichnis nur einen Platzhalter
mkdir -p "$WEB" && echo '<?php echo "leer";' > "$WEB/index.php"
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$WEB" "$T/router.php" > "$T/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 50); do curl -s -o /dev/null "$URL/" && break; sleep 0.1; done

echo "Erstes Deployment"
A=20261102-100000-v1.9.0; build $A v1.9.0
load_start; dep upload $A > "$T/out" 2>&1; rc=$?; load_stop
ok 'Upload vollständig, unter Last nur 200er' '[ $rc = 0 ] && grep -q "vollständig" "$T/out" && only200'
ok 'nach dem Upload noch nicht aktiv' '[ "$(html /admin/login)" = leer ] && [ ! -e "$BASE/current" ]'
load_start; dep activate $A > "$T/out" 2>&1; rc=$?; load_stop
ok 'Umschalten ok, unter Last nur 200er' '[ $rc = 0 ] && only200' || cat "$T/out"
ok 'Zeiger auf A, A wird ausgeliefert' '[ "$(cat "$BASE/current")" = $A ] && html /admin/login | grep -q "/v/$A/css/app.css" && [ "$(code /v/$A/css/app.css)" = 200 ] && html / | grep -q v1.9.0'
ok 'app_root.php findet das App-Verzeichnis (aus APP_DIR/WEB_DIR)' 'grep -qF "/../talkslot-app'"'"')" "$WEB/app_root.php" && [ ! -e "$WEB/assets" ]'
ok 'app/var gemeinsam (Sessions, Logs), Version ohne eigenes app/var' '[ -f "$BASE/app/var/.htaccess" ] && [ ! -e "$BASE/releases/$A/app/var" ]'

echo "Nächste Version"
B=20261102-110000-v1.9.1; build $B v1.9.1
load_start; dep upload $B > "$T/out" 2>&1 && dep activate $B > "$T/out" 2>&1; rc=$?; load_stop
ok 'B aktiv, unter Last nur 200er' '[ $rc = 0 ] && [ "$(cat "$BASE/current")" = $B ] && html / | grep -q v1.9.1 && only200'
ok 'A bleibt für einen Rollback liegen' '[ -d "$BASE/releases/$A" ] && [ -d "$WEB/v/$A" ]'
ok 'list markiert die aktive' 'dep list | grep -q "^\* $B" && dep list | grep -q "^  $A"'

echo "Schalter (Rollback)"
load_start; dep switch previous > "$T/out" 2>&1; rc=$?; load_stop
ok 'previous → A, unter Last nur 200er' '[ $rc = 0 ] && [ "$(cat "$BASE/current")" = $A ] && html / | grep -q v1.9.0 && only200'
ok 'keine ältere Version → Fehler, nichts geändert' '! dep switch previous > /dev/null 2>&1 && [ "$(cat "$BASE/current")" = $A ]'
ok 'zurück auf B per Name' 'dep switch $B > /dev/null 2>&1 && [ "$(cat "$BASE/current")" = $B ]'
ok 'aktive Version erneut: nichts zu tun' 'dep switch $B 2>&1 | grep -q "bereits aktiv"'
ok 'unbekannte oder ungültige Version abgelehnt' '! dep switch nichtda > /dev/null 2>&1 && ! dep switch ../x > /dev/null 2>&1 && [ "$(cat "$BASE/current")" = $B ]'

echo "Kaputte Version"
C=20261102-120000-v1.9.2; build $C v1.9.2
echo '<?php throw new Error("kaputt");' > "$T/build/app/releases/$C/app/src/App.php"
dep upload $C > /dev/null 2>&1; dep activate $C > "$T/out" 2>&1; rc=$?
ok 'Prüfung schlägt fehl → zurück auf B' '[ $rc != 0 ] && grep -q "zurückgeschaltet auf $B" "$T/out" && [ "$(cat "$BASE/current")" = $B ] && html / | grep -q v1.9.1'
ok 'kaputte Version gelöscht' '[ ! -e "$BASE/releases/$C" ] && [ ! -e "$WEB/v/$C" ]'
D=20261102-123000-v1.9.2; build $D v1.9.2; dep upload $D > /dev/null 2>&1
ok 'discard löscht eine nicht aktivierte Version, nie die aktive' 'dep discard $D > /dev/null 2>&1 && [ ! -e "$BASE/releases/$D" ] && ! dep discard $B > /dev/null 2>&1 && [ -d "$BASE/releases/$B" ]'

echo "Zeiger-Notfälle (app_root.php)"
rm "$BASE/current"
ok 'Zeiger fehlt → neueste vorhandene Version' '[ "$(code /admin/login)" = 200 ] && html /admin/login | grep -q "/v/$B/"'
echo "../../etc" > "$BASE/current"
ok 'ungültiger Zeiger → neueste vorhandene Version' 'html /admin/login | grep -q "/v/$B/"'
echo $B > "$BASE/current"

echo "Aufräumen"
for n in 20261102-130000-v1.9.3 20261102-140000-v1.9.4; do build $n v0; KEEP=2 dep upload $n > /dev/null 2>&1; KEEP=2 dep activate $n > /dev/null 2>&1; done
ok 'nur die KEEP neuesten Versionen bleiben (Code und Assets)' '[ "$(ls "$BASE/releases" | tr "\n" " ")" = "20261102-130000-v1.9.3 20261102-140000-v1.9.4 " ] && [ "$(ls "$WEB/v" | tr "\n" " ")" = "20261102-130000-v1.9.3 20261102-140000-v1.9.4 " ]'

echo
[ $FAIL = 0 ] && echo "Deploy-Test: alles ok" || echo "Deploy-Test: $FAIL Fehler"
exit $FAIL
