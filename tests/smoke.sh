#!/usr/bin/env bash
# End-to-End-Test gegen die lokale Docker-Umgebung (dev/compose.yml).
# Aufruf: tests/smoke.sh [base_url] [admin_user] [admin_pass]
# Voraussetzung: frisch installiert (dev/reset.sh), config aus dev/config.dev.php. Der Test installiert
# danach selbst noch einmal über den Web-Installer neu (gleiche Zugangsdaten).
set -u
B=${1:-http://localhost:8080}
USER=${2:-sabrina}
PASS=${3:-Sehr-Sicheres-Dev-Pw1}
J=$(mktemp)
FAIL=0
ok()   { printf '  ✓ %s\n' "$1"; }
bad()  { printf '  ✗ %s\n' "$1"; FAIL=1; }
# Fehlermeldungen (App\Alert, alert_email aus dev/config.dev.php): vorher leeren, am Ende darf keine dasein
ALERTS() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T app php -r "$1"; }
ALERTS 'unlink("/srv/app/app/var/alerts.json"); file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org", false, stream_context_create(["http" => ["method" => "DELETE"]]));' 2>/dev/null
check(){ if eval "$2"; then ok "$1"; else bad "$1"; return 1; fi; }
csrf() { grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

echo "Installation (Web und CLI)"
dcx() { docker compose -f "$(dirname "$0")/../dev/compose.yml" "$@"; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
check "Installer nach der Installation gesperrt (404)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/install.php")" = 404 ]'
check "CLI-Installer nach der Installation: Abbruch" 'dcx exec -T app php /srv/app/bin/install.php x </dev/null 2>&1 | grep -q "Bereits installiert"'
# Neu installieren, diesmal über den Web-Installer (gleiche Zugangsdaten wie dev/reset.sh). Er löscht
# sich nach Erfolg selbst; die Datei wird danach wiederhergestellt.
INSTALL_BAK=$(mktemp); cp "$ROOT/public/install.php" "$INSTALL_BAK"
dcx exec -T db mariadb -uapp -papp-dev-only talkslot -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS tp_round_translations, tp_sent_summaries, tp_bookings, tp_slots, tp_day_breaks, tp_round_days, tp_rounds, tp_pages, tp_rate_limits, tp_settings, tp_users, tp_admin_users; CREATE TABLE tp_users (id INT)"
dcx exec -T app php -r '@unlink("/srv/app/app/var/installed.lock");'
check "Installer erreichbar, solange nicht installiert" 'curl -s "$B/install.php" | grep -q "name=\"install_key\""'
check "CLI-Installer: verschiedene Passwörter → Abbruch" 'printf "Sehr-Sicheres-Pw-1\nanderes-Passwort-2\n" | dcx exec -T app php /srv/app/bin/install.php sabrina 2>&1 | grep -q "Passwörter stimmen nicht überein"'
IK=dev-install-key-123
check "Falscher Installationsschlüssel abgelehnt" 'curl -s -d "install_key=falsch&username=$USER" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php" | grep -q "Installationsschlüssel ist falsch"'
check "Fremde Tabelle gleichen Namens → keine Installation" 'curl -s -d "install_key=$IK&username=$USER" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php" | grep -q "enthält bereits Tabellen mit gleichem Namen (tp_users)"'
dcx exec -T db mariadb -uapp -papp-dev-only talkslot -e "DROP TABLE tp_users"
check "Zu kurzes Passwort abgelehnt, Eingaben bleiben" 'P=$(curl -s -d "install_key=$IK&username=$USER&password=kurz&password2=kurz&notify_email=lehrer@example.org" "$B/install.php"); echo "$P" | grep -q "mindestens 12 Zeichen" && echo "$P" | grep -q "value=\"lehrer@example.org\""'
IR=$(curl -s -d "install_key=$IK&username=$USER&notify_email=lehrer@example.org" --data-urlencode "password=$PASS" --data-urlencode "password2=$PASS" "$B/install.php")
[ -f "$ROOT/public/install.php" ] || cp "$INSTALL_BAK" "$ROOT/public/install.php"
rm -f "$INSTALL_BAK"
check "Installation über das Web abgeschlossen, danach gesperrt" 'echo "$IR" | grep -q "Installation abgeschlossen" && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/install.php")" = 404 ]'

echo "Admin"
T=$(curl -s -c "$J" -b "$J" "$B/admin/login" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J" -b "$J" -d "csrf=$T&username=$USER&password=falsch" "$B/admin/login")
check "falsches Passwort → 401" '[ "$code" = 401 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J" -b "$J" -d "csrf=$T&username=$USER&password=$PASS" "$B/admin/login")
check "Login → 303" '[ "$code" = 303 ]'
grep -q 'tp_admin' "$J" && ok "Session-Cookie gesetzt" || bad "Session-Cookie"
grep -q '/admin' "$J" && ok "Cookie-Pfad /admin" || bad "Cookie-Pfad"
T=$(curl -s -b "$J" "$B/admin" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" -d "title=Test" "$B/admin/round/new")
check "POST ohne CSRF wird abgewiesen" '[ "$code" = 303 ] && ! curl -s -b "$J" "$B/admin" | grep -q ">Test<"'
NF=$(curl -s -b "$J" "$B/admin/round/new")
check "Neue Runde: keine leeren Pausenzeilen, nur die Vorlage für „+ Pause hinzufügen“" '[ "$(echo "$NF" | grep -c "name=\"break_start\[\]\"")" = 1 ] && echo "$NF" | grep -q "<template data-break-template>"'
EB=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "title=Pausenfehler&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=14:00&end_time=16:00&slot_minutes=15&break_start%5B%5D=15:00&break_end%5B%5D=&break_label%5B%5D=" "$B/admin/round/new")
check "Fehlerhafte Pause: Meldung, keine weitere leere Zeile" 'echo "$EB" | grep -q "Pause 1: Bitte Start- und Endzeit" && [ "$(echo "$EB" | grep -c "name=\"break_start\[\]\"")" = 1 ]'
EB=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "title=Pausenfehler&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=&start_time=14:00&end_time=16:00&slot_minutes=15&break_start%5B%5D=15:00&break_end%5B%5D=15:30&break_label%5B%5D=Kaffee" "$B/admin/round/new")
check "Eingegebene Pause bleibt nach einem Fehler erhalten" 'echo "$EB" | grep -q "value=\"Kaffee\"" && [ "$(echo "$EB" | grep -c "name=\"break_start\[\]\"")" = 2 ]'

# Ohne Kontaktdaten im Profil darf keine Runde aktiviert werden
NP=$(curl -s -w '\n%{http_code}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ohne+Profil&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=14:00&end_time=15:00&slot_minutes=30" "$B/admin/round/new")
check "Aktivieren ohne Angaben für Impressum/Datenschutz abgelehnt (nichts angelegt)" '[ "$(echo "$NP" | tail -1)" = 200 ] && echo "$NP" | grep -q "Bevor Runden aktiviert werden können" && ! curl -s -b "$J" "$B/admin" | grep -q "Ohne Profil" && curl -s -b "$J" "$B/admin" | grep -q "Bitte ergänzen Sie"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Frau Sabrina Test" \
  --data-urlencode $'controller_contact=Sabrina Test\nTeststraße 1\n12345 Teststadt\nE-Mail: sabrina@example.org' \
  -d "email=lehrer@example.org&summary_evening=1&summary_deadline=1" "$B/admin/profile"
check "Profil gespeichert" '! curl -s -b "$J" "$B/admin" | grep -q "Bitte ergänzen Sie"'

TOMORROW=$(date -d '+3 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Größe Übung" \
  -d "random_suffix=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&description=Nur+ein+Termin&day_dates%5B%5D=$TOMORROW&start_time=14:00&end_time=16:00&slot_minutes=15" "$B/admin/round/new")
RID=$(echo "$loc" | grep -o '[0-9]*$')
check "Runde angelegt (id=$RID)" '[ -n "$RID" ]'
PAGE=$(curl -s -b "$J" "$B/admin/round/$RID")
SLUG=$(echo "$PAGE" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "Slug mit Umlauten + Zufallsanteil ($SLUG)" '[[ "$SLUG" =~ ^elternsprechtag-groesse-uebung-[a-z2-9]{8}$ ]]'
N=$(echo "$PAGE" | grep -c 'class="is-free')
check "8 Slots erzeugt ($N)" '[ "$N" = 8 ]'
DID=$(echo "$PAGE" | grep -o 'id="day-[0-9]*"' | head -1 | grep -o '[0-9]*')
curl -s -o /dev/null -b "$J" -d "csrf=$T&start_time=15:00&end_time=15:20&label=Pause" "$B/admin/day/$DID/break"
N=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -c 'class="is-free')
check "Pause 15:00–15:20 → 6 Slots, danach ab 15:20 ($N)" '[ "$N" = 6 ] && curl -s -b "$J" "$B/admin/round/$RID" | grep -q "15:20–15:35"'

D1=$(date -d '+5 day' +%F); D2=$(date -d '+6 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Zwei+Tage&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$D1&day_dates%5B%5D=$D2&start_time=08:00&end_time=10:00&slot_minutes=20&break_start%5B%5D=09:00&break_end%5B%5D=09:20&break_label%5B%5D=Pause&break_start%5B%5D=&break_end%5B%5D=" "$B/admin/round/new")
R5=$(echo "$loc" | grep -o '[0-9]*$'); P5=$(curl -s -b "$J" "$B/admin/round/$R5")
check "Neue Runde: 2 Tage mit Pause direkt im Formular (je 5 Termine)" '[ "$(echo "$P5" | grep -c "class=\"is-free")" = 10 ] && echo "$P5" | grep -q "09:20–09:40" && ! echo "$P5" | grep -q "09:00–09:20</td>"'
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ohne+Tag&booking_deadline_hours=0&cancel_deadline_hours=0&start_time=08:00&end_time=10:00&slot_minutes=20" "$B/admin/round/new")
check "Neue Runde ohne Datum abgelehnt" '[ "$code" = 200 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_dates%5B%5D=$(date -d '+7 day' +%F)&start_time=08:00&end_time=09:00&slot_minutes=30&break_start%5B%5D=08:30&break_end%5B%5D=09:00" "$B/admin/round/$R5/day"
check "Tag hinzufügen mit Pause" '[ "$(curl -s -b "$J" "$B/admin/round/$R5" | grep -c "class=\"is-free")" = 11 ]'
DUP=$(curl -s -b "$J" -d "csrf=$T&day_dates%5B%5D=$D1&start_time=8&end_time=9.30&slot_minutes=30" "$B/admin/round/$R5/day")
check "Doppeltes Datum abgelehnt, Eingaben bleiben erhalten" 'echo "$DUP" | grep -q "gibt es in dieser Runde bereits" && echo "$DUP" | grep -q "value=\"09:30\"" && [ "$(curl -s -b "$J" "$B/admin/round/$R5" | grep -c "class=\"is-free")" = 11 ]'

echo "Eltern"
P=$(curl -s -D - "$B/$SLUG")
check "Rundenseite ohne Cookie" '! echo "$P" | grep -qi "^set-cookie"'
check "Neue Runde: ein Termin pro Person (Standard), Hinweis für Eltern" 'echo "$P" | grep -q "Pro Person ist nur ein Termin möglich. Für Geschwister" && curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Ein Termin pro Person ·"'
PC=$(echo "$P" | csrf)
SLOT=$(echo "$P" | grep -o 'name="slot_id" id="slot-[0-9]*" value="[0-9]*"' | head -1 | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT&name=Max&privacy=1&website=spam" "$B/$SLUG")
check "Honeypot blockiert" '[ "$code" = 422 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT&name=Max" "$B/$SLUG")
check "ohne Datenschutz-Haken → 422" '[ "$code" = 422 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -d "csrf=123.abc&slot_id=$SLOT&name=Max&privacy=1" "$B/$SLUG")
check "ungültiges CSRF-Token → 422" '[ "$code" = 422 ]'
X=$(curl -s --data-urlencode "csrf=$PC" --data-urlencode 'name=<script>alert(1)</script>' -d "slot_id=$SLOT&email=kaputt" "$B/$SLUG")
check "XSS escaped + E-Mail-Validierung" 'echo "$X" | grep -q "&lt;script&gt;" && ! echo "$X" | grep -q "<script>alert" && echo "$X" | grep -q "ungültig"'

# Race Condition: 20 parallele Buchungen auf denselben Slot
for i in $(seq 1 20); do
  curl -s -o /dev/null -w '%{http_code}\n' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT&name=Kind+$i&privacy=1&remember=1&email=eltern$i@example.org" "$B/$SLUG" &
done > /tmp/tp_race.$$; wait
WIN=$(grep -c '^303$' /tmp/tp_race.$$); rm -f /tmp/tp_race.$$
check "Race: genau 1 von 20 parallelen Buchungen erfolgreich ($WIN)" '[ "$WIN" = 1 ]'
PUB=$(curl -s "$B/$SLUG"); check "Slot öffentlich nicht mehr sichtbar" '! echo "$PUB" | grep -q "id=\"slot-$SLOT\""' || { echo "    SLOT=$SLOT"; echo "$PUB" | grep -n "id=\"slot-$SLOT\""; }

SLOT2=$(curl -s "$B/$SLUG" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$PC" -d "slot_id=$SLOT2&name=Erika+Muster&email=erika@example.org&privacy=1&remember=1&lang=en" "$B/$SLUG")
TOKEN=$(echo "$LOC" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
check "Buchung → Änderungslink mit 43-Zeichen-Token" '[ ${#TOKEN} = 43 ]'
M=$(curl -s "$LOC")
check "Bestätigung EN + localStorage-Merker" 'echo "$M" | grep -q "Your appointment is booked" && echo "$M" | grep -q "data-remember-round"'
check "Mail-Hinweis" 'echo "$M" | grep -q "confirmation with this link has been sent"'
MAILS=$(docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T app php -r 'echo file_get_contents("http://mail:8025/api/v1/search?query=to:erika@example.org");')
check "Bestätigungsmail mit termin.ics-Anhang" 'echo "$MAILS" | grep -q "\"Attachments\":1"'
check "Token nur gehasht in DB" '! docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT token_hash FROM tp_bookings" | grep -q "$TOKEN"'

MC=$(echo "$M" | csrf)
SLOT3=$(echo "$M" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$MC" -d "action=move&slot_id=$SLOT" "$B/b/$TOKEN")
check "Umbuchen auf belegten Slot abgelehnt" '[ "$code" = 200 ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$MC" -d "action=move&slot_id=$SLOT3" "$B/b/$TOKEN")
check "Umbuchen auf freien Slot" 'echo "$loc" | grep -q "done=changed"'
check "Alter Slot wieder frei" 'curl -s "$B/$SLUG" | grep -q "id=\"slot-$SLOT2\""'
C=$(curl -s --data-urlencode "csrf=$MC" -d "action=cancel&confirm=1" "$B/b/$TOKEN")
check "Absage + Merker wird gelöscht" 'echo "$C" | grep -q "data-forget-round"'
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/b/$TOKEN")
check "Link nach Absage ungültig (404)" '[ "$code" = 404 ]'

echo "Sprachen"
LP=$(curl -s "$B/$SLUG?lang=tr")
check "Türkische Seite mit Flaggen für 6 Sprachen" 'echo "$LP" | grep -q "Boş bir saat seçin" && [ "$(echo "$LP" | grep -c "assets/flags/")" = 6 ] && echo "$LP" | grep -q "title=\"Українська\""'
check "Flaggen-SVG ausgeliefert" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/assets/flags/uk.svg")" = 200 ]'
check "Datenschutz in neuer Sprache fällt auf Englisch zurück" 'curl -s "$B/datenschutz?lang=pl" | grep -q "Controller"'
check "Datenschutz ohne Google-Kalender" '! curl -s "$B/datenschutz" | grep -qiE "google[- ]?(kalender|calendar)"'

check "Hell/Dunkel: theme.js vor dem Stylesheet, Schalter im Fuß (ohne JS ausgeblendet)" 'P=$(curl -s "$B/$SLUG?lang=en"); echo "$P" | tr -d "\n" | grep -q "js/theme.js?v=[0-9]*\"></script><link rel=\"stylesheet\"" && echo "$P" | grep -q "data-theme-switch role=\"group\" aria-label=\"Appearance\" hidden" && echo "$P" | grep -q "data-theme-choice=\"dark\" aria-pressed=\"false\">Dark<"'
check "Hell/Dunkel: Schalter auch im Admin-Bereich" 'curl -s -b "$J" "$B/admin" | grep -q "data-theme-choice=\"light\" aria-pressed=\"false\">Hell<"'
check "Datenschutz erklärt die gespeicherte Darstellung" 'curl -s "$B/datenschutz" | grep -q "Darstellung (hell/dunkel)" && curl -s "$B/datenschutz?lang=en" | grep -q "Appearance (light/dark)"'

echo "Übersetzungen (im Browser der Lehrkraft, simuliert)"
DC="docker compose -f $(dirname "$0")/../dev/compose.yml"
BT=$(mktemp)
# Wie app.js: Daten der Rundenseite lesen, „übersetzen“ („[XX] Text“, zeilenweise) und an den Server schicken.
# btrans <runden-id> [1 = alle neu] [seiteninhalt] → „<http-status> <antwort>“
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
    echo http_build_query($q);' "${2:-0}" "$T" > "$BT" || { echo "0 kein-formular"; return; }
  curl -s -b "$J" -H 'Accept: application/json' -w ' %{http_code}' --data-binary @"$BT" "$B/admin/round/$1/translations/auto" | awk '{c=$NF; $NF=""; sub(/ $/,""); print c " " $0}'
}
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Herbst" --data-urlencode "description=Raum 1.12" -d "source_lang=de&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+8 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
R6=$(echo "$loc" | grep -o '[0-9]*$'); A6=$(curl -s -b "$J" "$B/admin/round/$R6"); S6=$(echo "$A6" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "Nach dem Anlegen: 5 Sprachen zum Übersetzen im Browser angeboten, Hinweis für andere Browser" 'echo "$A6" | grep -q "data-pending=\"en,pl,ru,uk,tr\"" && echo "$A6" | grep -q "Im Browser übersetzen (5 Sprachen)" && echo "$A6" | grep -q "data-bt-hint"'
check "Ohne Übersetzung sehen Eltern das Original" 'curl -s "$B/$S6?lang=tr" | grep -q "<h1>Elternsprechtag Herbst</h1>"'
check "Browser-Übersetzung ohne CSRF-Token abgelehnt" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" -d "hash=x&t_title%5Btr%5D=boese" "$B/admin/round/$R6/translations/auto")" = 303 ] && ! curl -s "$B/$S6?lang=tr" | grep -q boese'
r=$(btrans "$R6")
check "Browser-Übersetzung gespeichert ($(echo "$r" | cut -c1-18)…)" '[[ "$r" == "200 {\"saved\":5,"* ]] && curl -s -b "$J" "$B/admin/round/$R6" | grep -q "data-pending=\"\""'
check "Eltern sehen Titel/Beschreibung übersetzt (TR)" 'curl -s "$B/$S6?lang=tr" | grep -q "\[TR\] Elternsprechtag Herbst" && curl -s "$B/$S6?lang=tr" | grep -q "\[TR\] Raum 1.12"'
check "Hinweis „automatisch übersetzt“ in der Sprache der Eltern, nicht im Original" 'curl -s "$B/$S6?lang=tr" | grep -q "Başlık ve açıklama otomatik olarak çevrildi (orijinal: Deutsch)" && ! curl -s "$B/$S6?lang=de" | grep -q "data-auto-translated"'
check "Original in der Ausgangssprache" 'curl -s "$B/$S6?lang=de" | grep -q "<h1>Elternsprechtag Herbst</h1>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka jesień" --data-urlencode "t_description[pl]=Sala 1.12" "$B/admin/round/$R6/translations"
check "Übersetzung von Hand korrigiert" 'curl -s "$B/$S6?lang=pl" | grep -q "Wywiadówka jesień"'
check "Von Hand übersetzt: kein Hinweis auf automatische Übersetzung" '! curl -s "$B/$S6?lang=pl" | grep -q "data-auto-translated"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Herbst" --data-urlencode "description=Raum 1.12" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
check "Speichern ohne Textänderung: nichts offen, Handkorrektur bleibt" 'curl -s -b "$J" "$B/admin/round/$R6" | grep -q "data-pending=\"\"" && curl -s "$B/$S6?lang=pl" | grep -q "Wywiadówka jesień"'
check "Nichts offen: weiterer Browser-Lauf ändert nichts" '[[ "$(btrans "$R6")" == "200 {\"saved\":0,"* ]] && curl -s "$B/$S6?lang=pl" | grep -q "Wywiadówka jesień"'
check "Alle neu übersetzen (im Browser) überschreibt auch die Handkorrektur" '[[ "$(btrans "$R6" 1)" == "200 {\"saved\":5,"* ]] && curl -s "$B/$S6?lang=pl" | grep -q "<h1>\[PL\] Elternsprechtag Herbst</h1>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka jesień" --data-urlencode "t_description[pl]=Sala 1.12" -d "t_seen%5Bpl%5D=$(curl -s -b "$J" "$B/admin/round/$R6" | grep -o 'name="t_seen\[pl\]" value="[^"]*"' | sed 's/.*value="//;s/"$//')" "$B/admin/round/$R6/translations"
OLDPAGE=$(curl -s -b "$J" "$B/admin/round/$R6")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Elternsprechtag Winter" --data-urlencode "description=Raum 1.12" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
check "Übersetzung eines inzwischen geänderten Originals abgelehnt (409)" '[ "$(btrans "$R6" 1 "$OLDPAGE")" = "409 {\"error\":\"outdated\"}" ] && ! curl -s "$B/$S6?lang=tr" | grep -q "\[TR\] Elternsprechtag Herbst"'
check "Original geändert → alle veraltet (auch die von Hand), Browser übersetzt neu" 'curl -s -b "$J" "$B/admin/round/$R6" | grep -q "data-pending=\"en,pl,ru,uk,tr\"" && [[ "$(btrans "$R6")" == "200 {\"saved\":5,"* ]] && curl -s "$B/$S6?lang=pl" | grep -q "\[PL\] Elternsprechtag Winter"'
P6=$(curl -s "$B/$S6?lang=uk")
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$P6" | csrf)" -d "lang=uk&slot_id=$(echo "$P6" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Kind+UK&email=uk-eltern@example.org&privacy=1" "$B/$S6"
UKM=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:uk-eltern@example.org"),true); echo $r["messages"][0]["Subject"] ?? "";')
check "Mail an Eltern mit übersetztem Titel ($UKM)" 'echo "$UKM" | grep -q "\[UK\] Elternsprechtag Winter"'
UKT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:uk-eltern@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
check "Mail an Eltern mit Hinweis „automatisch übersetzt“ (UK)" 'echo "$UKT" | grep -q "Назву та опис перекладено автоматично"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
check "Original geändert, (noch) nicht übersetzt: gespeichert, Eltern sehen das Original" 'curl -s "$B/$S6?lang=tr" | grep -q "<h1>FAIL</h1>" && curl -s "$B/$S6?lang=de" | grep -q "<h1>FAIL</h1>"'
# Original mit Beschreibung geändert, nicht neu übersetzt → alle Übersetzungen veraltet
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "description=Raum 2.04" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0" "$B/admin/round/$R6/edit"
outdated() { curl -s -b "$J" "$B/admin/round/$R6" | grep -o "veraltet – Original geändert" | wc -l; }
check "Veraltete Übersetzung: Eltern sehen das Original" 'P=$(curl -s "$B/$S6?lang=tr"); echo "$P" | grep -q "<h1>FAIL</h1>" && echo "$P" | grep -q "Raum 2.04" && ! echo "$P" | grep -q "\[TR\]" && [ "$(outdated)" = 5 ]'
# Das Formular schickt alle Sprachen; geändert wird nur Polnisch (Titel, Beschreibung leer)
TFORM=(); for l in en ru uk tr; do U=$(echo "$l" | tr a-z A-Z); TFORM+=(--data-urlencode "t_title[$l]=[$U] Elternsprechtag Winter" --data-urlencode "t_description[$l]=[$U] Raum 1.12"); done
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" "${TFORM[@]}" --data-urlencode "t_title[pl]=Wywiadówka" --data-urlencode "t_description[pl]=" "$B/admin/round/$R6/translations"
check "Nur geänderte Übersetzung gespeichert, unveränderte bleiben veraltet" '[ "$(outdated)" = 4 ] && ! curl -s "$B/$S6?lang=tr" | grep -q "\[TR\]"'
check "Leere übersetzte Beschreibung: Original-Beschreibung bleibt" 'P=$(curl -s "$B/$S6?lang=pl"); echo "$P" | grep -q "<h1>Wywiadówka</h1>" && echo "$P" | grep -q "Raum 2.04"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" "${TFORM[@]}" -d "t_confirm%5Btr%5D=1" --data-urlencode "t_title[pl]=Wywiadówka" "$B/admin/round/$R6/translations"
check "Veraltete Übersetzung ohne Textänderung als aktuell bestätigt" '[ "$(outdated)" = 3 ] && curl -s "$B/$S6?lang=tr" | grep -q "<h1>\[TR\] Elternsprechtag Winter</h1>"'
# Formular in zwei Fenstern: das ältere überschreibt eine inzwischen geänderte Übersetzung nicht
seen() { echo "$1" | grep -o "name=\"t_seen\[$2\]\" value=\"[^\"]*\"" | sed 's/.*value="//;s/"$//'; }
OLDFORM=$(curl -s -b "$J" "$B/admin/round/$R6")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka nowa" -d "t_seen%5Bpl%5D=$(seen "$OLDFORM" pl)" "$B/admin/round/$R6/translations"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[pl]=Wywiadówka" -d "t_seen%5Bpl%5D=$(seen "$OLDFORM" pl)" "$B/admin/round/$R6/translations"
check "Veraltetes Formular überschreibt nichts, Hinweis angezeigt" 'curl -s "$B/$S6?lang=pl" | grep -q "<h1>Wywiadówka nowa</h1>" && curl -s -b "$J" "$B/admin/round/$R6" | grep -q "inzwischen anderweitig geändert"'
# Duplizieren mit veralteten und von Hand gepflegten Übersetzungen
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$R6/duplicate"); RC6=$(echo "$loc" | grep -o '[0-9]*$')
C6=$(curl -s -b "$J" "$B/admin/round/$RC6")
check "Kopie: veraltete bleiben veraltet (ohne Zusatz), von Hand gepflegte aktuell mit Zusatz" '[ "$(echo "$C6" | grep -o "veraltet – Original geändert" | wc -l)" = 3 ] && echo "$C6" | grep -q "value=\"\[EN\] Elternsprechtag Winter\"" && echo "$C6" | grep -q "value=\"Wywiadówka nowa (Kopie)\"" && [ "$(echo "$C6" | grep -o ">von Hand<" | wc -l)" = 2 ] && echo "$C6" | grep -q "data-pending=\"en,ru,uk\""'
# Titel leeren = Sprache bewusst ohne Übersetzung; der Browser ergänzt sie nicht wieder
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[ru]=" "$B/admin/round/$R6/translations"
check "Bewusst ohne Übersetzung: bleibt so, nicht wieder offen, Eltern sehen das Original" 'A=$(curl -s -b "$J" "$B/admin/round/$R6"); echo "$A" | grep -q "bewusst ohne Übersetzung" && ! echo "$A" | grep -o "data-pending=\"[^\"]*\"" | grep -q ru && curl -s "$B/$S6?lang=ru" | grep -q "<h1>FAIL</h1>"'
# Lange Beschreibung: Browser liefert mehr als 5000 Zeichen; unverändert abgesendetes Formular ändert nichts
LONGD=$(head -c 5000 /dev/zero | tr '\0' 'a')  # Höchstlänge; „[PL] “ davor ergibt 5005 Zeichen
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=Lange Beschreibung" --data-urlencode "description=$LONGD" -d "source_lang=de&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+9 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
RL=$(echo "$loc" | grep -o '[0-9]*$')
btrans "$RL" > /dev/null
FORM=(); for l in en pl ru uk tr; do FORM+=(--data-urlencode "t_title[$l]=$(curl -s -b "$J" "$B/admin/round/$RL" | grep -o "name=\"t_title\[$l\]\"[^>]*value=\"[^\"]*\"" | sed 's/.*value="//;s/"$//')" --data-urlencode "t_description[$l]=$(printf '[%s] %s' "$(echo $l | tr a-z A-Z)" "$LONGD" | cut -c1-5000)"); done
check "Über 5000 Zeichen: Übersetzung gekürzt gespeichert, unverändertes Formular → keine Änderungen" 'curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" "${FORM[@]}" "$B/admin/round/$RL/translations" && curl -s -b "$J" "$B/admin/round/$RL" | grep -q "Keine Änderungen" && [ "$(curl -s -b "$J" "$B/admin/round/$RL" | grep -o ">automatisch<" | wc -l)" = 5 ]'

echo "Rechtstexte"
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nÜbersetzung über DeepL.') WHERE page_key='privacy' AND lang='en'"
check "Admin-Hinweis, solange die gespeicherte Datenschutzerklärung DeepL nennt" 'curl -s -b "$J" "$B/admin" | grep -q "(EN) nennt noch DeepL" && curl -s -b "$J" "$B/admin/texts" | grep -q "nennt noch DeepL"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '\n\nÜbersetzung über DeepL.', '') WHERE page_key='privacy' AND lang='en'"
check "Kein Hinweis mehr nach dem Anpassen" '! curl -s -b "$J" "$B/admin" | grep -q "nennt noch DeepL"'
# Frühere, unbearbeitete Fassung der Vorlage (mit DeepL); ihr Hash steht in Migrations::OLD_PAGE_HASHES
cp "$(dirname "$0")/fixtures/privacy.de.alt.md" "$BT"
php -r '$p = new PDO("mysql:host=127.0.0.1;dbname=" . (getenv("TP_DB") ?: "talkslot") . ";charset=utf8mb4", "app", "app-dev-only"); $p->prepare("UPDATE tp_pages SET content = ? WHERE page_key = \"privacy\" AND lang = \"de\"")->execute([file_get_contents($argv[1])]);' "$BT" 2>/dev/null \
  || $DC exec -T app php -r '$p = new PDO("mysql:host=db;dbname=talkslot;charset=utf8mb4", "app", "app-dev-only"); $p->prepare("UPDATE tp_pages SET content = ? WHERE page_key = \"privacy\" AND lang = \"de\"")->execute([file_get_contents("php://stdin")]);' < "$BT"
DBQ "UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "Unbearbeitete alte Datenschutzerklärung wird ersetzt, auch ohne Schema-Schritt" '! curl -s "$B/datenschutz" | grep -q "DeepL" && curl -s "$B/datenschutz" | grep -q "Translator API" && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"pages_version\"")" != alt ]'

echo "Admin-Aktionen"
T=$(curl -s -b "$J" "$B/admin/round/$RID" | csrf)
BID=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -o '/admin/booking/[0-9]*/edit' | head -1 | grep -o '[0-9]\+')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T&name=Neu+Name&email=&lang=de" "$B/admin/booking/$BID/edit")
check "Buchung bearbeiten" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Neu Name"'
FREE=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -o '/admin/slot/[0-9]*/book' | head -1 | grep -o '[0-9]\+')
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Selbst+eingetragen&lang=de" "$B/admin/slot/$FREE/book"
check "Eintrag durch Lehrkraft" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "selbst eingetragen"'
FREE=$(curl -s -b "$J" "$B/admin/round/$RID" | grep -o '/admin/slot/[0-9]*/block' | head -1 | grep -o '[0-9]\+')
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/slot/$FREE/block"
check "Slot gesperrt → öffentlich unsichtbar" '! curl -s "$B/$SLUG" | grep -q "id=\"slot-$FREE\""'
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_date=$TOMORROW&start_time=14:05&end_time=16:00&slot_minutes=15" "$B/admin/day/$DID/edit"
check "Zeitplan verkürzt → gebuchte Slots als Konflikt" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Konflikt(e)"'
check "Druckansicht" 'curl -s -b "$J" "$B/admin/round/$RID/print" | grep -q "Neu Name"'
check "Kalender-Export im Verwaltungsbereich entfernt" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$RID/calendar.ics")" = 404 ]'
btrans "$RID" > /dev/null # Übersetzungen wie im Browser, damit die Kopie sie übernehmen kann
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$RID/duplicate")
RC=$(echo "$loc" | grep -o '[0-9]*$')
check "Runde duplizieren" 'curl -s -b "$J" "$B/admin" | grep -q "(Kopie)"'
check "Kopie übernimmt aktuelle Übersetzungen, nichts offen" 'A=$(curl -s -b "$J" "$B/admin/round/$RC"); echo "$A" | grep -q "(5 von 5 aktuell)" && echo "$A" | grep -q "value=\"\[TR\] Elternsprechtag Größe Übung (Kopie)\"" && echo "$A" | grep -q "data-pending=\"\""'

echo "Regressionen aus dem Review"
# Langer Titel: Slug > 80 Zeichen bleibt beim Bearbeiten unverändert
LONG="Gesprächstermine für die Eltern der Klassen drei a und drei b im zweiten Schulhalbjahr zwanzig sechsundzwanzig"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$LONG" \
  -d "random_suffix=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&day_dates%5B%5D=$TOMORROW&start_time=14:00&end_time=15:00&slot_minutes=10" "$B/admin/round/new")
R2=$(echo "$loc" | grep -o '[0-9]*$')
S_BEFORE=$(curl -s -b "$J" "$B/admin/round/$R2/edit" | grep -o 'id="slug" name="slug" maxlength="100" value="[^"]*"' | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$LONG" --data-urlencode "slug=$S_BEFORE" \
  -d "is_active=1&booking_deadline_hours=5&cancel_deadline_hours=0&delete_after_hours=24" "$B/admin/round/$R2/edit"
S_AFTER=$(curl -s -b "$J" "$B/admin/round/$R2/edit" | grep -o 'id="slug" name="slug" maxlength="100" value="[^"]*"' | sed 's/.*value="//;s/"$//')
check "Slug ${#S_BEFORE} Zeichen bleibt beim Bearbeiten erhalten" '[ ${#S_BEFORE} -gt 80 ] && [ "$S_BEFORE" = "$S_AFTER" ]'

# Konflikt-Neuberechnung: passende Buchung darf nicht als Konflikt markiert werden
D2=$(curl -s -b "$J" "$B/admin/round/$R2" | grep -o 'id="day-[0-9]*"' | head -1 | grep -o '[0-9]*')
for s in $(curl -s -b "$J" "$B/admin/round/$R2" | grep -o '/admin/slot/[0-9]*/book' | head -2 | grep -o '[0-9]\+'); do
  curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Konflikttest+$s&lang=de" "$B/admin/slot/$s/book"
done
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_date=$TOMORROW&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/day/$D2/edit"
check "10→15 Min: nur 14:10 ist Konflikt, 14:00 bleibt gültig" 'curl -s -b "$J" "$B/admin/round/$R2" | grep -q "<strong>1 Konflikt(e):"'

# Tag mit Buchungen: Löschen ohne Bestätigung wird serverseitig abgelehnt
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/day/$D2/delete"
check "Tag mit Buchungen ohne Bestätigung nicht gelöscht" 'curl -s -b "$J" "$B/admin/round/$R2" | grep -q "id=\"day-$D2\""'

# Deaktivierte Runde: Eltern können weiterhin absagen
P2=$(curl -s "$B/$(curl -s -b "$J" "$B/admin/round/$R2" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')")
S2=$(echo "$P2" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
L2=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$P2" | csrf)" -d "slot_id=$S2&name=Absagetest&privacy=1" "$B/$(echo "$P2" | grep -o 'action="/[^"]*"' | head -1 | sed 's#action="/##;s/"$//')")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$R2/toggle"
MP=$(curl -s "$L2")
check "Deaktivierte Runde: Absage möglich, Umbuchen ausgeblendet" 'echo "$MP" | grep -q "name=\"action\" value=\"cancel\"" && ! echo "$MP" | grep -q "value=\"move\""'
C2=$(curl -s --data-urlencode "csrf=$(echo "$MP" | csrf)" -d "action=cancel&confirm=1" "$L2")
check "Deaktivierte Runde: Absage durchgeführt" 'echo "$C2" | grep -q "data-forget-round"'

echo "Schutz mit dem Rundencode"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Geschuetzte+Runde&code_required=1&random_suffix=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&day_dates%5B%5D=$TOMORROW&start_time=10:00&end_time=11:00&slot_minutes=20" "$B/admin/round/new")
R3=$(echo "$loc" | grep -o '[0-9]*$')
AP=$(curl -s -b "$J" "$B/admin/round/$R3")
RC3=$(echo "$AP" | grep -o 'data-round-code>[A-Z0-9-]*' | sed 's/.*>//')
S3=$(echo "$AP" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "Nur mit Rundencode: angezeigt, Einladungstext nennt den Code ($RC3)" '[[ "$RC3" =~ ^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$ ]] && echo "$AP" | grep -q "Code der Terminvergabe: $RC3 (wird nach dem Öffnen des Links abgefragt)" && echo "$AP" | grep -q ">Nur mit Rundencode<" && ! echo "$AP" | grep -qi "zugangscode"'
check "Formular: Schalter statt Zugangscode-Feld" 'F=$(curl -s -b "$J" "$B/admin/round/$R3/edit"); echo "$F" | grep -q "name=\"code_required\" value=\"1\" checked" && ! echo "$F" | grep -q "access_pin"'
G=$(curl -s "$B/$S3")
check "Ohne Code: keine Termine, kein Titel" 'echo "$G" | grep -q "name=\"step\" value=\"unlock\"" && ! echo "$G" | grep -q "slot_id" && ! echo "$G" | grep -q "Geschuetzte"'
GC=$(echo "$G" | csrf)
WRONG=ZZZZZZZZ
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$GC" -d "step=unlock&code=$WRONG" "$B/$S3")
check "Falscher Code → 422 mit Hinweis" '[ "$code" = 422 ] && curl -s --data-urlencode "csrf=$GC" -d "step=unlock&code=$WRONG" "$B/$S3" | grep -q "Der Code ist nicht korrekt"'
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
H1=$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket='code'")
check "Alte PIN (nur Ziffern) auf der Rundenseite → Formathinweis, zählt nicht" 'curl -s --data-urlencode "csrf=$GC" -d "step=unlock&code=482913" "$B/$S3" | grep -q "besteht aus 8 Buchstaben und Ziffern" && [ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")" = "$H1" ]'
U=$(curl -s --data-urlencode "csrf=$GC" --data-urlencode "code=$(echo "$RC3" | tr A-Z a-z | tr - ' ')" -d "step=unlock" "$B/$S3")
check "Richtiger Code (klein, mit Leerzeichen) → Termine + Zugangstoken" 'echo "$U" | grep -q "name=\"access\"" && echo "$U" | grep -q "slot_id"'
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$(echo "$U" | csrf)" -d "slot_id=$(echo "$U" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Ohne+Token&privacy=1" "$B/$S3")
check "Buchung ohne Zugangstoken abgelehnt" '[ "$code" = 422 ]'
ACC=$(echo "$U" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$U" | csrf)" --data-urlencode "access=$ACC" -d "slot_id=$(echo "$U" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Mit+Token&privacy=1" "$B/$S3")
check "Buchung mit Zugangstoken erfolgreich" 'echo "$loc" | grep -q "/b/"'
check "Datenschutz-Kurzinfo im Formular" 'echo "$U" | grep -q "Datenschutz auf einen Blick"'
SL=$(echo "$U" | grep -o 'value="[0-9]*" required' | sed -n 2p | grep -o '[0-9]*')
KEEP=$(curl -s --data-urlencode "csrf=$(echo "$U" | csrf)" --data-urlencode "access=$ACC" --data-urlencode "name=Müller Max" -d "lang=de&switch_lang=en&slot_id=$SL&email=m@example.org&privacy=1&remember=1" "$B/$S3")
check "Sprachwechsel behält Eingaben (Termin, Name, E-Mail, Haken), bucht nicht" 'echo "$KEEP" | grep -q "Choose a free time slot" && echo "$KEEP" | grep -q "value=\"Müller Max\"" && echo "$KEEP" | grep -q "value=\"m@example.org\"" && echo "$KEEP" | grep -q "value=\"$SL\" required checked" && echo "$KEEP" | grep -q "name=\"privacy\" value=\"1\" required checked" && ! echo "$KEEP" | grep -q "alert-error"'
check "Sprachknöpfe senden das Buchungsformular" 'echo "$KEEP" | grep -q "form=\"booking-form\" name=\"switch_lang\" value=\"de\""'
SW=$(curl -s -d "step=switch&lang=en" --data-urlencode "access=$ACC" "$B/$S3")
check "Sprachwechsel behält Zugang (Code nicht erneut nötig)" 'echo "$SW" | grep -q "slot_id" && echo "$SW" | grep -q "Choose a free time slot" && ! echo "$SW" | grep -q "name=\"step\" value=\"unlock\""'
check "Sprachwechsel ohne gültigen Zugang → Code-Abfrage" 'curl -s -d "step=switch&lang=en&access=123.abc" "$B/$S3" | grep -q "name=\"step\" value=\"unlock\""'
for i in $(seq 1 32); do curl -s -o /dev/null --data-urlencode "csrf=$GC" -d "step=unlock&code=$WRONG" "$B/$S3"; done
code=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$GC" --data-urlencode "code=$RC3" -d "step=unlock" "$B/$S3")
check "Code-Raten auf der Rundenseite begrenzt (429, auch für den richtigen Code)" '[ "$code" = 429 ]'
check "Gemeinsames Limit: auch die Startseite ist gesperrt, Meldung ohne Link-Rat" 'P=$(curl -s -d "code=$RC3" "$B/"); echo "$P" | grep -q "<li>Zu viele Fehlversuche aus Ihrem Netzwerk. Bitte versuchen Sie es in 15 Minuten erneut.</li>" && ! echo "$P" | grep -q "slot_id"'
docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -e "DELETE FROM tp_rate_limits WHERE bucket='code'"
TP=$(curl -s -b "$J" "$B/admin/round/$R3" | csrf)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TP" -d "title=Geschuetzte+Runde&code_required=1&new_code=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R3/edit"
NEW3=$(curl -s -b "$J" "$B/admin/round/$R3" | grep -o 'data-round-code>[A-Z0-9-]*' | sed 's/.*>//')
check "Neuer Rundencode: alter Code und offenes Zugangstoken ungültig, neuer Code gilt" '[ "$NEW3" != "$RC3" ] && curl -s --data-urlencode "csrf=$GC" --data-urlencode "code=$RC3" -d "step=unlock" "$B/$S3" | grep -q "Der Code ist nicht korrekt" && curl -s -d "step=switch&lang=en" --data-urlencode "access=$ACC" "$B/$S3" | grep -q "name=\"step\" value=\"unlock\"" && curl -s --data-urlencode "csrf=$GC" --data-urlencode "code=$NEW3" -d "step=unlock" "$B/$S3" | grep -q "slot_id"'
RC3=$NEW3
# Veraltetes Formular ohne Schalter lässt den Schutz an; Kopie bleibt geschützt; Schalter aus = ohne Schutz
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TP" -d "title=Geschuetzte+Runde&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R3/edit"
check "Formular ohne Schalter-Feld (z. B. vor dem Update geöffnet) lässt den Schutz an" '[ "$(DBQ "SELECT code_required FROM tp_rounds WHERE id=$R3")" = 1 ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$TP" "$B/admin/round/$R3/duplicate"); RD3=$(echo "$loc" | grep -o '[0-9]*$')
check "Kopie einer geschützten Runde bleibt geschützt (eigener Code)" '[ "$(DBQ "SELECT CONCAT(code_required, code <> \"$(echo $RC3 | tr -d -)\") FROM tp_rounds WHERE id=$RD3")" = 11 ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TP" -d "title=Kopie+offen&code_required_field=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RD3/edit"
check "Schalter aus: Runde ohne Schutz über den Link erreichbar" '[ "$(DBQ "SELECT code_required FROM tp_rounds WHERE id=$RD3")" = 0 ] && curl -s "$B/$(curl -s -b "$J" "$B/admin/round/$RD3" | grep -o "data-copy=\"[^\"]*\"" | head -1 | sed "s#.*/##;s/\"$//")" | grep -q "slot_id"'

echo "Rundencode (Startseite)"
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
rcode() { curl -s -b "$J" "$B/admin/round/$1" | grep -o 'data-round-code>[A-Z0-9-]*' | sed 's/.*>//'; }
check "Code direkt beim Anlegen/Duplizieren vergeben (nicht erst beim Ansehen)" '[ -n "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RID")" ] && [ -n "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RC")" ] && [ "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RC")" != "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RID")" ]'
AR=$(curl -s -b "$J" "$B/admin/round/$RID"); RCODE=$(rcode "$RID")
check "Rundencode angezeigt + im Einladungstext ($RCODE)" '[[ "$RCODE" =~ ^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$ ]] && echo "$AR" | grep -q "den Code der Terminvergabe $RCODE eingeben"'
check "Startseite mit Codefeld" 'curl -s "$B/" | grep -q "name=\"code\""'
check "Ohne Sprachwahl immer Deutsch (Browsersprache egal)" 'curl -s -H "Accept-Language: en-US,en;q=0.9" "$B/" | grep -q "<html lang=\"de\">" && curl -s -H "Accept-Language: tr" "$B/$SLUG" | grep -q ">Name <"'
r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -G --data-urlencode "code=$(echo "$RCODE" | tr A-Z a-z | tr - ' ')" -d lang=tr "$B/")
check "Code (klein, mit Leerzeichen) → Runde, Sprache bleibt ($r)" '[[ "$r" == "303 "*"/$SLUG?lang=tr" ]]'
CYR=$(echo "$RCODE" | sed 's/A/А/g;s/B/В/g;s/C/С/g;s/E/Е/g;s/H/Н/g;s/K/К/g;s/M/М/g;s/P/Р/g;s/T/Т/g;s/X/Х/g;s/Y/У/g;s/3/З/g;s/-/–/')
check "Kyrillische Doppelgänger und Gedankenstrich werden erkannt" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -G --data-urlencode "code=$CYR" "$B/")" == *"/$SLUG" ]]'
check "Startseite TR: Codefeld übersetzt, Sprache im Formular" 'P=$(curl -s "$B/?lang=tr"); echo "$P" | grep -q "Davetiyedeki kod" && echo "$P" | grep -q "name=\"lang\" value=\"tr\""'
H0=$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")
for i in $(seq 1 35); do curl -s -o /dev/null "$B/?code=$RCODE"; done
check "Erfolgreiche Eingaben zählen nicht gegen das Limit (35× → weiter 303)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$RCODE")" = 303 ] && [ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")" = "$H0" ]'
r=$(curl -s -o /dev/null -w '%{http_code}' "$B/?code=ZZZZ-ZZZZ")
check "Unbekannter Code → 422 mit Hinweis, Eingabe bleibt" '[ "$r" = 422 ] && P=$(curl -s "$B/?code=ZZZZ-ZZZZ"); echo "$P" | grep -q "keine offene Terminvergabe" && echo "$P" | grep -q "value=\"ZZZZ-ZZZZ\""'
check "Nur Ziffern eingegeben (z. B. alte PIN) → Hinweis aufs Format, zählt nicht" 'H=$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\""); curl -s "$B/?code=482913" | grep -q "besteht aus 8 Buchstaben und Ziffern" && [ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"code\"")" = "$H" ]'
check "Code einer inaktiven Runde → nicht gefunden" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$(rcode "$R2")")" = 422 ]'
check "Code einer geschützten Runde auf der Startseite → direkt freigeschaltet (Termine, Zugangstoken)" 'P=$(curl -s "$B/?code=$(rcode "$R3")"); echo "$P" | grep -q "slot_id" && echo "$P" | grep -q "name=\"access\"" && ! echo "$P" | grep -q "name=\"step\" value=\"unlock\""'
check "Startseiten-Formular sendet per POST (Code nicht in der Adresse)" 'curl -s "$B/" | grep -q "<form method=\"post\"" && [ "$(curl -s -o /dev/null -w "%{http_code}" -d "code=$(rcode "$R3")" "$B/")" = 200 ]'
HP=$(curl -s -d "code=$(rcode "$R3")" "$B/")
S3NOW=$(curl -s -b "$J" "$B/admin/round/$R3" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
HACT=$(echo "$HP" | grep -o '<form[^>]*id="booking-form"[^>]*>' | grep -o 'action="[^"]*"' | sed 's/action="//;s/"$//')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$HP" | csrf)" --data-urlencode "access=$(echo "$HP" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')" -d "slot_id=$(echo "$HP" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Ueber+Startseite&privacy=1" "$B$HACT")
check "Buchung über die auf der Startseite freigeschaltete Runde (Formular an /$S3NOW)" '[ "$HACT" = "/$S3NOW" ] && echo "$loc" | grep -q "/b/"'
check "Kopie hat eigenen Code" 'C=$(rcode "$RC"); [ -n "$C" ] && [ "$C" != "$RCODE" ]'
OLD6=$(rcode "$R6")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "description=Raum 2.04" --data-urlencode "slug=$S6" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0&new_code=1" "$B/admin/round/$R6/edit"
NEW6=$(rcode "$R6")
check "Neuer Rundencode: alter gilt nicht mehr, neuer führt zur Runde" '[ -n "$NEW6" ] && [ "$NEW6" != "$OLD6" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$OLD6")" = 422 ] && [[ "$(curl -s -o /dev/null -w "%{redirect_url}" "$B/?code=$NEW6")" == *"/$S6" ]]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=FAIL" --data-urlencode "description=Raum 2.04" -d "source_lang=de&is_active=1&booking_deadline_hours=1&cancel_deadline_hours=0&random_suffix=1" "$B/admin/round/$R6/edit"
check "Neuer Zufallsanteil im Link → auch neuer Rundencode" 'N=$(rcode "$R6"); [ -n "$N" ] && [ "$N" != "$NEW6" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$NEW6")" = 422 ]'
for i in $(seq 1 35); do curl -s -o /dev/null "$B/?code=ZZZZZZZZ"; done
check "Code-Raten begrenzt (429, auch für gültige Codes)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/?code=$RCODE")" = 429 ] && curl -s "$B/?code=$RCODE" | grep -q "Zu viele Fehlversuche"'
DBQ "DELETE FROM tp_rate_limits WHERE bucket='code'"

