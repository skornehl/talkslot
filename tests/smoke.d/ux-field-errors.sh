# Errors at the field (expert review P2-2; see README.md in this directory)
echo "Errors at the field (expert review P2-2)"
FE=$(curl -s -b "$J" "$B/admin" | csrf)
FERUN=$(date +%s)
FED=$(date -d '+21 day' +%F)
fe_post() { curl -s -b "$J" --data-urlencode "csrf=$FE" "$@"; } # fe_post <curl arguments>
FEID=$(fe_post -o /dev/null -w '%{redirect_url}' -d "title=Feldfehler+$FERUN&is_active=1&booking_limit=round&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$FED&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
FES=$(slugof "$FEID")

fe_marked() { local page=$1 f; shift; for f in "$@"; do echo "$page" | has "<a href=\"#$f\">" && echo "$page" | has "id=\"$f-error\"" || return 1; done; } # fe_marked <page> <field ids>

# Parents: the booking form
FEP=$(curl -s "$B/$FES")
check "booking form as it stands: no field marked, the hints still linked" \
  '! echo "$FEP" | has "aria-invalid" && echo "$FEP" | has "aria-describedby=\"name-hint\"" && ! echo "$FEP" | has "role=\"radiogroup\""'
fe_book() { local P; P=$(curl -s "$B/$FES"); curl -s --data-urlencode "csrf=$(echo "$P" | csrf)" -d "privacy=1&$1" "$B/$FES"; } # fe_book <fields>
FEB=$(fe_book "name=x&email=kaputt")
check "booking with three errors: each linked from the list, marked and explained at its field" \
  'echo "$FEB" | has "<a href=\"#slot_id\">" && echo "$FEB" | has "<a href=\"#name\">" && echo "$FEB" | has "<a href=\"#email\">" \
   && echo "$FEB" | has "aria-invalid=\"true\" aria-describedby=\"name-error name-hint\"" && echo "$FEB" | has "aria-invalid=\"true\" aria-describedby=\"email-error email-hint\"" \
   && echo "$FEB" | has "<p class=\"field-error\" id=\"name-error\">" && echo "$FEB" | has "<p class=\"field-error\" id=\"email-error\">"'
check "… no appointment chosen: the group of radio buttons is the target and carries the message" \
  'echo "$FEB" | has "<div id=\"slot_id\" tabindex=\"-1\" role=\"radiogroup\" aria-labelledby=\"slots-title\" aria-invalid=\"true\" aria-describedby=\"slot_id-error\">" && echo "$FEB" | has "id=\"slot_id-error\""'
FESLOT=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id WHERE d.round_id=$FEID ORDER BY s.start_time LIMIT 1")
FESLOT2=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id=s.day_id WHERE d.round_id=$FEID ORDER BY s.start_time DESC LIMIT 1")
fe_book "name=Erste+Familie+$FERUN&slot_id=$FESLOT" > /dev/null
FEB=$(fe_book "name=Zweite+Familie+$FERUN&slot_id=$FESLOT")
check "appointment taken meanwhile: the message at the appointments, not at the name" \
  'echo "$FEB" | has "<a href=\"#slot_id\">" && ! echo "$FEB" | has "<a href=\"#name\">"'
FEB=$(fe_book "name=Erste+Familie+$FERUN&slot_id=$FESLOT2")
check "name already has an appointment: the message at the name" \
  'echo "$FEB" | has "<a href=\"#name\">" && echo "$FEB" | has "id=\"name-error\""'
fe_book "name=Zweite+Familie+$FERUN&slot_id=$FESLOT2" > /dev/null
FEB=$(fe_book "name=Dritte+Familie+$FERUN")
check "nothing left to choose: the message is there, without a link into a form that is gone" \
  'echo "$FEB" | has "<div class=\"alert alert-error\"" && ! echo "$FEB" | has "<a href=\"#slot_id\">" && ! echo "$FEB" | has "id=\"booking-form\""'

# Admins: new round, with a break that has a typo
FER=$(fe_post -d "title=&is_active=1&booking_limit=round&booking_deadline_hours=x&cancel_deadline_hours=999&delete_after_days=abc&start_time=11:00&end_time=10:00&slot_minutes=3&break_start%5B%5D=25:00&break_end%5B%5D=&break_label%5B%5D=Tippfehler" "$B/admin/round/new")
check "new round: each wrong field linked from the list and marked" \
  'fe_marked "$FER" title booking_deadline_hours cancel_deadline_hours delete_after_days day_dates start_time slot_minutes breaks'
check "… hints stay linked after the error, the target of the dates is the first date" \
  'echo "$FER" | has "aria-invalid=\"true\" aria-describedby=\"title-error title-hint\"" && echo "$FER" | has "aria-invalid=\"true\" aria-describedby=\"delete_after_days-error del-hint\"" && [ "$(echo "$FER" | grep -c "id=\"day_dates\"")" = 1 ]'
check "… the break with the typo is still in the form, the fieldset names the message" \
  'echo "$FER" | has "value=\"25:00\"" && echo "$FER" | has "value=\"Tippfehler\"" && echo "$FER" | has "id=\"breaks\" tabindex=\"-1\" aria-describedby=\"breaks-error\""'
FEDAY=$(DBQ "SELECT id FROM tp_round_days WHERE round_id=$FEID")
FER=$(fe_post -d "day_date=kaputt&start_time=10:00&end_time=&slot_minutes=30&breaks_field=1&location=$(printf 'x%.0s' {1..201})" "$B/admin/day/$FEDAY/edit")
check "edit a day: date, end of the time range and location linked and marked" \
  'fe_marked "$FER" day_date end_time location'
FER=$(fe_post -d "day_dates%5B%5D=$FED&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/$FEID/day")
check "add a day that exists: at the date on the round page" \
  'echo "$FER" | has "<a href=\"#day_dates\">" && echo "$FER" | has "id=\"day_dates-error\""'
