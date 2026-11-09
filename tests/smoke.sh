#!/usr/bin/env bash
# End-to-end test against the local Docker environment (dev/compose.yml).
# Usage: tests/smoke.sh [base_url] [admin_user] [admin_pass]
# Prerequisite: freshly installed (dev/reset.sh), config from dev/config.dev.php. The test then
# reinstalls once more on its own via the web installer (same credentials).
set -u
# Dates in the time zone of the app (timezone in config/config.sample.php): "tomorrow" in the test is the
# app's tomorrow, also on a CI runner in UTC between midnight in Berlin and midnight UTC
export TZ=Europe/Berlin
B=${1:-http://localhost:8080}
USER=${2:-sabrina}
PASS=${3:-Sehr-Sicheres-Dev-Pw1}
J=$(mktemp)
FAIL=0
ok()   { printf '  ✓ %s\n' "$1"; }
bad()  { printf '  ✗ %s\n' "$1"; FAIL=1; }
# Error reports (App\Alert, alert_email from dev/config.dev.php): clear beforehand, none may exist at the end
ALERTS() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T app php -r "$1"; }
ALERTS 'unlink("/srv/app/app/var/alerts.json"); file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org", false, stream_context_create(["http" => ["method" => "DELETE"]]));' 2>/dev/null
check(){ if eval "$2"; then ok "$1"; else bad "$1"; return 1; fi; }
csrf() { grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
# A stable marker of the templates (attribute data-<name>) instead of wording that may change, e.g.
# '! curl -s … | marker profile-hints': a reworded text would make a negative check pass unnoticed
marker() { grep -qE "data-$1([ =>]|\$)"; }

echo "Installation (web and CLI)"
dcx() { docker compose -f "$(dirname "$0")/../dev/compose.yml" "$@"; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# At the end no PHP warning, notice or deprecation of the app may have been logged (tests/php-errors.sh)
PHP_LOG_START=$(stat -c %s "$ROOT/app/var/php-error.log" 2>/dev/null || echo 0)
check "installer locked after installation (404)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/install.php")" = 404 ]'
check "CLI installer after installation: abort" 'dcx exec -T app php /srv/app/bin/install.php x </dev/null 2>&1 | grep -q "Already installed"'
# Reinstall, this time via the web installer (same credentials as dev/reset.sh). It deletes
# itself after success; the file is restored afterwards.
INSTALL_BAK=$(mktemp); cp "$ROOT/public/install.php" "$INSTALL_BAK"
dcx exec -T db mariadb -uapp -papp-dev-only talkslot -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS tp_round_trash, tp_mail_outbox, tp_round_templates, tp_audit_log, tp_round_translations, tp_sent_summaries, tp_bookings, tp_slots, tp_day_breaks, tp_round_days, tp_rounds, tp_pages, tp_rate_limits, tp_settings, tp_users, tp_admin_users; CREATE TABLE tp_users (id INT)"
dcx exec -T app php -r '@unlink("/srv/app/app/var/installed.lock");'
check "installer reachable while not installed" 'curl -s "$B/install.php" | grep -q "name=\"install_key\""'
check "CLI installer: different passwords, no operator type → abort, the usage names the three input lines" 'O=$(printf "Sehr-Sicheres-Pw-1\nanderes-Passwort-2\n" | dcx exec -T app php /srv/app/bin/install.php sabrina 2>&1); echo "$O" | grep -q "Passwörter stimmen nicht überein" && echo "$O" | grep -q "Bitte wählen Sie die Betriebsart" && echo "$O" | grep -qF "Input: password, password again, operator type (school or private), one per line"'
IK=dev-install-key-123
check "wrong install key rejected" 'curl -s -d "install_key=falsch&username=$USER" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php" | grep -q "Installationsschlüssel ist falsch"'
check "foreign table with the same name → no installation" 'curl -s -d "install_key=$IK&username=$USER" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php" | grep -q "enthält bereits Tabellen mit gleichem Namen (tp_users)"'
dcx exec -T db mariadb -uapp -papp-dev-only talkslot -e "DROP TABLE tp_users"
check "password too short rejected, input kept" 'P=$(curl -s -d "install_key=$IK&username=$USER&password=kurz&password2=kurz&notify_email=lehrer@example.org" "$B/install.php"); echo "$P" | grep -q "mindestens 12 Zeichen" && echo "$P" | grep -q "value=\"lehrer@example.org\""'
# Expert review P0-2b: the installer asks who runs the platform (school or private); it decides the legal templates
check "installer: operator type required, the chosen one is kept after an error" 'P=$(curl -s -d "install_key=$IK&username=$USER" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php"); echo "$P" | grep -q "Bitte wählen Sie die Betriebsart" && echo "$P" | grep -q "name=\"operator_type\" value=\"school\" required" && curl -s -d "install_key=$IK&username=$USER&password=kurz&password2=kurz&operator_type=private" "$B/install.php" | grep -q "value=\"private\" required[^>]* checked"'
IR=$(curl -s -d "install_key=$IK&username=$USER&notify_email=lehrer@example.org&operator_type=school" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php")
[ -f "$ROOT/public/install.php" ] || cp "$INSTALL_BAK" "$ROOT/public/install.php"
rm -f "$INSTALL_BAK"
check "installation via the web completed, locked afterwards" 'echo "$IR" | grep -q "Installation abgeschlossen" && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/install.php")" = 404 ]'

echo "Admin"
T=$(curl -s -c "$J" -b "$J" "$B/admin/login" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J" -b "$J" -d "csrf=$T&username=$USER&password=falsch" "$B/admin/login")
check "wrong password → 401" '[ "$code" = 401 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J" -b "$J" -d "csrf=$T&username=$USER&password=$PASS" "$B/admin/login")
check "Login → 303" '[ "$code" = 303 ]'
grep -q 'tp_admin' "$J" && ok "session cookie set" || bad "session cookie"
check "footer: Powered by TalkSlot with version, linked to the project page (public and admin)" 'curl -s "$B/" | grep -q "Powered by <a href=\"https://skornehl.github.io/talkslot/\">TalkSlot \(v[0-9]\|dev\)" && curl -s -b "$J" "$B/admin" | grep -q "Powered by <a href=\"https://skornehl.github.io/talkslot/\">TalkSlot " && ! curl -s "$B/" | grep -q "class=\"version\">Version"'
grep -q '/admin' "$J" && ok "cookie path /admin" || bad "cookie path"
T=$(curl -s -b "$J" "$B/admin" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" -d "title=Test" "$B/admin/round/new")
check "POST without CSRF is rejected" '[ "$code" = 303 ] && ! curl -s -b "$J" "$B/admin" | grep -q ">Test<"'
NF=$(curl -s -b "$J" "$B/admin/round/new")
check "new round: no empty break rows, only the template for '+ add break'" '[ "$(echo "$NF" | grep -c "name=\"break_start\[\]\"")" = 1 ] && echo "$NF" | grep -q "<template data-break-template>"'
EB=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "title=Pausenfehler&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=14:00&end_time=16:00&slot_minutes=15&break_start%5B%5D=15:00&break_end%5B%5D=&break_label%5B%5D=" "$B/admin/round/new")
check "invalid break: message, the row stays as entered, no additional empty one" 'echo "$EB" | grep -q "Pause 1: Bitte Start- und Endzeit" && [ "$(echo "$EB" | grep -c "name=\"break_start\[\]\"")" = 2 ] && echo "$EB" | grep -q "name=\"break_start\[\]\" value=\"15:00\""'
EB=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "title=Pausenfehler&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=&start_time=14:00&end_time=16:00&slot_minutes=15&break_start%5B%5D=15:00&break_end%5B%5D=15:30&break_label%5B%5D=Kaffee" "$B/admin/round/new")
check "entered break is kept after an error" 'echo "$EB" | grep -q "value=\"Kaffee\"" && [ "$(echo "$EB" | grep -c "name=\"break_start\[\]\"")" = 2 ]'

# Without the details for legal notice/privacy policy no round may be activated
NP=$(curl -s -w '\n%{http_code}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ohne+Profil&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=14:00&end_time=15:00&slot_minutes=30" "$B/admin/round/new")
check "activating without imprint/privacy details rejected (nothing created)" '[ "$(echo "$NP" | tail -1)" = 200 ] && echo "$NP" | grep -q "Bevor Runden aktiviert werden können" && ! curl -s -b "$J" "$B/admin" | grep -q "Ohne Profil" && curl -s -b "$J" "$B/admin" | grep -q "Die Angaben für Impressum und Datenschutz fehlen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Frau Sabrina Test" \
  -d "email=lehrer@example.org&summary_evening=1&summary_deadline=1" "$B/admin/profile"
check "profile saved: no hint to complete it any more" '! curl -s -b "$J" "$B/admin" | marker profile-hints'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" \
  --data-urlencode $'controller_contact=Sabrina Test\r\nTeststraße 1\n12345 Teststadt\nE-Mail: sabrina@example.org' "$B/admin/texts/operator"
check "details for legal notice/privacy policy saved on their own page, no notice left" 'curl -s -b "$J" "$B/admin/texts" | grep -q "Teststraße 1" && ! curl -s -b "$J" "$B/admin" | grep -q "Angaben für Impressum und Datenschutz fehlen"'
LONG=$(curl -s -L -b "$J" --data-urlencode "csrf=$T" --data-urlencode "controller_contact=$(printf 'x%.0s' $(seq 2001))" "$B/admin/texts/operator")
check "details longer than 2000 characters rejected, stored details unchanged" 'echo "$LONG" | grep -q "Die Angaben sind zu lang" && curl -s -b "$J" "$B/admin/texts" | grep -q "Teststraße 1"'
# Rounds can only be activated once the privacy policy has no open [PLACEHOLDERS] (an unconfigured web host
# counts as well): fill them in like an admin, from the template of the variant $1 (tests/smoke.d/parents.sh)
SMOKE_HOST='Beispiel Hosting GmbH, Serverweg 1, 12345 Serverstadt'
legal_fill() {
  local l t
  t=$(curl -s -b "$J" "$B/admin" | csrf)
  for l in de en; do
    curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$t" --data-urlencode "content=$(perl -CSD -pe 's/\[\[HOSTER\]\]/'"$SMOKE_HOST"'/g; s/(?<!\[)\[\p{Lu}[^\[\]\p{Ll}\n]{3,}\](?![\](])/Testangabe/g' "$ROOT/app/templates/pages/${1:-privacy}.$l.md")" "$B/admin/page/privacy/$l"
  done
}
legal_fill

TOMORROW=$(date -d '+3 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Größe Übung" \
  -d "random_suffix=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&description=Nur+ein+Termin&day_dates%5B%5D=$TOMORROW&start_time=14:00&end_time=16:00&slot_minutes=15" "$B/admin/round/new")
RID=$(echo "$loc" | grep -o '[0-9]*$')
check "round created (id=$RID)" '[ -n "$RID" ]'
PAGE=$(curl -s -b "$J" "$B/admin/round/$RID")
SLUG=$(echo "$PAGE" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "slug with umlauts + random part ($SLUG)" '[[ "$SLUG" =~ ^elternsprechtag-groesse-uebung-[a-z2-9]{8}$ ]]'
N=$(echo "$PAGE" | grep -c 'class="is-free')
check "8 slots generated ($N)" '[ "$N" = 8 ]'
DID=$(echo "$PAGE" | grep -o 'id="day-[0-9]*"' | head -1 | grep -o '[0-9]*')
curl -s -o /dev/null -b "$J" -d "csrf=$T&start_time=15:00&end_time=15:20&label=Pause" "$B/admin/day/$DID/break"
N=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -c 'class="is-free')
check "break 15:00–15:20 → 6 slots, then from 15:20 ($N)" '[ "$N" = 6 ] && curl -s -b "$J" "$B/admin/round/$RID" | grep -q "15:20–15:35"'

D1=$(date -d '+5 day' +%F); D2=$(date -d '+6 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Zwei+Tage&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$D1&day_dates%5B%5D=$D2&start_time=08:00&end_time=10:00&slot_minutes=20&break_start%5B%5D=09:00&break_end%5B%5D=09:20&break_label%5B%5D=Pause&break_start%5B%5D=&break_end%5B%5D=" "$B/admin/round/new")
R5=$(echo "$loc" | grep -o '[0-9]*$'); P5=$(curl -s -b "$J" "$B/admin/round/$R5")
check "new round: 2 days with a break directly in the form (5 slots each)" '[ "$(echo "$P5" | grep -c "class=\"is-free")" = 10 ] && echo "$P5" | grep -q "09:20–09:40" && ! echo "$P5" | grep -q "09:00–09:20</td>"'
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ohne+Tag&booking_deadline_hours=0&cancel_deadline_hours=0&start_time=08:00&end_time=10:00&slot_minutes=20" "$B/admin/round/new")
check "new round without date rejected" '[ "$code" = 200 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_dates%5B%5D=$(date -d '+7 day' +%F)&start_time=08:00&end_time=09:00&slot_minutes=30&break_start%5B%5D=08:30&break_end%5B%5D=09:00" "$B/admin/round/$R5/day"
check "add day with break" '[ "$(curl -s -b "$J" "$B/admin/round/$R5" | grep -c "class=\"is-free")" = 11 ]'
DUP=$(curl -s -b "$J" -d "csrf=$T&day_dates%5B%5D=$D1&start_time=8&end_time=9.30&slot_minutes=30" "$B/admin/round/$R5/day")
check "duplicate date rejected, input kept" 'echo "$DUP" | grep -q "gibt es in dieser Runde bereits" && echo "$DUP" | grep -q "value=\"09:30\"" && [ "$(curl -s -b "$J" "$B/admin/round/$R5" | grep -c "class=\"is-free")" = 11 ]'

echo "Parents"
P=$(curl -s -D - "$B/$SLUG")
check "round page without cookie" '! echo "$P" | grep -qi "^set-cookie"'
check "new round: one appointment per person (default), notice for parents" 'echo "$P" | grep -q "Pro Kind ist nur ein Termin möglich. Für Geschwister" && curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Ein Termin pro Person ·"'
PC=$(echo "$P" | csrf)
SLOT=$(echo "$P" | grep -o 'name="slot_id" id="slot-[0-9]*" value="[0-9]*"' | head -1 | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT&name=Max&website=spam" "$B/$SLUG")
check "honeypot blocks" '[ "$code" = 422 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -d "csrf=123.abc&slot_id=$SLOT&name=Max" "$B/$SLUG")
check "invalid CSRF token → 422" '[ "$code" = 422 ]'
X=$(curl -s --data-urlencode "csrf=$PC" --data-urlencode 'name=<script>alert(1)</script>' -d "slot_id=$SLOT&email=kaputt" "$B/$SLUG")
check "XSS escaped + email validation" 'echo "$X" | grep -q "&lt;script&gt;" && ! echo "$X" | grep -q "<script>alert" && echo "$X" | grep -q "ungültig"'

# Race condition: 20 parallel bookings for the same slot
for i in $(seq 1 20); do
  curl -s -o /dev/null -w '%{http_code}\n' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT&name=Kind+$i&remember=1&email=eltern$i@example.org" "$B/$SLUG" &
done > /tmp/tp_race.$$; wait
WIN=$(grep -c '^303$' /tmp/tp_race.$$); rm -f /tmp/tp_race.$$
check "race: exactly 1 of 20 parallel bookings succeeds ($WIN)" '[ "$WIN" = 1 ]'
PUB=$(curl -s "$B/$SLUG"); check "slot no longer publicly visible" '! echo "$PUB" | grep -q "id=\"slot-$SLOT\""' || { echo "    SLOT=$SLOT"; echo "$PUB" | grep -n "id=\"slot-$SLOT\""; }

SLOT2=$(curl -s "$B/$SLUG" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT2&name=Erika+Muster&email=erika@example.org&remember=1&lang=en" "$B/$SLUG")
TOKEN=$(echo "$LOC" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
check "booking → manage link with 43-character token" '[ ${#TOKEN} = 43 ]'
M=$(curl -s "$LOC")
check "confirmation EN + localStorage marker" 'echo "$M" | grep -q "Your appointment is booked" && echo "$M" | grep -q "data-remember-round"'
check "mail notice" 'echo "$M" | grep -q "confirmation with this link has been sent"'
MAILS=$(docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T app php -r 'echo file_get_contents("http://mail:8025/api/v1/search?query=to:erika@example.org");')
check "confirmation mail with termin.ics attachment" 'echo "$MAILS" | grep -q "\"Attachments\":1"'
check "token only hashed in DB" '! docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT token_hash FROM tp_bookings" | grep -q "$TOKEN"'

MC=$(echo "$M" | csrf)
SLOT3=$(echo "$M" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$MC" -d "action=move&slot_id=$SLOT" "$B/b/$TOKEN")
check "moving to a taken slot rejected" '[ "$code" = 200 ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$MC" -d "action=move&slot_id=$SLOT3" "$B/b/$TOKEN")
check "moving to a free slot" 'echo "$loc" | grep -q "done=changed"'
check "old slot free again" 'curl -s "$B/$SLUG" | grep -q "id=\"slot-$SLOT2\""'
C=$(curl -s --data-urlencode "csrf=$MC" -d "action=cancel&confirm=1" "$B/b/$TOKEN")
check "cancellation + marker is removed" 'echo "$C" | grep -q "data-forget-url=\"/b/$TOKEN\""'
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/b/$TOKEN")
check "link invalid after cancellation (404)" '[ "$code" = 404 ]'

echo "Languages"
LP=$(curl -s "$B/$SLUG?lang=tr")
check "Turkish page with the names of all 6 languages" 'echo "$LP" | grep -q "Boş bir saat seçin" && [ "$(echo "$LP" | grep -c "name=\"switch_lang\" value=")" = 6 ] && echo "$LP" | grep -q "lang=\"uk\">Українська</button>"'
check "flag SVG served (admin translation form)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/assets/flags/uk.svg")" = 200 ]'
check "privacy page in a new language falls back to English" 'curl -s "$B/datenschutz?lang=pl" | grep -q "Controller"'
check "privacy page without Google Calendar" '! curl -s "$B/datenschutz" | grep -qiE "google[- ]?(kalender|calendar)"'

check "light/dark: theme.js before the stylesheet, switch in the footer (hidden without JS)" 'P=$(curl -s "$B/$SLUG?lang=en"); echo "$P" | tr -d "\n" | grep -q "js/theme.js?v=[0-9]*\"></script><link rel=\"stylesheet\"" && echo "$P" | grep -q "data-theme-switch role=\"group\" aria-label=\"Appearance\" hidden" && echo "$P" | grep -q "data-theme-choice=\"dark\" aria-pressed=\"false\">Dark<"'
check "light/dark: switch in the admin area too" 'curl -s -b "$J" "$B/admin" | grep -q "data-theme-choice=\"light\" aria-pressed=\"false\">Hell<"'
check "privacy page explains the stored appearance" 'curl -s "$B/datenschutz" | grep -q "Darstellung (hell/dunkel)" && curl -s "$B/datenschutz?lang=en" | grep -q "Appearance (light/dark)"'

echo "Translations (in the teacher's browser, simulated)"
DC="docker compose -f $(dirname "$0")/../dev/compose.yml"
BT=$(mktemp)
# Like app.js: read the round page data, "translate" it ("[XX] text", line by line) and send it to the server.
# btrans <round-id> [1 = redo all] [page content] → "<http-status> <response>"
btrans() {
  local page="${3:-$(curl -s -b "$J" "$B/admin/round/$1")}"
  echo "$page" | php -r '
    preg_match("/<form[^>]*data-browser-translate[^>]*>/s", stream_get_contents(STDIN), $m) or exit(1);
    preg_match_all("/data-([a-z]+)=\"([^\"]*)\"/", $m[0], $mm, PREG_SET_ORDER);
    $a = []; foreach ($mm as $x) { $a[$x[1]] = html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5); }
    $all = $argv[1] === "1";
    $tr = fn ($l, $t) => implode("\n", array_map(fn ($z) => trim($z) === "" ? $z : "[" . strtoupper($l) . "] " . $z, explode("\n", $t)));
    $q = ["csrf" => $argv[2], "hash" => $a["hash"]] + ($all ? ["force" => "1"] : []);
    foreach (array_filter(explode(",", $all ? $a["all"] : $a["pending"])) as $l) { $q["t_title"][$l] = $tr($l, $a["title"]); $q["t_description"][$l] = $tr($l, $a["description"]); }
    echo http_build_query($q);' "${2:-0}" "$T" > "$BT" || { echo "0 no-form"; return; }
  curl -s -b "$J" -H 'Accept: application/json' -w ' %{http_code}' --data-binary @"$BT" "$B/admin/round/$1/translations/auto" | awk '{c=$NF; $NF=""; sub(/ $/,""); print c " " $0}'
}
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Herbst" --data-urlencode "description=Raum 1.12" -d "source_lang=de&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+8 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
R6=$(echo "$loc" | grep -o '[0-9]*$'); A6=$(curl -s -b "$J" "$B/admin/round/$R6"); S6=$(echo "$A6" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "after creation: 5 languages offered for in-browser translation, notice for other browsers" 'echo "$A6" | grep -q "data-pending=\"en,pl,ru,uk,tr\"" && echo "$A6" | grep -q "Im Browser übersetzen (5 Sprachen)" && echo "$A6" | grep -q "data-bt-hint"'
check "without translation parents see the original" 'curl -s "$B/$S6?lang=tr" | grep -q "<h1>Elternsprechtag Herbst</h1>"'
check "browser translation without CSRF token rejected" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" -d "hash=x&t_title%5Btr%5D=boese" "$B/admin/round/$R6/translations/auto")" = 303 ] && ! curl -s "$B/$S6?lang=tr" | grep -q boese'
r=$(btrans "$R6")
check "browser translation saved ($(echo "$r" | cut -c1-18)…)" '[[ "$r" == "200 {\"saved\":5,"* ]] && curl -s -b "$J" "$B/admin/round/$R6" | grep -q "data-pending=\"\""'
check "parents see translated title/description (TR)" 'curl -s "$B/$S6?lang=tr" | grep -q "\[TR\] Elternsprechtag Herbst" && curl -s "$B/$S6?lang=tr" | grep -q "\[TR\] Raum 1.12"'
check "notice 'automatically translated' in the parents' language, not in the original" 'curl -s "$B/$S6?lang=tr" | grep -q "Başlık ve açıklama otomatik olarak çevrildi (orijinal: Deutsch)" && ! curl -s "$B/$S6?lang=de" | grep -q "data-auto-translated"'
check "original in the source language" 'curl -s "$B/$S6?lang=de" | grep -q "<h1>Elternsprechtag Herbst</h1>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka jesień" --data-urlencode "t_description[pl]=Sala 1.12" "$B/admin/round/$R6/translations"
check "translation corrected manually" 'curl -s "$B/$S6?lang=pl" | grep -q "Wywiadówka jesień"'
check "translated manually: no notice about automatic translation" '! curl -s "$B/$S6?lang=pl" | grep -q "data-auto-translated"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Herbst" --data-urlencode "description=Raum 1.12" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
check "saving without text change: nothing pending, manual correction stays" 'curl -s -b "$J" "$B/admin/round/$R6" | grep -q "data-pending=\"\"" && curl -s "$B/$S6?lang=pl" | grep -q "Wywiadówka jesień"'
check "nothing pending: another browser run changes nothing" '[[ "$(btrans "$R6")" == "200 {\"saved\":0,"* ]] && curl -s "$B/$S6?lang=pl" | grep -q "Wywiadówka jesień"'
check "retranslate all (in the browser) also overwrites the manual correction" '[[ "$(btrans "$R6" 1)" == "200 {\"saved\":5,"* ]] && curl -s "$B/$S6?lang=pl" | grep -q "<h1>\[PL\] Elternsprechtag Herbst</h1>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka jesień" --data-urlencode "t_description[pl]=Sala 1.12" -d "t_seen%5Bpl%5D=$(curl -s -b "$J" "$B/admin/round/$R6" | grep -o 'name="t_seen\[pl\]" value="[^"]*"' | sed 's/.*value="//;s/"$//')" "$B/admin/round/$R6/translations"
OLDPAGE=$(curl -s -b "$J" "$B/admin/round/$R6")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Winter" --data-urlencode "description=Raum 1.12" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
check "translation of an original changed in the meantime rejected (409)" '[ "$(btrans "$R6" 1 "$OLDPAGE")" = "409 {\"error\":\"outdated\"}" ] && ! curl -s "$B/$S6?lang=tr" | grep -q "\[TR\] Elternsprechtag Herbst"'
check "original changed → all outdated (manual ones too), browser retranslates" 'curl -s -b "$J" "$B/admin/round/$R6" | grep -q "data-pending=\"en,pl,ru,uk,tr\"" && [[ "$(btrans "$R6")" == "200 {\"saved\":5,"* ]] && curl -s "$B/$S6?lang=pl" | grep -q "\[PL\] Elternsprechtag Winter"'
P6=$(curl -s "$B/$S6?lang=uk")
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$P6" | csrf)" -d "lang=uk&slot_id=$(echo "$P6" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Kind+UK&email=uk-eltern@example.org" "$B/$S6"
UKM=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:uk-eltern@example.org"),true); echo $r["messages"][0]["Subject"] ?? "";')
check "mail to parents with translated title ($UKM)" 'echo "$UKM" | grep -q "\[UK\] Elternsprechtag Winter"'
UKT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:uk-eltern@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
check "mail to parents with notice 'automatically translated' (UK)" 'echo "$UKT" | grep -q "Назву та опис перекладено автоматично"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
check "original changed, not translated (yet): saved, parents see the original" 'curl -s "$B/$S6?lang=tr" | grep -q "<h1>FAIL</h1>" && curl -s "$B/$S6?lang=de" | grep -q "<h1>FAIL</h1>"'
# Original changed including description, not retranslated → all translations outdated
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "description=Raum 2.04" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
outdated() { curl -s -b "$J" "$B/admin/round/$R6" | grep -o "veraltet – Original geändert" | wc -l; }
check "outdated translation: parents see the original" 'P=$(curl -s "$B/$S6?lang=tr"); echo "$P" | grep -q "<h1>FAIL</h1>" && echo "$P" | grep -q "Raum 2.04" && ! echo "$P" | grep -q "\[TR\]" && [ "$(outdated)" = 5 ]'
# The form sends all languages; only Polish is changed (title, empty description)
TFORM=(); for l in en ru uk tr; do U=$(echo "$l" | tr a-z A-Z); TFORM+=(--data-urlencode "t_title[$l]=[$U] Elternsprechtag Winter" --data-urlencode "t_description[$l]=[$U] Raum 1.12"); done
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" "${TFORM[@]}" --data-urlencode "t_title[pl]=Wywiadówka" --data-urlencode "t_description[pl]=" "$B/admin/round/$R6/translations"
check "only the changed translation is saved, unchanged ones stay outdated" '[ "$(outdated)" = 4 ] && ! curl -s "$B/$S6?lang=tr" | grep -q "\[TR\]"'
check "empty translated description: original description stays" 'P=$(curl -s "$B/$S6?lang=pl"); echo "$P" | grep -q "<h1>Wywiadówka</h1>" && echo "$P" | grep -q "Raum 2.04"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" "${TFORM[@]}" -d "t_confirm%5Btr%5D=1" --data-urlencode "t_title[pl]=Wywiadówka" "$B/admin/round/$R6/translations"
check "outdated translation confirmed as current without text change" '[ "$(outdated)" = 3 ] && curl -s "$B/$S6?lang=tr" | grep -q "<h1>\[TR\] Elternsprechtag Winter</h1>"'
# Form open in two windows: the older one does not overwrite a translation changed in the meantime
seen() { echo "$1" | grep -o "name=\"t_seen\[$2\]\" value=\"[^\"]*\"" | sed 's/.*value="//;s/"$//'; }
OLDFORM=$(curl -s -b "$J" "$B/admin/round/$R6")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka nowa" -d "t_seen%5Bpl%5D=$(seen "$OLDFORM" pl)" "$B/admin/round/$R6/translations"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka" -d "t_seen%5Bpl%5D=$(seen "$OLDFORM" pl)" "$B/admin/round/$R6/translations"
check "outdated form overwrites nothing, notice shown" 'curl -s "$B/$S6?lang=pl" | grep -q "<h1>Wywiadówka nowa</h1>" && curl -s -b "$J" "$B/admin/round/$R6" | grep -q "inzwischen anderweitig geändert"'
# Duplicating with outdated and manually maintained translations
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$R6/duplicate"); RC6=$(echo "$loc" | grep -o '[0-9]*$')
C6=$(curl -s -b "$J" "$B/admin/round/$RC6")
check "copy: outdated ones stay outdated (without suffix), manually maintained ones current with suffix" '[ "$(echo "$C6" | grep -o "veraltet – Original geändert" | wc -l)" = 3 ] && echo "$C6" | grep -q "value=\"\[EN\] Elternsprechtag Winter\"" && echo "$C6" | grep -q "value=\"Wywiadówka nowa (Kopie)\"" && [ "$(echo "$C6" | grep -o ">von Hand<" | wc -l)" = 2 ] && echo "$C6" | grep -q "data-pending=\"en,ru,uk\""'
# Clearing the title = language deliberately without translation; the browser does not fill it in again
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[ru]=" "$B/admin/round/$R6/translations"
check "deliberately without translation: stays that way, not pending again, parents see the original" 'A=$(curl -s -b "$J" "$B/admin/round/$R6"); echo "$A" | grep -q "bewusst ohne Übersetzung" && ! echo "$A" | grep -o "data-pending=\"[^\"]*\"" | grep -q ru && curl -s "$B/$S6?lang=ru" | grep -q "<h1>FAIL</h1>"'
# Long description: browser returns more than 5000 characters; submitting the form unchanged changes nothing
LONGD=$(head -c 5000 /dev/zero | tr '\0' 'a')  # maximum length; "[PL] " in front gives 5005 characters
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Lange Beschreibung" --data-urlencode "description=$LONGD" -d "source_lang=de&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+9 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
RL=$(echo "$loc" | grep -o '[0-9]*$')
btrans "$RL" > /dev/null
FORM=(); for l in en pl ru uk tr; do FORM+=(--data-urlencode "t_title[$l]=$(curl -s -b "$J" "$B/admin/round/$RL" | grep -o "name=\"t_title\[$l\]\"[^>]*value=\"[^\"]*\"" | sed 's/.*value="//;s/"$//')" --data-urlencode "t_description[$l]=$(printf '[%s] %s' "$(echo $l | tr a-z A-Z)" "$LONGD" | cut -c1-5000)"); done
check "over 5000 characters: translation saved truncated, unchanged form → no changes" 'curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" "${FORM[@]}" "$B/admin/round/$RL/translations" && curl -s -b "$J" "$B/admin/round/$RL" | grep -q "Keine Änderungen" && [ "$(curl -s -b "$J" "$B/admin/round/$RL" | grep -o ">automatisch<" | wc -l)" = 5 ]'

echo "Legal texts"
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nÜbersetzung über DeepL.') WHERE page_key='privacy' AND lang='en'"
check "admin notice while the stored privacy policy mentions DeepL" 'curl -s -b "$J" "$B/admin" | grep -q "(EN) nennt noch DeepL" && curl -s -b "$J" "$B/admin/texts" | grep -q "nennt noch DeepL"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '\n\nÜbersetzung über DeepL.', '') WHERE page_key='privacy' AND lang='en'"
check "no notice anymore after adjusting" '! curl -s -b "$J" "$B/admin" | grep -q "nennt noch DeepL"'
# Earlier, unedited version of the template (with DeepL); its hash is in Migrations::OLD_PAGE_HASHES
cp "$(dirname "$0")/fixtures/privacy.de.alt.md" "$BT"
php -r '$p = new PDO("mysql:host=127.0.0.1;dbname=" . (getenv("TP_DB") ?: "talkslot") . ";charset=utf8mb4", "app", "app-dev-only"); $p->prepare("UPDATE tp_pages SET content = ? WHERE page_key = \"privacy\" AND lang = \"de\"")->execute([file_get_contents($argv[1])]);' "$BT" 2>/dev/null \
  || $DC exec -T app php -r '$p = new PDO("mysql:host=db;dbname=talkslot;charset=utf8mb4", "app", "app-dev-only"); $p->prepare("UPDATE tp_pages SET content = ? WHERE page_key = \"privacy\" AND lang = \"de\"")->execute([file_get_contents("php://stdin")]);' < "$BT"
DBQ "UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "unedited old privacy policy is replaced, even without a schema step" '! curl -s "$B/datenschutz" | grep -q "DeepL" && curl -s "$B/datenschutz" | grep -q "Translator API" && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"pages_version\"")" != alt ]'
legal_fill # the new template has open placeholders again

echo "Admin actions"
T=$(curl -s -b "$J" "$B/admin/round/$RID" | csrf)
BID=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -o '/admin/booking/[0-9]*/edit' | head -1 | grep -o '[0-9]\+')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T&name=Neu+Name&email=&lang=de" "$B/admin/booking/$BID/edit")
check "edit booking" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Neu Name"'
FREE=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -o '/admin/slot/[0-9]*/book' | head -1 | grep -o '[0-9]\+')
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Selbst+eingetragen&lang=de" "$B/admin/slot/$FREE/book"
check "entry by teacher" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "selbst eingetragen"'
FREE=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -o '/admin/slot/[0-9]*/block' | head -1 | grep -o '[0-9]\+')
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/slot/$FREE/block"
check "slot blocked → publicly invisible" '! curl -s "$B/$SLUG" | grep -q "id=\"slot-$FREE\""'
# Crafted request: an existing booking must not be moved onto a blocked slot either
# (booking a blocked slot directly is covered under "Rules the server enforces itself")
MOVE_PAGE=$(curl -s "$B/$SLUG")
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$MOVE_PAGE" | csrf)" -d "slot_id=$(echo "$MOVE_PAGE" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Verschieben+Gesperrt&lang=de" "$B/$SLUG")
MOVE_TOKEN=$(echo "$LOC" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
X=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(curl -s "$B/b/$MOVE_TOKEN" | csrf)" -d "action=move&slot_id=$FREE" "$B/b/$MOVE_TOKEN")
check "blocked slot: moving a booking there rejected" '[ ${#MOVE_TOKEN} = 43 ] && [ "$(echo "$X" | tail -1)" = 200 ] && echo "$X" | grep -q "Dieser Termin ist nicht mehr verfügbar"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_date=$TOMORROW&start_time=14:05&end_time=16:00&slot_minutes=15" "$B/admin/day/$DID/edit"
check "schedule shortened → booked slots as conflict" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Konflikt(e)"'
check "print view" 'curl -s -b "$J" "$B/admin/round/$RID/print" | grep -q "Neu Name"'
check "calendar export removed from the admin area" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$RID/calendar.ics")" = 404 ]'
btrans "$RID" > /dev/null # translations as in the browser, so that the copy can take them over
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$RID/duplicate")
RC=$(echo "$loc" | grep -o '[0-9]*$')
check "duplicate round" 'curl -s -b "$J" "$B/admin" | grep -q "(Kopie)"'
check "copy takes over current translations, nothing pending" 'A=$(curl -s -b "$J" "$B/admin/round/$RC"); echo "$A" | grep -q "(5 von 5 aktuell)" && echo "$A" | grep -q "value=\"\[TR\] Elternsprechtag Größe Übung (Kopie)\"" && echo "$A" | grep -q "data-pending=\"\""'
# Duplicate with a new first day: all days keep their distance (and their breaks)
NEWFIRST=$(date -d '+40 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T&first_date=$NEWFIRST" "$B/admin/round/$RID/duplicate"); RS=$(echo "$loc" | grep -o '[0-9]*$')
OFFS="SELECT GROUP_CONCAT(DATEDIFF(day_date, (SELECT MIN(day_date) FROM tp_round_days WHERE round_id = %s)), '/', start_time, '/', slot_minutes ORDER BY day_date) FROM tp_round_days WHERE round_id = %s"
check "duplicate with first day: days shifted together, times and breaks kept, inactive" '[ -n "$RS" ] && [ "$RS" != "$RID" ] && [ "$(DBQ "SELECT MIN(day_date) FROM tp_round_days WHERE round_id=$RS")" = "$NEWFIRST" ] && [ "$(DBQ "$(printf "$OFFS" $RS $RS)")" = "$(DBQ "$(printf "$OFFS" $RID $RID)")" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_day_breaks k JOIN tp_round_days d ON d.id=k.day_id WHERE d.round_id=$RS")" = "$(DBQ "SELECT COUNT(*) FROM tp_day_breaks k JOIN tp_round_days d ON d.id=k.day_id WHERE d.round_id=$RID")" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$RS")" = 0 ] && curl -s -b "$J" "$B/admin/round/$RS" | grep -q "erster Tag am"'
N=$(DBQ "SELECT COUNT(*) FROM tp_rounds")
X=$(curl -s -L -b "$J" -d "csrf=$T&first_date=2026-02-30" "$B/admin/round/$RID/duplicate")
check "duplicate with an invalid date: message, nothing created" 'echo "$X" | grep -q "gültiges Datum für den ersten Tag" && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds")" = "$N" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RS/delete"

echo "Regressions from the review"
# Long title: slug > 80 characters stays unchanged when editing
LONG="Gesprächstermine für die Eltern der Klassen drei a und drei b im zweiten Schulhalbjahr zwanzig sechsundzwanzig"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$LONG" \
  -d "random_suffix=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&day_dates%5B%5D=$TOMORROW&start_time=14:00&end_time=15:00&slot_minutes=10" "$B/admin/round/new")
R2=$(echo "$loc" | grep -o '[0-9]*$')
S_BEFORE=$(curl -s -b "$J" "$B/admin/round/$R2/edit" | grep -o 'id="slug" name="slug" maxlength="100" value="[^"]*"' | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$LONG" --data-urlencode "slug=$S_BEFORE" \
  -d "is_active=1&booking_deadline_hours=5&cancel_deadline_hours=0&delete_after_hours=24" "$B/admin/round/$R2/edit"
S_AFTER=$(curl -s -b "$J" "$B/admin/round/$R2/edit" | grep -o 'id="slug" name="slug" maxlength="100" value="[^"]*"' | sed 's/.*value="//;s/"$//')
check "slug of ${#S_BEFORE} characters is kept when editing" '[ ${#S_BEFORE} -gt 80 ] && [ "$S_BEFORE" = "$S_AFTER" ]'

# Conflict recalculation: a fitting booking must not be marked as a conflict
D2=$(curl -s -b "$J" "$B/admin/round/$R2" | grep -o 'id="day-[0-9]*"' | head -1 | grep -o '[0-9]*')
for s in $(curl -s -b "$J" "$B/admin/round/$R2" | grep -o '/admin/slot/[0-9]*/book' | head -2 | grep -o '[0-9]\+'); do
  curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Konflikttest+$s&lang=de" "$B/admin/slot/$s/book"
done
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_date=$TOMORROW&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/day/$D2/edit"
check "10→15 min: only 14:10 is a conflict, 14:00 stays valid" 'curl -s -b "$J" "$B/admin/round/$R2" | grep -q "<strong>1 Konflikt(e):"'

# Day with bookings: deleting without confirmation is rejected server-side
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/day/$D2/delete"
check "day with bookings not deleted without confirmation" 'curl -s -b "$J" "$B/admin/round/$R2" | grep -q "id=\"day-$D2\""'

# Deactivated round: parents can still cancel
P2=$(curl -s "$B/$(curl -s -b "$J" "$B/admin/round/$R2" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')")
S2=$(echo "$P2" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
L2=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$P2" | csrf)" -d "slot_id=$S2&name=Absagetest" "$B/$(echo "$P2" | grep -o 'action="/[^"]*"' | head -1 | sed 's#action="/##;s/"$//')")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$R2/toggle"
MP=$(curl -s "$L2")
check "deactivated round: cancellation possible, moving hidden" 'echo "$MP" | grep -q "name=\"action\" value=\"cancel\"" && ! echo "$MP" | grep -q "value=\"move\""'
C2=$(curl -s --data-urlencode "csrf=$(echo "$MP" | csrf)" -d "action=cancel&confirm=1" "$L2")
check "deactivated round: cancellation done" 'echo "$C2" | grep -q "data-forget-url"'

echo "Protection with the round code"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Geschuetzte+Runde&code_required=1&random_suffix=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&day_dates%5B%5D=$TOMORROW&start_time=10:00&end_time=11:00&slot_minutes=20" "$B/admin/round/new")
R3=$(echo "$loc" | grep -o '[0-9]*$')
AP=$(curl -s -b "$J" "$B/admin/round/$R3")
RC3=$(echo "$AP" | grep -o 'data-round-code>[A-Z0-9-]*' | sed 's/.*>//')
S3=$(echo "$AP" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "round code only: shown, invitation text mentions the code ($RC3)" '[[ "$RC3" =~ ^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$ ]] && echo "$AP" | grep -q "Code der Terminvergabe: $RC3 (wird nach dem Öffnen des Links abgefragt)" && echo "$AP" | grep -q ">Nur mit Rundencode<" && ! echo "$AP" | grep -qi "zugangscode"'
check "form: switch instead of access code field" 'F=$(curl -s -b "$J" "$B/admin/round/$R3/edit"); echo "$F" | grep -q "name=\"code_required\" value=\"1\" checked" && ! echo "$F" | grep -q "access_pin"'
G=$(curl -s "$B/$S3")
check "without code: no slots, no title" 'echo "$G" | grep -q "name=\"step\" value=\"unlock\"" && ! echo "$G" | grep -q "slot_id" && ! echo "$G" | grep -q "Geschuetzte"'
GC=$(echo "$G" | csrf)
WRONG=ZZZZZZZZ
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$GC" -d "step=unlock&code=$WRONG" "$B/$S3")
check "wrong code → 422 with notice" '[ "$code" = 422 ] && curl -s --data-urlencode "csrf=$GC" -d "step=unlock&code=$WRONG" "$B/$S3" | grep -q "Der Code ist nicht korrekt"'
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
H1=$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket='code'")
check "old PIN (digits only) on the round page → format notice, does not count" 'curl -s --data-urlencode "csrf=$GC" -d "step=unlock&code=482913" "$B/$S3" | grep -q "besteht aus 8 Buchstaben und Ziffern" && [ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")" = "$H1" ]'
U=$(curl -s --data-urlencode "csrf=$GC" --data-urlencode "code=$(echo "$RC3" | tr A-Z a-z | tr - ' ')" -d "step=unlock" "$B/$S3")
check "correct code (lower case, with spaces) → slots + access token" 'echo "$U" | grep -q "name=\"access\"" && echo "$U" | grep -q "slot_id"'
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$(echo "$U" | csrf)" -d "slot_id=$(echo "$U" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Ohne+Token" "$B/$S3")
check "booking without access token rejected" '[ "$code" = 422 ]'
ACC=$(echo "$U" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$U" | csrf)" --data-urlencode "access=$ACC" -d "slot_id=$(echo "$U" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Mit+Token" "$B/$S3")
check "booking with access token succeeds" 'echo "$loc" | grep -q "/b/"'
check "privacy summary in the form" 'echo "$U" | grep -q "Datenschutz auf einen Blick"'
SL=$(echo "$U" | grep -o 'value="[0-9]*" required' | sed -n 2p | grep -o '[0-9]*')
KEEP=$(curl -s --data-urlencode "csrf=$(echo "$U" | csrf)" --data-urlencode "access=$ACC" --data-urlencode "name=Müller Max" -d "lang=de&switch_lang=en&slot_id=$SL&email=m@example.org&remember=1" "$B/$S3")
check "language switch keeps input (slot, name, email, checkbox), does not book" 'echo "$KEEP" | grep -q "Choose a free time slot" && echo "$KEEP" | grep -q "value=\"Müller Max\"" && echo "$KEEP" | grep -q "value=\"m@example.org\"" && echo "$KEEP" | grep -q "value=\"$SL\" required checked" && echo "$KEEP" | grep -q "name=\"remember\" value=\"1\" aria-describedby=\"remember-hint\" checked" && ! echo "$KEEP" | grep -q "alert-error"'
check "language buttons submit the booking form" 'echo "$KEEP" | grep -q "form=\"booking-form\" name=\"switch_lang\" value=\"de\""'
SW=$(curl -s -d "step=switch&lang=en" --data-urlencode "access=$ACC" "$B/$S3")
check "language switch keeps access (code not needed again)" 'echo "$SW" | grep -q "slot_id" && echo "$SW" | grep -q "Choose a free time slot" && ! echo "$SW" | grep -q "name=\"step\" value=\"unlock\""'
check "language switch without valid access → code prompt" 'curl -s -d "step=switch&lang=en&access=123.abc" "$B/$S3" | grep -q "name=\"step\" value=\"unlock\""'
for i in $(seq 1 32); do curl -s -o /dev/null --data-urlencode "csrf=$GC" -d "step=unlock&code=$WRONG" "$B/$S3"; done
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$GC" --data-urlencode "code=$RC3" -d "step=unlock" "$B/$S3")
check "code guessing on the round page limited (429, also for the correct code)" '[ "$code" = 429 ]'
check "shared limit: the home page is blocked too, message without link advice" 'P=$(curl -s -d "code=$RC3" "$B/"); echo "$P" | grep -q "<li>Zu viele Fehlversuche aus Ihrem Netzwerk. Bitte versuchen Sie es in 15 Minuten erneut.</li>" && ! echo "$P" | grep -q "slot_id"'
docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -e "DELETE FROM tp_rate_limits WHERE bucket='code'"
TP=$(curl -s -b "$J" "$B/admin/round/$R3" | csrf)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TP" -d "title=Geschuetzte+Runde&code_required=1&new_code=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R3/edit"
NEW3=$(curl -s -b "$J" "$B/admin/round/$R3" | grep -o 'data-round-code>[A-Z0-9-]*' | sed 's/.*>//')
check "new round code: old code and open access token invalid, new code valid" '[ "$NEW3" != "$RC3" ] && curl -s --data-urlencode "csrf=$GC" --data-urlencode "code=$RC3" -d "step=unlock" "$B/$S3" | grep -q "Der Code ist nicht korrekt" && curl -s -d "step=switch&lang=en" --data-urlencode "access=$ACC" "$B/$S3" | grep -q "name=\"step\" value=\"unlock\"" && curl -s --data-urlencode "csrf=$GC" --data-urlencode "code=$NEW3" -d "step=unlock" "$B/$S3" | grep -q "slot_id"'
RC3=$NEW3
# Outdated form without the switch keeps the protection on; copy stays protected; switch off = no protection
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TP" -d "title=Geschuetzte+Runde&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R3/edit"
check "form without switch field (e.g. opened before the update) keeps the protection on" '[ "$(DBQ "SELECT code_required FROM tp_rounds WHERE id=$R3")" = 1 ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$TP" "$B/admin/round/$R3/duplicate"); RD3=$(echo "$loc" | grep -o '[0-9]*$')
check "copy of a protected round stays protected (own code)" '[ "$(DBQ "SELECT CONCAT(code_required, code <> \"$(echo $RC3 | tr -d -)\") FROM tp_rounds WHERE id=$RD3")" = 11 ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TP" -d "title=Kopie+offen&code_required_field=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RD3/edit"
check "switch off: round reachable via the link without protection" '[ "$(DBQ "SELECT code_required FROM tp_rounds WHERE id=$RD3")" = 0 ] && curl -s "$B/$(curl -s -b "$J" "$B/admin/round/$RD3" | grep -o "data-copy=\"[^\"]*\"" | head -1 | sed "s#.*/##;s/\"$//")" | grep -q "slot_id"'

echo "Round code (home page)"
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
rcode() { curl -s -b "$J" "$B/admin/round/$1" | grep -o 'data-round-code>[A-Z0-9-]*' | sed 's/.*>//'; }
check "code assigned right when creating/duplicating (not only when viewing)" '[ -n "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RID")" ] && [ -n "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RC")" ] && [ "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RC")" != "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RID")" ]'
AR=$(curl -s -b "$J" "$B/admin/round/$RID"); RCODE=$(rcode "$RID")
check "round code shown + in the invitation text ($RCODE)" '[[ "$RCODE" =~ ^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$ ]] && echo "$AR" | grep -q "den Code der Terminvergabe $RCODE eingeben"'
check "home page with code field" 'curl -s "$B/" | grep -q "name=\"code\""'
check "without language choice always German (the browser language does not switch)" 'curl -s -H "Accept-Language: en-US,en;q=0.9" "$B/" | grep -q "<html lang=\"de\">" && curl -s -H "Accept-Language: tr" "$B/$SLUG" | grep -q ">Name des Kindes <"'
r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -G --data-urlencode "code=$(echo "$RCODE" | tr A-Z a-z | tr - ' ')" -d lang=tr "$B/")
check "code (lower case, with spaces) → round, language kept ($r)" '[[ "$r" == "303 "*"/$SLUG?lang=tr" ]]'
CYR=$(echo "$RCODE" | sed 's/A/А/g;s/B/В/g;s/C/С/g;s/E/Е/g;s/H/Н/g;s/K/К/g;s/M/М/g;s/P/Р/g;s/T/Т/g;s/X/Х/g;s/Y/У/g;s/3/З/g;s/-/–/')
check "Cyrillic look-alikes and en dash are recognized" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -G --data-urlencode "code=$CYR" "$B/")" == *"/$SLUG" ]]'
check "home page TR: code field translated, language in the form" 'P=$(curl -s "$B/?lang=tr"); echo "$P" | grep -q "Davetiyedeki kod" && echo "$P" | grep -q "name=\"lang\" value=\"tr\""'
H0=$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")
for i in $(seq 1 35); do curl -s -o /dev/null "$B/?code=$RCODE"; done
check "successful entries do not count against the limit (35× → still 303)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$RCODE")" = 303 ] && [ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")" = "$H0" ]'
r=$(curl -s -o /dev/null -w '%{http_code}' "$B/?code=ZZZZ-ZZZZ")
check "unknown code → 422 with notice, input kept" '[ "$r" = 422 ] && P=$(curl -s "$B/?code=ZZZZ-ZZZZ"); echo "$P" | grep -q "keine offene Terminvergabe" && echo "$P" | grep -q "value=\"ZZZZ-ZZZZ\""'
check "digits only (e.g. old PIN) → format notice, does not count" 'H=$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\""); curl -s "$B/?code=482913" | grep -q "besteht aus 8 Buchstaben und Ziffern" && [ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")" = "$H" ]'
check "code of an inactive round → not found" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$(rcode "$R2")")" = 422 ]'
check "code of a protected round on the home page → unlocked directly (slots, access token)" 'P=$(curl -s "$B/?code=$(rcode "$R3")"); echo "$P" | grep -q "slot_id" && echo "$P" | grep -q "name=\"access\"" && ! echo "$P" | grep -q "name=\"step\" value=\"unlock\""'
check "home page form submits via POST (code not in the URL)" 'curl -s "$B/" | grep -q "<form method=\"post\"" && [ "$(curl -s -o /dev/null -w "%{http_code}" -d "code=$(rcode "$R3")" "$B/")" = 200 ]'
HP=$(curl -s -d "code=$(rcode "$R3")" "$B/")
S3NOW=$(curl -s -b "$J" "$B/admin/round/$R3" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
HACT=$(echo "$HP" | grep -o '<form[^>]*id="booking-form"[^>]*>' | grep -o 'action="[^"]*"' | sed 's/action="//;s/"$//')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$HP" | csrf)" --data-urlencode "access=$(echo "$HP" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')" -d "slot_id=$(echo "$HP" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Ueber+Startseite" "$B$HACT")
check "booking via the round unlocked on the home page (form to /$S3NOW)" '[ "$HACT" = "/$S3NOW" ] && echo "$loc" | grep -q "/b/"'
check "copy has its own code" 'C=$(rcode "$RC"); [ -n "$C" ] && [ "$C" != "$RCODE" ]'
OLD6=$(rcode "$R6")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "description=Raum 2.04" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0&new_code=1" "$B/admin/round/$R6/edit"
NEW6=$(rcode "$R6")
check "new round code: old one no longer valid, new one leads to the round" '[ -n "$NEW6" ] && [ "$NEW6" != "$OLD6" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$OLD6")" = 422 ] && [[ "$(curl -s -o /dev/null -w "%{redirect_url}" "$B/?code=$NEW6")" == *"/$S6" ]]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "description=Raum 2.04" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0&random_suffix=1" "$B/admin/round/$R6/edit"
check "new random part in the link → new round code too" 'N=$(rcode "$R6"); [ -n "$N" ] && [ "$N" != "$NEW6" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$NEW6")" = 422 ]'
for i in $(seq 1 35); do curl -s -o /dev/null "$B/?code=ZZZZZZZZ"; done
check "code guessing limited (429, also for valid codes)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$RCODE")" = 429 ] && curl -s "$B/?code=$RCODE" | grep -q "Zu viele Fehlversuche"'
DBQ "DELETE FROM tp_rate_limits WHERE bucket='code'"

echo "Multiple teachers"
DC="docker compose -f $(dirname "$0")/../dev/compose.yml"
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
UOUT=$(curl -s -L -b "$J" -d "csrf=$T&display_name=Herr+Zwei&email=Zwei@Example.org&role=teacher" "$B/admin/users/new")
check "teacher created with name + email only, invitation sent" 'echo "$UOUT" | grep -q "Einladung an zwei@example.org gesendet" && echo "$UOUT" | grep -q "Einladung offen"'
check "new account with role teacher is a teacher; the form offers the role" '[ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT role FROM tp_users WHERE username=\"zwei@example.org\"")" = teacher ] && curl -s -b "$J" "$B/admin/users/new" | grep -q "name=\"role\" value=\"admin\""'
WMAIL=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:zwei@example.org%20subject:Einladung"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
LINK=$(echo "$WMAIL" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
check "invitation mail: link, username, no password" '[ -n "$LINK" ] && echo "$WMAIL" | grep -q "Ihr Benutzername: zwei@example.org" && ! echo "$WMAIL" | grep -qi "passwort:"'
check "invitation mail without the old model (no contact details, not responsible)" '! echo "$WMAIL" | grep -q "Kontaktdaten für die Datenschutzhinweise" && ! echo "$WMAIL" | grep -q "datenschutzrechtlich verantwortlich"'
J2=$(mktemp)
T2=$(curl -s -c "$J2" -b "$J2" "$B/admin/login" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J2" -b "$J2" -d "csrf=$T2&username=zwei@example.org&password=irgendwas-langes" "$B/admin/login")
check "no login possible before accepting the invitation" '[ "$code" = 401 ]'
IP=$(curl -s -c "$J2" -b "$J2" "$LINK")
check "invitation link opens the password form" 'echo "$IP" | grep -q "Passwort festlegen" && echo "$IP" | grep -q "zwei@example.org"'
T2=$(echo "$IP" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" -d "new_password=kurz&new_password2=kurz" "$LINK")
check "password too short rejected" '[ "$code" = 200 ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" -d "new_password=Zweites-Passwort-123&new_password2=Zweites-Passwort-123" "$LINK")
check "password set via invitation" 'echo "$loc" | grep -q "/admin/login"'
check "invitation link invalid afterwards" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$LINK")" = 404 ]'
T2=$(curl -s -c "$J2" -b "$J2" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$T2&username=zwei@example.org&password=Zweites-Passwort-123" "$B/admin/login"
check "login with self-chosen password" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin")" = 200 ]'

# Forgot password: same response for unknown addresses, link for known ones
J3=$(mktemp); T3=$(curl -s -c "$J3" -b "$J3" "$B/admin/forgot" | csrf)
A1=$(curl -s -c "$J3" -b "$J3" --data-urlencode "csrf=$T3" -d "email=gibt-es-nicht@example.org" "$B/admin/forgot")
A2=$(curl -s -c "$J3" -b "$J3" --data-urlencode "csrf=$T3" -d "email=zwei@example.org" "$B/admin/forgot")
check "forgot password: identical response (no account enumeration)" '[ "$(echo "$A1" | grep -c "Falls zu dieser Angabe")" = 1 ] && [ "$(echo "$A2" | grep -c "Falls zu dieser Angabe")" = 1 ]'
RMAIL=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:zwei@example.org%20subject:%22Passwort%20festlegen%22"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
RLINK=$(echo "$RMAIL" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
check "reset link by mail, old password stays valid until it is used" '[ -n "$RLINK" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin")" = 200 ]'
$DC exec -T db mariadb -uapp -papp-dev-only talkslot -e "UPDATE tp_users SET access_token_expires_at = NOW() - INTERVAL 1 MINUTE WHERE username = 'zwei@example.org'"
check "expired link invalid" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$RLINK")" = 404 ]'
rm -f "$J3"

# Data separation: teacher 2 must not see or change anything of teacher 1
CODES=""
for u in "/admin/round/$RID" "/admin/round/$RID/edit" "/admin/round/$RID/print" "/admin/booking/$BID/edit" "/admin/day/$DID/edit" "/admin/users" "/admin/texts" "/admin/page/privacy/de"; do
  CODES="$CODES $(curl -s -o /dev/null -w '%{http_code}' -c "$J2" -b "$J2" "$B$u")"
done
check "foreign round/booking/day/print and admin pages → 404 ($CODES)" '[ "$(echo $CODES | tr " " "\n" | sort -u)" = "404" ]'
T2=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
for u in "/admin/round/$RID/delete" "/admin/booking/$BID/delete" "/admin/round/$RID/toggle"; do curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$T2" "$B$u"; done
curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$T2&name=Fremd+eingetragen&lang=de" "$B/admin/slot/$FREE/book"
check "foreign round/booking unchanged via POST" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Neu Name" && ! curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Fremd eingetragen"'
check "overview shows only own rounds" '! curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Elternsprechtag Größe"'

# Teacher 2: profile, own round, booking for tomorrow
curl -s -o /dev/null -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" --data-urlencode "display_name=Herr Zwei" \
  --data-urlencode $'controller_contact=Herr Zwei\nSchulweg 2\n54321 Zweistadt' -d "email=zwei@example.org&summary_evening=1&summary_deadline=1" "$B/admin/profile"
NEXT=$(date -d '+1 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" -d "title=Sprechstunde+Zwei&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$NEXT&start_time=23:00&end_time=23:40&slot_minutes=20" "$B/admin/round/new")
R4=$(echo "$loc" | grep -o '[0-9]*$')
S4=$(curl -s -c "$J2" -b "$J2" "$B/admin/round/$R4" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
P4=$(curl -s "$B/$S4")
check "round page names the teacher" 'echo "$P4" | grep -q "Gesprächstermine bei Herr Zwei"'
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$P4" | csrf)" -d "slot_id=$(echo "$P4" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Kind+Zwei&email=eltern-zwei@example.org" "$B/$S4"
DBQ "UPDATE tp_pages SET content = REPLACE(content, '$SMOKE_HOST', '[[HOSTER]]') WHERE page_key='privacy' AND lang='de'"
PRIV=$(curl -s "$B/datenschutz?r=$S4")
check "without web host in the configuration: visible placeholder, notice in the admin area" 'echo "$PRIV" | grep -qF "[HOSTER MIT ANSCHRIFT]" && ! echo "$PRIV" | grep -qF "[[HOSTER]]" && curl -s -b "$J" "$B/admin/texts" | grep -q "fehlen die Angaben zum Hoster"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '[[HOSTER]]', '$SMOKE_HOST') WHERE page_key='privacy' AND lang='de'"
check "privacy notice names the admin as controller (also for a round of teacher 2)" 'echo "$PRIV" | grep -q "Teststraße 1" && ! echo "$PRIV" | grep -q "Schulweg 2" && ! echo "$PRIV" | grep -q "Auftragsverarbeiterin"'
check "privacy notice without round and imprint: also the admin's details" 'curl -s "$B/datenschutz" | grep -q "Teststraße 1" && curl -s "$B/impressum" | grep -q "Teststraße 1" && ! curl -s "$B/impressum" | grep -q "VORNAME NACHNAME"'
check "details only on the admins' texts page, not in any profile" '! curl -s -c "$J2" -b "$J2" "$B/admin/profile" | grep -q "name=\"controller_contact\"" && ! curl -s -b "$J" "$B/admin/profile" | grep -q "name=\"controller_contact\"" && curl -s -b "$J" "$B/admin/texts" | grep -q "name=\"controller_contact\"" && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin/texts")" = 404 ]'
CC2=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
check "a teacher cannot change the details" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" --data-urlencode "csrf=$CC2" -d "controller_contact=Uebernahme" "$B/admin/texts/operator")" = 404 ] && ! curl -s "$B/impressum" | grep -q Uebernahme'
check "admin sees all rounds (with teacher) and the round of teacher 2" 'D=$(curl -s -b "$J" "$B/admin"); echo "$D" | grep -q "Alle Terminrunden" && echo "$D" | grep -q "Sprechstunde Zwei" && echo "$D" | grep -q "<td>Herr Zwei</td>" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$R4")" = 200 ]'
TA=$(curl -s -b "$J" "$B/admin" | csrf)
U2=$(curl -s -b "$J" "$B/admin/round/new" | grep -o '<option value="[0-9]*">Herr Zwei' | grep -o '[0-9]\+')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$TA" -d "user_id=$U2&title=Vom+Admin+angelegt&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$NEXT&start_time=22:00&end_time=22:40&slot_minutes=20" "$B/admin/round/new")
R5=$(echo "$loc" | grep -o '[0-9]*$')
check "admin creates a round for teacher 2: shows up for them, parents see their name" '[ -n "$R5" ] && curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Vom Admin angelegt" && curl -s "$B/$(curl -s -b "$J" "$B/admin/round/$R5" | grep -o "data-copy=\"[^\"]*\"" | head -1 | sed "s#.*/##;s/\"$//")" | grep -q "Gesprächstermine bei Herr Zwei" && curl -s -b "$J" "$B/admin/round/$R5" | grep -q "bei Herr Zwei buchen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" -d "user_id=$U2&title=Vom+Admin+bearbeitet&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "admin edits a round of teacher 2 (stays theirs)" 'curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Vom Admin bearbeitet"'
check "admin's edit form preselects the round's teacher" 'curl -s -b "$J" "$B/admin/round/$R4/edit" | grep -q "<option value=\"$U2\" selected>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" -d "title=Vom+Admin+bearbeitet&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "saving without teacher field keeps the round with the teacher" '[ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT user_id FROM tp_rounds WHERE id=$R4")" = "$U2" ]'
check "navigation: admin 'Alle Runden', teacher 'Meine Runden'" 'curl -s -b "$J" "$B/admin" | grep -q ">Alle Runden<" && curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q ">Meine Runden<"'
# Admin works in the teacher's round: day, print view, create booking (mail with the teacher's Reply-To) and delete it
D4=$(curl -s -b "$J" "$B/admin/round/$R4" | grep -o '/admin/day/[0-9]*/edit' | head -1)
check "admin: day and print view of a teacher's round" '[ -n "$D4" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B$D4")" = 200 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$R4/print")" = 200 ]'
SL4=$(curl -s -b "$J" "$B/admin/round/$R4" | grep -o '/admin/slot/[0-9]*/book' | head -1)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" -d "name=Vom+Admin+gebucht&email=admin-buchung@example.org&lang=de&send_mail=1" "$B$SL4"
BK4=$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT id FROM tp_bookings WHERE name='Vom Admin gebucht'")
AMAIL=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:admin-buchung@example.org"),true); echo json_encode($r["messages"][0]["ReplyTo"] ?? []), " ", json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "admin books in a teacher's round: mail with the teacher's Reply-To, footer names the admin" '[ -n "$BK4" ] && echo "$AMAIL" | grep -q "zwei@example.org" && echo "$AMAIL" | grep -q "Verantwortlich für die Terminvergabe: Sabrina Test" && ! echo "$AMAIL" | grep -q "Verantwortlich für die Terminvergabe: Herr Zwei"'
curl -s -o /dev/null -b "$J" -d "csrf=$TA" "$B/admin/booking/$BK4/delete"
# The message after deleting names the parents, so look at the booking list itself
check "admin deletes a booking in a teacher's round" '[ -z "$(DBQ "SELECT id FROM tp_bookings WHERE id=$BK4")" ] && ! curl -s -b "$J" "$B/admin/round/$R4" | grep -q "<strong>Vom Admin gebucht</strong>"'
TT=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
curl -s -o /dev/null -c "$J2" -b "$J2" --data-urlencode "csrf=$TT" -d "user_id=1&title=Uebernahmeversuch&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "teacher cannot change the teacher of a round" 'curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Uebernahmeversuch" && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT user_id FROM tp_rounds WHERE id=$R4")" = "$U2" ]'
# Without the details for legal notice/privacy policy: no round is public, the teacher sees the reason
ADMINCC=$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT HEX(value) FROM tp_settings WHERE name='controller_contact'")
$DC exec -T db mariadb -uapp -papp-dev-only talkslot -e "UPDATE tp_settings SET value = '' WHERE name='controller_contact'"
check "without the admin's details: rounds offline, notice for the teacher" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 404 ] && curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Es sind noch keine Angaben für Impressum und Datenschutz hinterlegt" && curl -s "$B/datenschutz" | grep -q "werden gerade ergänzt"'
TT=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$TT" "$B/admin/round/$R4/toggle"   # deactivating an active round works
TG=$(curl -s -L -c "$J2" -b "$J2" -d "csrf=$TT" "$B/admin/round/$R4/toggle")
check "without the admin's details: teacher cannot activate (matching message)" 'echo "$TG" | grep -q "erst aktiviert werden, wenn ein Admin" && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT is_active FROM tp_rounds WHERE id=$R4")" = 0 ]'
TG=$(curl -s -L -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle")
check "without the admin's details: the admin cannot activate either (matching message)" 'echo "$TG" | grep -q "tragen Sie bitte unter „Texte“ die Angaben für Impressum und Datenschutz ein" && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT is_active FROM tp_rounds WHERE id=$R4")" = 0 ]'
$DC exec -T db mariadb -uapp -papp-dev-only talkslot -e "UPDATE tp_settings SET value = UNHEX('$ADMINCC') WHERE name='controller_contact'"
curl -s -o /dev/null -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle"
check "details back: round can be activated and reached again" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 200 ]'
MAILP=$($DC exec -T app php -r 'echo file_get_contents("http://mail:8025/api/v1/search?query=to:eltern-zwei@example.org");')
check "parent mail: teacher named, replies go to the teacher" 'echo "$MAILP" | grep -q "\"ReplyTo\":\[{\"Name\":\"\",\"Address\":\"zwei@example.org\"}\]"'
PTXT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:eltern-zwei@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
check "parent mail: the admin is the controller (footer), not the teacher" 'echo "$PTXT" | grep -q "Verantwortlich für die Terminvergabe: Sabrina Test" && ! echo "$PTXT" | grep -q "Verantwortlich für die Terminvergabe: Herr Zwei"'

# Summaries
OUT=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "daily overview for tomorrow sent" 'echo "$OUT" | grep -qE "summaries_evening +[1-9]"'
OUT2=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "no duplicate sending" 'echo "$OUT2" | grep -qE "summaries_evening +0"'
SUM=$($DC exec -T app php -r 'echo file_get_contents("http://mail:8025/api/v1/search?query=to:zwei@example.org%20subject:Morgen");')
check "daily overview to teacher 2: without names unless the school allows them, so no calendar file; no parent email" 'echo "$SUM" | grep -q "\"Attachments\":0" && ! echo "$SUM" | grep -q "eltern-zwei@"'

# A parent's booking with teacher 2, to try moving it after the account is deactivated
P4B=$(curl -s "$B/$S4")
L4=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$P4B" | csrf)" -d "slot_id=$(echo "$P4B" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Kind+Verschieben" "$B/$S4")
L4=${L4%%\?*}
M4C=$(curl -s "$L4" | csrf)

# Deactivating
curl -s -o /dev/null -b "$J" -d "csrf=$T&username=zwei@example.org&display_name=Herr+Zwei&email=zwei@example.org&role=teacher" "$B/admin/users/$(curl -s -b "$J" "$B/admin/users" | grep -o '/admin/users/[0-9]*/edit' | tail -1 | grep -o '[0-9]\+')/edit"
check "deactivated account: session ended" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin")" = 303 ]'
check "deactivated account: rounds publicly 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 404 ]'
X=$(curl -s -w "\n%{http_code}" --data-urlencode "csrf=$M4C" -d "action=move&slot_id=$(echo "$P4" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')" "$L4")
check "deactivated account: parents can no longer move, only cancel" '[[ "$L4" == */b/* ]] && [ "$(echo "$X" | tail -1)" = 200 ] && echo "$X" | grep -qF "Dieser Termin ist nicht mehr verfügbar" && echo "$X" | grep -qF "value=\"cancel\""'
curl -s -o /dev/null --data-urlencode "csrf=$M4C" -d "action=cancel&confirm=1" "$L4"
E4=$(curl -s -b "$J" "$B/admin/round/$R4/edit")
check "deactivated teacher stays preselected in the form (marked)" 'echo "$E4" | grep -q "<option value=\"$U2\" selected>Herr Zwei (deaktiviert)</option>" && curl -s -b "$J" "$B/admin" | grep -q "Herr Zwei <span class=\"badge\">deaktiviert</span>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" --data-urlencode "user_id=$U2" -d "title=Titel+korrigiert&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "admin corrects a round of a deactivated teacher: stays theirs, stays offline" '[ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT CONCAT(user_id, \"/\", title) FROM tp_rounds WHERE id=$R4")" = "$U2/Titel korrigiert" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 404 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle"
TG=$(curl -s -L -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle")
check "round of a deactivated teacher cannot be activated" 'echo "$TG" | grep -q "Konto dieser Lehrkraft ist deaktiviert"'
rm -f "$J2"

echo "Single admin (migration 9) and old legal texts"
LATEST=$(grep -o 'LATEST = [0-9]*' "$(dirname "$0")/../app/src/Migrations.php" | grep -o '[0-9]*$')
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nVerantwortlich ist jeweils die Lehrkraft, bei der Sie buchen.') WHERE page_key='privacy' AND lang='de'"
check "admin notice: privacy policy in the old model" 'curl -s -b "$J" "$B/admin" | grep -q "(DE) beschreibt noch das alte Modell"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '\n\nVerantwortlich ist jeweils die Lehrkraft, bei der Sie buchen.', '') WHERE page_key='privacy' AND lang='de'"
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('alt-admin-inaktiv', 'x', 'admin', 'Alt', 'a@example.org', 0, NOW()), ('alt-admin-aktiv', 'x', 'admin', 'Neu', 'b@example.org', 1, NOW()); UPDATE tp_users SET controller_contact = 'Alte Lehrkraft-Adresse' WHERE username = 'zwei@example.org'; UPDATE tp_settings SET value = '8' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 9 (and following): only the oldest active admin account stays admin, old teacher contact details deleted" '[ "$(DBQ "SELECT GROUP_CONCAT(username) FROM tp_users WHERE role=\"admin\"")" = sabrina ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_users WHERE role<>\"admin\" AND controller_contact IS NOT NULL")" = 0 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && curl -s "$B/datenschutz" | grep -q "Teststraße 1"'
DBQ "ALTER TABLE tp_rounds ADD COLUMN access_pin VARCHAR(12) NULL; UPDATE tp_rounds SET code_required = 0; UPDATE tp_rounds SET access_pin = '123456' WHERE id = $RID; UPDATE tp_rounds SET access_pin = '' WHERE id = $R2; UPDATE tp_settings SET value = '9' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 10: only rounds with an old PIN become 'round code only' (empty/NULL stay open)" '[ "$(DBQ "SELECT code_required FROM tp_rounds WHERE id=$RID")" = 1 ] && [ "$(DBQ "SELECT SUM(code_required) FROM tp_rounds")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'

DBQ "ALTER TABLE tp_rounds DROP COLUMN booking_limit; UPDATE tp_settings SET value = '10' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 11: existing rounds without limit (as before), new column present" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE booking_limit <> \"none\"")" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds")" -gt 0 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'