echo "Mehrere Lehrkräfte"
DC="docker compose -f $(dirname "$0")/../dev/compose.yml"
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
UOUT=$(curl -s -L -b "$J" -d "csrf=$T&display_name=Herr+Zwei&email=Zwei@Example.org&role=admin" "$B/admin/users/new")
check "Lehrkraft nur mit Name + E-Mail angelegt, Einladung gesendet" 'echo "$UOUT" | grep -q "Einladung an zwei@example.org gesendet" && echo "$UOUT" | grep -q "Einladung offen"'
check "Kein zweiter Admin: neues Konto ist Lehrkraft (auch mit role=admin), kein Rollenfeld" '[ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT role FROM tp_users WHERE username=\"zwei@example.org\"")" = teacher ] && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT COUNT(*) FROM tp_users WHERE role=\"admin\"")" = 1 ] && ! curl -s -b "$J" "$B/admin/users/new" | grep -q "name=\"role\""'
WMAIL=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:zwei@example.org%20subject:Einladung"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
LINK=$(echo "$WMAIL" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
check "Einladungsmail: Link, Benutzername, kein Passwort" '[ -n "$LINK" ] && echo "$WMAIL" | grep -q "Ihr Benutzername: zwei@example.org" && ! echo "$WMAIL" | grep -qi "passwort:"'
check "Einladungsmail ohne altes Modell (keine Kontaktdaten, nicht verantwortlich)" '! echo "$WMAIL" | grep -q "Kontaktdaten für die Datenschutzhinweise" && ! echo "$WMAIL" | grep -q "datenschutzrechtlich verantwortlich"'
J2=$(mktemp)
T2=$(curl -s -c "$J2" -b "$J2" "$B/admin/login" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J2" -b "$J2" -d "csrf=$T2&username=zwei@example.org&password=irgendwas-langes" "$B/admin/login")
check "Vor Annahme der Einladung kein Login möglich" '[ "$code" = 401 ]'
IP=$(curl -s -c "$J2" -b "$J2" "$LINK")
check "Einladungslink öffnet Passwort-Formular" 'echo "$IP" | grep -q "Passwort festlegen" && echo "$IP" | grep -q "zwei@example.org"'
T2=$(echo "$IP" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" -d "new_password=kurz&new_password2=kurz" "$LINK")
check "Zu kurzes Passwort abgelehnt" '[ "$code" = 200 ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" -d "new_password=Zweites-Passwort-123&new_password2=Zweites-Passwort-123" "$LINK")
check "Passwort über Einladung gesetzt" 'echo "$loc" | grep -q "/admin/login"'
check "Einladungslink danach ungültig" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$LINK")" = 404 ]'
T2=$(curl -s -c "$J2" -b "$J2" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$T2&username=zwei@example.org&password=Zweites-Passwort-123" "$B/admin/login"
check "Login mit selbst gesetztem Passwort" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin")" = 200 ]'

# Passwort vergessen: gleiche Antwort für unbekannte Adressen, Link für bekannte
J3=$(mktemp); T3=$(curl -s -c "$J3" -b "$J3" "$B/admin/forgot" | csrf)
A1=$(curl -s -c "$J3" -b "$J3" --data-urlencode "csrf=$T3" -d "email=gibt-es-nicht@example.org" "$B/admin/forgot")
A2=$(curl -s -c "$J3" -b "$J3" --data-urlencode "csrf=$T3" -d "email=zwei@example.org" "$B/admin/forgot")
check "Passwort vergessen: identische Antwort (keine Konto-Erkennung)" '[ "$(echo "$A1" | grep -c "Falls zu dieser Angabe")" = 1 ] && [ "$(echo "$A2" | grep -c "Falls zu dieser Angabe")" = 1 ]'
RMAIL=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:zwei@example.org%20subject:%22Passwort%20festlegen%22"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
RLINK=$(echo "$RMAIL" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
check "Reset-Link per Mail, altes Passwort bleibt bis zur Nutzung gültig" '[ -n "$RLINK" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin")" = 200 ]'
$DC exec -T db mariadb -uapp -papp-dev-only talkslot -e "UPDATE tp_users SET access_token_expires_at = NOW() - INTERVAL 1 MINUTE WHERE username = 'zwei@example.org'"
check "Abgelaufener Link ungültig" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$RLINK")" = 404 ]'
rm -f "$J3"

# Datentrennung: Lehrkraft 2 darf nichts von Lehrkraft 1 sehen oder ändern
CODES=""
for u in "/admin/round/$RID" "/admin/round/$RID/edit" "/admin/round/$RID/print" "/admin/booking/$BID/edit" "/admin/day/$DID/edit" "/admin/users" "/admin/texts" "/admin/page/privacy/de"; do
  CODES="$CODES $(curl -s -o /dev/null -w '%{http_code}' -c "$J2" -b "$J2" "$B$u")"
done
check "Fremde Runde/Buchung/Tag/Druck und Admin-Seiten → 404 ($CODES)" '[ "$(echo $CODES | tr " " "\n" | sort -u)" = "404" ]'
T2=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
for u in "/admin/round/$RID/delete" "/admin/booking/$BID/delete" "/admin/round/$RID/toggle"; do curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$T2" "$B$u"; done
curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$T2&name=Fremd+eingetragen&lang=de" "$B/admin/slot/$FREE/book"
check "Fremde Runde/Buchung per POST unverändert" 'curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Neu Name" && ! curl -s -b "$J" "$B/admin/round/$RID" | grep -q "Fremd eingetragen"'
check "Übersicht zeigt nur eigene Runden" '! curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Elternsprechtag Größe"'

# Lehrkraft 2: Profil, eigene Runde, Buchung für morgen
curl -s -o /dev/null -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" --data-urlencode "display_name=Herr Zwei" \
  --data-urlencode $'controller_contact=Herr Zwei\nSchulweg 2\n54321 Zweistadt' -d "email=zwei@example.org&summary_evening=1&summary_deadline=1" "$B/admin/profile"
NEXT=$(date -d '+1 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$J2" -b "$J2" --data-urlencode "csrf=$T2" -d "title=Sprechstunde+Zwei&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$NEXT&start_time=23:00&end_time=23:40&slot_minutes=20" "$B/admin/round/new")
R4=$(echo "$loc" | grep -o '[0-9]*$')
S4=$(curl -s -c "$J2" -b "$J2" "$B/admin/round/$R4" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
P4=$(curl -s "$B/$S4")
check "Rundenseite nennt die Lehrkraft" 'echo "$P4" | grep -q "Gesprächstermine bei Herr Zwei"'
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$P4" | csrf)" -d "slot_id=$(echo "$P4" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Kind+Zwei&email=eltern-zwei@example.org&privacy=1" "$B/$S4"
PRIV=$(curl -s "$B/datenschutz?r=$S4")
check "Ohne Hoster in der Konfiguration: sichtbarer Platzhalter, Hinweis im Admin" 'echo "$PRIV" | grep -qF "[HOSTER MIT ANSCHRIFT]" && ! echo "$PRIV" | grep -qF "[[HOSTER]]" && curl -s -b "$J" "$B/admin/texts" | grep -q "fehlen die Angaben zum Hoster"'
check "Datenschutzhinweise nennen den Admin als Verantwortlichen (auch bei Runde von Lehrkraft 2)" 'echo "$PRIV" | grep -q "Teststraße 1" && ! echo "$PRIV" | grep -q "Schulweg 2" && ! echo "$PRIV" | grep -q "Auftragsverarbeiterin"'
check "Datenschutzhinweise ohne Runde und Impressum: ebenfalls die Angaben des Admins" 'curl -s "$B/datenschutz" | grep -q "Teststraße 1" && curl -s "$B/impressum" | grep -q "Teststraße 1" && ! curl -s "$B/impressum" | grep -q "VORNAME NACHNAME"'
check "Lehrkraft-Profil ohne Angaben für Impressum/Datenschutz" '! curl -s -c "$J2" -b "$J2" "$B/admin/profile" | grep -q "name=\"controller_contact\"" && curl -s -b "$J" "$B/admin/profile" | grep -q "name=\"controller_contact\""'
check "Admin sieht alle Runden (mit Lehrkraft) und die Runde von Lehrkraft 2" 'D=$(curl -s -b "$J" "$B/admin"); echo "$D" | grep -q "Alle Terminrunden" && echo "$D" | grep -q "Sprechstunde Zwei" && echo "$D" | grep -q "<td>Herr Zwei</td>" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$R4")" = 200 ]'
TA=$(curl -s -b "$J" "$B/admin" | csrf)
U2=$(curl -s -b "$J" "$B/admin/round/new" | grep -o '<option value="[0-9]*">Herr Zwei' | grep -o '[0-9]\+')
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$TA" -d "user_id=$U2&title=Vom+Admin+angelegt&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$NEXT&start_time=22:00&end_time=22:40&slot_minutes=20" "$B/admin/round/new")
R5=$(echo "$loc" | grep -o '[0-9]*$')
check "Admin legt Runde für Lehrkraft 2 an: erscheint bei ihr, Eltern sehen ihren Namen" '[ -n "$R5" ] && curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Vom Admin angelegt" && curl -s "$B/$(curl -s -b "$J" "$B/admin/round/$R5" | grep -o "data-copy=\"[^\"]*\"" | head -1 | sed "s#.*/##;s/\"$//")" | grep -q "Gesprächstermine bei Herr Zwei" && curl -s -b "$J" "$B/admin/round/$R5" | grep -q "bei Herr Zwei buchen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" -d "user_id=$U2&title=Vom+Admin+bearbeitet&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "Admin bearbeitet Runde von Lehrkraft 2 (bleibt ihre)" 'curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Vom Admin bearbeitet"'
check "Bearbeiten-Formular des Admins wählt die Lehrkraft der Runde vor" 'curl -s -b "$J" "$B/admin/round/$R4/edit" | grep -q "<option value=\"$U2\" selected>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" -d "title=Vom+Admin+bearbeitet&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "Speichern ohne Lehrkraft-Feld lässt die Runde bei ihr" '[ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT user_id FROM tp_rounds WHERE id=$R4")" = "$U2" ]'
check "Navigation: Admin „Alle Runden“, Lehrkraft „Meine Runden“" 'curl -s -b "$J" "$B/admin" | grep -q ">Alle Runden<" && curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q ">Meine Runden<"'
# Admin arbeitet in der Runde der Lehrkraft: Tag, Druck, Buchung anlegen (Mail mit Reply-To der Lehrkraft) und löschen
D4=$(curl -s -b "$J" "$B/admin/round/$R4" | grep -o '/admin/day/[0-9]*/edit' | head -1)
check "Admin: Tag und Druckansicht einer Runde der Lehrkraft" '[ -n "$D4" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B$D4")" = 200 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/$R4/print")" = 200 ]'
SL4=$(curl -s -b "$J" "$B/admin/round/$R4" | grep -o '/admin/slot/[0-9]*/book' | head -1)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" -d "name=Vom+Admin+gebucht&email=admin-buchung@example.org&lang=de&send_mail=1" "$B$SL4"
BK4=$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT id FROM tp_bookings WHERE name='Vom Admin gebucht'")
AMAIL=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:admin-buchung@example.org"),true); echo json_encode($r["messages"][0]["ReplyTo"] ?? []), " ", json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "Admin bucht in Runde der Lehrkraft: Mail mit Reply-To der Lehrkraft, Footer nennt den Admin" '[ -n "$BK4" ] && echo "$AMAIL" | grep -q "zwei@example.org" && echo "$AMAIL" | grep -q "Verantwortlich: Sabrina Test" && ! echo "$AMAIL" | grep -q "Verantwortlich: Herr Zwei"'
curl -s -o /dev/null -b "$J" -d "csrf=$TA" "$B/admin/booking/$BK4/delete"
check "Admin löscht Buchung in Runde der Lehrkraft" '! curl -s -b "$J" "$B/admin/round/$R4" | grep -q "Vom Admin gebucht"'
TT=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
curl -s -o /dev/null -c "$J2" -b "$J2" --data-urlencode "csrf=$TT" -d "user_id=1&title=Uebernahmeversuch&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "Lehrkraft kann die Lehrkraft einer Runde nicht ändern" 'curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Uebernahmeversuch" && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT user_id FROM tp_rounds WHERE id=$R4")" = "$U2" ]'
# Ohne Angaben des Admins: keine Runde öffentlich, Lehrkraft sieht den Grund
ADMINCC=$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT HEX(controller_contact) FROM tp_users WHERE role='admin'")
$DC exec -T db mariadb -uapp -papp-dev-only talkslot -e "UPDATE tp_users SET controller_contact = NULL WHERE role='admin'"
check "Ohne Angaben des Admins: Runden offline, Hinweis bei der Lehrkraft" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 404 ] && curl -s -c "$J2" -b "$J2" "$B/admin" | grep -q "Der Admin hat noch keine Angaben" && curl -s "$B/datenschutz" | grep -q "werden gerade ergänzt"'
TT=$(curl -s -c "$J2" -b "$J2" "$B/admin" | csrf)
curl -s -o /dev/null -c "$J2" -b "$J2" -d "csrf=$TT" "$B/admin/round/$R4/toggle"   # aktive Runde deaktivieren geht
TG=$(curl -s -L -c "$J2" -b "$J2" -d "csrf=$TT" "$B/admin/round/$R4/toggle")
check "Ohne Angaben des Admins: Lehrkraft kann nicht aktivieren (passende Meldung)" 'echo "$TG" | grep -q "erst aktiviert werden, wenn der Admin" && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT is_active FROM tp_rounds WHERE id=$R4")" = 0 ]'
TG=$(curl -s -L -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle")
check "Ohne Angaben des Admins: auch der Admin kann nicht aktivieren (passende Meldung)" 'echo "$TG" | grep -q "tragen Sie bitte unter „Mein Profil“ die Angaben für Impressum und Datenschutz ein" && [ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT is_active FROM tp_rounds WHERE id=$R4")" = 0 ]'
$DC exec -T db mariadb -uapp -papp-dev-only talkslot -e "UPDATE tp_users SET controller_contact = UNHEX('$ADMINCC') WHERE role='admin'"
curl -s -o /dev/null -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle"
check "Angaben wieder da: Runde wieder aktivierbar und erreichbar" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 200 ]'
MAILP=$($DC exec -T app php -r 'echo file_get_contents("http://mail:8025/api/v1/search?query=to:eltern-zwei@example.org");')
check "Elternmail: Lehrkraft genannt, Antworten an Lehrkraft" 'echo "$MAILP" | grep -q "\"ReplyTo\":\[{\"Name\":\"\",\"Address\":\"zwei@example.org\"}\]"'
PTXT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:eltern-zwei@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];')
check "Elternmail: verantwortlich ist der Admin (Footer), nicht die Lehrkraft" 'echo "$PTXT" | grep -q "Verantwortlich: Sabrina Test" && ! echo "$PTXT" | grep -q "Verantwortlich: Herr Zwei"'

# Zusammenfassungen
OUT=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "Tagesübersicht für morgen verschickt" 'echo "$OUT" | grep -qE "summaries_evening +[1-9]"'
OUT2=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "Kein Doppelversand" 'echo "$OUT2" | grep -qE "summaries_evening +0"'
SUM=$($DC exec -T app php -r 'echo file_get_contents("http://mail:8025/api/v1/search?query=to:zwei@example.org%20subject:Morgen");')
check "Tagesübersicht an Lehrkraft 2 mit Kalenderdatei, ohne Eltern-E-Mail" 'echo "$SUM" | grep -q "\"Attachments\":1" && ! echo "$SUM" | grep -q "eltern-zwei@"'

# Deaktivieren
curl -s -o /dev/null -b "$J" -d "csrf=$T&username=zwei@example.org&display_name=Herr+Zwei&email=zwei@example.org&role=teacher" "$B/admin/users/$(curl -s -b "$J" "$B/admin/users" | grep -o '/admin/users/[0-9]*/edit' | tail -1 | grep -o '[0-9]\+')/edit"
check "Deaktiviertes Konto: Sitzung beendet" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$J2" -b "$J2" "$B/admin")" = 303 ]'
check "Deaktiviertes Konto: Runden öffentlich 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 404 ]'
E4=$(curl -s -b "$J" "$B/admin/round/$R4/edit")
check "Deaktivierte Lehrkraft bleibt im Formular vorausgewählt (markiert)" 'echo "$E4" | grep -q "<option value=\"$U2\" selected>Herr Zwei (deaktiviert)</option>" && curl -s -b "$J" "$B/admin" | grep -q "Herr Zwei <span class=\"badge\">deaktiviert</span>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$TA" --data-urlencode "user_id=$U2" -d "title=Titel+korrigiert&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$R4/edit"
check "Admin korrigiert Runde einer deaktivierten Lehrkraft: bleibt ihre, bleibt offline" '[ "$($DC exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SELECT CONCAT(user_id, \"/\", title) FROM tp_rounds WHERE id=$R4")" = "$U2/Titel korrigiert" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$S4")" = 404 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle"
TG=$(curl -s -L -b "$J" -d "csrf=$TA" "$B/admin/round/$R4/toggle")
check "Runde einer deaktivierten Lehrkraft lässt sich nicht aktivieren" 'echo "$TG" | grep -q "Konto dieser Lehrkraft ist deaktiviert"'
rm -f "$J2"

