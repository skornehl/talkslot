# Operations: the health check as the condition for keeping a deployed version (expert review P0-5).
# The switch-back on a wrong database password or a failing migration: tests/deploy.sh
echo "Health check (deployment)"
OP_LATEST=$(grep -o 'LATEST = [0-9]*' "$(dirname "$0")/../app/src/Migrations.php" | grep -o '[0-9]*$')
OP_TOK=$($DC exec -T app php -r 'require "/srv/app/app/bootstrap.php"; echo App\Health::token();')
OP_CTOK=$($DC exec -T app php -r 'require "/srv/app/app/bootstrap.php"; echo App\App::cronToken();')
op_health() { curl -s -D - -d '' -H "X-Health-Token: $OP_TOK" "$B/health"; }
op_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
# op_is <response> <HTTP status> <status word>: status line and the body with nothing but the status
op_is() { [ "$(printf '%s\n' "$1" | head -1 | cut -d' ' -f2)" = "$2" ] && [ "$(printf '%s\n' "$1" | tail -1)" = "{\"status\":\"$3\"}" ]; }
OP_SETTING() { DBQ "SELECT value FROM tp_settings WHERE name = '$1'"; }

OP_H=$(op_health)
check "health check: 404 without key, with a wrong one, the cron key or via GET (like the web cron); with the key 200 and only the status" \
  '[ "$(op_code -d "" "$B/health")" = 404 ] && [ "$(op_code -d "" -H "X-Health-Token: falsch" "$B/health")" = 404 ] && [ "$(op_code -d "" -H "X-Health-Token: $OP_CTOK" "$B/health")" = 404 ] && [ "$(op_code -H "X-Health-Token: $OP_TOK" "$B/health")" = 404 ] && op_is "$OP_H" 200 ok'
check "health check: JSON, never cached, no cookie" \
  'echo "$OP_H" | grep -qi "^content-type: application/json" && echo "$OP_H" | grep -qi "^cache-control: no-store" && ! echo "$OP_H" | grep -qi "^set-cookie"'

# The clean-up fallback of page requests is due (last run long ago), but neither the health check nor the web
# cron (even without its key) starts it
OP_LC=$(OP_SETTING last_cleanup)
DBQ "INSERT INTO tp_settings (name, value) VALUES ('last_cleanup', '1') ON DUPLICATE KEY UPDATE value = '1'"
op_health > /dev/null; op_code -d '' -H "X-Cron-Token: falsch" "$B/cron" > /dev/null; op_code "$B/health" > /dev/null
check "health check and web cron do not start the clean-up fallback of page requests" '[ "$(OP_SETTING last_cleanup)" = 1 ]'
DBQ "UPDATE tp_settings SET value = '${OP_LC:-1}' WHERE name = 'last_cleanup'"

# A migration that fails is reported (503 + alert mail) instead of being swallowed; here the settings table is
# missing. Without the lock file the same database counts as empty: before the installer, which the first
# deployment must pass.
DBQ "RENAME TABLE tp_settings TO tp_settings_op"
OP_H=$(op_health)
ALERTS 'rename("/srv/app/app/var/installed.lock", "/srv/app/app/var/installed.lock.op");'
OP_H2=$(op_health)
ALERTS 'rename("/srv/app/app/var/installed.lock.op", "/srv/app/app/var/installed.lock");'
DBQ "RENAME TABLE tp_settings_op TO tp_settings"
OP_ALERTS='$q = urlencode("to:betrieb@example.org subject:\"Health-Check: Migration\""); $u = "http://mail:8025/api/v1/search?query=$q";'
OP_AL=$(ALERTS "$OP_ALERTS"' echo json_decode(file_get_contents($u), true)["messages_count"] ?? "?";')
check "health check: failed migration → 503 migration_failed and an alert mail ($OP_AL)" 'op_is "$OP_H" 503 migration_failed && [ "$OP_AL" = 1 ]'
check "health check: empty database before the installer → 200 not_installed" 'op_is "$OP_H2" 200 not_installed'
ALERTS "$OP_ALERTS"' file_get_contents($u, false, stream_context_create(["http" => ["method" => "DELETE"]]));' # expected, not an error

# Another request holds the migration lock: the health check waits for it (10 s), then reports the schema as
# outdated instead of passing, so that the deployment tries again
DBQ "SELECT GET_LOCK('tp_migrate', 0), SLEEP(60)" > /dev/null 2>&1 &
OP_BG=$!
OP_CONN=''
for _ in $(seq 50); do OP_CONN=$(DBQ "SELECT IS_USED_LOCK('tp_migrate')"); [ -n "$OP_CONN" ] && [ "$OP_CONN" != NULL ] && break; sleep 0.2; done
DBQ "UPDATE tp_settings SET value = '$((OP_LATEST - 1))' WHERE name = 'schema_version'"
OP_H=$(op_health)
DBQ "UPDATE tp_settings SET value = '$OP_LATEST' WHERE name = 'schema_version'"
check "health check: migration still running elsewhere → 503 schema_outdated with Retry-After" \
  'op_is "$OP_H" 503 schema_outdated && echo "$OP_H" | grep -qi "^retry-after: 30"'
[[ $OP_CONN =~ ^[0-9]+$ ]] && DBQ "KILL $OP_CONN" 2> /dev/null # ends the session holding the lock
wait "$OP_BG" 2> /dev/null
check "health check: ok again once the migrations are through" 'op_is "$(op_health)" 200 ok'
