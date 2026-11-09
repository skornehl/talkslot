# Usability: the admin round page and its forms (expert review P2-3; see README.md in this directory)
echo "Round page and forms (expert review P2-3)"
UR=$(curl -s -b "$J" "$B/admin" | csrf)
URUN=$(date +%s)
URD1=$(date -d '+14 day' +%F); URD2=$(date -d '+15 day' +%F)
ur_post() { curl -s -b "$J" --data-urlencode "csrf=$UR" "$@"; } # ur_post <curl arguments>
URID=$(ur_post -o /dev/null -w '%{redirect_url}' -d "title=UX+Runde+$URUN&is_active=1&booking_limit=none&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_days=2&day_dates%5B%5D=$URD1&day_dates%5B%5D=$URD2&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
URS=$(slugof "$URID")
URP=$(curl -s -b "$J" "$B/admin/round/$URID")
ur_line() { echo "$URP" | grep -n "$1" | "$2" -1 | cut -d: -f1; } # ur_line <pattern> head|tail: its first or last line in the page
check "round page: the translations folded away, below the days" \
  'echo "$URP" | has "<details class=\"card\" id=\"translations\">" && [ "$(ur_line "class=\"card day-card\"" tail)" -lt "$(ur_line "id=\"translations\"" head)" ]'
check "round page: the form for a break behind \"+ Pause hinzufügen\", one per day" \
  '[ "$(echo "$URP" | grep -c "<details class=\"add-break\">")" = 2 ] && echo "$URP" | has "<summary>+ Pause hinzufügen</summary>"'
check "round page with several days: links to them, in \"Termin\" instead of \"Slot\"" \
  'echo "$URP" | has "<nav class=\"day-nav\"" && [ "$(echo "$URP" | sed -n "/day-nav/,/<\/nav>/p" | grep -o "href=\"#day-[0-9]*\"" | wc -l)" = 2 ] && ! echo "$URP" | grep -qwE "Slots?"'
check "parents: no technical term in the hint on remembering the appointment" '! curl -s "$B/$URS" | has "localStorage"'

# The deletion period in days
ur_edit() { ur_post -d "title=UX+Runde+$URUN&is_active=1&booking_limit=none&booking_deadline_hours=0&cancel_deadline_hours=0&$1" "$B/admin/round/$URID/edit"; }
ur_hours() { DBQ "SELECT COALESCE(delete_after_hours, 'NULL') FROM tp_rounds WHERE id=$URID"; }
check "new round: the deletion period in days is stored in hours, the page says days" '[ "$(ur_hours)" = 48 ] && echo "$URP" | has "(2 Tag(e) nach Ablauf)"'
ur_edit "delete_after_days=1,5" > /dev/null
check "decimal comma: 1,5 days are 36 hours" '[ "$(ur_hours)" = 36 ]'
URE=$(ur_edit "delete_after_days=abc")
check "invalid deletion period: message, input kept, the advanced options open, nothing stored" \
  'echo "$URE" | has "Runde löschen nach Ablauf: leer lassen (nie löschen) oder 0 bis 365 Tage." && echo "$URE" | has "step=\"any\" value=\"abc\"" && echo "$URE" | has "<details class=\"form-section\" open>" && [ "$(ur_hours)" = 36 ]'
check "more than 365 days refused" 'ur_edit "delete_after_days=366" | has "0 bis 365 Tage" && [ "$(ur_hours)" = 36 ]'
ur_edit "delete_after_days=" > /dev/null
check "empty: never deleted automatically" '[ "$(ur_hours)" = NULL ]'
ur_edit "delete_after_hours=72" > /dev/null
URE=$(curl -s -b "$J" "$B/admin/round/$URID/edit")
check "a form or script from before the update sends hours: still understood; the form shows days, \"Webadresse\" instead of \"Slug\"" \
  '[ "$(ur_hours)" = 72 ] && echo "$URE" | has "name=\"delete_after_days\" min=\"0\" max=\"365\" step=\"any\" value=\"3\"" && echo "$URE" | has ">Webadresse</label>" && ! echo "$URE" | has "Slug"'
ur_post -o /dev/null -d "name=UX+Vorlage+$URUN&start_time=10:00&end_time=11:00&slot_minutes=30&booking_deadline_hours=24&cancel_deadline_hours=12&delete_after_days=3&booking_limit=round" "$B/admin/templates/new"
URT=$(DBQ "SELECT id FROM tp_round_templates WHERE name='UX Vorlage $URUN'")
check "template: the deletion period in days, stored in hours like the rounds" \
  'D=$(DBQ "SELECT data FROM tp_round_templates WHERE id=$URT"); echo "$D" | has "\"delete_after_hours\":72" && ! echo "$D" | has "delete_after_days" && curl -s -b "$J" "$B/admin/templates/$URT/edit" | has "name=\"delete_after_days\" min=\"0\" max=\"365\" step=\"any\" value=\"3\""'
ur_post -o /dev/null "$B/admin/templates/$URT/delete"

# "Tag bearbeiten" with the fields of a new day: breaks, preview, input kept after an error
book "$URS" "Erika Muster $URUN" "" 2 > /dev/null
URDAY=$(DBQ "SELECT id FROM tp_round_days WHERE round_id=$URID AND day_date='$URD1'")
ur_day() { ur_post -d "day_date=$URD1&start_time=10:00&end_time=11:00&slot_minutes=30&$1" "$B/admin/day/$URDAY/edit"; }
ur_slots() { DBQ "SELECT COUNT(*) FROM tp_slots WHERE day_id=$URDAY"; }
ur_breaks() { DBQ "SELECT COUNT(*) FROM tp_day_breaks WHERE day_id=$URDAY"; }
ur_conflicts() { DBQ "SELECT COUNT(*) FROM tp_slots WHERE day_id=$URDAY AND is_conflict=1"; }
URF=$(curl -s -b "$J" "$B/admin/day/$URDAY/edit")
check "day form: the fields of a new day, the booked slot for the preview" \
  'echo "$URF" | has "data-day-fields data-booked=\"10:30\"" && echo "$URF" | has ">Erster Termin ab</label>" && echo "$URF" | has "step=\"5\" value=\"30\"" && echo "$URF" | has "name=\"breaks_field\" value=\"1\"" && echo "$URF" | has "data-slot-preview"'
ur_day "breaks_field=1&break_start%5B%5D=10:00&break_end%5B%5D=10:30&break_label%5B%5D=Kaffee" > /dev/null
check "day form: a break saved with the day, the free slot in it is gone, the booking stays" \
  '[ "$(ur_breaks)" = 1 ] && [ "$(DBQ "SELECT label FROM tp_day_breaks WHERE day_id=$URDAY")" = Kaffee ] && [ "$(ur_slots)" = 1 ] && [ "$(ur_conflicts)" = 0 ] && curl -s -b "$J" "$B/admin/day/$URDAY/edit" | has "name=\"break_start[]\" value=\"10:00\""'
ur_day "x=1" > /dev/null
check "day form from before the update (no breaks in it): the breaks stay" '[ "$(ur_breaks)" = 1 ]'
check "log: a change of the breaks is named" 'curl -s -b "$J" "$B/admin/log" | has "Pausen jetzt: 10:00–10:30"'
ur_day "breaks_field=1&break_start%5B%5D=10:30&break_end%5B%5D=11:00" > /dev/null
check "break over the booked slot: marked as a conflict, the booking stays" '[ "$(ur_conflicts)" = 1 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name=\"Erika Muster $URUN\"")" = 1 ]'
ur_day "breaks_field=1" > /dev/null
check "all breaks removed: the conflict fits again" '[ "$(ur_breaks)" = 0 ] && [ "$(ur_conflicts)" = 0 ] && [ "$(ur_slots)" = 2 ]'
URE=$(ur_post -d "day_date=$URD1&start_time=09:00&end_time=11:00&slot_minutes=30&breaks_field=1&break_start%5B%5D=12:00&break_end%5B%5D=11:00&location=Raum+7" "$B/admin/day/$URDAY/edit")
check "day form after an error: the message, the input kept, nothing stored" \
  'echo "$URE" | has "Pause 1: Bitte Start- und Endzeit angeben" && echo "$URE" | has "name=\"break_start[]\" value=\"12:00\"" && echo "$URE" | has "name=\"start_time\" required value=\"09:00\"" && echo "$URE" | has "value=\"Raum 7\"" && [ "$(DBQ "SELECT TIME_FORMAT(start_time, \"%H:%i\") FROM tp_round_days WHERE id=$URDAY")" = 10:00 ]'
check "day form: breaks that leave no slot refused" 'ur_day "breaks_field=1&break_start%5B%5D=10:00&break_end%5B%5D=11:00" | has "Mit diesen Zeiten und Pausen entsteht kein einziger Termin."'
ur_post -o /dev/null -d "confirm=1" "$B/admin/round/$URID/delete"