echo "Ein Admin (Migration 9) und alte Rechtstexte"
DBQ() { docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "$1"; }
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nVerantwortlich ist jeweils die Lehrkraft, bei der Sie buchen.') WHERE page_key='privacy' AND lang='de'"
check "Admin-Hinweis: Datenschutzerklärung im alten Modell" 'curl -s -b "$J" "$B/admin" | grep -q "(DE) beschreibt noch das alte Modell"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '\n\nVerantwortlich ist jeweils die Lehrkraft, bei der Sie buchen.', '') WHERE page_key='privacy' AND lang='de'"
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('alt-admin-inaktiv', 'x', 'admin', 'Alt', 'a@example.org', 0, NOW()), ('alt-admin-aktiv', 'x', 'admin', 'Neu', 'b@example.org', 1, NOW()); UPDATE tp_users SET controller_contact = 'Alte Lehrkraft-Adresse' WHERE username = 'zwei@example.org'; UPDATE tp_settings SET value = '8' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "Migration 9 (und folgende): nur das älteste aktive Admin-Konto bleibt Admin, alte Kontaktdaten der Lehrkräfte gelöscht" '[ "$(DBQ "SELECT GROUP_CONCAT(username) FROM tp_users WHERE role=\"admin\"")" = sabrina ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_users WHERE role<>\"admin\" AND controller_contact IS NOT NULL")" = 0 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = 12 ] && curl -s "$B/datenschutz" | grep -q "Teststraße 1"'
DBQ "ALTER TABLE tp_rounds ADD COLUMN access_pin VARCHAR(12) NULL; UPDATE tp_rounds SET code_required = 0; UPDATE tp_rounds SET access_pin = '123456' WHERE id = $RID; UPDATE tp_rounds SET access_pin = '' WHERE id = $R2; UPDATE tp_settings SET value = '9' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "Migration 10: nur Runden mit alter PIN werden „Nur mit Rundencode“ (leer/NULL bleiben offen)" '[ "$(DBQ "SELECT code_required FROM tp_rounds WHERE id=$RID")" = 1 ] && [ "$(DBQ "SELECT SUM(code_required) FROM tp_rounds")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = 12 ]'

