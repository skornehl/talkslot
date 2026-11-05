# Operations: no silent data loss (see README.md in this directory)
echo "Operations: automatic deletion only for rounds in use"
# Expert review P0-6: the clean-up lets only rounds expire that were in use (active, activated before or
# with an entry by the teacher). A copy that keeps the old dates, or any other draft, stays. The round page
# and the overview say when a round will be deleted. Rounds of this file are called "Betrieb …".
T=$(curl -s -b "$J" "$B/admin" | csrf)
OP_PAST=$(date -d '-3 day' +%F); OP_NEXT=$(date -d '+15 day' +%F)
op_round() { # op_round <title> <active 0/1> <date> [teacher id] → id of the new round (10:00–11:00, two slots)
  curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$1" -d "is_active=$2&user_id=${4:-}&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&day_dates%5B%5D=$3&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$'
}
op_entry() { # op_entry <round> <name>: entry by the teacher in the first free slot
  curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "name=$2" -d "lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$1" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
}
op_used() { DBQ "SELECT GROUP_CONCAT(id, ':', was_used ORDER BY id) FROM tp_rounds WHERE id IN ($1)"; }
OPA=$(op_round "Betrieb aktiv" 1 "$OP_PAST")
OPD=$(op_round "Betrieb Entwurf" 0 "$OP_PAST")
OPE=$(op_round "Betrieb Eintrag" 0 "$OP_PAST"); op_entry "$OPE" "Betrieb Eintrag"
OPX=$(op_round "Betrieb deaktiviert" 1 "$OP_NEXT")
OPB=$(op_round "Betrieb gebucht" 0 "$OP_NEXT"); op_entry "$OPB" "Betrieb Buchung"
OPX_PAGE=$(curl -s -b "$J" "$B/admin/round/$OPX")
check "active round: the round page says when it will be deleted (0:00 after the last day + 24 h)" 'echo "$OPX_PAGE" | grep -q "Runde wird am [A-Za-z]*, $(date -d "$OP_NEXT +2 day" +%d.%m.%Y), 00:00 Uhr gelöscht (24 h nach Ablauf)"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$OPX/toggle"
DBQ "UPDATE tp_round_days SET day_date = '$OP_PAST' WHERE round_id = $OPX"
# Deactivated accounts unused for 15 months: a draft must not keep them forever (it no longer expires)
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('betrieb-entwurf@example.org', 'x', 'teacher', 'Frau Entwurf', 'betrieb-entwurf@example.org', 1, NOW()), ('betrieb-genutzt@example.org', 'x', 'teacher', 'Herr Genutzt', 'betrieb-genutzt@example.org', 1, NOW())"
OPU1=$(DBQ "SELECT id FROM tp_users WHERE username = 'betrieb-entwurf@example.org'"); OPU2=$(DBQ "SELECT id FROM tp_users WHERE username = 'betrieb-genutzt@example.org'")
OPW1=$(op_round "Betrieb Konto Entwurf" 0 "$OP_NEXT" "$OPU1"); OPW2=$(op_round "Betrieb Konto genutzt" 1 "$OP_NEXT" "$OPU2")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$OPW2/toggle"
DBQ "UPDATE tp_users SET is_active = 0, last_login_at = NOW() - INTERVAL 2 YEAR WHERE id IN ($OPU1, $OPU2)"
OPC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$OPA/duplicate" | grep -o '[0-9]*$')
check "in use: activated (also when deactivated again) or with an entry; not: drafts and the copy of a round" '[ "$(op_used "$OPA,$OPD,$OPE,$OPX,$OPB,$OPC")" = "$OPA:1,$OPD:0,$OPE:1,$OPX:1,$OPB:1,$OPC:0" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$OPX")" = 0 ]'
check "draft: the round page says it is not deleted automatically" 'curl -s -b "$J" "$B/admin/round/$OPD" | has "Noch nie aktiv: wird nicht automatisch gelöscht"'
$DC exec -T app php -r 'require "/srv/app/app/bootstrap.php"; App\Cleanup::run();'
check "clean-up: rounds in use expire (active, deactivated by hand, with an entry); a draft never used stays, also the copy with the old dates" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id IN ($OPA,$OPX,$OPE) AND expired_at IS NOT NULL AND is_active = 0")" = 3 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id IN ($OPD,$OPC) AND expired_at IS NULL")" = 2 ] && ! curl -s -b "$J" "$B/admin/round/$OPC" | has "Abgelaufen:"'
check "deactivated account unused for 15 months: deleted with its draft; a round that was in use keeps it" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE id = $OPU1")" ] && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $OPW1")" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $OPW2 AND user_id = $OPU2 AND is_active = 0")" = 1 ]'
DBQ "DELETE FROM tp_users WHERE id IN ($OPU1, $OPU2)"
OP_DEL=$(DBQ "SELECT DATE_FORMAT(expired_at + INTERVAL 24 HOUR, '%d.%m.%Y, %H:%i') FROM tp_rounds WHERE id=$OPA")
OPA_PAGE=$(curl -s -b "$J" "$B/admin/round/$OPA")
check "expired round: notice with the date of deletion and how to keep the round ($OP_DEL)" 'echo "$OPA_PAGE" | grep -q "Abgelaufen: Die Runde wird am [A-Za-z]*, $OP_DEL Uhr automatisch gelöscht, mit allen Tagen und Einstellungen. Zum Behalten einen Tag ab heute hinzufügen" && echo "$OPA_PAGE" | grep -q "Runde wird am [A-Za-z]*, $OP_DEL Uhr gelöscht (24 h nach Ablauf)"'
OP_DASH=$(curl -s -b "$J" "$B/admin")
check "overview: expired round with the date of deletion, nothing for the draft" 'echo "$OP_DASH" | sed -n "/>Betrieb aktiv</,/<\/tr>/p" | grep -q "wird am ${OP_DEL%%,*} gelöscht" && ! echo "$OP_DASH" | sed -n "/>Betrieb Entwurf</,/<\/tr>/p" | grep -q "gelöscht"'