# Migration 13: the details move from the admin's profile into the settings (before that they are read from the profile)
DBQ "DELETE FROM tp_settings WHERE name = 'controller_contact'; UPDATE tp_users SET controller_contact = 'Alte Schule\nAlter Weg 1\nalt@example.org' WHERE username = 'sabrina'; UPDATE tp_settings SET value = '12' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 13: details taken over from the admin's profile" '[ "$(DBQ "SELECT SUBSTRING_INDEX(value, CHAR(10), 1) FROM tp_settings WHERE name=\"controller_contact\"")" = "Alte Schule" ] && curl -s "$B/impressum" | grep -q "Alter Weg 1" && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'
T=$(curl -s -b "$J" "$B/admin" | csrf)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode $'controller_contact=Sabrina Test\nTeststraße 1\n12345 Teststadt\nE-Mail: sabrina@example.org' "$B/admin/texts/operator"
check "saving the details also updates the admin's profile (read by older versions after a rollback)" '[ "$(DBQ "SELECT SUBSTRING_INDEX(controller_contact, CHAR(10), 1) FROM tp_users WHERE username=\"sabrina\"")" = "Sabrina Test" ] && curl -s "$B/impressum" | grep -q "Teststraße 1"'
DBQ "ALTER TABLE tp_rounds DROP COLUMN location; ALTER TABLE tp_round_days DROP COLUMN location; UPDATE tp_settings SET value = '13' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 14: location columns added, rounds still reachable" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=\"location\" AND TABLE_NAME IN (\"tp_rounds\",\"tp_round_days\")")" = 2 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$RID")" = 200 ]'
DBQ "ALTER TABLE tp_rounds DROP COLUMN remind_parents; ALTER TABLE tp_bookings DROP INDEX uq_bookings_reminder_token, DROP COLUMN reminded_at, DROP COLUMN reminder_token_hash; UPDATE tp_settings SET value = '14' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 15: reminder columns added, existing rounds remind" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME=\"tp_rounds\" AND COLUMN_NAME=\"remind_parents\") OR (TABLE_NAME=\"tp_bookings\" AND COLUMN_NAME IN (\"reminded_at\",\"reminder_token_hash\")))")" = 3 ] && [ "$(DBQ "SELECT MIN(remind_parents) FROM tp_rounds")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'
DBQ "DROP TABLE tp_audit_log; UPDATE tp_settings SET value = '15' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 16: log table created" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_audit_log\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/log")" = 200 ]'
DBQ "DROP TABLE tp_round_templates; UPDATE tp_settings SET value = '16' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 17: template table created" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_round_templates\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/templates")" = 200 ]'
DBQ "ALTER TABLE tp_users DROP COLUMN lang; UPDATE tp_settings SET value = '17' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 18: language column for accounts, existing ones keep the default (German)" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_users\" AND COLUMN_NAME=\"lang\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && curl -s -b "$J" "$B/admin" | grep -q "<html lang=\"de\""'