DBQ "ALTER TABLE tp_rounds DROP COLUMN booking_limit; UPDATE tp_settings SET value = '10' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "Migration 11: bestehende Runden ohne Sperre (wie bisher), neue Spalte da" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE booking_limit <> \"none\"")" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds")" -gt 0 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = 12 ]'

echo "Web-Cron"
CTOK=$(printf 'cron' | openssl dgst -sha256 -hmac "$(printf 'd%.0s' {1..64})" | sed 's/.*= //')
check "Web-Cron ohne/mit falschem Schlüssel → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" -X POST "$B/cron")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "X-Cron-Token: falsch" "$B/cron")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/cron")" = 404 ]'
check "Web-Cron mit Schlüssel läuft" 'curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron" | grep -q "\"status\":\"ok\""'
check "Tabellen mit Präfix tp_" '[ "$(docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SHOW TABLES" | grep -vc "^tp_")" = 0 ]'

echo "Mehrfachbuchungen begrenzen"
T=$(curl -s -b "$J" "$B/admin" | csrf)
check "Formular: „Ein Termin pro Person“ für neue Runden vorausgewählt" 'curl -s -b "$J" "$B/admin/round/new" | grep -q "<option value=\"round\" selected>Ein Termin pro Person in dieser Runde</option>"'
LD1=$(date -d '+6 day' +%F); LD2=$(date -d '+7 day' +%F)
mkround() { curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=$1&booking_limit=$2&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$LD1&day_dates%5B%5D=$LD2&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$'; }
slugof() { curl -s -b "$J" "$B/admin/round/$1" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//'; }
# book <slug> <name> <email> [n-ter freier Termin] → Status und ggf. Änderungslink
book() { local P; P=$(curl -s "$B/$1"); curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf=$(echo "$P" | csrf)" --data-urlencode "name=$2" --data-urlencode "email=$3" -d "privacy=1&slot_id=$(echo "$P" | grep -o 'value="[0-9]*" required' | sed -n "${4:-1}p" | grep -o '[0-9]*')" "$B/$1"; }
LR=$(mkround Sperre+Runde round); LRS=$(slugof "$LR")
check "Runde: erste Buchung" '[[ "$(book "$LRS" "Max Muster" "eltern-a@example.org")" == 303* ]]'
R=$(book "$LRS" "  max   MUSTER " "")
check "Runde: gleicher Name ohne E-Mail (anders geschrieben) abgelehnt" '[[ "$R" == 422* ]] && P=$(curl -s "$B/$LRS"); curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=max+muster&privacy=1&slot_id=$(echo "$P" | grep -o "value=\"[0-9]*\" required" | head -1 | grep -o "[0-9]*")" "$B/$LRS" | grep -q "Für diesen Namen gibt es hier bereits einen Termin"'
check "Runde: gleicher Name, gleiche E-Mail (anderer Tag) abgelehnt" '[[ "$(book "$LRS" "Max Muster" "Eltern-A@example.org" 5)" == 422* ]]'
check "Runde: Geschwister (anderer Name, gleiche E-Mail) erlaubt" '[[ "$(book "$LRS" "Erika Muster" "eltern-a@example.org")" == 303* ]]'
check "Runde: gleicher Name, andere E-Mail (andere Familie) erlaubt" '[[ "$(book "$LRS" "Max Muster" "eltern-b@example.org")" == 303* ]]'
check "Runde: Eintrag der Lehrkraft nicht begrenzt" 'curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Max+Muster&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$LR" | grep -o "/admin/slot/[0-9]*/book" | head -1)" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id=b.slot_id JOIN tp_round_days d ON d.id=s.day_id WHERE d.round_id=$LR AND b.name=\"Max Muster\"")" = 3 ]'
LT=$(mkround Sperre+Tag day); LTS=$(slugof "$LT")
check "Tag: Hinweis „ein Termin pro Person und Tag“" 'curl -s "$B/$LTS" | grep -q "Pro Person ist nur ein Termin pro Tag möglich" && curl -s -b "$J" "$B/admin/round/$LT" | grep -q "Ein Termin pro Person und Tag ·"'
TOM1=$(book "$LTS" "Tom" "")
check "Tag: zweiter Termin am selben Tag abgelehnt" '[[ "$(book "$LTS" "Tom" "")" == 422* ]] && P=$(curl -s "$B/$LTS"); curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=Tom&privacy=1&slot_id=$(echo "$P" | grep -o "value=\"[0-9]*\" required" | head -1 | grep -o "[0-9]*")" "$B/$LTS" | grep -q "an diesem Tag bereits einen Termin"'
TOM2=$(book "$LTS" "Tom" "" 4)
check "Tag: am anderen Tag erlaubt" '[[ "$TOM2" == 303* ]]'
M2=$(curl -s "${TOM2#* }"); MC2=$(echo "$M2" | csrf)
DAY1SLOT=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$LT AND d.day_date=\"$LD1\" AND b.id IS NULL ORDER BY s.start_time LIMIT 1")
check "Tag: Umbuchen auf einen Tag mit eigenem Termin abgelehnt" 'curl -s --data-urlencode "csrf=$MC2" -d "action=move&slot_id=$DAY1SLOT" "${TOM2#* }" | grep -q "an diesem Tag bereits einen Termin"'
DAY2SLOT=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$LT AND d.day_date=\"$LD2\" AND b.id IS NULL ORDER BY s.start_time LIMIT 1")
check "Tag: Umbuchen am selben Tag erlaubt" 'curl -s -o /dev/null -w "%{redirect_url}" --data-urlencode "csrf=$MC2" -d "action=move&slot_id=$DAY2SLOT" "${TOM2#* }" | grep -q "done=changed"'
LN=$(mkround Ohne+Sperre none); LNS=$(slugof "$LN")
check "Ohne Sperre: alter Hinweis, gleicher Name mehrfach möglich" 'curl -s "$B/$LNS" | grep -q "Bitte buchen Sie nur einen Termin." && [[ "$(book "$LNS" "Ida" "")" == 303* ]] && [[ "$(book "$LNS" "Ida" "")" == 303* ]]'
# Gleichzeitig: 10 parallele Buchungen mit demselben Namen auf verschiedene Termine → genau eine
LP=$(mkround Sperre+parallel round); LPS=$(slugof "$LP"); PP=$(curl -s "$B/$LPS"); PPC=$(echo "$PP" | csrf)
for i in $(seq 1 8); do
  curl -s -o /dev/null -w '%{http_code}\n' --data-urlencode "csrf=$PPC" -d "name=Gleichzeitig&privacy=1&slot_id=$(echo "$PP" | grep -o 'value="[0-9]*" required' | sed -n "${i}p" | grep -o '[0-9]*')" "$B/$LPS" &
done > /tmp/tp_limit.$$; wait
check "Gleichzeitige Buchungen derselben Person: genau eine erfolgreich ($(grep -c '^303$' /tmp/tp_limit.$$))" '[ "$(grep -c "^303$" /tmp/tp_limit.$$)" = 1 ]'
rm -f /tmp/tp_limit.$$
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Sperre+Tag&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$LT/edit"
check "Formular ohne Feld (vor dem Update geöffnet) lässt die Einstellung" '[ "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$LT")" = day ]'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$LT/duplicate")
check "Kopie übernimmt die Einstellung" '[ "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$(echo "$loc" | grep -o "[0-9]*$")")" = day ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Sperre+Tag&booking_limit=kaputt&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$LT/edit"
check "Ungültiger Wert ändert nichts" '[ "$(DBQ "SELECT booking_limit FROM tp_rounds WHERE id=$LT")" = day ]'

echo "Weitere Admin-Abläufe"
T=$(curl -s -b "$J" "$B/admin" | csrf)
has() { grep -qF -- "$1"; }   # feste Zeichenkette suchen
check "Login-Seite bei bestehender Anmeldung → Übersicht" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -b "$J" "$B/admin/login")" == */admin ]]'
JL=$(mktemp); curl -s -o /dev/null -c "$JL" -b "$JL" "$B/admin/login"
R=$(curl -s -c "$JL" -b "$JL" -d "csrf=alt&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")
check "Login mit abgelaufenem Formular → Hinweis, keine Anmeldung" 'echo "$R" | has "Das Formular ist abgelaufen" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JL" "$B/admin")" = 303 ]'
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JL" -b "$JL" -d "csrf=$(curl -s -c "$JL" -b "$JL" "$B/admin/login" | csrf)&username=zwei@example.org&password=Zweites-Passwort-123" "$B/admin/login")
check "Deaktiviertes Konto: Anmeldung abgelehnt wie bei falschem Passwort" '[ "$code" = 401 ]'
rm -f "$JL"

