# Taking over a round of another teacher (expert review P2-10, see README.md in this directory): admins see how it is
# booked, names and addresses of parents only after this logged action, which tells the teacher by e-mail.
echo "Substitution (admins and the parents' data)"
SU=su$$
SUT=$(curl -s -b "$J" "$B/admin/users" | csrf)
SUH=$($DC exec -T app php -r 'echo password_hash("Vertretung-Lehrkraft-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('vertretung-$SU@example.org', '$SUH', 'teacher', 'Frau Vertretung', 'vertretung-$SU@example.org', 1, NOW())"
SUU=$(DBQ "SELECT id FROM tp_users WHERE username = 'vertretung-$SU@example.org'")
SUR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$SUT" -d "title=Vertretung+Runde&user_id=$SUU&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+7 day' +%F)&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
SUS=$(slugof "$SUR")
book "$SUS" "Eltern Geheim" "geheim-$SU@example.org" > /dev/null
book "$SUS" "Eltern Zwei" "" > /dev/null
SUB=$(DBQ "SELECT id FROM tp_bookings WHERE name = 'Eltern Geheim'")
SUSL=$(DBQ "SELECT slot_id FROM tp_bookings WHERE id = $SUB")
SUD=$(DBQ "SELECT id FROM tp_round_days WHERE round_id = $SUR")
SUF=$(DBQ "SELECT s.id FROM tp_slots s LEFT JOIN tp_bookings b ON b.slot_id = s.id WHERE s.day_id = $SUD AND b.id IS NULL LIMIT 1")
SP_=$(curl -s -b "$J" "$B/admin/round/$SUR")
SUM="csrf=$SUT&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0"
check "admin in another teacher's round: how it is booked, no names or addresses, no booking actions, the offer to take over" 'echo "$SP_" | grep -q "action=\"/admin/round/$SUR/substitute\"" && [ "$(echo "$SP_" | grep -c ">gebucht<")" = 2 ] && ! echo "$SP_" | grep -q "Eltern Geheim" && ! echo "$SP_" | grep -q "geheim-$SU" && ! echo "$SP_" | grep -q "/admin/booking/$SUB/" && ! echo "$SP_" | grep -q "/admin/slot/$SUF/book" && ! echo "$SP_" | grep -q "/admin/round/$SUR/print"'
SU_CODE() { curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$J" "$@"; }
for u in "/admin/round/$SUR/print" "/admin/booking/$SUB/edit" "/admin/slot/$SUF/book" "/admin/day/$SUD/bookings"; do
  X=$(SU_CODE "$B$u"); [[ "$X" == "303 "*"/admin/round/$SUR" ]] || SU_BAD="${SU_BAD:-} $u:$X"
done
check "… pages with their data and changes to bookings send back to the offer${SU_BAD:+ ($SU_BAD)}" '[ -z "${SU_BAD:-}" ] && [[ "$(SU_CODE -d "csrf=$SUT" "$B/admin/booking/$SUB/delete")" == "303 "*"/admin/round/$SUR" ]] && [[ "$(SU_CODE -d "csrf=$SUT&name=X&lang=de" "$B/admin/booking/$SUB/edit")" == "303 "*"/admin/round/$SUR" ]] && [[ "$(SU_CODE -d "csrf=$SUT&booking%5B%5D=$SUB" "$B/admin/day/$SUD/bookings/delete")" == "303 "*"/admin/round/$SUR" ]] && [[ "$(SU_CODE -d "csrf=$SUT&confirm=1" "$B/admin/day/$SUD/delete")" == "303 "*"/admin/round/$SUR" ]] && [[ "$(SU_CODE -d "csrf=$SUT&confirm=1" "$B/admin/round/$SUR/delete")" == "303 "*"/admin/round/$SUR" ]] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id WHERE s.day_id = $SUD")" = 2 ]'
check "… nor can an admin hand the round over without taking it over, not even to themselves" 'curl -s -b "$J" --data-urlencode "title=Vertretung Runde" -d "$SUM&user_id=$(DBQ "SELECT id FROM tp_users WHERE username = \"$USER\"")" "$B/admin/round/$SUR/edit" | grep -q "Zum Übergeben einer Runde mit Buchungen bitte zuerst die Vertretung übernehmen." && [ "$(DBQ "SELECT user_id FROM tp_rounds WHERE id = $SUR")" = "$SUU" ]'
X=$(curl -s -L -b "$J" --data-urlencode "title=Vertretung Runde" --data-urlencode "location=Raum 7" -d "$SUM" "$B/admin/round/$SUR/edit")
check "… a change without taking over: only the number of parents to inform, no names" 'echo "$X" | grep -q "Die Lehrkraft sieht die Buchungen in ihrer Runde" && ! echo "$X" | grep -q "Eltern Zwei" && ! echo "$X" | grep -q "geheim-$SU"'
SUE=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$SUT" -d "title=Vertretung+Leer&user_id=$SUU&booking_limit=none&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+7 day' +%F)&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
book_past() { DBQ "INSERT INTO tp_bookings (slot_id, name, token_hash, lang, source, created_at, updated_at) SELECT MIN(s.id), 'Vorbei', SHA2(RAND(), 256), 'de', 'parent', NOW(), NOW() FROM tp_slots s JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $SUE; UPDATE tp_round_days SET day_date = CURDATE() - INTERVAL 1 DAY WHERE round_id = $SUE"; }
book_past
check "… a round whose appointments are over can be cleaned up without taking over (nobody to inform)" 'curl -s -b "$J" "$B/admin/round/$SUE" | grep -q "action=\"/admin/round/$SUE/delete\"" && [[ "$(SU_CODE -d "csrf=$SUT&confirm=1" "$B/admin/round/$SUE/delete")" == "303 "*"/admin" ]] && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $SUE")" ]'
SU_MAILS() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true)["messages_count"] ?? "?";' "$1"; }
X=$(curl -s -L -b "$J" --data-urlencode "csrf=$SUT" -d "current_password=falsch-falsch" "$B/admin/round/$SUR/substitute")
check "taking over: refused without the right password, nothing logged, no mail" 'echo "$X" | grep -q "Zum Übernehmen der Vertretung bitte Ihr Passwort eingeben." && ! echo "$X" | grep -q "Eltern Geheim" && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE round_id = $SUR AND action = \"substitution\"")" = 0 ] && [ "$(SU_MAILS "to:vertretung-$SU@example.org subject:Vertretung")" = 0 ]'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'reauth'"
X=$(curl -s -L -b "$J" --data-urlencode "csrf=$SUT" --data-urlencode "current_password=$PASS" "$B/admin/round/$SUR/substitute")
check "taken over: names and addresses visible, booking actions back, print view open, the message says the teacher was told" 'echo "$X" | grep -q "Vertretung übernommen: Sie sehen" && echo "$X" | grep -q "Die Lehrkraft wurde per E-Mail informiert." && echo "$X" | grep -q "Eltern Geheim" && echo "$X" | grep -q "geheim-$SU@example.org" && echo "$X" | grep -q "/admin/booking/$SUB/edit" && ! echo "$X" | grep -q "action=\"/admin/round/$SUR/substitute\"" && curl -s -b "$J" "$B/admin/round/$SUR/print" | grep -q "Eltern Geheim" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$J" "$B/admin/booking/$SUB/edit")" = 200 ]'
SU_TEXT=$($DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $r["messages"][0]["ID"]), true)["Text"];' "to:vertretung-$SU@example.org subject:Vertretung")
check "… the teacher is told by e-mail, without data of parents" '[ "$(SU_MAILS "to:vertretung-$SU@example.org subject:Vertretung")" = 1 ] && echo "$SU_TEXT" | grep -qF "Hallo Frau Vertretung," && echo "$SU_TEXT" | grep -qF "die Vertretung für Ihre Runde „Vertretung Runde“ übernommen" && ! echo "$SU_TEXT" | grep -q "Eltern Geheim"'
check "… logged, structured: kind of change and round, no data of parents" '[ "$(DBQ "SELECT CONCAT(action, \":\", round_id, \":\", COALESCE(params, \"none\")) FROM tp_audit_log WHERE round_id = $SUR AND action = \"substitution\"")" = "substitution:$SUR:{\"reached\":1}" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE round_id = $SUR AND (message LIKE \"%Eltern Geheim%\" OR message LIKE \"%geheim-$SU%\" OR params LIKE \"%geheim%\")")" = 0 ] && curl -s -b "$J" "$B/admin/log" | grep -q "Vertretung übernommen, Namen und E-Mail-Adressen der Eltern sichtbar"'
curl -s -o /dev/null -b "$J" -d "csrf=$SUT&booking%5B%5D=$SUB" "$B/admin/day/$SUD/bookings/delete"
check "… a change it allows is logged with its values (bulk deletion: number and day)" '[ "$(DBQ "SELECT params FROM tp_audit_log WHERE round_id = $SUR AND action = \"bookings_deleted\"")" = "{\"count\":1,\"date\":\"$(date -d "+7 day" +%F)\"}" ]'
JB=$(mktemp); curl -s -o /dev/null -c "$JB" -b "$JB" -d "csrf=$(curl -s -c "$JB" -b "$JB" "$B/admin/login" | csrf)&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
check "a new sign-in has to take over again" 'curl -s -b "$JB" "$B/admin/round/$SUR" | grep -q "action=\"/admin/round/$SUR/substitute\"" && ! curl -s -b "$JB" "$B/admin/round/$SUR" | grep -q "Eltern Zwei"'
JC=$(mktemp); curl -s -o /dev/null -c "$JC" -b "$JC" -d "csrf=$(curl -s -c "$JC" -b "$JC" "$B/admin/login" | csrf)&username=vertretung-$SU@example.org&password=Vertretung-Lehrkraft-1" "$B/admin/login"
check "the teacher sees their own round as ever, cannot use the route (admins only)" 'curl -s -b "$JC" "$B/admin/round/$SUR" | grep -q "Eltern Zwei" && ! curl -s -b "$JC" "$B/admin/round/$SUR" | grep -q "action=\"/admin/round/$SUR/substitute\"" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JC" --data-urlencode "csrf=$(curl -s -b "$JC" "$B/admin" | csrf)" "$B/admin/round/$SUR/substitute")" = 404 ]'
rm -f "$JB" "$JC"
# The teacher moves on: another owner means taking over again; no address: the admin is told the teacher was not reached
SUU2=$(DBQ "SELECT id FROM tp_users WHERE role = 'admin' ORDER BY id LIMIT 1")
curl -s -o /dev/null -b "$J" --data-urlencode "title=Vertretung Runde" -d "$SUM&user_id=$SUU2" "$B/admin/round/$SUR/edit"
check "handed over after taking over: logged as such" '[ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE round_id = $SUR AND action = \"round_reassigned\"")" = 1 ] && [ "$(DBQ "SELECT user_id FROM tp_rounds WHERE id = $SUR")" = "$SUU2" ]'
curl -s -o /dev/null -b "$J" --data-urlencode "title=Vertretung Runde" -d "$SUM&user_id=$SUU" "$B/admin/round/$SUR/edit"
DBQ "UPDATE tp_users SET email = NULL WHERE id = $SUU"
check "… and back to the teacher it counts as a new round to take over" 'curl -s -b "$J" "$B/admin/round/$SUR" | grep -q "action=\"/admin/round/$SUR/substitute\""'
X=$(curl -s -L -b "$J" --data-urlencode "csrf=$SUT" --data-urlencode "current_password=$PASS" "$B/admin/round/$SUR/substitute")
check "… a teacher without an address: the admin is told, and the log says so" 'echo "$X" | grep -q "Die Lehrkraft konnte nicht per E-Mail informiert werden" && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE round_id = $SUR AND action = \"substitution\" AND params = \"{\\\"reached\\\":0}\"")" = 1 ]'
# Deleting an account with upcoming bookings names their parents: only with the password
book "$SUS" "Bleibt Offen" "" > /dev/null
check "deleting an account with upcoming bookings: not without the password" 'curl -s -L -b "$J" -d "csrf=$SUT&confirm=1" "$B/admin/users/$SUU/delete" | grep -q "Zum Löschen eines Kontos mit anstehenden Buchungen bitte Ihr Passwort eingeben" && [ -n "$(DBQ "SELECT id FROM tp_users WHERE id = $SUU")" ]'
X=$(curl -s -L -b "$J" -d "csrf=$SUT&confirm=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$SUU/delete")
check "… with it the names are shown once, logged as such" 'echo "$X" | grep -q "Bleibt Offen" && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE action = \"account_deleted\" AND params LIKE \"%count%\"")" -ge 1 ] && [ -z "$(DBQ "SELECT id FROM tp_users WHERE id = $SUU")" ]'
DBQ "DELETE FROM tp_rounds WHERE id = $SUR; DELETE FROM tp_users WHERE id = $SUU; DELETE FROM tp_rate_limits WHERE bucket = 'reauth'"
