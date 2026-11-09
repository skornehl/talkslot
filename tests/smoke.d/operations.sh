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
check "active round: the round page says when it will be deleted (0:00 after the last day + 24 h)" 'echo "$OPX_PAGE" | grep -q "Runde wird am [A-Za-z]*, $(date -d "$OP_NEXT +2 day" +%d.%m.%Y), 00:00 Uhr gelöscht (1 Tag(e) nach Ablauf)"'
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
check "expired round: notice with the date of deletion and how to keep the round ($OP_DEL)" 'echo "$OPA_PAGE" | grep -q "Abgelaufen: Die Runde wird am [A-Za-z]*, $OP_DEL Uhr automatisch gelöscht, mit allen Tagen und Einstellungen. Zum Behalten einen Tag ab heute hinzufügen" && echo "$OPA_PAGE" | grep -q "Runde wird am [A-Za-z]*, $OP_DEL Uhr gelöscht (1 Tag(e) nach Ablauf)"'
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
# (the name of App\Db::lock(): per database and prefix)
OP_LOCK="SHA1(CONCAT(DATABASE(), '.', 'tp_migrate'))"
DBQ "SELECT GET_LOCK($OP_LOCK, 0), SLEEP(60)" > /dev/null 2>&1 &
OP_BG=$!
OP_CONN=''
for _ in $(seq 50); do OP_CONN=$(DBQ "SELECT IS_USED_LOCK($OP_LOCK)"); [ -n "$OP_CONN" ] && [ "$OP_CONN" != NULL ] && break; sleep 0.2; done
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
# clean-up on page views (last_cleanup). Without a completed run for cron_alert_hours (3 in dev/config.dev.php), admins
# see a warning on the dashboard and the operator gets one alert mail per outage, repeated daily.
op_set() { DBQ "INSERT INTO tp_settings (name, value) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE value = '$2'"; }
op_get() { DBQ "SELECT value FROM tp_settings WHERE name = '$1'"; }
op_recent() { [[ "$1" =~ ^[0-9]+$ ]] && [ $(( $(date +%s) - $1 )) -lt 300 ]; }
op_cron_mails() { ALERTS 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org%20subject:Cronjob"), true)["messages_count"] ?? "?";'; }
# App\Alert sends the same alert at most once per hour: forget only the throttle entry of this one (others may
# hold back repetitions that later checks rely on); op_throttle_reset <daily count> also sets the day's count
op_throttle_reset() {
  $DC exec -T app php -r '$f = "/srv/app/app/var/alerts.json"; $s = json_decode((string) @file_get_contents($f), true);
    if (is_array($s)) { unset($s["keys"][hash("sha256", "cron-late")]); if (($argv[1] ?? "") !== "") $s["_count"] = (int) $argv[1]; file_put_contents($f, json_encode($s)); }' "${1:-}"
}
OPC_COUNT=$(ALERTS 'echo (int) (json_decode((string) @file_get_contents("/srv/app/app/var/alerts.json"), true)["_count"] ?? 0);')
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
check "late (4 h): admins see a warning with the time of the last run, the GitHub rule and a link to the guide" 'echo "$OPC_DASH" | has "<div class=\"alert alert-warning\" role=\"status\"><p>Der Cronjob ist seit dem $(TZ=Europe/Berlin date -d "@$OPC_LAST" "+%d.%m.%Y %H:%M") Uhr nicht mehr vollständig gelaufen." && echo "$OPC_DASH" | has "nach 60 Tagen ohne Aktivität ab" && echo "$OPC_DASH" | has "<a href=\"https://skornehl.github.io/talkslot/de/guide/operations/#cron-monitoring\">Zur Anleitung</a>"'
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
# Repaired, then down again within a day of the last mail: mailed 10 hours ago, last completed run 5 hours
# ago (after that mail) → a new outage, mailed at once and only once, with the time of the new last run
OPC_LAST=$(( $(date +%s) - 5 * 3600 ))
op_set cron_alert_sent $(( $(date +%s) - 10 * 3600 )); op_set last_cron "$OPC_LAST"; op_throttle_reset
curl -s -o /dev/null "$B/"; curl -s -o /dev/null "$B/impressum"
OPC_MAIL=$(ALERTS '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org%20subject:Cronjob"), true); $m = $r["messages"][0] ?? ["ID" => "x"]; echo json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $m["ID"]), true)["Text"] ?? "";')
check "a new outage within a day of the last mail is mailed at once, once, with the new last run ($(op_cron_mails))" '[ "$(op_cron_mails)" = 3 ] && echo "$OPC_MAIL" | has "seit dem $(TZ=Europe/Berlin date -d "@$OPC_LAST" "+%d.%m.%Y %H:%M") Uhr nicht mehr vollständig gelaufen" && op_recent "$(op_get cron_alert_sent)"'
op_set cron_alert_sent $(( $(date +%s) - 86400 - 60 )); op_throttle_reset
OPC_WEBOUT=$(curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron")
curl -s -o /dev/null "$B/"
check "the web cron ends the outage without reporting it: no mail, no warning any more ($(op_cron_mails))" 'echo "$OPC_WEBOUT" | has "\"status\":\"ok\"" && [ "$(op_cron_mails)" = 3 ] && op_recent "$(op_get last_cron)" && ! curl -s -b "$J" "$B/admin" | has "Cronjob"'

# Never: no run recorded (e.g. after the update, or never set up)
DBQ "DELETE FROM tp_settings WHERE name IN ('last_cron', 'cron_watch_since')"
OPC_FRESH=$(curl -s -b "$J" "$B/admin")
OPC_SINCE=$(op_get cron_watch_since)
op_set cron_watch_since $(( $(date +%s) - 4 * 3600 )); op_throttle_reset
OPC_NEVER=$(curl -s -b "$J" "$B/admin"); curl -s -o /dev/null "$B/"
check "no run recorded: the monitoring begins with a page view, 3 h later admins are asked to set up the cron job; no mail" 'op_recent "$OPC_SINCE" && ! echo "$OPC_FRESH" | has "Cronjob" && echo "$OPC_NEVER" | has "<div class=\"alert alert-warning\" role=\"status\"><p>Es ist noch kein vollständiger Lauf des Cronjobs verzeichnet." && echo "$OPC_NEVER" | has "php bin/cron.php -v“, woran er scheitert" && echo "$OPC_NEVER" | has "cron_alert_hours = 0" && [ "$(op_cron_mails)" = 3 ]'

$DC exec -T app php /srv/app/bin/cron.php >/dev/null
DBQ "DELETE FROM tp_users WHERE username = 'betrieb-cron@example.org'"
rm -f "$JC"
# The alert mails of this section are expected; the final check counts unexpected ones
for _ in 1 2 3 4 5; do
  ALERTS 'file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org%20subject:Cronjob", false, stream_context_create(["http" => ["method" => "DELETE"]]));'
  [ "$(op_cron_mails)" = 0 ] && break; sleep 1
done
op_throttle_reset "$OPC_COUNT"
check "cron monitoring: the expected alert mails are removed again, the cron job ran last ($(op_cron_mails))" '[ "$(op_cron_mails)" = 0 ] && op_recent "$(op_get last_cron)"'

# Operations: start and platform checks (expert review P1-10). PHP version, extensions, configuration and the
# texts: tests/unit.d/operations.php; old PHP versions: the CI workflow (tests.yml)
echo "Operations: start and platform checks"
OP_DASH=$(curl -s -b "$J" "$B/admin")
check "dashboard: no setup hint with the dev configuration on MariaDB (no false alarm)" \
  'echo "$OP_DASH" | grep -q "<h1>Alle Terminrunden</h1>" && ! echo "$OP_DASH" | grep -q "data-setup-hints"'
check "cron job and web cron report the database server as supported" \
  '$DC exec -T app php /srv/app/bin/cron.php -v | grep -qE "^db_server +ok$" && curl -s -d "" -H "X-Cron-Token: $OP_CTOK" "$B/cron" | grep -q "^{\"status\":\"ok\",\"db_server\":\"ok\","'
# A typo in config.php (here "sumary_evening_hour") is listed for admins; config.php changed for a moment. The
# waits let a PHP opcache notice the changed file (it checks every 2 seconds by default).
ALERTS '$f = "/srv/app/config/config.php"; copy($f, "$f.op"); $c = require $f; $c["sumary_evening_hour"] = 18;
  file_put_contents($f, "<?php return " . var_export($c, true) . ";");'
sleep 3
OP_HINTS=$(curl -s -b "$J" "$B/admin" | sed -n '/data-setup-hints/,/<\/div>/p')
ALERTS '$f = "/srv/app/config/config.php"; file_put_contents($f, file_get_contents("$f.op")) && unlink("$f.op");' # same file, owner and permissions
sleep 3
check "dashboard: a typo in config.php is listed for admins, with the setting probably meant" \
  'echo "$OP_HINTS" | grep -q "<li>„sumary_evening_hour“ ist keine Einstellung von TalkSlot und wirkt nicht. Gemeint ist vermutlich „summary_evening_hour“.</li>" && ! curl -s -b "$J" "$B/admin" | grep -q "data-setup-hints"'
# A pending migration on MySQL (or MariaDB before 10.6) is refused before its first step, with the reason, instead
# of failing half-way on MariaDB syntax. Stood in for by App\Health::$serverVersion; a script file (not php -r),
# so that a coverage run sees the lines it runs.
OP_SCRIPT="$ROOT/tests/.op-platform.php"
cat > "$OP_SCRIPT" <<'PHP'
<?php
require dirname(__DIR__) . '/app/bootstrap.php';
App\Health::$serverVersion = '8.0.36';
try {
    App\Migrations::run();
    echo 'migrated';
} catch (RuntimeException $e) {
    echo $e->getMessage();
}
PHP
DBQ "UPDATE tp_settings SET value = '$((OP_LATEST - 1))' WHERE name = 'schema_version'"
OP_MIG=$($DC exec -T app php /srv/app/tests/.op-platform.php)
OP_MV=$(OP_SETTING schema_version)
DBQ "UPDATE tp_settings SET value = '$OP_LATEST' WHERE name = 'schema_version'"
rm -f "$OP_SCRIPT"
check "migration on MySQL: refused before the first step, with the reason (schema $OP_MV of $OP_LATEST)" \
  '[ "$OP_MV" = "$((OP_LATEST - 1))" ] && [[ "$OP_MIG" == "Der Datenbankserver wird nicht unterstützt: MySQL 8.0.36. TalkSlot braucht MariaDB 10.6 oder neuer;"* ]]' \
  || echo "    $OP_MIG"

echo "Operations: a second installation in the same database (expert review P2-7)"
# The prefix is meant for databases shared with other applications or a second TalkSlot (test and live, two
# schools). Foreign keys get the prefix as well, since their names are unique per database; a name that is taken
# stops the installer before it changes anything, and a failed installation drops the tables it created.
OP_DROP() { # OP_DROP <prefix>: the tables of a test installation (LIKE with an escaped _)
  local t; t=$(DBQ "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '$(printf '%s' "$1" | sed 's/_/\\\\_/g')%'")
  [ -z "$t" ] || [ "$t" = NULL ] || DBQ "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE $t; SET FOREIGN_KEY_CHECKS = 1"
}
for p in tp2_ tp4_ tp5_; do OP_DROP "$p"; done
DBQ "DROP TABLE IF EXISTS op_legacy"
OP_FKS=$(grep -c 'CONSTRAINT fk_' "$ROOT/app/schema.sql")
OP_SCRIPT="$ROOT/tests/.op-prefix.php"
cat > "$OP_SCRIPT" <<'PHP'
<?php
// tests/smoke.d/operations.sh: installations under another prefix in the database of the smoke test
require dirname(__DIR__) . '/app/bootstrap.php';
$cfg = App\Config::all();
$as = static function (string $prefix) use ($cfg): void {
    App\Config::load(['db' => ['prefix' => $prefix] + (array) $cfg['db']] + $cfg);
};
$try = static function (callable $fn): string {
    try {
        $fn();
        return 'installed';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};
$fks = static fn (string $prefix): string => implode(',', array_column(App\Db::all(
    "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        AND TABLE_NAME LIKE ? ORDER BY CONSTRAINT_NAME", [str_replace('_', '\_', $prefix) . '%']), 'CONSTRAINT_NAME'));
switch ($argv[1]) {
    case 'second': // a second school with the prefix tp2_
        $as('tp2_');
        echo 'result=', $try(static fn () => App\Installer::install('zweitschule', 'Sehr-Sicheres-Zweit-Pw1', '', '', 'school')), "\n";
        echo 'fks=', $fks('tp2_'), "\n";
        echo 'fk=', App\Db::foreignKey('rounds', 'user_id'), "\n";
        echo 'admins=', App\Db::value("SELECT GROUP_CONCAT(username) FROM users WHERE role = 'admin'"), "\n";
        echo 'again=', $try(static fn () => App\Installer::install('noch', 'Sehr-Sicheres-Zweit-Pw1', '', '', 'school')), "\n";
        // A foreign key under the old name (fk_…, directly through PDO) is found and dropped all the same
        App\Db::pdo()->exec('ALTER TABLE tp2_mail_outbox DROP FOREIGN KEY tp2_fk_outbox_user');
        App\Db::pdo()->exec('ALTER TABLE tp2_mail_outbox ADD CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES tp2_users (id) ON DELETE CASCADE');
        echo 'old=', App\Db::foreignKey('mail_outbox', 'user_id'), '|';
        App\Db::dropForeignKey('mail_outbox', 'user_id');
        App\Db::dropForeignKey('mail_outbox', 'user_id'); // no foreign key any more: nothing happens
        echo App\Db::foreignKey('mail_outbox', 'user_id') ?? 'none', "\n";
        $as('tp_');
        echo 'first=', App\Db::foreignKey('rounds', 'user_id'), '|', App\Db::value("SELECT GROUP_CONCAT(username) FROM users WHERE role = 'admin'"), "\n";
        break;
    case 'legacy': // an installation from before the prefixed names (fk_…) and a new one without a prefix
        // Directly through PDO: Db::execRaw() would give the foreign key the prefix of the smoke test
        App\Db::pdo()->exec('CREATE TABLE op_legacy (user_id INT UNSIGNED NOT NULL, CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES tp_users (id)) ENGINE=InnoDB');
        $as('');
        echo 'conflicts=', implode(',', App\Installer::conflictingConstraints()), "\n";
        echo 'web=', implode(' ', App\Installer::conflicts()), "\n";
        echo 'result=', $try(static fn () => App\Installer::install('ohnepraefix', 'Sehr-Sicheres-Zweit-Pw1', '', '', 'school')), "\n";
        echo 'try=', App\Installer::tryInstall('ohnepraefix', 'Sehr-Sicheres-Zweit-Pw1', '', '', 'school') ?? 'installed', "\n";
        echo 'tables=', implode(',', App\Installer::conflictingTables()), "\n";
        App\Db::pdo()->exec('DROP TABLE op_legacy');
        break;
    case 'failed': // a statement fails half-way: the tables created up to then are dropped again
        $as('Tp4_'); // capitals: dropped exactly as the server names them
        $schema = tempnam(sys_get_temp_dir(), 'schema');
        $statements = explode(';', (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(APP_ROOT . '/app/schema.sql')));
        array_splice($statements, 4, 0, ["\nCREATE TABLE rounds_broken (id INT REFERENCES nowhere"]);
        file_put_contents($schema, implode(';', $statements));
        App\Installer::$schemaFile = $schema;
        echo 'result=', App\Installer::tryInstall('kaputt', 'Sehr-Sicheres-Zweit-Pw1', '', '', 'school') ?? 'installed', "\n";
        echo 'tables=', implode(',', App\Installer::conflictingTables()), "\n";
        unlink($schema);
        break;
    case 'killed': // the connection is lost half-way: the tables cannot be dropped, and the message says so
        $as('tp5_');
        $schema = tempnam(sys_get_temp_dir(), 'schema');
        $statements = explode(';', (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(APP_ROOT . '/app/schema.sql')));
        array_splice($statements, 2, 0, ["\nKILL " . App\Db::value('SELECT CONNECTION_ID()')]);
        file_put_contents($schema, implode(';', $statements));
        App\Installer::$schemaFile = $schema;
        echo 'result=', App\Installer::tryInstall('kaputt', 'Sehr-Sicheres-Zweit-Pw1', '', '', 'school') ?? 'installed', "\n";
        unlink($schema);
        break;
    case 'lock': // lock names per database and prefix (GET_LOCK names count for the whole server)
        $free = static fn (string $sql): string => (string) App\Db::pdo()->query($sql)->fetchColumn();
        $got = App\Db::lock('migrate', 0) ? 'yes' : 'no';
        echo 'lock=', $got, '|', $free("SELECT IS_FREE_LOCK(SHA1(CONCAT(DATABASE(), '.', 'tp_migrate')))"), '|', $free("SELECT IS_FREE_LOCK('tp_migrate')"), '|';
        App\Db::unlock('migrate');
        echo $free("SELECT IS_FREE_LOCK(SHA1(CONCAT(DATABASE(), '.', 'tp_migrate')))"), "\n";
        break;
}
PHP
OP_PX=$($DC exec -T app php /srv/app/tests/.op-prefix.php second)
check "second installation (prefix tp2_): own tables, foreign keys with the prefix, the first one untouched" \
  'echo "$OP_PX" | grep -qx "result=installed" && echo "$OP_PX" | grep -qx "fk=tp2_fk_rounds_user" && echo "$OP_PX" | grep -qx "admins=zweitschule" && [ "$(echo "$OP_PX" | grep "^fks=" | tr "," "\n" | grep -c "^fks=tp2_fk_\|^tp2_fk_")" = "$OP_FKS" ] && echo "$OP_PX" | grep -qx "old=fk_outbox_user|none" && echo "$OP_PX" | grep -qx "first=tp_fk_rounds_user|$USER" && curl -s -b "$J" "$B/admin" | grep -q "data-csrf\|name=\"csrf\""' \
  || echo "$OP_PX" | sed 's/^/    /'
check "the same prefix once more: refused because of its tables, nothing changed" \
  'echo "$OP_PX" | grep -q "^again=Die Datenbank enthält bereits Tabellen mit gleichem Namen (tp2_users" && [ "$(DBQ "SELECT COUNT(*) FROM tp2_users")" = 1 ]'
OP_DROP tp2_
OP_PX=$($DC exec -T app php /srv/app/tests/.op-prefix.php legacy)
check "a foreign key name of an older installation (fk_rounds_user) stops an installation without a prefix before it creates anything" \
  'echo "$OP_PX" | grep -qx "conflicts=fk_rounds_user" && echo "$OP_PX" | grep -q "^web=Die Datenbank enthält bereits Fremdschlüssel mit gleichem Namen (fk_rounds_user), vermutlich von einer älteren TalkSlot-Installation" && echo "$OP_PX" | grep -q "^result=Die Datenbank enthält bereits Fremdschlüssel mit gleichem Namen (fk_rounds_user)" && echo "$OP_PX" | grep -q "^try=Die Installation ist fehlgeschlagen (.*Es wurde nichts verändert" && echo "$OP_PX" | grep -qx "tables="' \
  || echo "$OP_PX" | sed 's/^/    /'
OP_PX=$($DC exec -T app php /srv/app/tests/.op-prefix.php failed)
check "installation failing half-way: the tables it created are dropped again, so it can be repeated" \
  'echo "$OP_PX" | grep -q "^result=Die Installation ist fehlgeschlagen (MariaDB 1064: SQLSTATE\[42000\]: Syntax error" && echo "$OP_PX" | grep -q "Die dabei angelegten Tabellen wurden wieder entfernt" && echo "$OP_PX" | grep -qx "tables=" && [ "$(DBQ "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) LIKE '"'"'tp4\\_%'"'"'")" = 0 ]' \
  || echo "$OP_PX" | sed 's/^/    /'
OP_PX=$($DC exec -T app php /srv/app/tests/.op-prefix.php killed)
check "connection lost half-way: the message says the tables are still there instead of claiming they were removed" \
  'echo "$OP_PX" | grep -q "^result=Die Installation ist fehlgeschlagen (.*Die dabei angelegten Tabellen ließen sich nicht entfernen"' || echo "$OP_PX" | sed 's/^/    /'
OP_DROP tp5_
OP_PX=$($DC exec -T app php /srv/app/tests/.op-prefix.php lock)
check "locks: the name contains the database and the prefix, not only tp_migrate" '[ "$OP_PX" = "lock=yes|0|1|1" ]' || echo "    $OP_PX"
rm -f "$OP_SCRIPT"
for p in tp2_ tp4_ tp5_; do OP_DROP "$p"; done