NEWR() { curl -s -b "$J" --data-urlencode "csrf=$T" "$@" "$B/admin/round/new"; }
FD="booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+4 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30"
R=$(NEWR --data-urlencode "description=$(head -c 5001 /dev/zero | tr '\0' a)" -d "title=&booking_deadline_hours=999&cancel_deadline_hours=0&delete_after_hours=99999&day_dates%5B%5D=$(date -d '+4 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30")
check "Runde: Titel fehlt, Beschreibung zu lang, Fristen ungültig → Meldungen" 'echo "$R" | has "Bitte einen Titel" && echo "$R" | has "Beschreibungstext ist zu lang" && echo "$R" | has "ganze Zahl zwischen 0 und 720" && echo "$R" | has "Löschfrist"'
check "Runde für deaktivierte Lehrkraft abgelehnt" 'NEWR -d "title=X&user_id=$U2&$FD" | has "Bitte eine aktive Lehrkraft"'
MANY=$(for i in $(seq 1 31); do printf '&day_dates%%5B%%5D=%s' "$(date -d "+$((i + 40)) day" +%F)"; done)
check "Mehr als 30 Tage auf einmal abgelehnt" 'NEWR -d "title=Viele&booking_deadline_hours=0&cancel_deadline_hours=0&start_time=10:00&end_time=11:00&slot_minutes=30$MANY" | has "Höchstens 30 Tage"'
check "Ungültiges Datum abgelehnt" 'NEWR -d "title=X&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=2026-02-30&start_time=10:00&end_time=11:00&slot_minutes=30" | has "Ungültiges Datum: 2026-02-30"'
R=$(NEWR -d "title=X&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+4 day' +%F)&start_time=11:00&end_time=10:00&slot_minutes=300")
check "Zeiten: Ende vor Beginn und Dauer über 240 Minuten → Meldungen" 'echo "$R" | has "erster Termin vor dem Ende" && echo "$R" | has "Dauer pro Termin: 5 bis 240 Minuten"'
check "Zeitraum kürzer als ein Termin → kein Termin, abgelehnt" 'NEWR -d "title=X&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d "+4 day" +%F)&start_time=10:00&end_time=10:10&slot_minutes=30" | has "entsteht kein einziger Termin"'