echo "Web cron"
CTOK=$(printf 'cron' | openssl dgst -sha256 -hmac "$(printf 'd%.0s' {1..64})" | sed 's/.*= //')
check "web cron without/with wrong key → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST "$B/cron")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "X-Cron-Token: falsch" "$B/cron")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/cron")" = 404 ]'
check "web cron with key runs" 'curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron" | grep -q "\"status\":\"ok\""'
check "tables with prefix tp_" '[ "$(docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SHOW TABLES" | grep -vc "^tp_")" = 0 ]'

echo "Limiting multiple bookings"
T=$(curl -s -b "$J" "$B/admin" | csrf)
check "form: 'one appointment per person' preselected for new rounds" 'curl -s -b "$J" "$B/admin/round/new" | grep -q "<option value=\"round\" selected>Ein Termin pro Person in dieser Runde</option>"'
LD1=$(date -d '+6 day' +%F); LD2=$(date -d '+7 day' +%F)
mkround() { curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=$1&booking_limit=$2&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$LD1&day_dates%5B%5D=$LD2&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$'; }
slugof() { curl -s -b "$J" "$B/admin/round/$1" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//'; }
# book <slug> <name> <email> [n-th free slot] → status and, if any, manage link
book() { local P; P=$(curl -s "$B/$1"); curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf=$(echo "$P" | csrf)" --data-urlencode "name=$2" --data-urlencode "email=$3" -d "slot_id=$(echo "$P" | grep -o 'value="[0-9]*" required' | sed -n "${4:-1}p" | grep -o '[0-9]*')" "$B/$1"; }
LR=$(mkround Sperre+Runde round); LRS=$(slugof "$LR")
check "round: first booking" '[[ "$(book "$LRS" "Max Muster" "eltern-a@example.org")" == 303* ]]'
R=$(book "$LRS" "  max   MUSTER " "")
check "round: same name without email (spelled differently) rejected" '[[ "$R" == 422* ]] && P=$(curl -s "$B/$LRS"); curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=max+muster&slot_id=$(echo "$P" | grep -o "value=\"[0-9]*\" required" | head -1 | grep -o "[0-9]*")" "$B/$LRS" | grep -q "Für diesen Namen gibt es hier bereits einen Termin"'
check "round: same name, same email (other day) rejected" '[[ "$(book "$LRS" "Max Muster" "Eltern-A@example.org" 5)" == 422* ]]'
check "round: siblings (other name, same email) allowed" '[[ "$(book "$LRS" "Erika Muster" "eltern-a@example.org")" == 303* ]]'
check "round: same name, other email (other family) allowed" '[[ "$(book "$LRS" "Max Muster" "eltern-b@example.org")" == 303* ]]'
check "round: teacher's entry not limited" 'curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Max+Muster&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$LR" | grep -o "/admin/slot/[0-9]*/book" | head -1)" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id=b.slot_id JOIN tp_round_days d ON d.id=s.day_id WHERE d.round_id=$LR AND b.name=\"Max Muster\"")" = 3 ]'
LT=$(mkround Sperre+Tag day); LTS=$(slugof "$LT")
check "day: notice 'one appointment per person and day'" 'curl -s "$B/$LTS" | grep -q "Pro Kind ist nur ein Termin pro Tag möglich" && curl -s -b "$J" "$B/admin/round/$LT" | grep -q "Ein Termin pro Person und Tag ·"'
TOM1=$(book "$LTS" "Tom" "")
check "day: second appointment on the same day rejected" '[[ "$(book "$LTS" "Tom" "")" == 422* ]] && P=$(curl -s "$B/$LTS"); curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=Tom&slot_id=$(echo "$P" | grep -o "value=\"[0-9]*\" required" | head -1 | grep -o "[0-9]*")" "$B/$LTS" | grep -q "an diesem Tag bereits einen Termin"'
TOM2=$(book "$LTS" "Tom" "" 4)
check "day: allowed on another day" '[[ "$TOM2" == 303* ]]'
M2=$(curl -s "${TOM2#* }"); MC2=$(echo "$M2" | csrf)
DAY1SLOT=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$LT AND d.day_date=\"$LD1\" AND b.id IS NULL ORDER BY s.start_time LIMIT 1")
check "day: moving to a day with an own appointment rejected" 'curl -s --data-urlencode "csrf=$MC2" -d "action=move&slot_id=$DAY1SLOT" "${TOM2#* }" | grep -q "an diesem Tag bereits einen Termin"'
DAY2SLOT=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$LT AND d.day_date=\"$LD2\" AND b.id IS NULL ORDER BY s.start_time LIMIT 1")
check "day: moving on the same day allowed" 'curl -s -o /dev/null -w "%{redirect_url}" --data-urlencode "csrf=$MC2" -d "action=move&slot_id=$DAY2SLOT" "${TOM2#* }" | grep -q "done=changed"'
LN=$(mkround Ohne+Sperre none); LNS=$(slugof "$LN")
check "no limit: old notice, same name possible multiple times" 'curl -s "$B/$LNS" | grep -qF "Bitte buchen Sie nur einen Termin pro Kind." && [[ "$(book "$LNS" "Ida" "")" == 303* ]] && [[ "$(book "$LNS" "Ida" "")" == 303* ]]'
# Concurrent: 10 parallel bookings with the same name for different slots → exactly one
LP=$(mkround Sperre+parallel round); LPS=$(slugof "$LP"); PP=$(curl -s "$B/$LPS"); PPC=$(echo "$PP" | csrf)
for i in $(seq 1 8); do
  curl -s -o /dev/null -w '%{http_code}\n' --data-urlencode "csrf=$PPC" -d "name=Gleichzeitig&slot_id=$(echo "$PP" | grep -o 'value="[0-9]*" required' | sed -n "${i}p" | grep -o '[0-9]*')" "$B/$LPS" &
done > /tmp/tp_limit.$$; wait
check "concurrent bookings by the same person: exactly one succeeds ($(grep -c '^303$' /tmp/tp_limit.$$))" '[ "$(grep -c "^303$" /tmp/tp_limit.$$)" = 1 ]'
rm -f /tmp/tp_limit.$$
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Sperre+Tag&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$LT/edit"
check "form without the field (opened before the update) keeps the setting" '[ "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$LT")" = day ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$LT/duplicate")
check "copy takes over the setting" '[ "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$(echo "$loc" | grep -o "[0-9]*$")")" = day ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Sperre+Tag&booking_limit=kaputt&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$LT/edit"
check "invalid value changes nothing" '[ "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$LT")" = day ]'

echo "More admin flows"
T=$(curl -s -b "$J" "$B/admin" | csrf)
has() { grep -qF -- "$1"; }   # search for a fixed string
check "login page while logged in → overview" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -b "$J" "$B/admin/login")" == */admin ]]'
JL=$(mktemp); curl -s -o /dev/null -c "$JL" -b "$JL" "$B/admin/login"
R=$(curl -s -c "$JL" -b "$JL" -d "csrf=alt&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")
check "login with expired form → notice, no login" 'echo "$R" | has "Das Formular ist abgelaufen" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JL" "$B/admin")" = 303 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JL" -b "$JL" -d "csrf=$(curl -s -c "$JL" -b "$JL" "$B/admin/login" | csrf)&username=zwei@example.org&password=Zweites-Passwort-123" "$B/admin/login")
check "deactivated account: login rejected like with a wrong password" '[ "$code" = 401 ]'
rm -f "$JL"

