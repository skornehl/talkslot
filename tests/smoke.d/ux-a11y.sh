# Usability: accessibility (expert review P2-2; see README.md in this directory)
echo "Accessibility in the admin area (expert review P2-2)"
A1T=$(curl -s -b "$J" "$B/admin" | csrf)
A1RUN=$(date +%s)
A1R=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$A1T" -d "title=A11y+Runde+$A1RUN&is_active=1&booking_limit=none&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+16 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
check "set-up: the round for these checks exists" '[ -n "$A1R" ]'
A1S=$(slugof "$A1R")
book "$A1S" "Lukas Schmidt" "" > /dev/null
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$A1T" -d "start_time=12:00&end_time=12:30" "$B/admin/day/$(DBQ "SELECT id FROM tp_round_days WHERE round_id=$A1R")/break"
DBQ "UPDATE tp_slots s JOIN tp_bookings b ON b.slot_id = s.id JOIN tp_round_days d ON d.id = s.day_id SET s.is_conflict = 1 WHERE d.round_id = $A1R"
A1P=$(curl -s -b "$J" "$B/admin/round/$A1R")
check "menu: aria-current=page on the page itself, true within its section" \
  'curl -s -b "$J" "$B/admin" | has "<a href=\"/admin\" aria-current=\"page\">" && curl -s -b "$J" "$B/admin/users" | has "<a href=\"/admin/users\" aria-current=\"page\">" && curl -s -b "$J" "$B/admin/users/new" | has "<a href=\"/admin/users\" aria-current=\"true\">" && echo "$A1P" | has "<a href=\"/admin\" aria-current=\"true\">" && curl -s -b "$J" "$B/admin/page/privacy/de" | has "<a href=\"/admin/texts\" aria-current=\"true\">"'
check "buttons that read alike say what they are for" \
  'curl -s -b "$J" "$B/admin" | has "aria-label=\"Link kopieren: A11y Runde $A1RUN\"" && echo "$A1P" | grep -qE "aria-label=\"Bearbeiten: [^\"]*10:00–10:30, Lukas Schmidt\"" && echo "$A1P" | grep -qE "aria-label=\"Löschen: [^\"]*10:00–10:30, Lukas Schmidt\"" && echo "$A1P" | grep -qE "aria-label=\"Eintragen: [^\"]*10:30–11:00\"" && echo "$A1P" | grep -qE "aria-label=\"Sperren: [^\"]*10:30–11:00\"" && echo "$A1P" | grep -qE "aria-label=\"Tag bearbeiten: [^\"]+\"" && echo "$A1P" | grep -qE "aria-label=\"Pause entfernen: [^\"]+, 12:00–12:30\""'
check "conflict as text, not only a symbol with a tooltip" \
  'echo "$A1P" | has "<span class=\"badge badge-warning\" data-conflict><span aria-hidden=\"true\">⚠ </span>Konflikt</span>" && ! echo "$A1P" | has "title=\"Konflikt\""'
curl -s -o /dev/null -b "$J" -d "csrf=$A1T&confirm=1" "$B/admin/round/$A1R/delete"
check "clean-up: the round is gone" '[ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$A1R")" ]'
