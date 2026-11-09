# Request for an interpreter, asked per round (expert review P3-1), see tests/unit.d/interpreter.php
echo "Request for an interpreter"
IN_N=$(date +%s%N | tail -c 8) # Mailpit keeps the mails of earlier runs: addresses of this run only
IN_MAIL="dolmetschung-$IN_N@example.org"
# Booking with the field: slug, name, e-mail, language, n-th free slot, further form data
in_book() { local P; P=$(curl -s "$B/$1"); curl -s -w '\n%{http_code} %{redirect_url}' --data-urlencode "csrf=$(echo "$P" | csrf)" --data-urlencode "name=$2" --data-urlencode "email=$3" --data-urlencode "interpreter_lang=$4" ${6:+-d "$6"} -d "slot_id=$(echo "$P" | grep -o 'value="[0-9]*" required' | sed -n "${5:-1}p" | grep -o '[0-9]*')" "$B/$1"; }
in_lang() { DBQ "SELECT COALESCE(interpreter_lang, 'NULL') FROM tp_bookings WHERE name = '$1'"; }
in_rows() { DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name = '$1'"; }
in_new() { curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=$1&ask_interpreter_field=1&ask_interpreter=1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$LD1&start_time=09:00&end_time=11:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$'; }
in_edit() { curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=$2&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0${3:+&$3}" "$B/admin/round/$1/edit"; }
in_mailtext() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode("to:" . $argv[1])), true); echo $r["messages_count"] ?? 0, "\n"; foreach ($r["messages"] ?? [] as $m) { $d = json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $m["ID"]), true); echo $d["Subject"], "\n", $d["Text"], "\n"; }' "$1"; }

# Off by default
IN_R0=$(mkround "Ohne+Dolmetschung+$IN_N" none); IN_S0=$(slugof "$IN_R0")
IN_P0=$(curl -s "$B/$IN_S0")
check "a new round does not ask: no field, the short privacy information does not mention it" \
  '[ "$(DBQ "SELECT ask_interpreter FROM tp_rounds WHERE id = $IN_R0")" = 0 ] && ! echo "$IN_P0" | has "name=\"interpreter_lang\"" && echo "$IN_P0" | has "und – freiwillig – Ihre E-Mail-Adresse.</li>"'