NEWR() { curl -s -b "$J" --data-urlencode "csrf=$T" "$@" "$B/admin/round/new"; }
FD="booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+4 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30"
R=$(NEWR --data-urlencode "description=$(head -c 5001 /dev/zero | tr '\0' a)" -d "title=&booking_deadline_hours=999&cancel_deadline_hours=0&delete_after_hours=99999&day_dates%5B%5D=$(date -d '+4 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30")
check "round: title missing, description too long, deadlines invalid → messages" 'echo "$R" | has "Bitte einen Titel" && echo "$R" | has "Beschreibungstext ist zu lang" && echo "$R" | has "ganze Zahl zwischen 0 und 720" && echo "$R" | has "Löschfrist"'
check "round for deactivated teacher rejected" 'NEWR -d "title=X&user_id=$U2&$FD" | has "Bitte eine aktive Lehrkraft"'
MANY=$(for i in $(seq 1 31); do printf '&day_dates%%5B%%5D=%s' "$(date -d "+$((i + 40)) day" +%F)"; done)
check "more than 30 days at once rejected" 'NEWR -d "title=Viele&booking_deadline_hours=0&cancel_deadline_hours=0&start_time=10:00&end_time=11:00&slot_minutes=30$MANY" | has "Höchstens 30 Tage"'
check "invalid date rejected" 'NEWR -d "title=X&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=2026-02-30&start_time=10:00&end_time=11:00&slot_minutes=30" | has "Ungültiges Datum: 2026-02-30"'
R=$(NEWR -d "title=X&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+4 day' +%F)&start_time=11:00&end_time=10:00&slot_minutes=300")
check "times: end before start and duration over 240 minutes → messages" 'echo "$R" | has "erster Termin vor dem Ende" && echo "$R" | has "Dauer pro Termin: 5 bis 240 Minuten"'
check "time range shorter than one slot → no slot, rejected" 'NEWR -d "title=X&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d "+4 day" +%F)&start_time=10:00&end_time=10:10&slot_minutes=30" | has "entsteht kein einziger Termin"'