echo "Operations: migration 19"
OPS=$(op_round "Betrieb Sync" 1 "$OP_NEXT")
OP_USED=$(DBQ "SELECT GROUP_CONCAT(id) FROM tp_rounds WHERE was_used = 1")
DBQ "ALTER TABLE tp_rounds DROP COLUMN was_used; UPDATE tp_settings SET value = '18' WHERE name = 'schema_version'"
curl -s -o /dev/null -b "$J" "$B/admin"
check "migration 19: active, expired or booked rounds count as used, drafts not" '[ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$(grep -o "LATEST = [0-9]*" "$ROOT/app/src/Migrations.php" | grep -o "[0-9]*$")" ] && [ "$(op_used "$OPA,$OPD,$OPB,$OPC,$OPS")" = "$OPA:1,$OPD:0,$OPB:1,$OPC:0,$OPS:1" ]'
DBQ "UPDATE tp_rounds SET was_used = 1 WHERE id IN ($OP_USED)"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$OPC/toggle"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Betrieb+Entwurf&is_active=0&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24" "$B/admin/round/$OPD/edit"
OP_STILL=$(op_used "$OPD")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Betrieb+Entwurf&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24" "$B/admin/round/$OPD/edit"
check "a draft activated by the button or in the form is in use from then on (saved inactive: still a draft)" '[ "$OP_STILL" = "$OPD:0" ] && [ "$(op_used "$OPD,$OPC")" = "$OPD:1,$OPC:1" ]'

