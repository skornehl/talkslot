# Data-minimal wording (see README.md in this directory)
echo "Data-minimal wording: the child's name, neutral titles, organisational notes, the log"
# Expert review P2-4: the name field asks for the child's name (with the sibling rule where the round limits bookings),
# the round form asks for neutral titles, the notes column of the print view is for organisational notes only, and
# booking events appear in the log with their date only (with the time they could still be matched to a person).
T=$(curl -s -b "$J" "$B/admin" | csrf)
DM1=$(date -d '+50 day' +%F); DM2=$(date -d '+51 day' +%F)
dm_round() { # dm_round <title> <booking_limit>: id of a new active round with two days, 14:00–15:00 in 15 minutes
  curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$1" -d "is_active=1&booking_limit=$2&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$DM1&day_dates%5B%5D=$DM2&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$'
}
DMR=$(dm_round "Elternsprechtag Datensparsam" round)
DMN=$(dm_round "Elternsprechtag Ohne Grenze" none)
DMP=$(curl -s "$B/$(DBQ "SELECT slug FROM tp_rounds WHERE id = $DMR")")
check "booking form: the child's name asked for, first and last name with the class, the sibling rule at the field" 'echo "$DMP" | has "<label for=\"name\">Name des Kindes <span class=\"req\"" && echo "$DMP" | has "id=\"name-hint\">Vor- und Nachname, bei Bedarf mit Klasse (z. B. „Mia Schmidt, 7b“). Mehr ist nicht nötig. Für Geschwister buchen Sie bitte je Kind einen eigenen Termin mit dessen Namen.</p>"'
check "… without a booking limit no sibling rule; in Turkish the child's name as well" 'P=$(curl -s "$B/$(DBQ "SELECT slug FROM tp_rounds WHERE id = $DMN")"); echo "$P" | has "Mehr ist nicht nötig.</p>" && ! echo "$P" | has "Für Geschwister buchen Sie bitte je Kind" && curl -s "$B/$(DBQ "SELECT slug FROM tp_rounds WHERE id = $DMN")?lang=tr" | has "<label for=\"name\">Çocuğun adı <span"'
check "round form: neutral titles recommended, linked to the title field" 'curl -s -b "$J" "$B/admin/round/new" | has "aria-describedby=\"title-hint\">" && curl -s -b "$J" "$B/admin/round/$DMR/edit" | has "<p class=\"hint\" id=\"title-hint\">Bitte neutral halten, z. B. „Elternsprechtag 7b“: Titel und Beschreibung stehen in den E-Mails an Eltern und in Kalenderdateien, der Titel auch im Protokoll."'

# Entries by the admin: in the log with the date of the booking, never its time
DMS=($(curl -s -b "$J" "$B/admin/round/$DMR" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Mia+Schmidt+7b&lang=de" "$B/admin/slot/${DMS[0]}/book"
DMB=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Mia Schmidt 7b'")
dm_log() { DBQ "SELECT message FROM tp_audit_log ORDER BY id DESC LIMIT 1"; }
DMD1=$(date -d "$DM1" +%d.%m.%Y); DMD2=$(date -d "$DM2" +%d.%m.%Y)
check "entry by the admin: logged with the date only (no time, no name)" '[ -n "$DMB" ] && [ "$(dm_log)" = "Runde „Elternsprechtag Datensparsam“ (Frau Sabrina Test): Buchung am $DMD1 eingetragen" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Mia+Schmidt+7c&lang=de" "$B/admin/booking/$DMB/edit"
check "entry changed: date only" '[ "$(dm_log)" = "Runde „Elternsprechtag Datensparsam“ (Frau Sabrina Test): Buchung am $DMD1 geändert" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Mia+Schmidt+7c&lang=de&move_to=${DMS[1]}" "$B/admin/booking/$DMB/edit"
check "moved to another time on the same day: no time, not even the new one" '[ "$(DBQ "SELECT slot_id FROM tp_bookings WHERE id = $DMB")" = "${DMS[1]}" ] && [ "$(dm_log)" = "Runde „Elternsprechtag Datensparsam“ (Frau Sabrina Test): Buchung am $DMD1 geändert, auf eine andere Uhrzeit am selben Tag umgebucht" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Mia+Schmidt+7c&lang=de&move_to=${DMS[5]}" "$B/admin/booking/$DMB/edit"
check "moved to another day: the two dates" '[ "$(DBQ "SELECT slot_id FROM tp_bookings WHERE id = $DMB")" = "${DMS[5]}" ] && [ "$(dm_log)" = "Runde „Elternsprechtag Datensparsam“ (Frau Sabrina Test): Buchung am $DMD1 geändert, umgebucht auf den $DMD2" ]'
check "print view: the notes column is for organisational notes only, nothing about the conversation" 'P=$(curl -s -b "$J" "$B/admin/round/$DMR/print"); echo "$P" | has "<th class=\"col-notes\">Organisatorisches</th>" && echo "$P" | has "Vertraulich – nur Organisatorisches notieren, keine Gesprächsinhalte; nach dem Termin vernichten." && ! echo "$P" | has ">Notizen<"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/booking/$DMB/delete"
check "entry deleted: date only; no booking event of the test with a time" '[ "$(dm_log)" = "Runde „Elternsprechtag Datensparsam“ (Frau Sabrina Test): Buchung am $DMD2 gelöscht" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"%Elternsprechtag Datensparsam%Buchung%\" AND message REGEXP \"[0-9]{2}:[0-9]{2}\"")" = 0 ]'
check "log page: no names or e-mail addresses of parents, bookings with their date only" 'curl -s -b "$J" "$B/admin/log" | has "Namen und E-Mail-Adressen von Eltern nie, Buchungen nur mit ihrem Datum."'

# Back to the state of the main script
for r in "$DMR" "$DMN"; do curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$r/delete"; done
check "cleaned up: both rounds gone" '[ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id IN ($DMR, $DMN)")" ]'