# Separate round for the following flows: 2 days with 2 slots each
DA=$(date -d '+4 day' +%F); DB2=$(date -d '+11 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Abdeckung&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$DA&day_dates%5B%5D=$DB2&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
RA=$(echo "$loc" | grep -o '[0-9]*$'); PA=$(curl -s -b "$J" "$B/admin/round/$RA")
DAY1=$(echo "$PA" | grep -o 'id="day-[0-9]*"' | sed -n 1p | grep -o '[0-9]*'); DAY2=$(echo "$PA" | grep -o 'id="day-[0-9]*"' | sed -n 2p | grep -o '[0-9]*')
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "slug=Eigener Link Ä" -d "title=Abdeckung&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RA/edit"
SA=$(curl -s -b "$J" "$B/admin/round/$RA" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "custom link text becomes the slug ($SA)" '[ "$SA" = eigener-link-ae ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$SA")" = 200 ]'
check "edit day: existing date rejected" 'curl -s -b "$J" -d "csrf=$T&day_date=$DA&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/day/$DAY2/edit" | has "Diesen Tag gibt es in der Runde bereits"'
check "edit day: times without any slot rejected" 'curl -s -b "$J" -d "csrf=$T&day_date=$DB2&start_time=10:00&end_time=10:10&slot_minutes=30" "$B/admin/day/$DAY2/edit" | has "entsteht kein einziger Termin"'
check "break with end before start rejected" 'curl -s -L -b "$J" -d "csrf=$T&start_time=10:30&end_time=10:00&label=" "$B/admin/day/$DAY1/break" | has "Pause: Bitte gültige Start- und Endzeit"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&start_time=10:30&end_time=11:00&label=Kurz" "$B/admin/day/$DAY2/break"
BRK=$(curl -s -b "$J" "$B/admin/round/$RA" | grep -o '/admin/break/[0-9]*/delete' | head -1)
check "remove break: slot back again" '[ -n "$BRK" ] && curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B$BRK" && [ "$(curl -s -b "$J" "$B/admin/round/$RA" | grep -c "class=\"is-free")" = 4 ]'

# Manual entries: form, errors, taken slot, editing, moving with mail
SLOTS=($(curl -s -b "$J" "$B/admin/round/$RA" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
check "form 'create entry'" 'curl -s -b "$J" "$B/admin/slot/${SLOTS[0]}/book" | has "Eintrag anlegen"'
check "entry without name rejected" 'curl -s -b "$J" -d "csrf=$T&name=&lang=de" "$B/admin/slot/${SLOTS[0]}/book" | has "Bitte geben Sie einen Namen an"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hand+Eins&email=hand@example.org&lang=de" "$B/admin/slot/${SLOTS[0]}/book"
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hand+Zwei&lang=de" "$B/admin/slot/${SLOTS[1]}/book"
check "taken slot: second entry rejected" 'curl -s -L -b "$J" -d "csrf=$T&name=Doppelt&lang=de" "$B/admin/slot/${SLOTS[0]}/book" | has "Dieser Termin ist bereits gebucht" && ! curl -s -b "$J" "$B/admin/round/$RA" | has "Doppelt"'
BK1=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hand Eins'")
check "form 'edit entry' with move target" 'curl -s -b "$J" "$B/admin/booking/$BK1/edit" | has "Eintrag bearbeiten"'
check "editing without name rejected" 'curl -s -b "$J" -d "csrf=$T&name=&lang=de" "$B/admin/booking/$BK1/edit" | has "Bitte geben Sie einen Namen an"'
check "moving to a taken slot: nothing changed" 'curl -s -b "$J" -d "csrf=$T&name=Hand+Eins&lang=de&move_to=${SLOTS[1]}" "$B/admin/booking/$BK1/edit" | has "nicht mehr frei" && [ "$(DBQ "SELECT slot_id FROM tp_bookings WHERE id=$BK1")" = "${SLOTS[0]}" ]'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hand+Eins&email=hand@example.org&lang=de&send_mail=1&move_to=${SLOTS[2]}" "$B/admin/booking/$BK1/edit")
check "moving to a free slot with mail to the parents" 'echo "$R" | has "Änderung per E-Mail mitgeteilt" && [ "$(DBQ "SELECT slot_id FROM tp_bookings WHERE id=$BK1")" = "${SLOTS[2]}" ]'

# Assign a round code retroactively (rounds from before codes existed)
DBQ "UPDATE tp_rounds SET code = NULL WHERE id = $RA"
check "round without code gets one when viewed" '[[ "$(rcode "$RA")" =~ ^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$ ]] && [ -n "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RA")" ]'
# Expired round: a new day makes it activatable again
DBQ "UPDATE tp_rounds SET expired_at = NOW(), is_active = 0 WHERE id = $RA"
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=10:30&slot_minutes=30" "$B/admin/round/$RA/day"
check "new day in an expired round lifts the expiry" '[ "$(DBQ "SELECT expired_at IS NULL FROM tp_rounds WHERE id=$RA")" = 1 ]'
DAY3=$(curl -s -b "$J" "$B/admin/round/$RA" | grep -o 'id="day-[0-9]*"' | sed -n 3p | grep -o '[0-9]*')
check "delete day without bookings" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/day/$DAY3/delete" | has "Tag gelöscht." && ! curl -s -b "$J" "$B/admin/round/$RA" | has "id=\"day-$DAY3\""'
check "delete day with bookings after confirmation" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/day/$DAY2/delete" | has "inkl. 1 Buchung(en)"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RA/toggle"

# Profile: notices and errors
ACC0=$(DBQ "SELECT CONCAT(HEX(display_name), ':', (SELECT HEX(value) FROM tp_settings WHERE name='controller_contact'), ':', HEX(email)) FROM tp_users WHERE role='admin'")
DBQ "UPDATE tp_users SET display_name = '', email = '' WHERE role='admin'; UPDATE tp_settings SET value = 'Nur Name' WHERE name='controller_contact'"
R=$(curl -s -b "$J" "$B/admin")
check "profile notices on the overview: display name, email in the imprint, email for summaries missing" 'echo "$R" | has "Anzeigename fehlt" && echo "$R" | has "fehlt eine E-Mail-Adresse (für das Impressum" && echo "$R" | has "Zusammenfassungen können nicht versendet werden"'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ohne+Anzeigename&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+13 day' +%F)&start_time=10:00&end_time=10:30&slot_minutes=30" "$B/admin/round/new")
RN=$(echo "$loc" | grep -o '[0-9]*$')
check "own round cannot be activated without display name" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/round/$RN/toggle" | has "tragen Sie bitte unter „Mein Profil“ Ihren Anzeigenamen ein"'
DBQ "UPDATE tp_users SET display_name = UNHEX('${ACC0%%:*}'), email = UNHEX('${ACC0##*:}') WHERE role='admin'; UPDATE tp_settings SET value = UNHEX('$(echo "$ACC0" | cut -d: -f2)') WHERE name='controller_contact'"
R=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "display_name=&email=kaputt" "$B/admin/profile")
check "profile: missing display name and invalid email rejected" 'echo "$R" | has "Bitte einen Anzeigenamen angeben" && echo "$R" | has "Die E-Mail-Adresse ist ungültig"'
check "delete round" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/round/$RN/delete" | has "Runde in den Papierkorb gelegt" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$RN")" ]'

# Change password (profile) and forced change
PW() { curl -s -L -c "$J" -b "$J" -d "csrf=$T" --data-urlencode "current_password=$1" --data-urlencode "new_password=$2" --data-urlencode "new_password2=$3" "$B/admin/${4:-password}"; }
check "password: current one wrong" 'PW falsch Neues-Passwort-123 Neues-Passwort-123 | has "Das aktuelle Passwort ist falsch"'
check "password: repetition differs" 'PW "$PASS" Neues-Passwort-123 Anderes-Passwort-1 | has "Die neuen Passwörter stimmen nicht überein"'
check "password: too short" 'PW "$PASS" kurz kurz | has "mindestens 12 Zeichen"'
check "password: same as the previous one" 'PW "$PASS" "$PASS" "$PASS" | has "muss sich vom bisherigen unterscheiden"'
check "password changed" 'PW "$PASS" Neues-Passwort-123 Neues-Passwort-123 | has "Passwort geändert."'
T=$(curl -s -b "$J" "$B/admin" | csrf)
DBQ "UPDATE tp_users SET must_change_password = 1 WHERE role='admin'"
check "initial password: every page leads to the password change" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -b "$J" "$B/admin/round/$RA")" == */admin/password-change ]] && curl -s -b "$J" "$B/admin/password-change" | has "Neues Passwort festlegen"'
check "initial password: error is shown" 'PW Neues-Passwort-123 "$PASS" anders-anders-1 password-change | has "Die neuen Passwörter stimmen nicht überein"'
check "initial password replaced, then continue normally" 'PW Neues-Passwort-123 "$PASS" "$PASS" password-change | has "Passwort festgelegt" && [ "$(DBQ "SELECT must_change_password FROM tp_users WHERE role=\"admin\"")" = 0 ]'
T=$(curl -s -b "$J" "$B/admin" | csrf)

# User management: errors, editing, resending the link, deleting
R=$(curl -s -b "$J" -d "csrf=$T&display_name=&email=kaputt" "$B/admin/users/new")
check "new account: name missing, email invalid" 'echo "$R" | has "Bitte einen Namen angeben" && echo "$R" | has "Bitte eine gültige E-Mail-Adresse"'
check "new account: email already taken" 'curl -s -b "$J" -d "csrf=$T&display_name=Doppelt&email=zwei@example.org" "$B/admin/users/new" | has "gibt es bereits ein Konto"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Drei&email=drei@example.org" "$B/admin/users/new"
U3=$(DBQ "SELECT id FROM tp_users WHERE username='drei@example.org'"); UA=$(DBQ "SELECT id FROM tp_users WHERE role='admin'")
R=$(curl -s -b "$J" "$B/admin/users/$U3/edit")
check "edit account: username, resend invitation, delete" 'echo "$R" | has "name=\"username\"" && echo "$R" | has "Einladung erneut senden" && echo "$R" | has "Ja, endgültig löschen"'
check "invalid username rejected" 'curl -s -b "$J" -d "csrf=$T&username=a b&display_name=Frau+Drei&email=drei@example.org&is_active=1" "$B/admin/users/$U3/edit" | has "Benutzername: 3–254 Zeichen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "user_id=$U3&title=Abdeckung&is_active=0&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RA/edit"
check "round with bookings handed over to another teacher → notice" '[ "$(DBQ "SELECT user_id FROM tp_rounds WHERE id=$RA")" = "$U3" ] && curl -s -b "$J" "$B/admin/round/$RA" | has "Die Runde gehört jetzt Frau Drei. Die 1 bestehende(n) Buchung(en)"'
DBQ "UPDATE tp_users SET display_name = '' WHERE id = $U3"
check "round of a teacher without display name cannot be activated" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/round/$RA/toggle" | has "Für diese Lehrkraft fehlt ein Anzeigename"'
DBQ "UPDATE tp_users SET display_name = 'Frau Drei' WHERE id = $U3"
check "own password cannot be reset via user management" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$UA/reset-password" | has "Ihr eigenes Passwort ändern Sie unter „Mein Profil“"'
DBQ "UPDATE tp_users SET email = NULL WHERE id = $U3"
check "resend link without email address → notice" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$U3/reset-password" | has "keine E-Mail-Adresse hinterlegt"'
DBQ "UPDATE tp_users SET email = 'drei@example.org' WHERE id = $U3"
check "invitation resent" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$U3/reset-password" | has "Einladung an drei@example.org gesendet"'
LINK3=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:drei@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];' | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
J4=$(mktemp); curl -s -o /dev/null -c "$J4" -b "$J4" "$LINK3"
check "invitation with expired form → notice, link stays valid" 'curl -s -c "$J4" -b "$J4" -d "csrf=alt&new_password=Drittes-Passwort-1&new_password2=Drittes-Passwort-1" "$LINK3" | has "Das Formular ist abgelaufen" && [ "$(curl -s -o /dev/null -w "%{http_code}" "$LINK3")" = 200 ]'
rm -f "$J4"
check "own account cannot be deleted" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$UA/delete" | has "eigenes Konto nicht löschen"'
check "deleting without confirmation rejected" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$U3/delete" | has "Bitte das Löschen ausdrücklich bestätigen" && [ -n "$(DBQ "SELECT id FROM tp_users WHERE id=$U3")" ]'