# Eigene Runde für die folgenden Abläufe: 2 Tage à 2 Termine
DA=$(date -d '+4 day' +%F); DB2=$(date -d '+11 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Abdeckung&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$DA&day_dates%5B%5D=$DB2&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
RA=$(echo "$loc" | grep -o '[0-9]*$'); PA=$(curl -s -b "$J" "$B/admin/round/$RA")
DAY1=$(echo "$PA" | grep -o 'id="day-[0-9]*"' | sed -n 1p | grep -o '[0-9]*'); DAY2=$(echo "$PA" | grep -o 'id="day-[0-9]*"' | sed -n 2p | grep -o '[0-9]*')
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "slug=Eigener Link Ä" -d "title=Abdeckung&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RA/edit"
SA=$(curl -s -b "$J" "$B/admin/round/$RA" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "Eigener Link-Text wird zum Slug ($SA)" '[ "$SA" = eigener-link-ae ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$SA")" = 200 ]'
check "Tag bearbeiten: vorhandenes Datum abgelehnt" 'curl -s -b "$J" -d "csrf=$T&day_date=$DA&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/day/$DAY2/edit" | has "Diesen Tag gibt es in der Runde bereits"'
check "Tag bearbeiten: Zeiten ohne Termin abgelehnt" 'curl -s -b "$J" -d "csrf=$T&day_date=$DB2&start_time=10:00&end_time=10:10&slot_minutes=30" "$B/admin/day/$DAY2/edit" | has "entsteht kein einziger Termin"'
check "Pause mit Ende vor Beginn abgelehnt" 'curl -s -L -b "$J" -d "csrf=$T&start_time=10:30&end_time=10:00&label=" "$B/admin/day/$DAY1/break" | has "Pause: Bitte gültige Start- und Endzeit"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&start_time=10:30&end_time=11:00&label=Kurz" "$B/admin/day/$DAY2/break"
BRK=$(curl -s -b "$J" "$B/admin/round/$RA" | grep -o '/admin/break/[0-9]*/delete' | head -1)
check "Pause entfernen: Termin wieder da" '[ -n "$BRK" ] && curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B$BRK" && [ "$(curl -s -b "$J" "$B/admin/round/$RA" | grep -c "class=\"is-free")" = 4 ]'

# Einträge von Hand: Formular, Fehler, belegter Slot, Bearbeiten, Umbuchen mit Mail
SLOTS=($(curl -s -b "$J" "$B/admin/round/$RA" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
check "Formular „Eintrag anlegen“" 'curl -s -b "$J" "$B/admin/slot/${SLOTS[0]}/book" | has "Eintrag anlegen"'
check "Eintrag ohne Namen abgelehnt" 'curl -s -b "$J" -d "csrf=$T&name=&lang=de" "$B/admin/slot/${SLOTS[0]}/book" | has "Bitte geben Sie einen Namen an"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hand+Eins&email=hand@example.org&lang=de" "$B/admin/slot/${SLOTS[0]}/book"
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hand+Zwei&lang=de" "$B/admin/slot/${SLOTS[1]}/book"
check "Belegter Slot: zweiter Eintrag abgelehnt" 'curl -s -L -b "$J" -d "csrf=$T&name=Doppelt&lang=de" "$B/admin/slot/${SLOTS[0]}/book" | has "Dieser Slot ist bereits belegt" && ! curl -s -b "$J" "$B/admin/round/$RA" | has "Doppelt"'
BK1=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hand Eins'")
check "Formular „Eintrag bearbeiten“ mit Umbuchungsziel" 'curl -s -b "$J" "$B/admin/booking/$BK1/edit" | has "Eintrag bearbeiten"'
check "Bearbeiten ohne Namen abgelehnt" 'curl -s -b "$J" -d "csrf=$T&name=&lang=de" "$B/admin/booking/$BK1/edit" | has "Bitte geben Sie einen Namen an"'
check "Umbuchen auf belegten Slot: nichts geändert" 'curl -s -b "$J" -d "csrf=$T&name=Hand+Eins&lang=de&move_to=${SLOTS[1]}" "$B/admin/booking/$BK1/edit" | has "nicht mehr frei" && [ "$(DBQ "SELECT slot_id FROM tp_bookings WHERE id=$BK1")" = "${SLOTS[0]}" ]'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hand+Eins&email=hand@example.org&lang=de&send_mail=1&move_to=${SLOTS[2]}" "$B/admin/booking/$BK1/edit")
check "Umbuchen auf freien Slot mit Mail an die Eltern" 'echo "$R" | has "Änderung per E-Mail mitgeteilt" && [ "$(DBQ "SELECT slot_id FROM tp_bookings WHERE id=$BK1")" = "${SLOTS[2]}" ]'

# Rundencode nachträglich vergeben (Runden aus der Zeit vor den Codes)
DBQ "UPDATE tp_rounds SET code = NULL WHERE id = $RA"
check "Runde ohne Code bekommt beim Ansehen einen" '[[ "$(rcode "$RA")" =~ ^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$ ]] && [ -n "$(DBQ "SELECT code FROM tp_rounds WHERE id=$RA")" ]'
# Abgelaufene Runde: neuer Tag macht sie wieder aktivierbar
DBQ "UPDATE tp_rounds SET expired_at = NOW(), is_active = 0 WHERE id = $RA"
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=10:30&slot_minutes=30" "$B/admin/round/$RA/day"
check "Neuer Tag in abgelaufener Runde hebt den Ablauf auf" '[ "$(DBQ "SELECT expired_at IS NULL FROM tp_rounds WHERE id=$RA")" = 1 ]'
DAY3=$(curl -s -b "$J" "$B/admin/round/$RA" | grep -o 'id="day-[0-9]*"' | sed -n 3p | grep -o '[0-9]*')
check "Tag ohne Buchungen löschen" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/day/$DAY3/delete" | has "Tag gelöscht." && ! curl -s -b "$J" "$B/admin/round/$RA" | has "id=\"day-$DAY3\""'
check "Tag mit Buchungen nach Bestätigung löschen" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/day/$DAY2/delete" | has "inkl. 1 Buchung(en)"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RA/toggle"

# Profil: Hinweise und Fehler
ACC0=$(DBQ "SELECT CONCAT(HEX(display_name), ':', HEX(controller_contact), ':', HEX(email)) FROM tp_users WHERE role='admin'")
DBQ "UPDATE tp_users SET display_name = '', controller_contact = 'Nur Name', email = '' WHERE role='admin'"
R=$(curl -s -b "$J" "$B/admin")
check "Profil-Hinweise auf der Übersicht: Anzeigename, E-Mail im Impressum, E-Mail für Zusammenfassungen fehlen" 'echo "$R" | has "Anzeigename fehlt" && echo "$R" | has "fehlt eine E-Mail-Adresse (für das Impressum" && echo "$R" | has "Zusammenfassungen können nicht versendet werden"'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Ohne+Anzeigename&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+13 day' +%F)&start_time=10:00&end_time=10:30&slot_minutes=30" "$B/admin/round/new")
RN=$(echo "$loc" | grep -o '[0-9]*$')
check "Eigene Runde ohne Anzeigenamen nicht aktivierbar" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/round/$RN/toggle" | has "tragen Sie bitte unter „Mein Profil“ Ihren Anzeigenamen ein"'
DBQ "UPDATE tp_users SET display_name = UNHEX('${ACC0%%:*}'), controller_contact = UNHEX('$(echo "$ACC0" | cut -d: -f2)'), email = UNHEX('${ACC0##*:}') WHERE role='admin'"
R=$(curl -s -b "$J" --data-urlencode "csrf=$T" -d "display_name=&email=kaputt" "$B/admin/profile")
check "Profil: fehlender Anzeigename und ungültige E-Mail abgelehnt" 'echo "$R" | has "Bitte einen Anzeigenamen angeben" && echo "$R" | has "Die E-Mail-Adresse ist ungültig"'
check "Runde löschen" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/round/$RN/delete" | has "Runde mit allen Tagen und Buchungen gelöscht" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$RN")" ]'

# Passwort ändern (Profil) und erzwungener Wechsel
PW() { curl -s -L -c "$J" -b "$J" -d "csrf=$T" --data-urlencode "current_password=$1" --data-urlencode "new_password=$2" --data-urlencode "new_password2=$3" "$B/admin/${4:-password}"; }
check "Passwort: aktuelles falsch" 'PW falsch Neues-Passwort-123 Neues-Passwort-123 | has "Das aktuelle Passwort ist falsch"'
check "Passwort: Wiederholung abweichend" 'PW "$PASS" Neues-Passwort-123 Anderes-Passwort-1 | has "Die neuen Passwörter stimmen nicht überein"'
check "Passwort: zu kurz" 'PW "$PASS" kurz kurz | has "mindestens 12 Zeichen"'
check "Passwort: gleich dem bisherigen" 'PW "$PASS" "$PASS" "$PASS" | has "muss sich vom bisherigen unterscheiden"'
check "Passwort geändert" 'PW "$PASS" Neues-Passwort-123 Neues-Passwort-123 | has "Passwort geändert."'
T=$(curl -s -b "$J" "$B/admin" | csrf)
DBQ "UPDATE tp_users SET must_change_password = 1 WHERE role='admin'"
check "Startpasswort: jede Seite führt zum Passwortwechsel" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -b "$J" "$B/admin/round/$RA")" == */admin/password-change ]] && curl -s -b "$J" "$B/admin/password-change" | has "Neues Passwort festlegen"'
check "Startpasswort: Fehler wird angezeigt" 'PW Neues-Passwort-123 "$PASS" anders-anders-1 password-change | has "Die neuen Passwörter stimmen nicht überein"'
check "Startpasswort ersetzt, danach normal weiter" 'PW Neues-Passwort-123 "$PASS" "$PASS" password-change | has "Passwort festgelegt" && [ "$(DBQ "SELECT must_change_password FROM tp_users WHERE role=\"admin\"")" = 0 ]'
T=$(curl -s -b "$J" "$B/admin" | csrf)

# Benutzerverwaltung: Fehler, Bearbeiten, Link neu senden, Löschen
R=$(curl -s -b "$J" -d "csrf=$T&display_name=&email=kaputt" "$B/admin/users/new")
check "Neues Konto: Name fehlt, E-Mail ungültig" 'echo "$R" | has "Bitte einen Namen angeben" && echo "$R" | has "Bitte eine gültige E-Mail-Adresse"'
check "Neues Konto: E-Mail schon vergeben" 'curl -s -b "$J" -d "csrf=$T&display_name=Doppelt&email=zwei@example.org" "$B/admin/users/new" | has "gibt es bereits ein Konto"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Drei&email=drei@example.org" "$B/admin/users/new"
U3=$(DBQ "SELECT id FROM tp_users WHERE username='drei@example.org'"); UA=$(DBQ "SELECT id FROM tp_users WHERE role='admin'")
R=$(curl -s -b "$J" "$B/admin/users/$U3/edit")
check "Konto bearbeiten: Benutzername, Einladung erneut senden, Löschen" 'echo "$R" | has "name=\"username\"" && echo "$R" | has "Einladung erneut senden" && echo "$R" | has "Ja, endgültig löschen"'
check "Ungültiger Benutzername abgelehnt" 'curl -s -b "$J" -d "csrf=$T&username=a b&display_name=Frau+Drei&email=drei@example.org&is_active=1" "$B/admin/users/$U3/edit" | has "Benutzername: 3–254 Zeichen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "user_id=$U3&title=Abdeckung&is_active=0&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RA/edit"
check "Runde mit Buchungen an andere Lehrkraft übergeben → Hinweis" '[ "$(DBQ "SELECT user_id FROM tp_rounds WHERE id=$RA")" = "$U3" ] && curl -s -b "$J" "$B/admin/round/$RA" | has "Die Runde gehört jetzt Frau Drei. Die 1 bestehende(n) Buchung(en)"'
DBQ "UPDATE tp_users SET display_name = '' WHERE id = $U3"
check "Runde einer Lehrkraft ohne Anzeigenamen nicht aktivierbar" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/round/$RA/toggle" | has "Für diese Lehrkraft fehlt ein Anzeigename"'
DBQ "UPDATE tp_users SET display_name = 'Frau Drei' WHERE id = $U3"
check "Eigenes Passwort nicht über die Benutzerverwaltung zurücksetzen" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$UA/reset-password" | has "Ihr eigenes Passwort ändern Sie unter „Mein Profil“"'
DBQ "UPDATE tp_users SET email = NULL WHERE id = $U3"
check "Link neu senden ohne E-Mail-Adresse → Hinweis" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$U3/reset-password" | has "keine E-Mail-Adresse hinterlegt"'
DBQ "UPDATE tp_users SET email = 'drei@example.org' WHERE id = $U3"
check "Einladung erneut gesendet" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$U3/reset-password" | has "Einladung an drei@example.org gesendet"'
LINK3=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:drei@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];' | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
J4=$(mktemp); curl -s -o /dev/null -c "$J4" -b "$J4" "$LINK3"
check "Einladung mit abgelaufenem Formular → Hinweis, Link bleibt gültig" 'curl -s -c "$J4" -b "$J4" -d "csrf=alt&new_password=Drittes-Passwort-1&new_password2=Drittes-Passwort-1" "$LINK3" | has "Das Formular ist abgelaufen" && [ "$(curl -s -o /dev/null -w "%{http_code}" "$LINK3")" = 200 ]'
rm -f "$J4"
check "Eigenes Konto nicht löschbar" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$UA/delete" | has "eigenes Konto nicht löschen"'
check "Löschen ohne Bestätigung abgelehnt" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/users/$U3/delete" | has "Bitte das Löschen ausdrücklich bestätigen" && [ -n "$(DBQ "SELECT id FROM tp_users WHERE id=$U3")" ]'

# Rechtstexte bearbeiten
check "Text-Formular; unbekannte Sprache → 404" 'curl -s -b "$J" "$B/admin/page/imprint/de" | has "name=\"content\"" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/page/imprint/xx")" = 404 ]'
IMP=$(DBQ "SELECT HEX(content) FROM tp_pages WHERE page_key='imprint' AND lang='de'")
curl -s -o /dev/null -b "$J" -d "csrf=$T" --data-urlencode $'content=# Impressum\r\nOhne Platzhalter' "$B/admin/page/imprint/de"
check "Text gespeichert (Zeilenenden vereinheitlicht), Hinweis auf fehlenden Platzhalter" 'curl -s "$B/impressum" | has "Ohne Platzhalter" && [ "$(DBQ "SELECT LOCATE(CHAR(13), content) FROM tp_pages WHERE page_key=\"imprint\" AND lang=\"de\"")" = 0 ] && curl -s -b "$J" "$B/admin/texts" | has "enthalten den Platzhalter [[VERANTWORTLICHE]] nicht"'
DBQ "UPDATE tp_pages SET content = UNHEX('$IMP') WHERE page_key='imprint' AND lang='de'"

echo "Weitere Eltern-Abläufe"
check "Buchung an unbekannte Runde → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" -d "slot_id=1&name=X&privacy=1" "$B/gibt-es-nicht-xyz")" = 404 ]'
SP=$(curl -s -b "$J" "$B/admin/round/$R6" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')  # aktueller Link (Zufallsanteil wurde oben neu vergeben)
P=$(curl -s "$B/$SP")
R=$(curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=&privacy=1" "$B/$SP")
check "Buchung ohne Termin und Namen → beide Hinweise" 'echo "$R" | has "Bitte wählen Sie einen Termin" && echo "$R" | has "Bitte geben Sie einen Namen an"'
G=$(curl -s "$B/$S3")
check "Code-Abfrage mit abgelaufenem Formular → Hinweis" 'curl -s -d "csrf=alt&step=unlock&code=$RC3" "$B/$S3" | has "Das Formular ist abgelaufen"'
# Rate-Limits: Zähler für diese IP direkt hochsetzen (IPv4 und IPv6, je nachdem, wie localhost auflöst)
K4=$(php -r 'echo hash_hmac("sha256", "ip|127.0.0.0", str_repeat("d", 64));'); K6=$(php -r 'echo hash_hmac("sha256", "ip|::", str_repeat("d", 64));')
LIMIT() { DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('$1', '$K4', UNIX_TIMESTAMP(), 100000), ('$1', '$K6', UNIX_TIMESTAMP(), 100000) ON DUPLICATE KEY UPDATE hits = 100000, window_start = UNIX_TIMESTAMP()"; }
LIMIT attempt
check "Zu viele Buchungsversuche → 429" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$(echo "$P" | csrf)" -d "slot_id=1&name=Max&privacy=1" "$B/$SP")" = 429 ]'
LIMIT code
check "Gesperrt: auch formal falsche Codes auf der Rundenseite → 429" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$(echo "$G" | csrf)" -d "step=unlock&code=123" "$B/$S3")" = 429 ]'
LIMIT manage
check "Zu viele Aufrufe von Änderungslinks → 429" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/b/$(head -c 43 /dev/zero | tr "\0" A)")" = 429 ]'
LIMIT login
JX=$(mktemp); TX=$(curl -s -c "$JX" -b "$JX" "$B/admin/login" | csrf)
check "Zu viele Login-Versuche: auch das richtige Passwort wird abgelehnt (ohne Hinweis auf das Konto)" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JX" -b "$JX" -d "csrf=$TX&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")" = 401 ] && curl -s -c "$JX" -b "$JX" -d "csrf=$TX&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login" | grep -q "Anmeldung fehlgeschlagen. Bitte prüfen Sie Ihre Eingaben"'
rm -f "$JX"
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('attempt', 'code', 'manage', 'login')"
# Admin-Sitzung läuft nach 30 Minuten ohne Aktivität ab (eigene Sitzung, die Haupt-Sitzung bleibt)
JS=$(mktemp); TS=$(curl -s -c "$JS" -b "$JS" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$JS" -b "$JS" -d "csrf=$TS&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
SID=$(grep tp_admin "$JS" | awk '{print $7}')
ALERTS '$p = "/srv/app/app/var/sessions/sess_'"$SID"'"; file_put_contents($p, preg_replace("/last\|i:\d+;/", "last|i:" . (time() - 31 * 60) . ";", file_get_contents($p)));'
check "Admin-Sitzung nach 30 Minuten Inaktivität abgelaufen (Hinweis, neue Anmeldung nötig)" '[[ "$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JS" -b "$JS" "$B/admin")" == */admin/login ]] && curl -s -c "$JS" -b "$JS" "$B/admin/login" | grep -q "Die Sitzung ist abgelaufen"'
rm -f "$JS"
# Regeln, die der Server selbst durchsetzt (Formular manipuliert)
PB=$(curl -s "$B/$SP"); PBC=$(echo "$PB" | csrf)
FREEB=$(echo "$PB" | grep -o 'value="[0-9]*" required' | sed -n 2p | grep -o '[0-9]*')
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/slot/$FREEB/block"
check "Gesperrter Termin per manipuliertem Formular nicht buchbar" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$PBC" -d "slot_id=$FREEB&name=Gesperrt+Test&privacy=1" "$B/$SP"); [[ "$R" == *422 ]] && echo "$R" | grep -q "Dieser Termin ist nicht mehr verfügbar"'
OTHER=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$LT AND b.id IS NULL LIMIT 1")
check "Termin einer anderen Runde per manipuliertem Formular nicht buchbar" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$PBC" -d "slot_id=$OTHER&name=Fremd+Test&privacy=1" "$B/$SP"); [[ "$R" == *422 ]] && echo "$R" | grep -q "Dieser Termin ist nicht mehr verfügbar" && [ -z "$(DBQ "SELECT id FROM tp_bookings WHERE name=\"Fremd Test\"")" ]'
FREEC=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$R6 AND b.id IS NULL AND s.is_blocked=0 LIMIT 1")
DBQ "UPDATE tp_rounds SET booking_deadline_hours = 720 WHERE id = $R6"
check "Nach Buchungsschluss per manipuliertem Formular nicht buchbar" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$PBC" -d "slot_id=$FREEC&name=Frist+Test&privacy=1" "$B/$SP"); [[ "$R" == *422 ]] && echo "$R" | grep -q "die Buchungsfrist ist abgelaufen"'
DBQ "UPDATE tp_rounds SET booking_deadline_hours = 1 WHERE id = $R6"
# Geschützte Runde mit genau einem Termin: buchen, Änderungsseite, danach keine freien Termine
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Einzeltermin&code_required=1&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+5 day' +%F)&start_time=10:00&end_time=10:30&slot_minutes=30" "$B/admin/round/new")
RE=$(echo "$loc" | grep -o '[0-9]*$'); SE=$(curl -s -b "$J" "$B/admin/round/$RE" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//'); CE=$(rcode "$RE")
UE=$(curl -s -d "code=$CE" "$B/")
LOCE=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$UE" | csrf)" --data-urlencode "access=$(echo "$UE" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')" -d "slot_id=$(echo "$UE" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Einzel&privacy=1" "$B/$SE")
ME=$(curl -s "$LOCE"); MEC=$(echo "$ME" | csrf); TKE=$(echo "$LOCE" | grep -o '/b/[A-Za-z0-9_-]\{43\}')
check "Änderungsseite: keine anderen freien Termine" 'echo "$ME" | has "Derzeit gibt es keine anderen freien Termine"'
check "Änderung mit abgelaufenem Formular → Hinweis" 'curl -s -d "csrf=alt&action=cancel&confirm=1" "$B$TKE" | has "Das Formular ist abgelaufen"'
check "Absage ohne Bestätigung → Hinweis" 'curl -s --data-urlencode "csrf=$MEC" -d "action=cancel" "$B$TKE" | has "Bitte bestätigen Sie die Absage"'
check "Umbuchen ohne Termin → Hinweis" 'curl -s --data-urlencode "csrf=$MEC" -d "action=move&slot_id=0" "$B$TKE" | has "Bitte wählen Sie einen Termin"'
check "Änderung über ungültigen Link → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$MEC" -d "action=cancel&confirm=1" "$B/b/$(head -c 43 /dev/zero | tr "\0" A)")" = 404 ]'
UE2=$(curl -s -d "code=$CE" "$B/")
check "Geschützte Runde ohne freie Termine: Hinweis, Sprachwechsel behält den Zugang" 'echo "$UE2" | has "Zurzeit sind keine freien Termine verfügbar" && echo "$UE2" | has "<input type=\"hidden\" name=\"step\" value=\"switch\">" && curl -s -d "step=switch&lang=en" --data-urlencode "access=$(echo "$UE2" | grep -o "name=\"access\" value=\"[^\"]*\"" | head -1 | sed "s/.*value=\"//;s/\"$//")" "$B/$SE" | has "There are currently no free appointments"'
DBQ "UPDATE tp_rounds SET cancel_deadline_hours = 720 WHERE id = $RE"
check "Änderungsfrist vorbei: Hinweis, Absage abgelehnt" 'curl -s "$B$TKE" | has "nur bis 720 Stunden vor dem Termin möglich" && curl -s --data-urlencode "csrf=$MEC" -d "action=cancel&confirm=1" "$B$TKE" | has "nur bis 720 Stunden vor dem Termin möglich" && curl -s -o /dev/null -w "%{http_code}" "$B$TKE" | grep -q 200'