echo "Operations: deleting a round"
# Expert review P0-6: with bookings only with the box ticked, checked on the server (the dialog needs
# JavaScript); the button in its own section after the days, deactivating offered instead
OPB_PAGE=$(curl -s -b "$J" "$B/admin/round/$OPB")
check "own section after the days, the action row only links to it" '[ "$(echo "$OPB_PAGE" | grep -c "action=\"/admin/round/$OPB/delete\"")" = 1 ] && [ "$(echo "$OPB_PAGE" | grep -n "id=\"delete-round\"" | cut -d: -f1)" -gt "$(echo "$OPB_PAGE" | grep -n "id=\"day-[0-9]*\"" | tail -1 | cut -d: -f1)" ] && echo "$OPB_PAGE" | grep -q "<a class=\"button\" href=\"#delete-round\">Löschen …</a>"'
check "with bookings the box \"inkl. 1 Buchung(en)\" is required; deactivating instead only for an active round" 'echo "$OPB_PAGE" | sed -n "/id=\"delete-round\"/,/<\/section>/p" | grep -q "name=\"confirm\" value=\"1\" required> inkl. 1 Buchung(en)" && ! echo "$OPB_PAGE" | has "Stattdessen deaktivieren" && echo "$OPX_PAGE" | sed -n "/id=\"delete-round\"/,/<\/section>/p" | grep -q "action=\"/admin/round/$OPX/toggle\""'
OP_LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$OPB/delete")
check "without the box (no JavaScript): refused with the number of bookings, round and booking kept; with the box deleted" '[[ "$OP_LOC" == */admin/round/$OPB#delete-round ]] && curl -s -b "$J" "$B/admin/round/$OPB" | has "In dieser Runde gibt es 1 Buchung(en). Bitte das Löschen ausdrücklich bestätigen." && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $OPB")" = 1 ] && curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$OPB/delete" | has "Runde mit allen Tagen und Buchungen gelöscht" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$OPB")" ]'

