# Abuse protection of the public pages (expert review P1-7, see README.md in this directory): bookings per round
# and network, a warning about a burst, mails per address, rebookings per network, deleting several bookings of a
# day at once, the alarm about the mail volume. dev/config.dev.php sets the new limits high; the checks fill the
# counters themselves (the key App\RateLimiter stores, dev secret).
echo "Abuse protection of the public pages"
AT=$(curl -s -b "$J" "$B/admin" | csrf)
AB=ab$$ # addresses of this run
AB_DAY=$(date -d '+6 day' +%F)
# ab_round <title> [user id] → id of a new active round on AB_DAY, 09:00–11:00 in 15 minutes (8 slots)
ab_round() { curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$AT" --data-urlencode "title=$1" -d "user_id=${2:-}&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$AB_DAY&start_time=09:00&end_time=11:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$'; }
ab_hm() { $DC exec -T app php -r 'echo hash_hmac("sha256", "subject|" . $argv[1], str_repeat("d", 64));' "$1"; }
ab_fill() { DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('$1', '$2', UNIX_TIMESTAMP(), $3) ON DUPLICATE KEY UPDATE hits = $3, window_start = UNIX_TIMESTAMP()"; }
ab_hits() { DBQ "SELECT COALESCE(SUM(hits), 0) FROM tp_rate_limits WHERE bucket = '$1' AND key_hash = '$2'"; }
# Mailpit: number of mails for a search, the text of the newest one
ab_mails() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true)["messages_count"] ?? "?";' "$1"; }
ab_text() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo isset($r["messages"][0]) ? json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $r["messages"][0]["ID"]), true)["Text"] : "";' "$1"; }
ab_free() { echo "$1" | grep -o 'value="[0-9]*" required' | sed -n "${2:-1}p" | grep -o '[0-9]*'; }

# Bookings per round and shortened IP (slot squatting): counted per round, other rounds not affected
ABR=$(ab_round "Missbrauch Runde"); ABS=$(slugof "$ABR")
ABR2=$(ab_round "Missbrauch Andere"); ABS2=$(slugof "$ABR2")
R1=$(book "$ABS" "Erste Buchung" "")
CK=$(DBQ "SELECT key_hash FROM tp_rate_limits WHERE bucket = 'attempt' ORDER BY window_start DESC LIMIT 1") # App\RateLimiter::clientKey()
check "bookings counted per round and network (HMAC of the round and the shortened IP)" '[[ "$R1" == 303* ]] && [ "$(ab_hits round "$(ab_hm "round:$ABR|$CK")")" = 1 ]'
ab_fill round "$(ab_hm "round:$ABR|$CK")" 1000
P=$(curl -s "$B/$ABS")
R2=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=Zweite+Buchung&privacy=1&slot_id=$(ab_free "$P")" "$B/$ABS")
check "round used up from this network: 429 with a way out (another connection, the teacher), nothing booked, other rounds still open" '[ "$(echo "$R2" | tail -1)" = 429 ] && echo "$R2" | grep -q "mobile Daten statt WLAN" && echo "$R2" | grep -q "auch dann nicht, wenden Sie sich bitte direkt an die Lehrkraft" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name = \"Zweite Buchung\"")" = 0 ] && [[ "$(book "$ABS2" "Andere Runde" "")" == 303* ]]'
# Counted before booking, given back when nothing was booked (parallel requests cannot all pass the check)
ab_fill round "$(ab_hm "round:$ABR|$CK")" 999
B999=$(DBQ "SELECT COALESCE(SUM(hits), 0) FROM tp_rate_limits WHERE bucket = 'booking'")
P=$(curl -s "$B/$ABS"); TAKEN=$(DBQ "SELECT slot_id FROM tp_bookings WHERE name = 'Erste Buchung'")
X=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$(echo "$P" | csrf)" -d "name=Zu+Spaet&privacy=1&slot_id=$TAKEN" "$B/$ABS")
check "a booking that fails (slot taken) gives its count back (round and network)" '[ "$X" = 422 ] && [ "$(ab_hits round "$(ab_hm "round:$ABR|$CK")")" = 999 ] && [ "$(DBQ "SELECT COALESCE(SUM(hits), 0) FROM tp_rate_limits WHERE bucket = \"booking\"")" = "$B999" ]'
R2=$(book "$ABS" "Letzte Buchung" "")
check "… the last one below the limit is booked and counted" '[[ "$R2" == 303* ]] && [ "$(ab_hits round "$(ab_hm "round:$ABR|$CK")")" = 1000 ]'