# Editing legal texts
check "text form; unknown language → 404" 'curl -s -b "$J" "$B/admin/page/imprint/de" | has "name=\"content\"" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/page/imprint/xx")" = 404 ]'
IMP=$(DBQ "SELECT HEX(content) FROM tp_pages WHERE page_key='imprint' AND lang='de'")
curl -s -o /dev/null -b "$J" -d "csrf=$T" --data-urlencode $'content=# Impressum\r\nOhne Platzhalter' "$B/admin/page/imprint/de"
check "text saved (line endings normalized), notice about missing placeholder" 'curl -s "$B/impressum" | has "Ohne Platzhalter" && [ "$(DBQ "SELECT LOCATE(CHAR(13), content) FROM tp_pages WHERE page_key=\"imprint\" AND lang=\"de\"")" = 0 ] && curl -s -b "$J" "$B/admin/texts" | has "enthalten den Platzhalter [[VERANTWORTLICHE]] nicht"'
DBQ "UPDATE tp_pages SET content = UNHEX('$IMP') WHERE page_key='imprint' AND lang='de'"

echo "More parent flows"
check "booking for unknown round → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" -d "slot_id=1&name=X" "$B/gibt-es-nicht-xyz")" = 404 ]'
SP=$(curl -s -b "$J" "$B/admin/round/$R6" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')  # current link (random part was regenerated above)
P=$(curl -s "$B/$SP")
R=$(curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=" "$B/$SP")
check "booking without slot and name → both notices" 'echo "$R" | has "Bitte wählen Sie einen Termin" && echo "$R" | has "Bitte geben Sie einen Namen an"'
DBQ "UPDATE tp_rounds SET code_required = 1 WHERE id = $R3" # protection was reset by the migration test above
S3C=$(curl -s -b "$J" "$B/admin/round/$R3" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')  # current link
G=$(curl -s "$B/$S3C")
check "protected round shows the code prompt" 'echo "$G" | has "name=\"step\" value=\"unlock\""'
check "code prompt with expired form → notice" 'curl -s -d "csrf=alt&step=unlock&code=$RC3" "$B/$S3C" | has "Das Formular ist abgelaufen"'
# Rate limits: raise the counters for this client directly. The key is the one the server already uses
# for our bookings (with Docker the client is the bridge gateway, not 127.0.0.1); IPv4/IPv6 localhost as fallback.
KC=$(DBQ "SELECT key_hash FROM tp_rate_limits WHERE bucket = 'booking' ORDER BY hits DESC LIMIT 1")
K4=$(php -r 'echo hash_hmac("sha256", "ip|127.0.0.0", str_repeat("d", 64));'); K6=$(php -r 'echo hash_hmac("sha256", "ip|::", str_repeat("d", 64));')
LIMIT() { local k; for k in $KC $K4 $K6; do DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('$1', '$k', UNIX_TIMESTAMP(), 100000) ON DUPLICATE KEY UPDATE hits = 100000, window_start = UNIX_TIMESTAMP()"; done; }
LIMIT attempt
check "too many booking attempts → 429" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$(echo "$P" | csrf)" -d "slot_id=1&name=Max" "$B/$SP")" = 429 ]'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'attempt'" # each limit on its own, so the right one answers
LIMIT code
X=$(curl -s -w "\n%{http_code}" --data-urlencode "csrf=$(echo "$G" | csrf)" -d "step=unlock&code=123" "$B/$S3C")
check "blocked: also malformed codes on the round page → 429 with code notice" '[ "$(echo "$X" | tail -1)" = 429 ] && echo "$X" | has "Zu viele Fehlversuche aus Ihrem Netzwerk"'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'code'"; DBQ "UPDATE tp_rounds SET code_required = 0 WHERE id = $R3"
LIMIT manage
check "too many manage link requests → 429" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/b/$(head -c 43 /dev/zero | tr "\0" A)")" = 429 ]'
X=$(curl -s -w "\n%{http_code}" -d "csrf=x&action=cancel" "$B/b/$(head -c 43 /dev/zero | tr "\0" A)")
check "too many manage link submissions (POST) → 429" '[ "$(echo "$X" | tail -1)" = 429 ] && echo "$X" | has "Zu viele Anfragen"'
LIMIT login
JX=$(mktemp); TX=$(curl -s -c "$JX" -b "$JX" "$B/admin/login" | csrf)
check "too many login attempts: the correct password is rejected too (without hint about the account)" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JX" -b "$JX" -d "csrf=$TX&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")" = 401 ] && curl -s -c "$JX" -b "$JX" -d "csrf=$TX&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login" | grep -q "Anmeldung fehlgeschlagen. Bitte prüfen Sie Ihre Eingaben"'
rm -f "$JX"
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('attempt', 'code', 'manage', 'login')"
# Admin session expires after 30 minutes of inactivity (separate session, the main session stays)
JS=$(mktemp); TS=$(curl -s -c "$JS" -b "$JS" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$JS" -b "$JS" -d "csrf=$TS&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
SID=$(grep tp_admin "$JS" | awk '{print $7}')
ALERTS '$p = "/srv/app/app/var/sessions/sess_'"$SID"'"; file_put_contents($p, preg_replace("/last\|i:\d+;/", "last|i:" . (time() - 31 * 60) . ";", file_get_contents($p)));'
check "admin session expired after 30 minutes of inactivity (notice, new login required)" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JS" -b "$JS" "$B/admin")" == */admin/login ]] && curl -s -c "$JS" -b "$JS" "$B/admin/login" | grep -q "Die Sitzung ist abgelaufen"'
rm -f "$JS"
# Rules the server enforces itself (tampered form)
PB=$(curl -s "$B/$SP"); PBC=$(echo "$PB" | csrf)
# The only free slot of the round (the second match used to be the privacy checkbox, value="1" required, which the
# form no longer has since expert review P1-9), blocked for the check and unblocked again
FREEB=$(echo "$PB" | grep -o 'name="slot_id" id="slot-[0-9]*" value="[0-9]*" required' | head -1 | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/slot/$FREEB/block"
check "blocked slot cannot be booked via a tampered form" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$PBC" -d "slot_id=$FREEB&name=Gesperrt+Test" "$B/$SP"); [ -n "$FREEB" ] && [[ "$R" == *422 ]] && echo "$R" | grep -q "Dieser Termin ist nicht mehr verfügbar" && [ "$(DBQ "SELECT is_blocked FROM tp_slots WHERE id = $FREEB")" = 1 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/slot/$FREEB/block"
OTHER=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$LT AND b.id IS NULL LIMIT 1")
check "slot of another round cannot be booked via a tampered form" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$PBC" -d "slot_id=$OTHER&name=Fremd+Test" "$B/$SP"); [[ "$R" == *422 ]] && echo "$R" | grep -q "Dieser Termin ist nicht mehr verfügbar" && [ -z "$(DBQ "SELECT id FROM tp_bookings WHERE name=\"Fremd Test\"")" ]'
FREEC=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$R6 AND b.id IS NULL AND s.is_blocked=0 LIMIT 1")
DBQ "UPDATE tp_rounds SET booking_deadline_hours = 720 WHERE id = $R6"
check "after the booking deadline nothing can be booked via a tampered form" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$PBC" -d "slot_id=$FREEC&name=Frist+Test" "$B/$SP"); [[ "$R" == *422 ]] && echo "$R" | grep -q "die Buchungsfrist ist abgelaufen"'
DBQ "UPDATE tp_rounds SET booking_deadline_hours = 1 WHERE id = $R6"
# Protected round with exactly one slot: book, manage page, then no free slots
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Einzeltermin&code_required=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+5 day' +%F)&start_time=10:00&end_time=10:30&slot_minutes=30" "$B/admin/round/new")
RE=$(echo "$loc" | grep -o '[0-9]*$'); SE=$(curl -s -b "$J" "$B/admin/round/$RE" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//'); CE=$(rcode "$RE")
UE=$(curl -s -d "code=$CE" "$B/")
LOCE=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$UE" | csrf)" --data-urlencode "access=$(echo "$UE" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')" -d "slot_id=$(echo "$UE" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Einzel" "$B/$SE")
ME=$(curl -s "$LOCE"); MEC=$(echo "$ME" | csrf); TKE=$(echo "$LOCE" | grep -o '/b/[A-Za-z0-9_-]\{43\}')
check "manage page: no other free slots" 'echo "$ME" | has "Derzeit gibt es keine anderen freien Termine"'
check "change with expired form → notice" 'curl -s -d "csrf=alt&action=cancel&confirm=1" "$B$TKE" | has "Das Formular ist abgelaufen"'
check "cancellation without confirmation → notice" 'curl -s --data-urlencode "csrf=$MEC" -d "action=cancel" "$B$TKE" | has "Bitte bestätigen Sie die Absage"'
check "moving without slot → notice" 'curl -s --data-urlencode "csrf=$MEC" -d "action=move&slot_id=0" "$B$TKE" | has "Bitte wählen Sie einen Termin"'
check "change via invalid link → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$MEC" -d "action=cancel&confirm=1" "$B/b/$(head -c 43 /dev/zero | tr "\0" A)")" = 404 ]'
UE2=$(curl -s -d "code=$CE" "$B/")
check "protected round without free slots: notice (all taken), language switch keeps access" 'echo "$UE2" | has "Alle Termine sind vergeben." && echo "$UE2" | has "<input type=\"hidden\" name=\"step\" value=\"switch\">" && curl -s -d "step=switch&lang=en" --data-urlencode "access=$(echo "$UE2" | grep -o "name=\"access\" value=\"[^\"]*\"" | head -1 | sed "s/.*value=\"//;s/\"$//")" "$B/$SE" | has "All time slots have been taken."'
DBQ "UPDATE tp_rounds SET cancel_deadline_hours = 720 WHERE id = $RE"
check "change deadline passed: notice, cancellation rejected" 'curl -s "$B$TKE" | has "nur bis 720 Stunden vor dem Termin möglich" && curl -s --data-urlencode "csrf=$MEC" -d "action=cancel&confirm=1" "$B$TKE" | has "nur bis 720 Stunden vor dem Termin möglich" && curl -s -o /dev/null -w "%{http_code}" "$B$TKE" | grep -q 200'

# Summary at the booking deadline
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Fristtest&is_active=1&booking_deadline_hours=720&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
RF=$(echo "$loc" | grep -o '[0-9]*$')
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Frist+Kind&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$RF" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
OUT=$($DC exec -T app php /srv/app/bin/cron.php -v)
FM=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=subject:%22Buchungsschluss:%20Fristtest%22"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "summary at the booking deadline: the number of appointments and the free slots, no name" 'echo "$OUT" | grep -qE "summaries_deadline +[1-9]" && echo "$FM" | has "  1 Termin um 10:00" && ! echo "$FM" | has "Frist Kind" && echo "$FM" | has "(1 Termin blieb frei)"'
check "summary at the booking deadline only once" '$DC exec -T app php /srv/app/bin/cron.php -v | grep -qE "summaries_deadline +0"'
check "delete account with all rounds" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$U3/delete" | has "mit allen Runden und Buchungen gelöscht" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$RA")" ]'

echo "Security (audit)"
T=$(curl -s -b "$J" "$B/admin" | csrf)
# Look up values exactly (via prepared statement) in the DB: dbp "<SQL with ?>" <value>
dbp() { php -r '$p = new PDO("mysql:host=127.0.0.1;dbname=" . (getenv("TP_DB") ?: "talkslot") . ";charset=utf8mb4", "app", "app-dev-only"); $s = $p->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo $s->fetchColumn();' "$@" 2>/dev/null \
  || dcx exec -T app php -r '$p = new PDO("mysql:host=db;dbname=talkslot;charset=utf8mb4", "app", "app-dev-only"); $s = $p->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo $s->fetchColumn();' "$@"; }
SQLI="Robert'); DROP TABLE tp_bookings;-- \" OR 1=1 #"
SQ=$(mkround SQL+Test none); SQS=$(slugof "$SQ")
R=$(book "$SQS" "$SQLI" "")
check "SQL injection in the name: stored literally, table unchanged" '[[ "$R" == 303* ]] && [ "$(dbp "SELECT COUNT(*) FROM tp_bookings WHERE name = ?" "$SQLI")" = 1 ] && [ "$(DBQ "SHOW TABLES LIKE \"tp_bookings\"")" = tp_bookings ]'
JQ=$(mktemp); TQ=$(curl -s -c "$JQ" -b "$JQ" "$B/admin/login" | csrf)
check "SQL injection in login → rejected like a wrong password" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JQ" -b "$JQ" --data-urlencode "csrf=$TQ" --data-urlencode "username=x'"'"' OR '"'"'1'"'"'='"'"'1'"'"' -- " --data-urlencode "password='"'"' OR '"'"'1'"'"'='"'"'1" "$B/admin/login")" = 401 ]'
check "SQL injection as round code → not found (422)" 'R=$(curl -s -w "%{http_code}" --data-urlencode "code=x'"'"' OR 1=1 -- " "$B/"); [[ "$R" == *422 ]] && echo "$R" | grep -q "keine offene Terminvergabe"'
check "SQL injection in URL, manage link and admin ID → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/x'"'"'%20OR%20'"'"'1'"'"'='"'"'1")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/b/'"'"'%20OR%201=1--")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/1%20OR%201=1")" = 404 ]'
check "SQL injection in 'forgot password' → same response" 'curl -s -c "$JQ" -b "$JQ" --data-urlencode "csrf=$(curl -s -c "$JQ" -b "$JQ" "$B/admin/forgot" | csrf)" --data-urlencode "email=x'"'"' OR '"'"'1'"'"'='"'"'1" "$B/admin/forgot" | grep -q "Falls zu dieser Angabe"'
rm -f "$JQ"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$SQLI" -d "booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$SQ/edit"
check "SQL injection in the round title: stored literally and shown escaped" '[ "$(dbp "SELECT COUNT(*) FROM tp_rounds WHERE id = ? AND title = ?" "$SQ" "$SQLI")" = 1 ] && curl -s -b "$J" "$B/admin/round/$SQ" | grep -q "Robert&apos;); DROP TABLE tp_bookings;-- &quot; OR 1=1 #"'
check "language parameter with SQL → ignored (German)" 'curl -s "$B/?lang=de%27%20OR%201=1" | grep -q "<html lang=\"de\">"'

# Input: arrays, invalid UTF-8, unusual forms, links in the name
TQ=$(curl -s "$B/admin/login" | csrf)
check "login with password[] → 401 without warning" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$TQ" -d "username=x&password[]=1" "$B/admin/login")" = 401 ]'
check "invalid UTF-8 in the round title → notice instead of server error" 'R=$(curl -s -w "%{http_code}" -b "$J" --data-urlencode "csrf=$T" -d "title=Kaputt%FF&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$SQ/edit"); [[ "$R" == *200 ]] && echo "$R" | grep -q "Bitte einen Titel"'
check "email with quotes/spaces rejected" '[[ "$(book "$SQS" "Mit Mail" "\"a b\"@example.org")" == 422* ]]'
P=$(curl -s "$B/$SQS")
check "link in the name rejected (phishing via confirmation mails)" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$(echo "$P" | csrf)" --data-urlencode "name=Zahlung offen: https://evil.example/pay" -d "slot_id=$(echo "$P" | grep -o "value=\"[0-9]*\" required" | head -1 | grep -o "[0-9]*")" "$B/$SQS"); [[ "$R" == *422 ]] && echo "$R" | grep -q "keine Internetadressen"'
check "language links on error pages never point to foreign domains" 'curl -s "$B/%5Cevil.example" | grep -q "href=\"/?lang=en\"" && ! curl -s "$B/%5Cevil.example" | grep -q "evil.example?lang" && ! curl -s "$B/%09/evil.example" | grep -q "evil.example?lang"'
check "login page without cookie does not create a session" '! curl -s -D - -o /dev/null "$B/admin/login" | grep -qi "^set-cookie: tp_admin" && ! curl -s -D - -o /dev/null "$B/admin/forgot" | grep -qi "^set-cookie"'
for f in probe.bak probe.swp; do echo x > "$ROOT/public/$f"; done
check "backup/editor files and .user.ini not retrievable (403)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/probe.bak")" = 403 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/probe.swp")" = 403 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/.user.ini")" = 403 ]'
rm -f "$ROOT/public/probe.bak" "$ROOT/public/probe.swp"