echo "Operations: slot sync never deletes a booked slot"
# Expert review P0-6: sync reads the slots from a snapshot; a booking that arrives in the meantime must not
# go with a slot that no longer fits (ON DELETE CASCADE). Simulated with a second connection.
OPS_OUT=$($DC exec -T app php -r '
  require "/srv/app/app/bootstrap.php";
  $day = (int) App\Db::value("SELECT id FROM round_days WHERE round_id = ?", [(int) $argv[1]]);
  $slot = (int) App\Db::value("SELECT id FROM slots WHERE day_id = ? AND start_time = ?", [$day, "10:30:00"]);
  App\Db::run("UPDATE round_days SET end_time = ? WHERE id = ?", ["10:30:00", $day]); // no room for 10:30 any more
  App\Db::pdo()->beginTransaction();
  App\Db::value("SELECT COUNT(*) FROM bookings"); // the snapshot sync reads its slots from starts here
  $c = App\Config::get("db");
  $other = new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $c["host"], (int) ($c["port"] ?? 3306), $c["name"]), $c["user"], $c["pass"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $other->prepare(App\Db::prefix("INSERT INTO bookings (slot_id, name, token_hash, lang, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())"))
    ->execute([$slot, "Betrieb Wettlauf", hash("sha256", random_bytes(16)), "de", "parent"]);
  $conflicts = App\SlotGenerator::sync($day);
  App\Db::pdo()->commit();
  echo $conflicts, "|", App\Db::value("SELECT COUNT(*) FROM bookings WHERE slot_id = ?", [$slot]), "|", (int) App\Db::value("SELECT is_conflict FROM slots WHERE id = ?", [$slot]);
' "$OPS")
check "booked while the schedule is synced: slot kept as a conflict, booking not deleted ($OPS_OUT)" '[ "$OPS_OUT" = "1|1|1" ] && curl -s -b "$J" "$B/admin/round/$OPS" | has "1 Konflikt(e):"'

for r in $OPA $OPD $OPE $OPX $OPC $OPS; do curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$r/delete"; done

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
# op_round_page <response with the status in the last line> <slug>: the page of that round (here its code prompt)
op_round_page() { [ "$(printf '%s\n' "$1" | tail -1)" = 200 ] && printf '%s\n' "$1" | grep -q "action=\"[^\"]*/$2\""; }
check "a round at /health or /cron from before keeps its page; with the key header the address is the health check or the web cron" \
  'op_round_page "$OP_RP" health && op_round_page "$OP_RC" cron && op_is "$OP_H" 200 ok && [ "$OP_CW" = 404 ]' \
  || echo "    $(printf '%s\n' "$OP_RP" | tail -1) $(printf '%s\n' "$OP_RC" | tail -1) $OP_CW"
OP_NR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Health&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
OP_NS=$(DBQ "SELECT slug FROM tp_rounds WHERE id = ${OP_NR:-0}")
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/${OP_NR:-0}/delete"
check "a new round titled \"Health\" gets the next free address ($OP_NS), removed again" '[ "$OP_NS" = health-2 ] && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $OP_NR")" ]'

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

echo "Operations: cron monitoring"
# Expert review P1-2: every completed cron run (bin/cron.php, web cron) records last_cron, apart from the
# clean-up on page views (last_cleanup). Without a completed run for cron_alert_hours (3 by default), admins
# see a warning on the dashboard and the operator gets one alert mail per outage, repeated daily.
op_set() { DBQ "INSERT INTO tp_settings (name, value) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE value = '$2'"; }
op_get() { DBQ "SELECT value FROM tp_settings WHERE name = '$1'"; }
op_recent() { [[ "$1" =~ ^[0-9]+$ ]] && [ $(( $(date +%s) - $1 )) -lt 300 ]; }
op_cron_mails() { ALERTS 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org%20subject:Cronjob"), true)["messages_count"] ?? "?";'; }
op_throttle_reset() { ALERTS '@unlink("/srv/app/app/var/alerts.json");'; } # App\Alert: the same alert at most once per hour
DBQ "DELETE FROM tp_settings WHERE name IN ('last_cron', 'cron_alert_sent'); UPDATE tp_settings SET value = '0' WHERE name = 'last_cleanup'"
curl -s -o /dev/null "$B/"
OPC_PAGE="$(op_get last_cron)|$(op_get last_cleanup)"
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
OPC_CLI=$(op_get last_cron)
op_set last_cron 1
OPC_WEBOUT=$(curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron")
OPC_WEB=$(op_get last_cron)
check "a page view runs the clean-up (last_cleanup) but records no cron run; bin/cron.php and the web cron do" '[[ "$OPC_PAGE" == "|"* ]] && op_recent "${OPC_PAGE#|}" && op_recent "$OPC_CLI" && echo "$OPC_WEBOUT" | has "\"status\":\"ok\"" && op_recent "$OPC_WEB"'

# Late: last completed run 4 hours ago
OPC_LAST=$(( $(date +%s) - 4 * 3600 ))
op_set last_cron "$OPC_LAST"; op_throttle_reset
OPC_DASH=$(curl -s -b "$J" "$B/admin")
curl -s -o /dev/null "$B/"; curl -s -o /dev/null "$B/impressum"
check "late (4 h): admins see a warning with the time of the last run, the GitHub rule and a link to the guide" 'echo "$OPC_DASH" | has "<div class=\"alert alert-warning\" role=\"status\"><p>Der Cronjob ist seit dem $(TZ=Europe/Berlin date -d "@$OPC_LAST" "+%d.%m.%Y %H:%M") Uhr nicht mehr gelaufen." && echo "$OPC_DASH" | has "nach 60 Tagen ohne Aktivität ab" && echo "$OPC_DASH" | has "<a href=\"https://skornehl.github.io/talkslot/de/guide/operations/#cron-monitoring\">Zur Anleitung</a>"'
OPC_TPW=$($DC exec -T app php -r 'echo password_hash("Betrieb-Cron-Passwort-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('betrieb-cron@example.org', '$OPC_TPW', 'teacher', 'Herr Cron', 'betrieb-cron@example.org', 1, NOW())"
JC=$(mktemp)
curl -s -o /dev/null -c "$JC" -b "$JC" -d "csrf=$(curl -s -c "$JC" -b "$JC" "$B/admin/login" | csrf)&username=betrieb-cron@example.org&password=Betrieb-Cron-Passwort-1" "$B/admin/login"
OPC_TDASH=$(curl -s -b "$JC" "$B/admin")
check "late: teachers see no warning (only admins can have it fixed)" 'echo "$OPC_TDASH" | has "Meine Terminrunden" && ! echo "$OPC_TDASH" | has "Cronjob"'
OPC_MAIL=$(ALERTS '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org%20subject:Cronjob"), true); $m = $r["messages"][0] ?? ["ID" => "x", "Subject" => ""]; echo $m["Subject"], "\n", json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $m["ID"]), true)["Text"] ?? "";')
check "late: exactly one alert mail for several page views, with the time of the last run and what to check ($(op_cron_mails))" '[ "$(op_cron_mails)" = 1 ] && echo "$OPC_MAIL" | head -1 | has "Cronjob läuft nicht" && echo "$OPC_MAIL" | has "seit dem $(TZ=Europe/Berlin date -d "@$OPC_LAST" "+%d.%m.%Y %H:%M") Uhr nicht mehr vollständig gelaufen (gemeldet wird nach 3 Stunden)" && echo "$OPC_MAIL" | has "gh workflow enable cron.yml" && op_recent "$(op_get cron_alert_sent)"'
# The outage goes on: mailed 23 hours ago → nothing yet; a day ago → once more
op_set last_cron $(( $(date +%s) - 30 * 3600 )); op_set cron_alert_sent $(( $(date +%s) - 23 * 3600 )); op_throttle_reset
curl -s -o /dev/null "$B/"
OPC_DAY=$(op_cron_mails)
op_set cron_alert_sent $(( $(date +%s) - 86400 - 60 )); op_throttle_reset
curl -s -o /dev/null "$B/"; curl -s -o /dev/null "$B/impressum"
check "an outage that goes on is mailed again once a day, not before ($OPC_DAY, then $(op_cron_mails))" '[ "$OPC_DAY" = 1 ] && [ "$(op_cron_mails)" = 2 ]'
op_set cron_alert_sent $(( $(date +%s) - 86400 - 60 )); op_throttle_reset
OPC_WEBOUT=$(curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron")
curl -s -o /dev/null "$B/"
check "the web cron ends the outage without reporting it: no mail, no warning any more ($(op_cron_mails))" 'echo "$OPC_WEBOUT" | has "\"status\":\"ok\"" && [ "$(op_cron_mails)" = 2 ] && op_recent "$(op_get last_cron)" && ! curl -s -b "$J" "$B/admin" | has "Cronjob"'

# Never: no run recorded (e.g. after the update, or never set up)
DBQ "DELETE FROM tp_settings WHERE name IN ('last_cron', 'cron_watch_since')"
OPC_FRESH=$(curl -s -b "$J" "$B/admin")
OPC_SINCE=$(op_get cron_watch_since)
op_set cron_watch_since $(( $(date +%s) - 4 * 3600 )); op_throttle_reset
OPC_NEVER=$(curl -s -b "$J" "$B/admin"); curl -s -o /dev/null "$B/"
check "no run recorded: the monitoring begins with a page view, 3 h later admins are asked to set up the cron job; no mail" 'op_recent "$OPC_SINCE" && ! echo "$OPC_FRESH" | has "Cronjob" && echo "$OPC_NEVER" | has "<div class=\"alert alert-info\" role=\"status\"><p>Es ist noch kein Lauf des Cronjobs verzeichnet." && echo "$OPC_NEVER" | has "cron_alert_hours = 0" && [ "$(op_cron_mails)" = 2 ]'
OPC_OFF=$(ALERTS 'require "/srv/app/app/bootstrap.php";
  App\Config::load(["cron_alert_hours" => 0] + (require "/srv/app/config/config.php"));
  App\Db::run("DELETE FROM settings WHERE name = ?", ["cron_watch_since"]);
  App\CronMonitor::check();
  echo App\Settings::get("cron_watch_since") ?? "none", "|", json_encode(App\CronMonitor::status());')
check "cron_alert_hours = 0: no monitoring, no notice ($OPC_OFF)" '[ "$OPC_OFF" = "none|null" ]'

$DC exec -T app php /srv/app/bin/cron.php >/dev/null
DBQ "DELETE FROM tp_users WHERE username = 'betrieb-cron@example.org'"
rm -f "$JC"
# The alert mails of this section are expected; the final check counts unexpected ones
ALERTS 'file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org%20subject:Cronjob", false, stream_context_create(["http" => ["method" => "DELETE"]]));'
op_throttle_reset