# A burst from one network: one warning to the teacher of the round and to the admins, queued (not sent in
# the request of whoever booked), none below the threshold
ABH=$($DC exec -T app php -r 'echo password_hash("Missbrauch-Lehrkraft-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('missbrauch-$AB@example.org', '$ABH', 'teacher', 'Herr Missbrauch', 'missbrauch-$AB@example.org', 1, NOW())"
ABU=$(DBQ "SELECT id FROM tp_users WHERE username = 'missbrauch-$AB@example.org'")
ABW=$(ab_round "Missbrauch Warnung" "$ABU"); ABWS=$(slugof "$ABW")
WSUB='subject:"Auffällig viele Buchungen"'
ABQ() { DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE kind = 'burst_warning' AND ref = 'round:$ABW'"; }
R3=$(book "$ABWS" "Echter Name" "")
check "a booking below the burst threshold: no warning" '[[ "$R3" == 303* ]] && [ "$(ABQ)" = 0 ]'
ab_fill burst "$(ab_hm "round:$ABW|$CK")" 1000
N0=$(ab_mails "to:lehrer@example.org $WSUB")
R3=$(book "$ABWS" "Fantasie Eins" ""); R4=$(book "$ABWS" "Fantasie Zwei" "")
WQ=$(ABQ); WM=$(ab_mails "to:missbrauch-$AB@example.org $WSUB")
$DC exec -T app php /srv/app/bin/cron.php > /dev/null
WT=$(ab_text "to:missbrauch-$AB@example.org $WSUB")
WEXP=$(( $(DBQ "SELECT COUNT(*) FROM tp_users WHERE role = 'admin' AND is_active = 1") + 1 ))
check "burst of bookings from one network: the bookings go through, one warning each to the teacher and the admins (not one per booking), sent after the response" '[[ "$R3" == 303* ]] && [[ "$R4" == 303* ]] && [ "$WQ" = "$WEXP" ] && [ "$WM" = 0 ] && [ "$(ab_mails "to:missbrauch-$AB@example.org $WSUB")" = 1 ] && [ "$(ab_mails "to:lehrer@example.org $WSUB")" = "$((N0 + 1))" ]'
check "… which names the round, the way to clean up and to protect it and the limit, without names of parents" 'echo "$WT" | grep -qF "Hallo Herr Missbrauch," && echo "$WT" | grep -qF "in der Runde „Missbrauch Warnung“ (Herr Missbrauch)" && echo "$WT" | grep -qF "mehr als 1000 Buchungen" && echo "$WT" | grep -qF "Sehen die Namen echt aus, ist nichts zu tun." && echo "$WT" | grep -qF "/admin/round/$ABW" && echo "$WT" | grep -qF "„Mehrere Buchungen löschen …“" && echo "$WT" | grep -qF "die Option „Nur mit Rundencode“ wählen" && echo "$WT" | grep -qF "höchstens 1000 Buchungen pro Stunde" && ! echo "$WT" | grep -q "Fantasie Eins"'
DBQ "UPDATE tp_rounds SET code_required = 1 WHERE id = $ABW"
WD=$($DC exec -T app php -r 'require "/srv/app/app/bootstrap.php"; var_export([App\Abuse::deliverWarning((int) $argv[1], "round:" . $argv[2]), App\Abuse::deliverWarning((int) $argv[1], "round:0")]);' "$ABU" "$ABW")
check "… for a round with a round code: a new code instead (the old one got out); round gone: dropped" '[ "$WD" = "array (
  0 => true,
  1 => NULL,
)" ] && ab_text "to:missbrauch-$AB@example.org $WSUB" | grep -qF "Legen Sie unter „Bearbeiten“ einen neuen Code an"'

# Mails parents trigger, per address: booked without a mail above the limit (and the booking keeps no address);
# lower case, without +tag
ABM=$(ab_round "Missbrauch Mails"); ABMS=$(slugof "$ABM")
ab_fill recipient "$(ab_hm "mail:viel-$AB@example.org")" 1000
R5=$(book "$ABMS" "Viel Post" "Viel-$AB+eins@Example.org")
R6=$(book "$ABMS" "Wenig Post" "wenig-$AB@example.org")
check "address with too many mails: booked, no confirmation, the page says so (no mails later either), the booking keeps no address" '[[ "$R5" == 303*mail=limited* ]] && curl -s "${R5#* }" | grep -q "data-mail-limited>Es wurde keine Bestätigung per E-Mail gesendet, und zu diesem Termin kommen auch später keine E-Mails" && [ "$(ab_mails "to:viel-$AB")" = 0 ] && [ "$(DBQ "SELECT COALESCE(email, \"none\") FROM tp_bookings WHERE name = \"Viel Post\"")" = none ]'
check "another address: confirmation sent and counted per hour and per day" '[[ "$R6" == 303*mail=1* ]] && [ "$(ab_mails "to:wenig-$AB@example.org")" = 1 ] && [ "$(ab_hits recipient "$(ab_hm "mail:wenig-$AB@example.org")")" = 1 ] && [ "$(ab_hits recipient_day "$(ab_hm "mail:wenig-$AB@example.org")")" = 1 ]'
ab_fill recipient_day "$(ab_hm "mail:tag-$AB@example.org")" 1000
check "… the limit per day the same" '[[ "$(book "$ABMS" "Tages Post" "tag-$AB@example.org")" == 303*mail=limited* ]] && [ "$(ab_mails "to:tag-$AB")" = 0 ]'
TOK5=$(echo "$R5" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
ID5=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Viel Post'")
X=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(curl -s "$B/b/$TOK5" | csrf)" -d "action=mail&email=viel-$AB@example.org" "$B/b/$TOK5")
check "confirmation sent again from the appointment page to such an address: refused (429), counted for the booking (probing costs its budget)" '[ "$(echo "$X" | tail -1)" = 429 ] && echo "$X" | grep -q "Es wurde keine E-Mail gesendet: An diese Adresse" && [ "$(ab_hits confirm "$(ab_hm "booking:$ID5")")" = 1 ] && [ "$(ab_mails "to:viel-$AB")" = 0 ]'

# Rebookings per network (each sends a mail) besides the limit per booking, with their own messages
TOK6=$(echo "$R6" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
ID6=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Wenig Post'")
ab_move() { local P; P=$(curl -s "$B/b/$TOK6"); curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(echo "$P" | csrf)" -d "action=move&slot_id=$(ab_free "$P" 2)" "$B/b/$TOK6"; }
ab_fill move_ip "$CK" 1000
X=$(ab_move)
check "rebookings used up from this network: 429 with the way out, booking not moved" '[ "$(echo "$X" | tail -1)" = 429 ] && echo "$X" | grep -q "mobile Daten statt WLAN" && [ "$(DBQ "SELECT s.start_time FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id WHERE b.id = $ID6")" = "09:15:00" ]'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'move_ip'"
ab_fill move "$(ab_hm "booking:$ID6")" 1000
X=$(ab_move)
check "… the limit per booking says it is this appointment (no hint about the network), the network limit not counted" '[ "$(echo "$X" | tail -1)" = 429 ] && echo "$X" | grep -q "schon sehr oft umgebucht" && ! echo "$X" | grep -q "mobile Daten" && [ "$(ab_hits move_ip "$CK")" = 0 ]'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'move' AND key_hash = '$(ab_hm "booking:$ID6")'"
ab_fill recipient "$(ab_hm "mail:wenig-$AB@example.org")" 1000
X=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf=$(curl -s "$B/b/$TOK6" | csrf)" -d "action=move&slot_id=$(ab_free "$(curl -s "$B/b/$TOK6")" 2)" "$B/b/$TOK6")
check "… below both limits moved and counted per network; the address got too many: no change notice, the address stays" '[[ "$X" == 303*mail=limited* ]] && curl -s "${X#* }" | grep -q "data-mail-limited>Es wurde keine E-Mail gesendet" && [ "$(ab_hits move_ip "$CK")" = 1 ] && [ "$(ab_mails "to:wenig-$AB@example.org")" = 1 ] && [ "$(DBQ "SELECT email FROM tp_bookings WHERE id = $ID6")" = "wenig-$AB@example.org" ]'
X=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(curl -s "$B/b/$TOK6" | csrf)" -d "action=cancel&confirm=1" "$B/b/$TOK6")
check "cancelled by the parents with such an address: cancelled, without a mail" '[ "$(echo "$X" | tail -1)" = 200 ] && [ -z "$(DBQ "SELECT id FROM tp_bookings WHERE id = $ID6")" ] && [ "$(ab_mails "to:wenig-$AB@example.org")" = 1 ]'

# Deleting several bookings of a day at once: list of a time span with boxes, then the ticked ones
ABD=$(ab_round "Missbrauch Aufraeumen"); ABDS=$(slugof "$ABD")
for i in 1 2 3 4; do book "$ABDS" "Squatter $i" "" > /dev/null; done
book "$ABDS" "Echte Mutter" "echt-$AB@example.org" > /dev/null
book "$ABDS" "Spaete Buchung" "" > /dev/null
DAYD=$(DBQ "SELECT id FROM tp_round_days WHERE round_id = $ABD")
curl -s -o /dev/null -b "$J" -d "csrf=$AT&name=Eigener+Eintrag&lang=de" "$B/admin/slot/$(DBQ "SELECT id FROM tp_slots WHERE day_id = $DAYD AND start_time = '10:30:00'")/book"
ABN() { DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id WHERE s.day_id = $DAYD"; }
ABBOX() { grep -o 'name="booking\[\]" value="[0-9]*" id="booking-[0-9]*" aria-labelledby="time-[0-9]* name-[0-9]*"[^>]*>' | grep -c "$1"; }
RP=$(curl -s -b "$J" "$B/admin/round/$ABD")
check "round page: under a day with bookings a form for a time span (the day's times preset)" 'echo "$RP" | grep -qF "action=\"/admin/day/$DAYD/bookings\"" && echo "$RP" | grep -qF "Mehrere Buchungen löschen …" && echo "$RP" | grep -q "name=\"from\" required value=\"09:00\""'
LP=$(curl -s -b "$J" "$B/admin/day/$DAYD/bookings?from=9&to=10:15")
check "list of the time span: the appointments within it, each ticked and labelled with time and name, the box for the parents unticked; nothing deleted yet" '[ "$(echo "$LP" | ABBOX "checked")" = 5 ] && [ "$(echo "$LP" | ABBOX "")" = 5 ] && echo "$LP" | grep -q "id=\"name-[0-9]*\">Squatter 4<" && echo "$LP" | grep -q "Echte Mutter" && ! echo "$LP" | grep -q "Spaete Buchung" && echo "$LP" | grep -q "<input type=\"checkbox\" id=\"notify\" name=\"notify\" value=\"1\">" && [ "$(ABN)" = 7 ]'
LW=$(curl -s -b "$J" "$B/admin/day/$DAYD/bookings")
check "list without a span: the whole day; the teacher's own entry unticked" '[ "$(echo "$LW" | ABBOX "")" = 7 ] && [ "$(echo "$LW" | ABBOX "checked")" = 6 ] && echo "$LW" | grep -q "Eigener Eintrag"'
LR=$(curl -s -w '\n%{http_code}' -b "$J" "$B/admin/day/$DAYD/bookings?from=10:00&to=09:00")
check "list: invalid or reversed times → a message and the form, no list; no bookings in the span → said so" '[ "$(echo "$LR" | tail -1)" = 200 ] && echo "$LR" | grep -q "Bitte gültige Start- und Endzeit angeben" && ! echo "$LR" | grep -q "name=\"booking\[\]\"" && echo "$LR" | grep -q "name=\"from\"" && curl -s -b "$J" "$B/admin/day/$DAYD/bookings?from=10:45&to=11:00" | grep -q "keine Buchungen"'
SQ=$(DBQ "SELECT GROUP_CONCAT(id) FROM tp_bookings WHERE name LIKE 'Squatter %'")
OTHER=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Andere Runde'")
DEL=(); for id in ${SQ//,/ } $OTHER; do DEL+=(-d "booking%5B%5D=$id"); done
NC=$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE kind = 'cancelled'")
X=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J" -d "csrf=$AT" "${DEL[@]}" "$B/admin/day/$DAYD/bookings/delete")
RP=$(curl -s -b "$J" "$B/admin/round/$ABD")
check "the ticked ones deleted (an id of another day ignored), back to the day, without cancellations and without a list of people to inform" '[[ "$X" == "303 "*"/admin/round/$ABD"* ]] && [ "$(ABN)" = 3 ] && [ -n "$(DBQ "SELECT id FROM tp_bookings WHERE id = $OTHER")" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE kind = \"cancelled\"")" = "$NC" ] && echo "$RP" | grep -q "4 Buchung(en) gelöscht – die Slots sind wieder frei." && echo "$RP" | grep -q "Es wurden keine Absagen per E-Mail versendet." && ! echo "$RP" | grep -q "Betroffen:"'
check "… logged without names: round, day, number" '[ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"Runde „Missbrauch Aufraeumen“ (%): Tag $(date -d "$AB_DAY" +%d.%m.%Y): 4 Buchung(en) auf einmal gelöscht\"")" = 1 ]'
ECHT=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Echte Mutter'")
curl -s -o /dev/null -b "$J" -d "csrf=$AT&booking%5B%5D=$ECHT&notify=1" "$B/admin/day/$DAYD/bookings/delete"
check "with the box for the parents: a cancellation queued for each one with an address (bulk mail), logged as such" '[ "$(ABN)" = 2 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE kind = \"cancelled\" AND payload LIKE \"%echt-$AB@example.org%\" AND priority = 2")" = 1 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"%1 Buchung(en) auf einmal gelöscht, Eltern von 1 Buchung(en) per E-Mail informiert\"")" = 1 ]'
DBQ "DELETE FROM tp_mail_outbox WHERE kind = 'cancelled' AND payload LIKE '%echt-$AB@example.org%'"
NA=$(DBQ "SELECT COUNT(*) FROM tp_audit_log")
check "nothing ticked, values that are no id, or none of this day (any more): nothing deleted, a message, nothing logged" 'curl -s -o /dev/null -b "$J" -d "csrf=$AT&booking=x&booking%5B%5D=abc" "$B/admin/day/$DAYD/bookings/delete" && curl -s -b "$J" "$B/admin/round/$ABD" | grep -q "Keine Buchung ausgewählt" && curl -s -o /dev/null -b "$J" -d "csrf=$AT&booking%5B%5D=$ECHT&booking%5B%5D=$OTHER" "$B/admin/day/$DAYD/bookings/delete" && curl -s -b "$J" "$B/admin/round/$ABD" | grep -q "Keine der ausgewählten Buchungen gibt es noch" && [ "$(ABN)" = 2 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log")" = "$NA" ]'
JA=$(mktemp)
curl -s -o /dev/null -c "$JA" -b "$JA" -d "csrf=$(curl -s -c "$JA" -b "$JA" "$B/admin/login" | csrf)&username=missbrauch-$AB@example.org&password=Missbrauch-Lehrkraft-1" "$B/admin/login"
TA=$(curl -s -b "$JA" "$B/admin" | csrf)
DAYW=$(DBQ "SELECT id FROM tp_round_days WHERE round_id = $ABW")
check "teachers: for their own rounds (the list), not for others' (404 for the list and the deletion)" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JA" "$B/admin/day/$DAYW/bookings?from=09:00&to=11:00")" = 200 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JA" "$B/admin/day/$DAYD/bookings?from=09:00&to=11:00")" = 404 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JA" -d "csrf=$TA&booking%5B%5D=$(DBQ "SELECT id FROM tp_bookings WHERE name = \"Spaete Buchung\"")" "$B/admin/day/$DAYD/bookings/delete")" = 404 ] && [ "$(ABN)" = 2 ]'
rm -f "$JA"

# Mail volume: above mail_alert_hourly the operator gets an alert, at most one per hour (in-process, script on
# stdin for the coverage run; the alert goes to a stand-in, not into the counter of the whole test run)
VA=$($DC exec -T app php <<'PHP'
<?php
require '/srv/app/app/bootstrap.php';
$cfg = require '/srv/app/config/config.php';
App\Config::load(['mail_alert_hourly' => 2] + $cfg);
App\Db::run('DELETE FROM rate_limits WHERE bucket = ?', ['mail_volume']);
$sent = [];
App\Alert::$transport = static function (string $to, string $subject, string $body) use (&$sent): bool { $sent[] = "$to|$subject|" . (str_contains($body, '@') ? 'address' : 'none'); return true; };
App\Alert::$stateFile = (string) tempnam(sys_get_temp_dir(), 'tp-volume');
foreach (['eins', 'zwei', 'drei', 'vier'] as $n) {
    App\Abuse::mailAllowed("volumen-$n@example.org");
}
$after = count($sent);
App\Config::load(['mail_alert_hourly' => 0] + $cfg);
App\Abuse::mailAllowed('volumen-fuenf@example.org');
echo $after, '|', count($sent), '|', $sent[0] ?? '';
unlink(App\Alert::$stateFile);
PHP
)
check "mail volume above mail_alert_hourly: one alert to the operator (not one per mail), without addresses; 0 = off ($VA)" '[ "$VA" = "1|1|betrieb@example.org|[TalkSlot] Ungewöhnlich viele E-Mails an Eltern|none" ]'

DBQ "DELETE FROM tp_rounds WHERE id IN ($ABR, $ABR2, $ABM, $ABD); DELETE FROM tp_users WHERE id = $ABU; DELETE FROM tp_rate_limits WHERE bucket IN ('round', 'burst', 'burst_notice', 'recipient', 'recipient_day', 'move', 'move_ip', 'mail_volume')"
