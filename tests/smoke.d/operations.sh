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
# cron (even with a wrong key) starts it
OP_LC=$(OP_SETTING last_cleanup)
DBQ "INSERT INTO tp_settings (name, value) VALUES ('last_cleanup', '1') ON DUPLICATE KEY UPDATE value = '1'"
op_health > /dev/null; op_code -d '' -H "X-Cron-Token: falsch" "$B/cron" > /dev/null; op_code -H "X-Health-Token: falsch" "$B/health" > /dev/null
check "health check and web cron do not start the clean-up fallback of page requests" '[ "$(OP_SETTING last_cleanup)" = 1 ]'
DBQ "UPDATE tp_settings SET value = '${OP_LC:-1}' WHERE name = 'last_cleanup'"

# New rounds never get /health or /cron (Slug::RESERVED, unit test); a round that got one of them before keeps
# its link: only a request with the key header is the health check or the web cron
DBQ "UPDATE tp_rounds SET slug = 'health' WHERE id = $RID"
OP_RP=$(curl -s -w '\n%{http_code}' "$B/health")
OP_H=$(op_health)
DBQ "UPDATE tp_rounds SET slug = 'cron' WHERE id = $RID"
OP_RC=$(curl -s -w '\n%{http_code}' "$B/cron")
OP_CW=$(op_code -d '' -H "X-Cron-Token: falsch" "$B/cron")
DBQ "UPDATE tp_rounds SET slug = '$SLUG' WHERE id = $RID"
check "a round at /health or /cron from before keeps its page; with the key header the address is the health check or the web cron" \
  'for p in "$OP_RP" "$OP_RC"; do [ "$(echo "$p" | tail -1)" = 200 ] && echo "$p" | grep -q "Elternsprechtag Größe Übung" || exit 1; done && op_is "$OP_H" 200 ok && [ "$OP_CW" = 404 ]'

# A migration that fails is reported by the health check (503 + alert mail) instead of being swallowed, while
# pages are still served (that is why the deployment cannot rely on them); here the settings table is missing.
# Without the lock file the same database counts as empty: before the installer, which the first deployment
# must pass.
OP_T0=$(date +%s)
DBQ "RENAME TABLE tp_settings TO tp_settings_op"
OP_H=$(op_health)
OP_PAGE=$(op_code "$B/admin/login")
ALERTS 'rename("/srv/app/app/var/installed.lock", "/srv/app/app/var/installed.lock.op");'
OP_H2=$(op_health)
OP_CRON=$(curl -s -d '' -H "X-Cron-Token: $OP_CTOK" "$B/cron")
ALERTS 'rename("/srv/app/app/var/installed.lock.op", "/srv/app/app/var/installed.lock");'
DBQ "RENAME TABLE tp_settings_op TO tp_settings"
OP_ALERTS='$q = urlencode("to:betrieb@example.org subject:\"Health-Check: Migration\""); $u = "http://mail:8025/api/v1/search?query=$q";'
OP_AL=$(ALERTS "$OP_ALERTS"' echo json_decode(file_get_contents($u), true)["messages_count"] ?? "?";')
check "health check: failed migration → 503 migration_failed and an alert mail ($OP_AL); the login page is still served ($OP_PAGE)" \
  'op_is "$OP_H" 503 migration_failed && [ "$OP_AL" = 1 ] && [ "$OP_PAGE" = 200 ]'
check "empty database before the installer: health check 200 not_installed, web cron not_installed" \
  'op_is "$OP_H2" 200 not_installed && [ "$OP_CRON" = "{\"status\":\"not_installed\"}" ]'
# The report was expected, not an error: delete the mail and the throttle entries written since (a later check
# expecting a report from the same place would be suppressed for an hour otherwise)
ALERTS "$OP_ALERTS"' file_get_contents($u, false, stream_context_create(["http" => ["method" => "DELETE"]]));
  $f = "/srv/app/app/var/alerts.json"; $s = json_decode((string) @file_get_contents($f), true);
  if (is_array($s)) { $n = count($s["keys"] ?? []); $s["keys"] = array_filter($s["keys"] ?? [], fn ($e) => $e["last"] < '"$OP_T0"');
    $s["_count"] = max(0, ($s["_count"] ?? 0) - $n + count($s["keys"])); file_put_contents($f, json_encode($s)); }'
OP_ALS=$(ALERTS '$s = json_decode((string) @file_get_contents("/srv/app/app/var/alerts.json"), true); echo count(array_filter($s["keys"] ?? [], fn ($e) => $e["last"] >= '"$OP_T0"'));')
check "the expected report leaves no throttle entry behind" '[ "$OP_ALS" = 0 ]'

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