# Manage link: rate limit per booking, remember marker only signed, moving only with active details
MV=$(book "$SQS" "Umbucher" ""); MVU="${MV#* }"; MVU="${MVU%%\?*}"
MP=$(curl -s "$MVU"); MPC=$(echo "$MP" | csrf)
check "foreign link with ?remember=1 sets no marker" '! curl -s "$MVU?remember=1" | grep -q "data-remember-round" && ! curl -s "$MVU?remember=1700000000.$(printf "0%.0s" {1..32})" | grep -q "data-remember-round"'
check "manage link with done[]=1 → 200 without warning" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$MVU?done[]=1")" = 200 ]'
FS=($(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$SQ AND b.id IS NULL ORDER BY s.id LIMIT 2"))
for i in $(seq 1 10); do curl -s -o /dev/null --data-urlencode "csrf=$MPC" -d "action=move&slot_id=${FS[$((i % 2))]}" "$MVU"; done
check "moving: at most 10 per booking and hour (against mail floods)" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$MPC" -d "action=move&slot_id=${FS[1]}" "$MVU")" = 429 ]'
ADMINCC2=$(DBQ "SELECT HEX(value) FROM tp_settings WHERE name='controller_contact'")
DBQ "UPDATE tp_settings SET value = '' WHERE name='controller_contact'"
check "without the details: manage page without moving, cancelling still possible" 'M=$(curl -s "$MVU"); ! echo "$M" | grep -q "value=\"move\"" && echo "$M" | grep -q "value=\"cancel\""'
DBQ "UPDATE tp_settings SET value = UNHEX('$ADMINCC2') WHERE name='controller_contact'"
# protected round from "More parent flows" (the earlier one was opened by migration 10)
G3=$(curl -s "$B/$SE"); U3=$(curl -s --data-urlencode "csrf=$(echo "$G3" | csrf)" --data-urlencode "code=$CE" -d "step=unlock" "$B/$SE")
ACC3=$(echo "$U3" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
check "access token is not extended on language switch" '[ -n "$ACC3" ] && sleep 1 && curl -s -d "step=switch&lang=en" --data-urlencode "access=$ACC3" "$B/$SE" | grep -q "name=\"access\" value=\"$ACC3\""'
BH0=$(DBQ "SELECT COALESCE(SUM(hits),0) FROM tp_rate_limits WHERE bucket='booking'")
book "$LTS" "Tom" "" >/dev/null
check "limit hits count against the booking rate limit (slows down probing)" '[ "$(DBQ "SELECT COALESCE(SUM(hits),0) FROM tp_rate_limits WHERE bucket=\"booking\"")" -gt "$BH0" ]'

# Accounts: login counter, email change, sessions, reset links
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('login', 'login_user', 'forgot')"
JL2=$(mktemp); TL2=$(curl -s -c "$JL2" -b "$JL2" "$B/admin/login" | csrf)
for i in 1 2; do curl -s -o /dev/null -c "$JL2" -b "$JL2" --data-urlencode "csrf=$TL2" -d "username=$USER&password=falsch-falsch" "$B/admin/login"; done
curl -s -o /dev/null -c "$JL2" -b "$JL2" --data-urlencode "csrf=$TL2" -d "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
check "successful login does not reset the IP's failed attempt counter" '[ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"login\"")" = 2 ]'
TF=$(curl -s "$B/admin/forgot" | csrf)
curl -s -o /dev/null --data-urlencode "csrf=$TF" -d "email=lehrer@example.org" "$B/admin/forgot"; H1=$(DBQ "SELECT access_token_hash FROM tp_users WHERE role='admin'")
curl -s -o /dev/null --data-urlencode "csrf=$TF" -d "email=lehrer@example.org" "$B/admin/forgot"; H2=$(DBQ "SELECT access_token_hash FROM tp_users WHERE role='admin'")
check "'forgot password': a fresh link is not replaced by others" '[ -n "$H1" ] && [ "$H1" = "$H2" ]'
curl -s -o /dev/null -c "$JL2" -b "$JL2" --data-urlencode "csrf=$TL2" -d "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
check "login invalidates open reset links" '[ "$(DBQ "SELECT access_token_hash IS NULL FROM tp_users WHERE role=\"admin\"")" = 1 ]'
CC=$(dbp "SELECT controller_contact FROM tp_users WHERE role = ?" admin)
PROF() { curl -s -L -c "$J" -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Frau Sabrina Test" --data-urlencode "controller_contact=$CC" -d "summary_evening=1&summary_deadline=1" "$@" "$B/admin/profile"; }
check "email address of another account cannot be taken over" 'PROF -d "email=zwei@example.org" | grep -q "gehört bereits zu einem anderen Konto"'
check "changing email without current password rejected" 'PROF -d "email=neu-admin@example.org" | grep -q "aktuelles Passwort eingeben" && [ "$(DBQ "SELECT email FROM tp_users WHERE role=\"admin\"")" = lehrer@example.org ]'
check "changing email with password, notice to the old address" 'PROF -d "email=neu-admin@example.org" --data-urlencode "current_password=$PASS" | grep -q "Profil gespeichert" && $DC exec -T app php -r "echo file_get_contents(\"http://mail:8025/api/v1/search?query=to:lehrer@example.org%20subject:%22E-Mail-Adresse%20wurde%20ge%C3%A4ndert%22\");" | grep -q "neu-admin@example.org"'
PROF -d "email=lehrer@example.org" --data-urlencode "current_password=$PASS" >/dev/null
check "password over 72 bytes rejected" 'PW "$PASS" "$(printf "x%.0s" {1..73})" "$(printf "x%.0s" {1..73})" | grep -q "höchstens 72 Byte"'
check "password change ends other sessions, the own one stays" 'PW "$PASS" Neues-Passwort-123 Neues-Passwort-123 | grep -q "Passwort geändert." && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JL2" "$B/admin")" = 303 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin")" = 200 ]'
PW Neues-Passwort-123 "$PASS" "$PASS" >/dev/null
T=$(curl -s -b "$J" "$B/admin" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Herr+Vier&email=vier@example.org" "$B/admin/users/new"
U4=$(DBQ "SELECT id FROM tp_users WHERE username='vier@example.org'")
LINK4=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:vier@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];' | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
JV=$(mktemp); TV=$(curl -s -c "$JV" -b "$JV" "$LINK4" | csrf)
LV=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JV" -b "$JV" --data-urlencode "csrf=$TV" -d "new_password=Viertes-Passwort-1&new_password2=Viertes-Passwort-1" "$LINK4")
check "after setting the password: username not in the URL (access logs)" '[[ "$LV" == */admin/login ]] && curl -s -c "$JV" -b "$JV" "$B/admin/login" | grep -q "value=\"vier@example.org\""'
curl -s -o /dev/null -c "$JV" -b "$JV" --data-urlencode "csrf=$(curl -s -c "$JV" -b "$JV" "$B/admin/login" | csrf)" -d "username=vier@example.org&password=Viertes-Passwort-1" "$B/admin/login"
check "password reset by the admin ends running sessions" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JV" "$B/admin")" = 200 ] && curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/users/$U4/reset-password" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JV" "$B/admin")" = 303 ]'
rm -f "$JV" "$JL2"
KT=$(php -r 'echo hash_hmac("sha256", "subject|user:" . $argv[1], str_repeat("d", 64));' "$(DBQ "SELECT id FROM tp_users WHERE role='admin'")")
DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('teacher_mail', '$KT', UNIX_TIMESTAMP(), 100000) ON DUPLICATE KEY UPDATE hits = 100000, window_start = UNIX_TIMESTAMP()"
check "mails from manual entries limited per account" 'curl -s -L -b "$J" -d "csrf=$T&name=Mail+Limit&email=limit@example.org&lang=de&send_mail=1" "$B$(curl -s -b "$J" "$B/admin/round/$SQ" | grep -o "/admin/slot/[0-9]*/book" | head -1)" | grep -q "Keine E-Mail gesendet: Höchstzahl"'
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('teacher_mail', 'login', 'login_user', 'forgot', 'booking', 'move')"

# Expired rounds: day moved into the future → no longer expired; never delete rounds with future days
DSQ=$(curl -s -b "$J" "$B/admin/round/$SQ" | grep -o 'id="day-[0-9]*"' | head -1 | grep -o '[0-9]*')
DBQ "UPDATE tp_rounds SET expired_at = NOW(), is_active = 0 WHERE id = $SQ"
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_date=$(date -d '+20 day' +%F)&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/day/$DSQ/edit"
check "day moved into the future: expiry lifted" '[ "$(DBQ "SELECT expired_at IS NULL FROM tp_rounds WHERE id=$SQ")" = 1 ]'
DBQ "UPDATE tp_rounds SET expired_at = NOW() - INTERVAL 10 DAY, delete_after_hours = 0 WHERE id = $SQ"
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at, last_login_at) VALUES ('alt-inaktiv@example.org', 'x', 'teacher', 'Alt', 'alt-inaktiv@example.org', 0, NOW() - INTERVAL 3 YEAR, NOW() - INTERVAL 2 YEAR)"
DBQ "UPDATE tp_users SET last_login_at = NOW() - INTERVAL 2 YEAR WHERE username = 'zwei@example.org'"
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "cleanup: round with a future day stays despite expired deadline" '[ -n "$(DBQ "SELECT id FROM tp_rounds WHERE id=$SQ")" ]'
check "cleanup: long inactive accounts without rounds deleted, with rounds kept" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE username=\"alt-inaktiv@example.org\"")" ] && [ -n "$(DBQ "SELECT id FROM tp_users WHERE username=\"zwei@example.org\"")" ]'

echo "Location"
T=$(curl -s -b "$J" "$B/admin" | csrf)
OD1=$(date -d '+12 day' +%F); OD2=$(date -d '+13 day' +%F)
RO=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ortstest&location=Raum+1.12&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$OD1&day_dates%5B%5D=$OD2&start_time=09:00&end_time=10:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
ROS=$(slugof "$RO")
check "location: on the round page, in the admin view and in the invitation text" 'curl -s "$B/$ROS" | grep -q "<p class=\"location\">Ort: Raum 1.12</p>" && A=$(curl -s -b "$J" "$B/admin/round/$RO"); echo "$A" | grep -q "Ort: <strong>Raum 1.12</strong>" && echo "$A" | grep -qx "Ort: Raum 1.12"'
OD2ID=$(DBQ "SELECT id FROM tp_round_days WHERE round_id=$RO AND day_date='$OD2'")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "day_date=$OD2&start_time=09:00&end_time=10:00&slot_minutes=30&location=Turnhalle" "$B/admin/day/$OD2ID/edit"
check "day with its own location: shown for that day only" 'P=$(curl -s "$B/$ROS"); [ "$(echo "$P" | grep -c "· Ort: Turnhalle</legend>")" = 1 ] && [ "$(echo "$P" | grep -c "</legend>")" = 2 ] && curl -s -b "$J" "$B/admin/day/$OD2ID/edit" | grep -q "placeholder=\"wie die Runde: Raum 1.12\""'
X=$(curl -s -b "$J" --data-urlencode "csrf=$T" --data-urlencode "location=$(printf 'x%.0s' $(seq 201))" -d "day_date=$OD2&start_time=09:00&end_time=10:00&slot_minutes=30" "$B/admin/day/$OD2ID/edit")
check "day location longer than 200 characters rejected" 'echo "$X" | grep -q "Ort: höchstens 200 Zeichen" && [ "$(DBQ "SELECT location FROM tp_round_days WHERE id=$OD2ID")" = Turnhalle ]'
OB=$(book "$ROS" "Ort Kind" "ort-eltern@example.org" 3)
check "confirmation page shows the location of the booked day" '[[ "$OB" == 303* ]] && curl -s "${OB#* }" | grep -q "<dd>Turnhalle</dd>"'
OM=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:ort-eltern@example.org"),true); $id=$r["messages"][0]["ID"]; $m=json_decode(file_get_contents("http://mail:8025/api/v1/message/".$id),true); echo $m["Text"], "\n---ICS---\n", file_get_contents("http://mail:8025/api/v1/message/".$id."/part/".$m["Attachments"][0]["PartID"]);')
check "mail and calendar file contain the location" 'echo "$OM" | grep -q "^Ort: Turnhalle" && echo "$OM" | grep -q "^LOCATION:Turnhalle"'
check "print view: location per day" 'PR=$(curl -s -b "$J" "$B/admin/round/$RO/print"); echo "$PR" | grep -q "Uhr · Raum 1.12</h2>" && echo "$PR" | grep -q "Uhr · Turnhalle</h2>"'
X=$(curl -s -b "$J" --data-urlencode "csrf=$T" --data-urlencode "location=$(printf 'x%.0s' $(seq 201))" -d "title=Ortstest&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&user_id=$(DBQ "SELECT user_id FROM tp_rounds WHERE id=$RO")" "$B/admin/round/$RO/edit")
check "location longer than 200 characters rejected" 'echo "$X" | grep -q "Ort: höchstens 200 Zeichen" && [ "$(DBQ "SELECT location FROM tp_rounds WHERE id=$RO")" = "Raum 1.12" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T" -d "title=Ortstest&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&user_id=$(DBQ "SELECT user_id FROM tp_rounds WHERE id=$RO")" "$B/admin/round/$RO/edit"
check "form without the field (opened before the update) keeps the location" '[ "$(DBQ "SELECT location FROM tp_rounds WHERE id=$RO")" = "Raum 1.12" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RO/delete"

echo "Reminders to parents"
T=$(curl -s -b "$J" "$B/admin" | csrf)
TMW=$(date -d '+1 day' +%F)
RUN=$(date +%s)   # mailpit keeps the mails of earlier runs
mkrem() { curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=$1&remind_parents_field=1&remind_parents=$2&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$TMW&start_time=09:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$'; }
RR=$(mkrem Vorabend+an 1); RRS=$(slugof "$RR")
RN=$(mkrem Vorabend+aus 0); RNS=$(slugof "$RN")
check "reminder setting: shown in the round and in the parents' email hint" 'curl -s -b "$J" "$B/admin/round/$RR" | grep -q "Erinnerung am Vorabend" && ! curl -s -b "$J" "$B/admin/round/$RN" | grep -q "Erinnerung am Vorabend" && curl -s "$B/$RRS" | grep -q "und eine Erinnerung am Vorabend" && ! curl -s "$B/$RNS" | grep -q "Erinnerung am Vorabend"'
RA=$(book "$RRS" "Rem A" "rem-a-$RUN@example.org"); RB=$(book "$RRS" "Rem B" ""); RD=$(book "$RRS" "Rem D" "rem-d-$RUN@example.org"); RC=$(book "$RNS" "Rem C" "rem-c-$RUN@example.org")
RAL=${RA#* }; RAL=${RAL%%\?*}
DBQ "UPDATE tp_bookings SET created_at = NOW() - INTERVAL 2 DAY, updated_at = NOW() - INTERVAL 2 DAY, notified_at = notified_at - INTERVAL 2 DAY WHERE name IN ('Rem A', 'Rem B', 'Rem C')"
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
remmail() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$1"'%20subject:Erinnerung"),true); echo count($r["messages"] ?? []), "\n"; if (!empty($r["messages"])) echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];'; }
REMA=$(remmail rem-a-$RUN@example.org)
RLINK=$(echo "$REMA" | grep -o 'http[^ ]*/b/[A-Za-z0-9_-]\{43\}' | head -1)
check "reminder sent the evening before, with the details and its own cancel link" '[ "$(echo "$REMA" | head -1)" = 1 ] && echo "$REMA" | grep -q "an Ihren Termin morgen erinnern" && echo "$REMA" | grep -q "^Name: Rem A" && [ -n "$RLINK" ] && [ "${RLINK##*/}" != "${RAL##*/}" ] && [ -n "$(DBQ "SELECT reminded_at FROM tp_bookings WHERE name=\"Rem A\"")" ] && ! echo "$REMA" | grep -q "termin.ics"'
check "no reminder: round with reminders off, booked on the same day, or without email" '[ "$(remmail rem-c-$RUN@example.org | head -1)" = 0 ] && [ "$(remmail rem-d-$RUN@example.org | head -1)" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE reminded_at IS NOT NULL AND name IN (\"Rem B\", \"Rem C\", \"Rem D\")")" = 0 ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "reminder only once" '[ "$(remmail rem-a-$RUN@example.org | head -1)" = 1 ]'
check "both links open the booking (confirmation and reminder)" 'curl -s "${RLINK%%\?*}" | grep -q "<dd>Rem A</dd>" && curl -s "$RAL" | grep -q "<dd>Rem A</dd>"'
RMP=$(curl -s "${RLINK%%\?*}")
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$RMP" | csrf)" -d "action=move&slot_id=$(echo "$RMP" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')" "${RLINK%%\?*}"
check "moving the booking resets the reminder (sent again for the new date)" '[ "$(DBQ "SELECT reminded_at IS NULL FROM tp_bookings WHERE name=\"Rem A\"")" = 1 ]'
RMP=$(curl -s "${RLINK%%\?*}")
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$RMP" | csrf)" -d "action=cancel&confirm=1" "${RLINK%%\?*}"
check "cancelling via the reminder link" '[ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name=\"Rem A\"")" = 0 ]'
DBQ "UPDATE tp_pages SET content = REPLACE(content, 'Erinnerung am Vorabend', 'Hinweis am Vorabend') WHERE page_key='privacy' AND lang='de'"
check "admin notice: privacy policy without the reminder" 'curl -s -b "$J" "$B/admin" | grep -q "(DE) erwähnt die Erinnerung an Eltern am Vortag noch nicht"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, 'Hinweis am Vorabend', 'Erinnerung am Vorabend') WHERE page_key='privacy' AND lang='de'"
check "notice gone with the current template" '! curl -s -b "$J" "$B/admin" | grep -q "erwähnt die Erinnerung"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RR/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RN/delete"

