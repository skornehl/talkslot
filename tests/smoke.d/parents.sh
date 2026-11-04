# Parents, texts and privacy (see README.md in this directory)
echo "Privacy: no browser page translation in the admin area"
# Admin page marked against the browser's page translation (cloud service): $1 path, $2 language (regex), $3 cookie jar
parents_notranslate() {
  local p
  p=$(curl -s ${3:+-b "$3"} "$B$1")
  echo "$p" | grep -q "<html lang=\"$2\" translate=\"no\" class=\"notranslate\">" \
    && echo "$p" | grep -q '<meta name="google" content="notranslate">'
}
check "login page (de/en): translate=\"no\", class and meta notranslate" 'parents_notranslate /admin/login de && parents_notranslate "/admin/login?lang=en" en'
check "round page and print view with booking list: marked as well" 'parents_notranslate "/admin/round/$RID" "[a-z]*" "$J" && parents_notranslate "/admin/round/$RID/print?email=1" "[a-z]*" "$J"'
check "parent pages stay translatable" 'P=$(curl -s "$B/"); echo "$P" | grep -q "<html lang=\"de\">" && ! echo "$P" | grep -q "notranslate"'

echo "Parents are not notified automatically (admin notices)"
# Deleting or changing bookings, days and rounds sends nothing to the parents (yet): dialogs, hints and
# messages say so. The mail option of a manual entry is ticked by default.
T=$(curl -s -b "$J" "$B/admin" | csrf)
PRUN=$(date +%s)
PM="hinweis-$PRUN@example.org"
pmails() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$1"'"),true)["messages_count"] ?? "?";'; }
oneline() { tr '\n' '~'; }   # dialogs contain line breaks
PD1=$(date -d '+40 day' +%F); PD2=$(date -d '+41 day' +%F); PD3=$(date -d '+42 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweistest&location=Raum+1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$PD1&day_dates%5B%5D=$PD2&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
RN=$(echo "$loc" | grep -o '[0-9]*$')
PG=$(curl -s -b "$J" "$B/admin/round/$RN")
check "round without bookings: delete dialog without a notice about parents" '[ -n "$RN" ] && echo "$PG" | has "Runde „Hinweistest“ mit allen Tagen und Buchungen endgültig löschen?\"" && ! echo "$PG" | has "NICHT automatisch"'
PS=($(echo "$PG" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
PDAYS=($(echo "$PG" | grep -o 'id="day-[0-9]*"' | grep -o '[0-9]\+'))

# Manual entries: mail ticked by default, also after an error without an address; a choice made by hand stays
check "new entry: mail option ticked by default" 'curl -s -b "$J" "$B/admin/slot/${PS[0]}/book" | has "name=\"send_mail\" value=\"1\" data-mail-for=\"email\" checked"'
check "new entry after an error: ticked while no address is entered, unticked by hand stays unticked" 'curl -s -b "$J" -d "csrf=$T&name=&lang=de" "$B/admin/slot/${PS[0]}/book" | has "data-mail-for=\"email\" checked" && ! curl -s -b "$J" -d "csrf=$T&name=&email=$PM&lang=de" "$B/admin/slot/${PS[0]}/book" | has "data-mail-for=\"email\" checked"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=de&send_mail=1" "$B/admin/slot/${PS[0]}/book"
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Ohne+Mail&lang=de" "$B/admin/slot/${PS[1]}/book"
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Tag+Zwei&email=tag2-$PRUN@example.org&lang=de" "$B/admin/slot/${PS[4]}/book"
PBA=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hinweis Mit Mail'")
PBB=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hinweis Ohne Mail'")
check "edit entry: mail option ticked by default" '[ "$(pmails "$PM")" = 1 ] && curl -s -b "$J" "$B/admin/booking/$PBA/edit" | has "data-mail-for=\"email\" checked"'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=de&send_mail=1" "$B/admin/booking/$PBA/edit")
check "saving an entry without any change sends no mail" 'echo "$R" | has "Eintrag gespeichert. Nichts geändert – keine E-Mail gesendet." && [ "$(pmails "$PM")" = 1 ]'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=en&send_mail=1" "$B/admin/booking/$PBA/edit")
check "a real change is mailed" 'echo "$R" | has "Änderung per E-Mail mitgeteilt" && [ "$(pmails "$PM")" = 2 ]'

# Delete dialogs: with and without an address, day and round with the number of bookings
PG=$(curl -s -b "$J" "$B/admin/round/$RN" | oneline)
check "delete entry with address: parents are NOT notified" 'echo "$PG" | has "Eintrag von „Hinweis Mit Mail“ löschen? Der Slot wird wieder frei.~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren.\""'
check "delete entry without address: please inform in person" 'echo "$PG" | has "Eintrag von „Hinweis Ohne Mail“ löschen? Der Slot wird wieder frei.~~Die Eltern werden NICHT automatisch benachrichtigt. Für diesen Eintrag ist keine E-Mail-Adresse hinterlegt – bitte persönlich informieren.\""'
check "delete day: number of bookings, parents NOT notified, print view first" 'echo "$PG" | has "Diesen Tag mit allen Slots und 2 Buchung(en) löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Wer gebucht hat, steht vorher in der Druckansicht – nach dem Löschen nicht mehr.\""'
check "delete round: number of bookings, parents NOT notified" 'echo "$PG" | has "Runde „Hinweistest“ mit allen Tagen und 3 Buchung(en) endgültig löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Wer gebucht hat, steht vorher in der Druckansicht"'

# Changing date or location: notice in the forms, message afterwards (only if booked parents are affected)
check "edit day: notice with the number of bookings, location hint" 'P=$(curl -s -b "$J" "$B/admin/day/${PDAYS[0]}/edit"); echo "$P" | has "An diesem Tag sind 2 Termin(e) gebucht. Die Eltern werden über Änderungen an Datum, Zeiten oder Ort NICHT automatisch benachrichtigt" && echo "$P" | has "Nur ausfüllen, wenn der Tag nicht am Ort der Runde stattfindet. Bereits gebuchte Eltern werden bei einer Änderung NICHT automatisch benachrichtigt."'
check "edit round: location hint about booked parents (not for a new round)" 'curl -s -b "$J" "$B/admin/round/$RN/edit" | has "Bereits gebuchte Eltern werden bei einer Änderung NICHT automatisch benachrichtigt." && ! curl -s -b "$J" "$B/admin/round/new" | has "Bereits gebuchte Eltern"'
pday() { curl -s -L -b "$J" -d "csrf=$T&day_date=$2&start_time=${4:-14:00}&end_time=15:00&slot_minutes=15" --data-urlencode "location=$3" "$B/admin/day/$1/edit"; }
# The round page shows the delete dialogs ("werden NICHT …"); the message after saving says "wurden NICHT …"
check "day saved unchanged: no message about parents" 'R=$(pday "${PDAYS[0]}" "$PD1" ""); echo "$R" | has "Tag gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "day gets the location of the round as its own: same place, no message" 'R=$(pday "${PDAYS[0]}" "$PD1" "Raum 1"); echo "$R" | has "Tag gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "new location of a day: parents of its bookings NOT notified" 'pday "${PDAYS[0]}" "$PD1" "Raum 2" | has "Die Eltern von 2 Buchung(en) wurden NICHT automatisch benachrichtigt – bitte selbst informieren."'
check "new date of a day: parents NOT notified" 'pday "${PDAYS[1]}" "$PD3" "" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt"'
pround() { curl -s -L -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweistest&booking_deadline_hours=0&cancel_deadline_hours=0" --data-urlencode "location=$1" "$B/admin/round/$RN/edit"; }
check "new location of the round: only bookings on days without their own location count" 'pround "Aula" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt"'
check "round saved with the same location: no message" 'R=$(pround "Aula"); echo "$R" | has "Runde gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "conflict after a change: rebooking does not inform parents by itself" 'pday "${PDAYS[1]}" "$PD3" "" 14:30 | has "als Konflikt markiert. Bitte umbuchen oder löschen. Die Eltern werden dabei NICHT automatisch benachrichtigt: Beim Umbuchen „Änderung per E-Mail mitteilen“ angehakt lassen" && curl -s -b "$J" "$B/admin/round/$RN" | has "Bitte umbuchen oder löschen. Die Eltern werden dabei NICHT automatisch benachrichtigt"'

# After deleting: message with the name (the dialog needs JavaScript)
check "entry deleted: parents NOT notified, named with their address (no longer shown anywhere)" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/booking/$PBA/delete" | has "Die Eltern („Hinweis Mit Mail“, $PM) wurden NICHT automatisch benachrichtigt – bitte selbst informieren."'
check "entry without address deleted: please inform in person" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/booking/$PBB/delete" | has "Die Eltern („Hinweis Ohne Mail“) wurden NICHT automatisch benachrichtigt; es war keine E-Mail-Adresse hinterlegt – bitte persönlich informieren."'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Drei&lang=de" "$B/admin/slot/${PS[2]}/book"
check "day with a booking deleted: parents NOT notified" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/day/${PDAYS[0]}/delete" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt"'
check "round with a booking deleted: parents NOT notified" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RN/delete" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$RN")" ]'

# Deleting an account with bookings
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Hinweis&email=lk-$PRUN@example.org&role=teacher" "$B/admin/users/new"
PU=$(DBQ "SELECT id FROM tp_users WHERE username='lk-$PRUN@example.org'")
check "account without bookings: delete dialog without a notice about parents" '[ -n "$PU" ] && ! curl -s -b "$J" "$B/admin/users/$PU/edit" | has "NICHT automatisch"'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweis+Konto&user_id=$PU&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$PD1&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Konto&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$(echo "$loc" | grep -o '[0-9]*$')" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
check "account with bookings: delete dialog says parents are NOT notified" 'curl -s -b "$J" "$B/admin/users/$PU/edit" | oneline | has "mit allen Runden und Buchungen endgültig löschen?~~Die Eltern von 1 Buchung(en) werden NICHT automatisch benachrichtigt – bitte selbst informieren.\""'
check "account deleted: parents NOT notified" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$PU/delete" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt" && [ -z "$(DBQ "SELECT id FROM tp_users WHERE id=$PU")" ]'