# Zusammenfassung zum Buchungsschluss
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Fristtest&is_active=1&booking_deadline_hours=720&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
RF=$(echo "$loc" | grep -o '[0-9]*$')
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Frist+Kind&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$RF" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
OUT=$($DC exec -T app php /srv/app/bin/cron.php -v)
FM=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=subject:%22Buchungsschluss:%20Fristtest%22"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "Zusammenfassung zum Buchungsschluss: Liste mit freien Terminen" 'echo "$OUT" | grep -qE "summaries_deadline +[1-9]" && echo "$FM" | has "Frist Kind" && echo "$FM" | has "(1 Termin blieb frei)"'
check "Zusammenfassung zum Buchungsschluss nur einmal" '$DC exec -T app php /srv/app/bin/cron.php -v | grep -qE "summaries_deadline +0"'
check "Konto mit allen Runden löschen" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$U3/delete" | has "mit allen Runden und Buchungen gelöscht" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$RA")" ]'

echo "Sicherheit (Audit)"
T=$(curl -s -b "$J" "$B/admin" | csrf)
# Werte exakt (per Prepared Statement) in der DB nachsehen: dbp "<SQL mit ?>" <wert>
dbp() { php -r '$p = new PDO("mysql:host=127.0.0.1;dbname=" . (getenv("TP_DB") ?: "talkslot") . ";charset=utf8mb4", "app", "app-dev-only"); $s = $p->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo $s->fetchColumn();' "$@" 2>/dev/null \
  || dcx exec -T app php -r '$p = new PDO("mysql:host=db;dbname=talkslot;charset=utf8mb4", "app", "app-dev-only"); $s = $p->prepare($argv[1]); $s->execute(array_slice($argv, 2)); echo $s->fetchColumn();' "$@"; }
SQLI="Robert'); DROP TABLE tp_bookings;-- \" OR 1=1 #"
SQ=$(mkround SQL+Test none); SQS=$(slugof "$SQ")
R=$(book "$SQS" "$SQLI" "")
check "SQL-Injection im Namen: wörtlich gespeichert, Tabelle unverändert" '[[ "$R" == 303* ]] && [ "$(dbp "SELECT COUNT(*) FROM tp_bookings WHERE name = ?" "$SQLI")" = 1 ] && [ "$(DBQ "SHOW TABLES LIKE \"tp_bookings\"")" = tp_bookings ]'
JQ=$(mktemp); TQ=$(curl -s -c "$JQ" -b "$JQ" "$B/admin/login" | csrf)
check "SQL-Injection im Login → abgelehnt wie falsches Passwort" '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$JQ" -b "$JQ" --data-urlencode "csrf=$TQ" --data-urlencode "username=x'"'"' OR '"'"'1'"'"'='"'"'1'"'"' -- " --data-urlencode "password='"'"' OR '"'"'1'"'"'='"'"'1" "$B/admin/login")" = 401 ]'
check "SQL-Injection als Rundencode → nicht gefunden (422)" 'R=$(curl -s -w "%{http_code}" --data-urlencode "code=x'"'"' OR 1=1 -- " "$B/"); [[ "$R" == *422 ]] && echo "$R" | grep -q "keine offene Terminvergabe"'
check "SQL-Injection in Adresse, Änderungslink und Admin-ID → 404" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/x'"'"'%20OR%20'"'"'1'"'"'='"'"'1")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/b/'"'"'%20OR%201=1--")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/round/1%20OR%201=1")" = 404 ]'
check "SQL-Injection in „Passwort vergessen“ → gleiche Antwort" 'curl -s -c "$JQ" -b "$JQ" --data-urlencode "csrf=$(curl -s -c "$JQ" -b "$JQ" "$B/admin/forgot" | csrf)" --data-urlencode "email=x'"'"' OR '"'"'1'"'"'='"'"'1" "$B/admin/forgot" | grep -q "Falls zu dieser Angabe"'
rm -f "$JQ"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$SQLI" -d "booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$SQ/edit"
check "SQL-Injection im Rundentitel: wörtlich gespeichert und escaped angezeigt" '[ "$(dbp "SELECT COUNT(*) FROM tp_rounds WHERE id = ? AND title = ?" "$SQ" "$SQLI")" = 1 ] && curl -s -b "$J" "$B/admin/round/$SQ" | grep -q "Robert&apos;); DROP TABLE tp_bookings;-- &quot; OR 1=1 #"'
check "Sprachparameter mit SQL → ignoriert (Deutsch)" 'curl -s "$B/?lang=de%27%20OR%201=1" | grep -q "<html lang=\"de\">"'