in_book "$IN_S0" "Familie Ungefragt $IN_N" "" "Arabisch" >/dev/null
check "a round that does not ask: a language sent anyway is not stored" '[ "$(in_lang "Familie Ungefragt $IN_N")" = NULL ]'
check "round page: nothing about an interpreter while it is off" '! curl -s -b "$J" "$B/admin/round/$IN_R0" | has "Fragt nach Dolmetschung"'
IN_SL0=($(curl -s -b "$J" "$B/admin/round/$IN_R0" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
check "entry by the teacher in such a round: no field" '! curl -s -b "$J" "$B/admin/slot/${IN_SL0[0]}/book" | has "name=\"interpreter_lang\""'

# Asked: the parents
IN_R=$(in_new "Dolmetschung+$IN_N"); IN_S=$(slugof "$IN_R")
IN_P=$(curl -s "$B/$IN_S")
check "the round asks: optional field with a hint, the short privacy information names it" \
  'echo "$IN_P" | has "name=\"interpreter_lang\"" && ! echo "$IN_P" | grep -q "name=\"interpreter_lang\"[^>]*required" && echo "$IN_P" | has "id=\"interpreter-hint\"" && echo "$IN_P" | has "und Ihr Wunsch nach einer Dolmetschung.</li>"'
check "round page and form say that it is on" 'curl -s -b "$J" "$B/admin/round/$IN_R" | has "Fragt nach Dolmetschung" && curl -s -b "$J" "$B/admin/round/$IN_R/edit" | grep -q "id=\"ask_interpreter\" name=\"ask_interpreter\" value=\"1\" checked"'
IN_LONG=$(printf 'x%.0s' $(seq 61))
IN_X=$(in_book "$IN_S" "Familie Zulang $IN_N" "" "$IN_LONG")
check "too long: message, nothing booked, the input stays" 'echo "$IN_X" | has "höchstens 60 Zeichen" && echo "$IN_X" | has "value=\"$IN_LONG\"" && [ "$(in_rows "Familie Zulang $IN_N")" = 0 ]'
IN_X=$(in_book "$IN_S" "Familie Link $IN_N" "" "https://example.org/x")
check "a link: message, nothing booked" 'echo "$IN_X" | has "keine Links" && [ "$(in_rows "Familie Link $IN_N")" = 0 ]'
IN_X=$(in_book "$IN_S" "Familie Sprachwechsel $IN_N" "" "Arabisch" 1 "switch_lang=en")
check "switching the language keeps the input, books nothing, field in the new language" \
  'echo "$IN_X" | has "value=\"Arabisch\"" && echo "$IN_X" | has "Interpreter requested for the meeting" && [ "$(in_rows "Familie Sprachwechsel $IN_N")" = 0 ]'
IN_X=$(in_book "$IN_S" "Familie Dolmetsch $IN_N" "$IN_MAIL" "  Arabisch ")
IN_U=$(echo "$IN_X" | tail -1 | cut -d' ' -f2)
check "booked with the language, cleaned" '[[ "$(echo "$IN_X" | tail -1)" == 303* ]] && [ "$(in_lang "Familie Dolmetsch $IN_N")" = Arabisch ]'
in_book "$IN_S" "Familie Ohne $IN_N" "" "" 1 >/dev/null
check "field left empty: nothing stored, the bookings page shows nothing" '[ "$(in_lang "Familie Ohne $IN_N")" = NULL ]'
check "page of the booking shows what was entered" 'curl -s "$IN_U" | has "data-interpreter>Arabisch</dd>"'
IN_M=$(in_mailtext "$IN_MAIL")
check "confirmation sent, without the language" '[ "$(echo "$IN_M" | head -1)" -ge 1 ] && ! echo "$IN_M" | has "Arabisch"' || printf '      %s\n' "$IN_M" | head -5

# The teacher
IN_A=$(curl -s -b "$J" "$B/admin/round/$IN_R")
check "list of appointments shows it with the booking, only there" '[ "$(echo "$IN_A" | grep -c "data-interpreter>Dolmetschung gewünscht: Arabisch")" = 1 ] && [ "$(echo "$IN_A" | grep -c "data-interpreter>")" = 1 ]'
check "printout shows it" 'curl -s -b "$J" "$B/admin/round/$IN_R/print" | has "Dolmetschung gewünscht: Arabisch"'
IN_X=$(curl -s -b "$J" --data-urlencode "csrf=$T" --data-urlencode "email=$IN_MAIL" -d purpose=1 "$B/admin/access-request")
check "request for access (Art. 15) lists it" 'echo "$IN_X" | has "data-interpreter>Dolmetschung gewünscht: Arabisch"'
IN_SL=($(echo "$IN_A" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
check "entry by the teacher: field in a round that asks" 'curl -s -b "$J" "$B/admin/slot/${IN_SL[0]}/book" | has "name=\"interpreter_lang\""'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "name=Familie Eintrag $IN_N" --data-urlencode "interpreter_lang= Farsi " -d "lang=de" "$B/admin/slot/${IN_SL[0]}/book"
IN_B=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Familie Eintrag $IN_N'")
check "entry by the teacher: stored, cleaned" '[ "$(in_lang "Familie Eintrag $IN_N")" = Farsi ]'
check "editing the entry: the field has the value" 'curl -s -b "$J" "$B/admin/booking/$IN_B/edit" | has "value=\"Farsi\""'
in_change() { curl -s -b "$J" --data-urlencode "csrf=$T" --data-urlencode "name=Familie Eintrag $IN_N" --data-urlencode "interpreter_lang=$1" -d "lang=de" "$B/admin/booking/$IN_B/edit"; }
in_change "Dari" >/dev/null
check "editing the entry: changed" '[ "$(in_lang "Familie Eintrag $IN_N")" = Dari ]'
check "editing the entry: too long → message, unchanged" 'in_change "$IN_LONG" | has "höchstens 60 Zeichen" && [ "$(in_lang "Familie Eintrag $IN_N")" = Dari ]'

# What is copied with the round
IN_D=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$IN_R/duplicate" | grep -o '[0-9]*$')
check "a copy of the round asks as well" '[ "$(DBQ "SELECT ask_interpreter FROM tp_rounds WHERE id = $IN_D")" = 1 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/from-round/$IN_R"
IN_TID=$(DBQ "SELECT id FROM tp_round_templates ORDER BY id DESC LIMIT 1")
check "a template from the round keeps it, a new round from it starts ticked" \
  'curl -s -b "$J" "$B/admin/round/new?template=$IN_TID" | grep -q "id=\"ask_interpreter\" name=\"ask_interpreter\" value=\"1\" checked" && curl -s -b "$J" "$B/admin/templates/$IN_TID/edit" | grep -q "id=\"ask_interpreter\" name=\"ask_interpreter\" value=\"1\" checked"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/$IN_TID/delete"
IN_R2=$(in_new "Dolmetschung+Papierkorb+$IN_N")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$IN_R2/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/trash/$(DBQ "SELECT id FROM tp_round_trash WHERE title = 'Dolmetschung Papierkorb $IN_N'")/restore"
check "a round restored from the trash asks as well" '[ "$(DBQ "SELECT ask_interpreter FROM tp_rounds WHERE title = \"Dolmetschung Papierkorb $IN_N\"")" = 1 ]'

# Turned off later
in_edit "$IN_R" "Dolmetschung+$IN_N"
check "a form that does not know the field yet leaves the setting alone" '[ "$(DBQ "SELECT ask_interpreter FROM tp_rounds WHERE id = $IN_R")" = 1 ]'
in_edit "$IN_R" "Dolmetschung+$IN_N" "ask_interpreter_field=1"
check "turned off: no more field for parents" '[ "$(DBQ "SELECT ask_interpreter FROM tp_rounds WHERE id = $IN_R")" = 0 ] && ! curl -s "$B/$IN_S" | has "name=\"interpreter_lang\""'
check "turned off: what was entered stays visible to the teacher, and can be edited" \
  'curl -s -b "$J" "$B/admin/round/$IN_R" | has "Dolmetschung gewünscht: Arabisch" && curl -s -b "$J" "$B/admin/booking/$IN_B/edit" | has "value=\"Dari\""'
IN_BO=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Familie Ohne $IN_N'")
check "turned off: no field at an entry without a language" '! curl -s -b "$J" "$B/admin/booking/$IN_BO/edit" | has "name=\"interpreter_lang\""'
in_change "" >/dev/null
check "emptied by the teacher: gone" '[ "$(in_lang "Familie Eintrag $IN_N")" = NULL ]'

# Migration: existing bookings and rounds stay as they are, off
DBQ "ALTER TABLE tp_rounds DROP COLUMN ask_interpreter; ALTER TABLE tp_bookings DROP COLUMN interpreter_lang; UPDATE tp_settings SET value = '27' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 28: columns added, existing rounds do not ask, existing bookings have no language" \
  '[ "$(DBQ "SELECT value FROM tp_settings WHERE name = \"schema_version\"")" = "$(grep -o "LATEST = [0-9]*" "$ROOT/app/src/Migrations.php" | grep -o "[0-9]*$")" ] && [ "$(DBQ "SELECT MAX(ask_interpreter) FROM tp_rounds")" = 0 ] && [ "$(DBQ "SELECT COUNT(interpreter_lang) FROM tp_bookings")" = 0 ]'
DBQ "DELETE FROM tp_rounds WHERE title LIKE '%$IN_N' OR title LIKE '%$IN_N (Kopie)'"
