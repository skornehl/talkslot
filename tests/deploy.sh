#!/usr/bin/env bash
# Test for the atomic deployment (bin/build.sh + bin/deploy.sh) without a real FTP server: lftp uploads via
# file:// into a temp directory served by a PHP test server. Checks the first deployment, switching
# under load, rollback (the "switch"), automatic switch-back, pointer emergencies, cleanup, the
# permissions of config.php and deleting old versions after changing secrets (prune).
#
# Usage: tests/deploy.sh   Requires: lftp, curl and the local database (like tests/smoke.sh);
# the configuration is taken from config/config.php.
set -uo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
command -v lftp > /dev/null || { echo "lftp missing (apt install lftp)"; exit 2; }
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
# Router: existing files directly, internal files blocked (like .htaccess), otherwise index.php
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
# Load during upload/switch: every response ≠ 200 is a failure
load_start() { : > "$T/codes"; touch "$T/load.on"; for p in /admin/login / /admin/login; do
  ( while [ -f "$T/load.on" ]; do curl -s -o /dev/null -w '%{http_code}\n' "$URL$p" >> "$T/codes"; done ) & done; }
load_stop() { rm -f "$T/load.on"; sleep 0.5; }
only200() { [ -s "$T/codes" ] && ! grep -qv '^200$' "$T/codes"; }

# Empty server; until the first deployment the web directory only serves a placeholder
mkdir -p "$WEB" && echo '<?php echo "empty";' > "$WEB/index.php"
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$WEB" "$T/router.php" > "$T/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 50); do curl -s -o /dev/null "$URL/" && break; sleep 0.1; done

echo "First deployment"
A=20261102-100000-v1.9.0; build $A v1.9.0
load_start; dep upload $A > "$T/out" 2>&1; rc=$?; load_stop
ok 'upload complete, only 200s under load' '[ $rc = 0 ] && grep -q "Uploaded and complete" "$T/out" && only200'
ok 'not active yet after upload' '[ "$(html /admin/login)" = empty ] && [ ! -e "$BASE/current" ]'
mode() { stat -c %a "$BASE/releases/$1/$2"; }
ok 'config.php only readable for its owner right after the upload (600)' '[ "$(mode $A config/config.php)" = 600 ] && grep -q "config.php: permissions 600 in $A" "$T/out"'
load_start; dep activate $A > "$T/out" 2>&1; rc=$?; load_stop
ok 'switch ok, only 200s under load' '[ $rc = 0 ] && only200' || cat "$T/out"
ok 'pointer to A, A is served' '[ "$(cat "$BASE/current")" = $A ] && html /admin/login | grep -q "/v/$A/css/app.css" && [ "$(code /v/$A/css/app.css)" = 200 ] && html / | grep -q v1.9.0'
ok 'app_root.php finds the app directory (from APP_DIR/WEB_DIR)' 'grep -qF "/../talkslot-app'"'"')" "$WEB/app_root.php" && [ ! -e "$WEB/assets" ]'
ok 'app/var shared (sessions, logs), version without its own app/var' '[ -f "$BASE/app/var/.htaccess" ] && [ ! -e "$BASE/releases/$A/app/var" ]'

echo "Next version"
B=20261102-110000-v1.9.1; build $B v1.9.1
ok 'invalid CONFIG_MODE (injection, readable for others, not for the owner) rejected before the upload' \
  '(for m in "600; rm -r x" 644 666 0604 200; do ! CONFIG_MODE=$m dep upload $B > /dev/null 2>&1 || exit 1; done) && [ ! -e "$BASE/releases/$B" ]'
chmod 644 "$BASE/releases/$A/config/config.php" # like a version uploaded before CONFIG_MODE existed
load_start; CONFIG_MODE=640 dep upload $B > "$T/out" 2>&1; rc=$?; AMODE=$(mode $A config/config.php)
[ $rc = 0 ] && CONFIG_MODE=640 dep activate $B > "$T/out" 2>&1; rc=$?; load_stop
ok 'B active, only 200s under load' '[ $rc = 0 ] && [ "$(cat "$BASE/current")" = $B ] && html / | grep -q v1.9.1 && only200'
ok 'A is kept for a rollback' '[ -d "$BASE/releases/$A" ] && [ -d "$WEB/v/$A" ]'
ok 'CONFIG_MODE=640: group may read config.php (PHP via the group)' '[ "$(mode $B config/config.php)" = 640 ]'
ok 'older version: config.php untouched by the upload, CONFIG_MODE once the new version passed the check' \
  '[ "$AMODE" = 644 ] && [ "$(mode $A config/config.php)" = 640 ]'
ok 'list marks the active one' 'dep list | grep -q "^\* $B" && dep list | grep -q "^  $A"'