# Eingaben: Arrays, ungültiges UTF-8, Sonderformen, Links im Namen
TQ=$(curl -s "$B/admin/login" | csrf)
check "Login mit password[] → 401 ohne Warnung" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$TQ" -d "username=x&password[]=1" "$B/admin/login")" = 401 ]'
check "Ungültiges UTF-8 im Rundentitel → Hinweis statt Serverfehler" 'R=$(curl -s -w "%{http_code}" -b "$J" --data-urlencode "csrf=$T" -d "title=Kaputt%FF&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$SQ/edit"); [[ "$R" == *200 ]] && echo "$R" | grep -q "Bitte einen Titel"'
check "E-Mail mit Anführungszeichen/Leerzeichen abgelehnt" '[[ "$(book "$SQS" "Mit Mail" "\"a b\"@example.org")" == 422* ]]'
P=$(curl -s "$B/$SQS")
check "Link im Namen abgelehnt (Phishing über Bestätigungsmails)" 'R=$(curl -s -w "%{http_code}" --data-urlencode "csrf=$(echo "$P" | csrf)" --data-urlencode "name=Zahlung offen: https://evil.example/pay" -d "privacy=1&slot_id=$(echo "$P" | grep -o "value=\"[0-9]*\" required" | head -1 | grep -o "[0-9]*")" "$B/$SQS"); [[ "$R" == *422 ]] && echo "$R" | grep -q "keine Internetadressen"'
check "Sprachlinks auf Fehlerseiten zeigen nie auf fremde Domains" 'curl -s "$B/%5Cevil.example" | grep -q "href=\"/?lang=en\"" && ! curl -s "$B/%5Cevil.example" | grep -q "evil.example?lang" && ! curl -s "$B/%09/evil.example" | grep -q "evil.example?lang"'
check "Anmeldeseite ohne Cookie legt keine Sitzung an" '! curl -s -D - -o /dev/null "$B/admin/login" | grep -qi "^set-cookie: tp_admin" && ! curl -s -D - -o /dev/null "$B/admin/forgot" | grep -qi "^set-cookie"'
for f in probe.bak probe.swp; do echo x > "$ROOT/public/$f"; done
check "Backup-/Editor-Dateien und .user.ini nicht abrufbar (403)" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$B/probe.bak")" = 403 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/probe.swp")" = 403 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/.user.ini")" = 403 ]'
rm -f "$ROOT/public/probe.bak" "$ROOT/public/probe.swp"

# Änderungslink: Rate-Limit je Buchung, Merker nur signiert, Umbuchen nur bei aktiven Angaben
MV=$(book "$SQS" "Umbucher" ""); MVU="${MV#* }"; MVU="${MVU%%\?*}"
MP=$(curl -s "$MVU"); MPC=$(echo "$MP" | csrf)
check "Fremder Link mit ?remember=1 setzt keinen Merker" '! curl -s "$MVU?remember=1" | grep -q "data-remember-round" && ! curl -s "$MVU?remember=1700000000.$(printf "0%.0s" {1..32})" | grep -q "data-remember-round"'
check "Änderungslink mit done[]=1 → 200 ohne Warnung" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$MVU?done[]=1")" = 200 ]'
FS=($(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id LEFT JOIN tp_bookings b ON b.slot_id=s.id WHERE d.round_id=$SQ AND b.id IS NULL ORDER BY s.id LIMIT 2"))
for i in $(seq 1 10); do curl -s -o /dev/null --data-urlencode "csrf=$MPC" -d "action=move&slot_id=${FS[$((i % 2))]}" "$MVU"; done
check "Umbuchen: höchstens 10 je Buchung und Stunde (gegen Mailfluten)" '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$MPC" -d "action=move&slot_id=${FS[1]}" "$MVU")" = 429 ]'
ADMINCC2=$(DBQ "SELECT HEX(controller_contact) FROM tp_users WHERE role='admin'")
DBQ "UPDATE tp_users SET controller_contact = NULL WHERE role='admin'"
check "Ohne Angaben des Admins: Änderungsseite ohne Umbuchen, Absagen weiter möglich" 'M=$(curl -s "$MVU"); ! echo "$M" | grep -q "value=\"move\"" && echo "$M" | grep -q "value=\"cancel\""'
DBQ "UPDATE tp_users SET controller_contact = UNHEX('$ADMINCC2') WHERE role='admin'"
# geschützte Runde aus „Weitere Eltern-Abläufe“ (die frühere wurde von der Migration 10 geöffnet)
G3=$(curl -s "$B/$SE"); U3=$(curl -s --data-urlencode "csrf=$(echo "$G3" | csrf)" --data-urlencode "code=$CE" -d "step=unlock" "$B/$SE")
ACC3=$(echo "$U3" | grep -o 'name="access" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
check "Zugangstoken wird beim Sprachwechsel nicht verlängert" '[ -n "$ACC3" ] && sleep 1 && curl -s -d "step=switch&lang=en" --data-urlencode "access=$ACC3" "$B/$SE" | grep -q "name=\"access\" value=\"$ACC3\""'
BH0=$(DBQ "SELECT COALESCE(SUM(hits),0) FROM tp_rate_limits WHERE bucket='booking'")
book "$LTS" "Tom" "" >/dev/null
check "Treffer der Sperre zählen gegen das Buchungslimit (bremst Ausprobieren)" '[ "$(DBQ "SELECT COALESCE(SUM(hits),0) FROM tp_rate_limits WHERE bucket=\"booking\"")" -gt "$BH0" ]'

# Konten: Login-Zähler, E-Mail-Änderung, Sitzungen, Reset-Links
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('login', 'login_user', 'forgot')"
JL2=$(mktemp); TL2=$(curl -s -c "$JL2" -b "$JL2" "$B/admin/login" | csrf)
for i in 1 2; do curl -s -o /dev/null -c "$JL2" -b "$JL2" --data-urlencode "csrf=$TL2" -d "username=$USER&password=falsch-falsch" "$B/admin/login"; done
curl -s -o /dev/null -c "$JL2" -b "$JL2" --data-urlencode "csrf=$TL2" -d "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
check "Erfolgreicher Login setzt den Fehlversuchszähler der IP nicht zurück" '[ "$(DBQ "SELECT COALESCE(MAX(hits),0) FROM tp_rate_limits WHERE bucket=\"login\"")" = 2 ]'
TF=$(curl -s "$B/admin/forgot" | csrf)
curl -s -o /dev/null --data-urlencode "csrf=$TF" -d "email=lehrer@example.org" "$B/admin/forgot"; H1=$(DBQ "SELECT access_token_hash FROM tp_users WHERE role='admin'")
curl -s -o /dev/null --data-urlencode "csrf=$TF" -d "email=lehrer@example.org" "$B/admin/forgot"; H2=$(DBQ "SELECT access_token_hash FROM tp_users WHERE role='admin'")
check "„Passwort vergessen“: frischer Link wird nicht durch Fremde ersetzt" '[ -n "$H1" ] && [ "$H1" = "$H2" ]'
curl -s -o /dev/null -c "$JL2" -b "$JL2" --data-urlencode "csrf=$TL2" -d "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
check "Anmeldung entwertet offene Reset-Links" '[ "$(DBQ "SELECT access_token_hash IS NULL FROM tp_users WHERE role=\"admin\"")" = 1 ]'
CC=$(dbp "SELECT controller_contact FROM tp_users WHERE role = ?" admin)
PROF() { curl -s -L -c "$J" -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Frau Sabrina Test" --data-urlencode "controller_contact=$CC" -d "summary_evening=1&summary_deadline=1" "$@" "$B/admin/profile"; }
check "E-Mail-Adresse eines anderen Kontos nicht übernehmbar" 'PROF -d "email=zwei@example.org" | grep -q "gehört bereits zu einem anderen Konto"'
check "E-Mail ändern ohne aktuelles Passwort abgelehnt" 'PROF -d "email=neu-admin@example.org" | grep -q "aktuelles Passwort eingeben" && [ "$(DBQ "SELECT email FROM tp_users WHERE role=\"admin\"")" = lehrer@example.org ]'
check "E-Mail ändern mit Passwort, Hinweis an die alte Adresse" 'PROF -d "email=neu-admin@example.org" --data-urlencode "current_password=$PASS" | grep -q "Profil gespeichert" && $DC exec -T app php -r "echo file_get_contents(\"http://mail:8025/api/v1/search?query=to:lehrer@example.org%20subject:%22E-Mail-Adresse%20wurde%20ge%C3%A4ndert%22\");" | grep -q "neu-admin@example.org"'
PROF -d "email=lehrer@example.org" --data-urlencode "current_password=$PASS" >/dev/null
check "Passwort über 72 Byte abgelehnt" 'PW "$PASS" "$(printf "x%.0s" {1..73})" "$(printf "x%.0s" {1..73})" | grep -q "höchstens 72 Byte"'
check "Passwortwechsel beendet andere Sitzungen, die eigene bleibt" 'PW "$PASS" Neues-Passwort-123 Neues-Passwort-123 | grep -q "Passwort geändert." && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JL2" "$B/admin")" = 303 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin")" = 200 ]'
PW Neues-Passwort-123 "$PASS" "$PASS" >/dev/null
T=$(curl -s -b "$J" "$B/admin" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Herr+Vier&email=vier@example.org" "$B/admin/users/new"
U4=$(DBQ "SELECT id FROM tp_users WHERE username='vier@example.org'")
LINK4=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:vier@example.org"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".$r["messages"][0]["ID"]),true)["Text"];' | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
JV=$(mktemp); TV=$(curl -s -c "$JV" -b "$JV" "$LINK4" | csrf)
LV=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JV" -b "$JV" --data-urlencode "csrf=$TV" -d "new_password=Viertes-Passwort-1&new_password2=Viertes-Passwort-1" "$LINK4")
check "Nach dem Passwortsetzen: Benutzername nicht in der Adresse (Zugriffslogs)" '[[ "$LV" == */admin/login ]] && curl -s -c "$JV" -b "$JV" "$B/admin/login" | grep -q "value=\"vier@example.org\""'
curl -s -o /dev/null -c "$JV" -b "$JV" --data-urlencode "csrf=$(curl -s -c "$JV" -b "$JV" "$B/admin/login" | csrf)" -d "username=vier@example.org&password=Viertes-Passwort-1" "$B/admin/login"
check "Passwort-Reset durch den Admin beendet laufende Sitzungen" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JV" "$B/admin")" = 200 ] && curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/users/$U4/reset-password" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JV" "$B/admin")" = 303 ]'
rm -f "$JV" "$JL2"
KT=$(php -r 'echo hash_hmac("sha256", "subject|user:" . $argv[1], str_repeat("d", 64));' "$(DBQ "SELECT id FROM tp_users WHERE role='admin'")")
DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('teacher_mail', '$KT', UNIX_TIMESTAMP(), 100000) ON DUPLICATE KEY UPDATE hits = 100000, window_start = UNIX_TIMESTAMP()"
check "Mails aus Einträgen von Hand je Konto begrenzt" 'curl -s -L -b "$J" -d "csrf=$T&name=Mail+Limit&email=limit@example.org&lang=de&send_mail=1" "$B$(curl -s -b "$J" "$B/admin/round/$SQ" | grep -o "/admin/slot/[0-9]*/book" | head -1)" | grep -q "Keine E-Mail gesendet: Höchstzahl"'
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('teacher_mail', 'login', 'login_user', 'forgot', 'booking', 'move')"

# Abgelaufene Runden: Tag in die Zukunft verlegt → nicht mehr abgelaufen; Runden mit künftigen Tagen nie löschen
DSQ=$(curl -s -b "$J" "$B/admin/round/$SQ" | grep -o 'id="day-[0-9]*"' | head -1 | grep -o '[0-9]*')
DBQ "UPDATE tp_rounds SET expired_at = NOW(), is_active = 0 WHERE id = $SQ"
curl -s -o /dev/null -b "$J" -d "csrf=$T&day_date=$(date -d '+20 day' +%F)&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/day/$DSQ/edit"
check "Tag in die Zukunft verlegt: Ablauf aufgehoben" '[ "$(DBQ "SELECT expired_at IS NULL FROM tp_rounds WHERE id=$SQ")" = 1 ]'
DBQ "UPDATE tp_rounds SET expired_at = NOW() - INTERVAL 10 DAY, delete_after_hours = 0 WHERE id = $SQ"
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at, last_login_at) VALUES ('alt-inaktiv@example.org', 'x', 'teacher', 'Alt', 'alt-inaktiv@example.org', 0, NOW() - INTERVAL 3 YEAR, NOW() - INTERVAL 2 YEAR)"
DBQ "UPDATE tp_users SET last_login_at = NOW() - INTERVAL 2 YEAR WHERE username = 'zwei@example.org'"
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "Bereinigung: Runde mit künftigem Tag bleibt trotz abgelaufener Frist" '[ -n "$(DBQ "SELECT id FROM tp_rounds WHERE id=$SQ")" ]'
check "Bereinigung: lange inaktive Konten ohne Runden gelöscht, mit Runden behalten" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE username=\"alt-inaktiv@example.org\"")" ] && [ -n "$(DBQ "SELECT id FROM tp_users WHERE username=\"zwei@example.org\"")" ]'

echo "Bereinigung"
docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T db mariadb -uapp -papp-dev-only talkslot -e \
  "UPDATE tp_round_days SET day_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE round_id = $RID"
OUT=$(docker compose -f "$(dirname "$0")/../dev/compose.yml" exec -T app php /srv/app/bin/cron.php -v)
echo "$OUT" | sed 's/^/    /'
check "Buchungen vom Vortag gelöscht" 'echo "$OUT" | grep -qE "bookings_deleted +[1-9]"'
check "Runde abgelaufen + deaktiviert" 'echo "$OUT" | grep -qE "rounds_expired +[1-9]"'
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/$SLUG")
check "Abgelaufene Runde öffentlich 404" '[ "$code" = 404 ]'

T=$(curl -s -b "$J" "$B/admin" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/logout"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$B/admin/round/$RID")
check "Logout" '[ "$code" = 303 ]'
AL=$(ALERTS 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:betrieb@example.org"), true)["messages_count"] ?? "?";')
check "Keine Fehlermeldung (Exception, Warnung) während des ganzen Testlaufs" '[ "$AL" = 0 ]'
rm -f "$J"
[ $FAIL = 0 ] && echo "ALLE TESTS BESTANDEN" || { echo "FEHLER"; exit 1; }