echo "Templates"
T=$(curl -s -b "$J" "$B/admin" | csrf)
TPL() { curl -s -b "$J" --data-urlencode "csrf=$T" --data-urlencode "name=$1" -d "start_time=8:00&end_time=16:00&slot_minutes=15&break_start%5B%5D=12:00&break_end%5B%5D=13:00&break_label%5B%5D=Mittagspause&booking_limit=day&remind_parents=1&location=Hauptgeb%C3%A4ude&booking_deadline_hours=48&cancel_deadline_hours=24&delete_after_hours=72" "${@:2}" "$B/admin/templates/new"; }
TPL "Elternsprechtag ganzer Tag" > /dev/null
TID=$(DBQ "SELECT id FROM tp_round_templates WHERE name='Elternsprechtag ganzer Tag'")
check "template saved, listed with a summary" '[ -n "$TID" ] && curl -s -b "$J" "$B/admin/templates" | grep -q "08:00–16:00 Uhr · 15 Min. pro Termin · Mittagspause 12:00–13:00 · ein Termin pro Person und Tag · Ort: Hauptgebäude"'
check "template: same name, invalid times, no slot → messages, nothing saved" 'TPL "Elternsprechtag ganzer Tag" | grep -q "gibt es bereits" && TPL "Kaputt" -d "start_time=16:00" | grep -q "Bitte gültige Zeiten" && TPL "Leer" -d "start_time=12:00&end_time=13:00" | grep -q "kein einziger Termin" && [ "$(DBQ "SELECT COUNT(*) FROM tp_round_templates")" = 1 ]'
X=$(curl -s -b "$J" --data-urlencode "csrf=$T" --data-urlencode "location=$(printf 'x%.0s' $(seq 201))" --data-urlencode "description=$(printf 'x%.0s' $(seq 5001))" -d "name=&start_time=8:00&end_time=9:00&slot_minutes=15&booking_deadline_hours=721&cancel_deadline_hours=x&delete_after_hours=9999" "$B/admin/templates/new")
check "template: name, deadlines, deletion period, location and description checked" 'echo "$X" | grep -q "Bitte einen Namen für die Vorlage" && echo "$X" | grep -q "Buchungsfrist: ganze Zahl" && echo "$X" | grep -q "Änderungsfrist: ganze Zahl" && echo "$X" | grep -q "Löschfrist: leer lassen" && echo "$X" | grep -q "Ort: höchstens 200 Zeichen" && echo "$X" | grep -q "Beschreibungstext ist zu lang" && [ "$(DBQ "SELECT COUNT(*) FROM tp_round_templates")" = 1 ]'
NF=$(curl -s -b "$J" "$B/admin/round/new?template=$TID")
check "new round from a template: times, break and settings prefilled" 'echo "$NF" | grep -q "name=\"start_time\" required value=\"08:00\"" && echo "$NF" | grep -q "name=\"end_time\" required value=\"16:00\"" && echo "$NF" | grep -q "value=\"Mittagspause\"" && echo "$NF" | grep -q "<option value=\"day\" selected>" && echo "$NF" | grep -q "id=\"location\" name=\"location\" maxlength=\"200\" value=\"Hauptgebäude\"" && echo "$NF" | grep -q "id=\"booking_deadline_hours\" name=\"booking_deadline_hours\" min=\"0\" max=\"720\" required value=\"48\"" && echo "$NF" | grep -q "<option value=\"$TID\" selected>Elternsprechtag ganzer Tag</option>" && echo "$NF" | grep -q "aus „Elternsprechtag ganzer Tag“ sind eingetragen"'
check "unknown template: empty form" 'curl -s -b "$J" "$B/admin/round/new?template=999999" | grep -q "name=\"start_time\" required value=\"14:00\""'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/templates/from-round/$RID"); TFR=$(echo "$loc" | grep -o '[0-9]*/edit$' | grep -o '^[0-9]*')
TFE=$(curl -s -b "$J" "$B/admin/templates/$TFR/edit")
FIRST=$(DBQ "SELECT DATE_FORMAT(start_time, '%H:%i') FROM tp_round_days WHERE round_id=$RID ORDER BY day_date, start_time LIMIT 1")
check "template from a round: created, form opened with a notice" '[ -n "$TFR" ] && echo "$TFE" | grep -q "Vorlage aus der Runde erstellt"' || echo "    TFR=$TFR loc=$loc"
check "template from a round: times of the first day" 'echo "$TFE" | grep -q "name=\"start_time\" required value=\"$FIRST\""' || { echo "    FIRST=$FIRST"; echo "$TFE" | grep -o 'name="start_time"[^>]*'; }
check "template from a round: settings of the round" '[ "$(DBQ "SELECT JSON_VALUE(data, \"$.booking_limit\") FROM tp_round_templates WHERE id=$TFR")" = "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$RID")" ]' || DBQ "SELECT data FROM tp_round_templates WHERE id=$TFR"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/templates/from-round/$RID"); TFR2=$(echo "$loc" | grep -o '[0-9]*/edit$' | grep -o '^[0-9]*')
check "second template from the same round gets its own name" '[ "$(DBQ "SELECT name FROM tp_round_templates WHERE id=$TFR2")" = "$(DBQ "SELECT name FROM tp_round_templates WHERE id=$TFR") (2)" ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "name=Umbenannt&start_time=9:00&end_time=12:00&slot_minutes=20&booking_limit=none&booking_deadline_hours=1&cancel_deadline_hours=1&delete_after_hours=" "$B/admin/templates/$TFR/edit"
check "template edited (no deletion period = never)" 'E=$(curl -s -b "$J" "$B/admin/templates"); echo "$E" | grep -q "<strong>Umbenannt</strong>" && echo "$E" | grep -q "09:00–12:00 Uhr · 20 Min. pro Termin · Mehrfachbuchungen erlaubt" && [ "$(DBQ "SELECT JSON_TYPE(JSON_EXTRACT(data, \"$.delete_after_hours\")) FROM tp_round_templates WHERE id=$TFR")" = NULL ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/$TFR/delete"; curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/$TFR2/delete"
check "template deleted; changes are logged" '[ "$(DBQ "SELECT COUNT(*) FROM tp_round_templates")" = 1 ] && L=$(curl -s -b "$J" "$B/admin/log"); echo "$L" | grep -q "Vorlage „Umbenannt“ gelöscht" && echo "$L" | grep -q "Vorlage „Elternsprechtag ganzer Tag“ angelegt" && echo "$L" | grep -q "Vorlage „Umbenannt“ bearbeitet (bisher"'

echo "Admin area in English"
LG=$(curl -s -H 'Accept-Language: en-GB,en;q=0.9,de;q=0.5' "$B/admin/login")
check "login page follows the browser language, with a language switch" 'echo "$LG" | grep -q "<html lang=\"en\"" && echo "$LG" | grep -qF ">Sign in</" && echo "$LG" | grep -q "href=\"/admin/login?lang=de\"" && ! echo "$LG" | grep -q ">Anmelden</"'
check "?lang= wins over the browser language; without a hint: German" 'curl -s -H "Accept-Language: en" "$B/admin/login?lang=de" | grep -q "<html lang=\"de\"" && curl -s "$B/admin/login" | grep -q "<html lang=\"de\"" && curl -s "$B/admin/forgot?lang=en" | grep -q "<html lang=\"en\""'
T=$(curl -s -b "$J" "$B/admin" | csrf)
AEM=$(DBQ "SELECT email FROM tp_users WHERE username='$USER'")
P=$(PROF -d "email=$AEM&lang=en")
check "profile: switching to English, confirmation already in English" 'echo "$P" | grep -q "<html lang=\"en\"" && echo "$P" | grep -qF "Profile saved." && echo "$P" | grep -q "<option value=\"en\" selected>English</option>" && [ "$(DBQ "SELECT lang FROM tp_users WHERE username=\"$USER\"")" = en ]'
D=$(curl -s -b "$J" "$B/admin")
check "admin area in English: navigation, page, JavaScript texts" 'echo "$D" | grep -qF ">All rounds</a>" && echo "$D" | grep -qF ">My profile</a>" && echo "$D" | grep -q "<script type=\"application/json\" id=\"tp-i18n\">" && ! echo "$D" | grep -q ">Alle Runden</a>"'
X=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "title=&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+30 day' +%F)&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
check "validation messages in English" 'echo "$X" | grep -qF "Please enter a title (max. 200 characters)."'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RID/toggle"; curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RID/toggle"
check "log entries stay in the default language (German) for an English admin" '[ "$(DBQ "SELECT message LIKE \"Runde „%“ (%): %aktiviert\" FROM tp_audit_log ORDER BY id DESC LIMIT 1")" = 1 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Ms+English&email=english-$RUN@example.org&role=teacher&lang=en" "$B/admin/users/new"
IM=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:english-'"$RUN"'@example.org"),true); $m=$r["messages"][0]; echo $m["Subject"], "\n", json_decode(file_get_contents("http://mail:8025/api/v1/message/".$m["ID"]),true)["Text"];')
ILINK=$(echo "$IM" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}?lang=en' | head -1)
check "invitation in the language of the new account, link opens the page in English" 'echo "$IM" | head -1 | grep -qF "Invitation to TalkSlot" && echo "$IM" | grep -qF "Please set your password using this link:" && [ -n "$ILINK" ] && curl -s "$ILINK" | grep -q "<html lang=\"en\"" && [ "$(DBQ "SELECT lang FROM tp_users WHERE username=\"english-$RUN@example.org\"")" = en ]'
P=$(PROF -d "email=$AEM&lang=de")
check "back to German" 'echo "$P" | grep -q "<html lang=\"de\"" && echo "$P" | grep -q "Profil gespeichert." && curl -s -b "$J" "$B/admin" | grep -q ">Alle Runden</a>" && ! curl -s -b "$J" "$B/admin" | grep -q "id=\"tp-i18n\""'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$(DBQ "SELECT id FROM tp_users WHERE username='english-$RUN@example.org'")/delete"

echo "Several admins"
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Zweitadmin&email=zweitadmin@example.org&role=admin" --data-urlencode "current_password=$PASS" "$B/admin/users/new"
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Fuenf&email=fuenf@example.org&role=teacher" "$B/admin/users/new"
UT=$(DBQ "SELECT id FROM tp_users WHERE username='fuenf@example.org'")
UZ=$(DBQ "SELECT id FROM tp_users WHERE username='zweitadmin@example.org'")
check "an admin can invite another admin" '[ -n "$UZ" ] && [ "$(DBQ "SELECT role FROM tp_users WHERE id=$UZ")" = admin ]'
VLINK=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:zweitadmin@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];' | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
JV=$(mktemp)
TV=$(curl -s -c "$JV" -b "$JV" "$VLINK" | csrf); sleep 2
curl -s -o /dev/null -c "$JV" -b "$JV" --data-urlencode "csrf=$TV" -d "new_password=Kaffee-Admin-Passwort-123&new_password2=Kaffee-Admin-Passwort-123" "$VLINK"
TV=$(curl -s -c "$JV" -b "$JV" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$JV" -b "$JV" -d "csrf=$TV&username=zweitadmin@example.org&password=Kaffee-Admin-Passwort-123" "$B/admin/login"
DV=$(curl -s -c "$JV" -b "$JV" "$B/admin")
check "second admin sees the rounds of everybody" 'echo "$DV" | grep -q "Alle Terminrunden" && echo "$DV" | grep -q "<td>Frau Sabrina Test</td>" && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" "$B/admin/round/$RID")" = 200 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" "$B/admin/round/$RID/edit")" = 200 ]'
TV=$(echo "$DV" | csrf)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JV" -b "$JV" --data-urlencode "csrf=$TV" -d "title=Von+Zweitadmin&user_id=$UT&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+20 day' +%F)&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
RV=$(echo "$loc" | grep -o '[0-9]*$')
check "second admin creates a round for a teacher" '[ -n "$RV" ] && [ "$(DBQ "SELECT user_id FROM tp_rounds WHERE id=$RV")" = "$UT" ]'
curl -s -o /dev/null -c "$JV" -b "$JV" -d "csrf=$TV" "$B/admin/round/$RV/toggle"
check "second admin activates it" '[ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$RV")" = 1 ]'
check "the first admin sees it too" 'curl -s -b "$J" "$B/admin/round/$RV" | grep -q "Von Zweitadmin"'
LOGP=$(curl -s -b "$J" "$B/admin/log")
check "log: the other admin's changes, with who and what" 'echo "$LOGP" | grep -q "<td>Frau Zweitadmin</td>" && echo "$LOGP" | grep -q "Runde „Von Zweitadmin“ (Frau Fuenf): angelegt" && echo "$LOGP" | grep -q "Runde „Von Zweitadmin“ (Frau Fuenf): aktiviert" && echo "$LOGP" | grep -q "Konto „Frau Zweitadmin“ angelegt (Admin) und eingeladen"'
FV=$(curl -s -c "$JV" -b "$JV" "$B/admin/round/$RV/edit")
check "teacher choice marks only oneself with (Sie), listed first" '[ "$(echo "$FV" | grep -c "(Sie)</option>")" = 1 ] && echo "$FV" | grep -A1 "<select id=\"user_id\"" | grep -q "Frau Zweitadmin (Sie)"'
curl -s -o /dev/null -c "$JV" -b "$JV" --data-urlencode "csrf=$TV" --data-urlencode $'controller_contact=Gemeinsame Schule\nSchulplatz 4\n12345 Teststadt\nE-Mail: schule@example.org' "$B/admin/texts/operator"
check "second admin maintains the details for all" 'curl -s "$B/impressum" | grep -q "Schulplatz 4" && curl -s -b "$J" "$B/admin/texts" | grep -q "Schulplatz 4"'
curl -s -o /dev/null -c "$JV" -b "$JV" -d "csrf=$TV&username=zweitadmin@example.org&display_name=Frau+Zweitadmin&email=zweitadmin@example.org&role=teacher&is_active=1" "$B/admin/users/$UZ/edit"
check "own role cannot be changed (no locking oneself out)" '[ "$(DBQ "SELECT role FROM tp_users WHERE id=$UZ")" = admin ] && ! curl -s -c "$JV" -b "$JV" "$B/admin/users/$UZ/edit" | grep -q "name=\"role\""'
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T&username=zweitadmin@example.org&display_name=Frau+Zweitadmin&email=zweitadmin@example.org&role=teacher&is_active=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/edit"
check "another admin can take the role away" '[ "$(DBQ "SELECT role FROM tp_users WHERE id=$UZ")" = teacher ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" "$B/admin/users")" = 404 ] && curl -s -c "$JV" -b "$JV" "$B/admin" | grep -q "Meine Terminrunden" && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" "$B/admin/round/$RID")" = 404 ]'
check "log: only for admins" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" "$B/admin/log")" = 404 ] && ! curl -s -c "$JV" -b "$JV" "$B/admin" | grep -q "href=\"/admin/log\"" && curl -s -b "$J" "$B/admin" | grep -q "href=\"/admin/log\">Protokoll</a>"'
check "templates: teachers can use them, but not maintain them" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" "$B/admin/templates")" = 404 ] && curl -s -c "$JV" -b "$JV" "$B/admin/round/new?template=$TID" | grep -q "name=\"start_time\" required value=\"08:00\"" && ! curl -s -c "$JV" -b "$JV" "$B/admin/round/$(DBQ "SELECT MAX(id) FROM tp_rounds WHERE user_id=$UZ")" | grep -q "Als Vorlage speichern" && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JV" -b "$JV" -d "csrf=$TV" "$B/admin/templates/from-round/$RV")" = 404 ]'
NLOG=$(DBQ "SELECT COUNT(*) FROM tp_audit_log")
TV=$(curl -s -c "$JV" -b "$JV" "$B/admin" | csrf)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JV" -b "$JV" --data-urlencode "csrf=$TV" -d "title=Eigene+Runde&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+21 day' +%F)&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
check "log: changes by a teacher in their own rounds are not recorded" '[ -n "$(echo "$loc" | grep -o "[0-9]*$")" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log")" = "$NLOG" ]'
DBQ "UPDATE tp_users SET role = 'admin', is_active = 0 WHERE id = $UZ"
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T&username=zweitadmin@example.org&display_name=Frau+Zweitadmin&email=zweitadmin@example.org&role=teacher" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/edit"
check "a deactivated admin can be demoted (one active admin remains)" '[ "$(DBQ "SELECT role FROM tp_users WHERE id=$UZ")" = teacher ]'
check "log: role changes are recorded" '[ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message = \"Konto „Frau Zweitadmin“ bearbeitet: Rolle jetzt Lehrkraft\"")" -ge 2 ]'
curl -s -o /dev/null -c "$JV" -b "$JV" -d "csrf=$TV" "$B/admin/logout"; rm -f "$JV"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RV/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$UZ/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$UT/delete"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode $'controller_contact=Sabrina Test\nTeststraße 1\n12345 Teststadt\nE-Mail: sabrina@example.org' "$B/admin/texts/operator"
check "cleaned up: one admin again, original details" '[ "$(DBQ "SELECT COUNT(*) FROM tp_users WHERE role=\"admin\"")" = 1 ] && curl -s "$B/impressum" | grep -q "Teststraße 1"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/$TID/delete"
check "log: entries of a deleted account stay, marked as deleted" 'curl -s -b "$J" "$B/admin/log" | grep -q "Frau Zweitadmin <span class=\"small muted\">(gelöscht)</span>"'
check "log: no data of parents (names of bookings)" '[ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"%Max Muster%\" OR message LIKE \"%@example.org%\"")" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"%: Buchung %\"")" -gt 0 ]'
DBQ "INSERT INTO tp_audit_log (created_at, user_id, actor, message) SELECT NOW() - INTERVAL 1 DAY, NULL, 'Altlast', CONCAT('Alter Eintrag ', seq) FROM seq_1_to_150; INSERT INTO tp_audit_log (created_at, user_id, actor, message) VALUES (NOW() - INTERVAL 100 DAY, NULL, 'Altlast', 'Abgelaufener Eintrag')"
LOG1=$(curl -s -b "$J" "$B/admin/log"); OLDER=$(echo "$LOG1" | grep -o '/admin/log?before=[0-9]*' | head -1)
check "log: paged, 100 entries per page" '[ "$(echo "$LOG1" | grep -c "<tr>")" = 101 ] && [ -n "$OLDER" ] && curl -s -b "$J" "$B$OLDER" | grep -q "Zu den neuesten Einträgen"'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "log: entries deleted after 90 days" '[ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message = \"Abgelaufener Eintrag\"")" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE actor = \"Altlast\"")" = 150 ]'
DBQ "DELETE FROM tp_audit_log WHERE actor = 'Altlast'"

# Further checks in tests/smoke.d/*.sh (one file per topic, sourced here in name order). They run
# with all helpers and variables of this script (B, J, T, RID, DBQ, DC, check, book, …) and must
# leave the data as they found it (the cleanup below expects the round $RID).
for f in "$(dirname "$0")"/smoke.d/*.sh; do
  [ -f "$f" ] && . "$f"
done

echo "Cleanup"
docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -e \
  "UPDATE tp_round_days SET day_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE round_id = $RID"
OUT=$(docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T app php /srv/app/bin/cron.php -v)
echo "$OUT" | sed 's/^/    /'
check "bookings from the previous day deleted" 'echo "$OUT" | grep -qE "bookings_deleted +[1-9]"'
check "round expired + deactivated" 'echo "$OUT" | grep -qE "rounds_expired +[1-9]"'
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/$SLUG")
check "expired round publicly 404" '[ "$code" = 404 ]'

T=$(curl -s -b "$J" "$B/admin" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/logout"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$B/admin/round/$RID")
check "Logout" '[ "$code" = 303 ]'
# Login limit per username (credential stuffing from many addresses): after 3 attempts from 3 different
# IPs, further attempts for the same name are refused before the password is checked (no failed_logins
# increment), even with the right password from a fresh IP.
LU=$(ALERTS 'require "/srv/app/app/bootstrap.php";
  App\Config::load(["rate_limits" => ["login" => [100, 3600], "login_user" => [3, 3600]]] + (require "/srv/app/config/config.php"));
  $failed = static fn () => (int) App\Db::value("SELECT failed_logins FROM users WHERE username = ?", ["sabrina"]);
  $before = $failed();
  foreach ([1, 2, 3, 4, 5] as $i) { $_SERVER["REMOTE_ADDR"] = "198.51.100.$i"; App\Auth::attempt("sabrina", "falsch-$i"); }
  $_SERVER["REMOTE_ADDR"] = "198.51.100.9";
  $right = App\Auth::attempt("sabrina", "Sehr-Sicheres-Dev-Pw1");
  echo ($failed() - $before) . "|" . ($right === true ? "in" : "refused");
  App\Db::run("DELETE FROM rate_limits WHERE bucket IN (?, ?)", ["login", "login_user"]);
  App\Db::run("UPDATE users SET failed_logins = ? WHERE username = ?", [$before, "sabrina"]);')
check "login: limit per username across IP addresses ($LU)" '[ "$LU" = "3|refused" ]'
AL=$(ALERTS 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org"), true)["messages_count"] ?? "?";')
check "no error report (exception, warning) during the whole test run" '[ "$AL" = 0 ]'
PE=$("$ROOT/tests/php-errors.sh" "$ROOT/app/var/php-error.log" "$PHP_LOG_START")
check "no PHP warning, notice or deprecation of the app in the error log during the whole test run" '[ -z "$PE" ]' || printf '%s\n' "$PE" | sed 's/^/      /'
rm -f "$J"
[ $FAIL = 0 ] && echo "ALL TESTS PASSED" || { echo "FAILURES"; exit 1; }