echo "Switch (rollback)"
load_start; dep switch previous > "$T/out" 2>&1; rc=$?; load_stop
ok 'previous → A, only 200s under load' '[ $rc = 0 ] && [ "$(cat "$BASE/current")" = $A ] && html / | grep -q v1.9.0 && only200'
ok 'no older version → error, nothing changed' '! dep switch previous > /dev/null 2>&1 && [ "$(cat "$BASE/current")" = $A ]'
ok 'back to B by name' 'dep switch $B > /dev/null 2>&1 && [ "$(cat "$BASE/current")" = $B ]'
ok 'active version again: nothing to do' 'dep switch $B 2>&1 | grep -q "already active"'
ok 'unknown or invalid version rejected' '! dep switch nichtda > /dev/null 2>&1 && ! dep switch ../x > /dev/null 2>&1 && [ "$(cat "$BASE/current")" = $B ]'

echo "Broken version"
C=20261102-120000-v1.9.2; build $C v1.9.2
echo '<?php throw new Error("broken");' > "$T/build/app/releases/$C/app/src/App.php"
dep upload $C > /dev/null 2>&1; dep activate $C > "$T/out" 2>&1; rc=$?
ok 'check fails → back to B, hint at CONFIG_MODE' '[ $rc != 0 ] && grep -q "switched back to $B" "$T/out" && grep -q "set CONFIG_MODE to 640" "$T/out" && [ "$(cat "$BASE/current")" = $B ] && html / | grep -q v1.9.1'
ok 'check failed: older versions keep their permissions (the rollback target stays readable for PHP)' '[ "$(mode $B config/config.php)" = 640 ] && [ "$(mode $A config/config.php)" = 640 ]'
ok 'broken version deleted' '[ ! -e "$BASE/releases/$C" ] && [ ! -e "$WEB/v/$C" ]'
D=20261102-123000-v1.9.2; build $D v1.9.2; CONFIG_MODE=keep dep upload $D > "$T/out" 2>&1
ok 'CONFIG_MODE=keep: permissions of config.php left to the server' '! grep -q "config.php: permissions" "$T/out" && [ "$(mode $D config/config.php)" = "$(mode $D app/bootstrap.php)" ]'
ok 'discard deletes a never-activated version, never the active one' 'dep discard $D > /dev/null 2>&1 && [ ! -e "$BASE/releases/$D" ] && ! dep discard $B > /dev/null 2>&1 && [ -d "$BASE/releases/$B" ]'
E=20261102-124500-v1.9.2; build $E v1.9.2; rm "$T/build/app/releases/$E/config/config.php"
dep upload $E > "$T/out" 2>&1; rc=$?
ok 'chmod fails (here: no config.php) → only a warning, the upload goes on' '[ $rc = 0 ] && grep -q "::warning::Could not set the permissions of config.php to 600 in $E" "$T/out" && grep -q "Uploaded and complete: $E" "$T/out"'
dep discard $E > /dev/null 2>&1

echo "Pointer emergencies (app_root.php)"
rm "$BASE/current"
ok 'pointer missing → newest existing version' '[ "$(code /admin/login)" = 200 ] && html /admin/login | grep -q "/v/$B/"'
echo "../../etc" > "$BASE/current"
ok 'invalid pointer → newest existing version' 'html /admin/login | grep -q "/v/$B/"'
echo $B > "$BASE/current"

echo "Cleanup"
for n in 20261102-130000-v1.9.3 20261102-140000-v1.9.4; do build $n v0; KEEP=2 dep upload $n > /dev/null 2>&1; KEEP=2 dep activate $n > /dev/null 2>&1; done
ok 'only the KEEP newest versions remain (code and assets)' '[ "$(ls "$BASE/releases" | tr "\n" " ")" = "20261102-130000-v1.9.3 20261102-140000-v1.9.4 " ] && [ "$(ls "$WEB/v" | tr "\n" " ")" = "20261102-130000-v1.9.3 20261102-140000-v1.9.4 " ]'

echo "Prune (after changing secrets)"
echo "../../etc" > "$BASE/current"
ok 'active version unknown → nothing deleted' '! dep prune > /dev/null 2>&1 && [ "$(ls "$BASE/releases" | wc -l)" = 2 ]'
echo 20261102-140000-v1.9.4 > "$BASE/current"
ok 'prune deletes every version except the active one (code and assets), the site keeps running' 'dep prune > /dev/null 2>&1 && [ "$(ls "$BASE/releases")" = 20261102-140000-v1.9.4 ] && [ "$(ls "$WEB/v")" = 20261102-140000-v1.9.4 ] && [ "$(code /admin/login)" = 200 ]'
ok 'prune with only the active version: nothing to do' 'dep prune 2>&1 | grep -q "Only the active version is left: 20261102-140000-v1.9.4"'

echo
[ $FAIL = 0 ] && echo "Deploy test: all ok" || echo "Deploy test: $FAIL failed"
exit $FAIL
