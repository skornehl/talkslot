# Trash for rounds without bookings (expert review P3-9), see App\RoundTrash
echo "Round trash"
RT_N=$(date +%s%N | tail -c 8)
RT_R=$(mkround "Papierkorb+$RT_N" none); RT_S=$(slugof "$RT_R"); RT_C=$(DBQ "SELECT code FROM tp_rounds WHERE id = $RT_R")
# Something of everything that must come back: a blocked slot, a break, a translation
RT_D=$(DBQ "SELECT id FROM tp_round_days WHERE round_id = $RT_R ORDER BY id LIMIT 1")
RT_SL=$(DBQ "SELECT id FROM tp_slots WHERE day_id = $RT_D ORDER BY start_time LIMIT 1")
DBQ "UPDATE tp_slots SET is_blocked = 1 WHERE id = $RT_SL; INSERT INTO tp_day_breaks (day_id, start_time, end_time, label) VALUES ($RT_D, '09:30:00', '09:45:00', 'Pause $RT_N'); INSERT INTO tp_round_translations (round_id, lang, title, description, source_hash, is_manual, updated_at) VALUES ($RT_R, 'en', 'Trash test', NULL, 'x', 1, NOW())"
RT_SLOTS=$(DBQ "SELECT COUNT(*) FROM tp_slots s JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $RT_R")
DBQ "UPDATE tp_rounds SET expired_at = '2026-01-01 00:00:00' WHERE id = $RT_R"
RT_DAYS=$(DBQ "SELECT COUNT(*) FROM tp_round_days WHERE round_id = $RT_R")
RT_P=$(curl -s -b "$J" "$B/admin/round/$RT_R")
check "round page: a round without bookings says it goes to the trash" 'echo "$RT_P" | has "data-trash-note"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_R/delete"
RT_E=$(DBQ "SELECT id FROM tp_round_trash WHERE title = 'Papierkorb $RT_N'")
check "deleted without bookings: gone from the rounds and public pages, entry in the trash, in the log" \
  '[ -n "$RT_E" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $RT_R")" = 0 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$RT_S")" = 404 ] && curl -s -b "$J" "$B/admin/trash" | has "Papierkorb $RT_N" && curl -s -b "$J" "$B/admin" | has "data-trash-link" && [ -n "$(DBQ "SELECT id FROM tp_audit_log WHERE message LIKE \"%Papierkorb $RT_N%in den Papierkorb gelegt%\"")" ]'
# Another teacher does not get at it (the admin does: the session above)
RT_H=$($DC exec -T app php -r 'echo password_hash("Papierkorb-Passwort-7", PASSWORD_DEFAULT);')
RT_U="rt-lehrkraft-$RT_N@example.org"
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('$RT_U', '$RT_H', 'teacher', 'Rt Lehrkraft', '$RT_U', 1, NOW())"
RT_J=$(mktemp); RT_T=$(curl -s -c "$RT_J" -b "$RT_J" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$RT_J" -b "$RT_J" --data-urlencode "csrf=$RT_T" --data-urlencode "username=$RT_U" --data-urlencode "password=Papierkorb-Passwort-7" "$B/admin/login"
RT_T=$(curl -s -b "$RT_J" "$B/admin" | csrf)
check "another teacher: not in their trash, restoring refused (404), entry stays" \
  '! curl -s -b "$RT_J" "$B/admin/trash" | has "Papierkorb $RT_N" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$RT_J" -d "csrf=$RT_T" "$B/admin/trash/$RT_E/restore")" = 404 ] && [ -n "$(DBQ "SELECT id FROM tp_round_trash WHERE id = $RT_E")" ]'
RT_L=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/trash/$RT_E/restore")
RT_N2=${RT_L##*/}
check "restored: address and code as before, days, slots, blocked slot, break and translation back, expiry cleared, entry gone" \
  '[ "$(DBQ "SELECT slug FROM tp_rounds WHERE id = $RT_N2")" = "$RT_S" ] && [ "$(DBQ "SELECT code FROM tp_rounds WHERE id = $RT_N2")" = "$RT_C" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_round_days WHERE round_id = $RT_N2")" = "$RT_DAYS" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_slots s JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $RT_N2")" = "$RT_SLOTS" ] && [ "$(DBQ "SELECT s.is_blocked FROM tp_slots s JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $RT_N2 ORDER BY d.day_date, s.start_time LIMIT 1")" = 1 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_day_breaks b JOIN tp_round_days d ON d.id = b.day_id WHERE d.round_id = $RT_N2 AND b.label = \"Pause $RT_N\"")" = 1 ] && [ "$(DBQ "SELECT title FROM tp_round_translations WHERE round_id = $RT_N2 AND lang = \"en\"")" = "Trash test" ] && [ -z "$(DBQ "SELECT id FROM tp_round_trash WHERE id = $RT_E")" ] && [ "$(DBQ "SELECT expired_at IS NULL FROM tp_rounds WHERE id = $RT_N2")" = 1 ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $RT_N2")" = 1 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$RT_S")" = 200 ]'
# Taken meanwhile: the address changes, and the teacher is told
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_N2/delete"
RT_E=$(DBQ "SELECT id FROM tp_round_trash WHERE title = 'Papierkorb $RT_N'")
RT_O=$(mkround "Besetzt+$RT_N" none)
DBQ "UPDATE tp_rounds SET slug = '$RT_S', code = '$RT_C' WHERE id = $RT_O"
RT_X=$(curl -s -L -b "$J" -d "csrf=$T" "$B/admin/trash/$RT_E/restore")
RT_N3=$(DBQ "SELECT id FROM tp_rounds WHERE title = 'Papierkorb $RT_N'")
check "address and code taken meanwhile: new ones, and the message says so" '[ -n "$RT_N3" ] && [ "$(DBQ "SELECT slug FROM tp_rounds WHERE id = $RT_N3")" != "$RT_S" ] && [ "$(DBQ "SELECT code FROM tp_rounds WHERE id = $RT_N3")" != "$RT_C" ] && echo "$RT_X" | has "Ihre bisherige Adresse oder ihr Rundencode war vergeben"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RT_O/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_N3/delete"
RT_E=$(DBQ "SELECT id FROM tp_round_trash WHERE title = 'Papierkorb $RT_N'")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/trash/$RT_E/delete"
check "deleted for good from the trash, in the log" '[ -z "$(DBQ "SELECT id FROM tp_round_trash WHERE id = $RT_E")" ] && [ -n "$(DBQ "SELECT id FROM tp_audit_log WHERE message LIKE \"%aus dem Papierkorb endgültig gelöscht%\"")" ]'
# An inactive round stays inactive; an active one only comes back active if it may be published
RT_I=$(mkround "Inaktiv+$RT_N" none); DBQ "UPDATE tp_rounds SET is_active = 0 WHERE id = $RT_I"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_I/delete"
RT_IE=$(DBQ "SELECT id FROM tp_round_trash WHERE title = 'Inaktiv $RT_N'")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/trash/$RT_IE/restore"
RT_IR=$(DBQ "SELECT id FROM tp_rounds WHERE title = 'Inaktiv $RT_N'")
check "an inactive round stays inactive" '[ -n "$RT_IR" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $RT_IR")" = 0 ]'
DBQ "UPDATE tp_rounds SET is_active = 1 WHERE id = $RT_IR"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_IR/delete"
RT_IE=$(DBQ "SELECT id FROM tp_round_trash WHERE title = 'Inaktiv $RT_N'")
RT_DN=$(DBQ "SELECT display_name FROM tp_users WHERE username = '$USER'")
DBQ "UPDATE tp_users SET display_name = '' WHERE username = '$USER'"
RT_X=$(curl -s -L -b "$J" -d "csrf=$T" "$B/admin/trash/$RT_IE/restore")
DBQ "UPDATE tp_users SET display_name = '$RT_DN' WHERE username = '$USER'"
RT_IR=$(DBQ "SELECT id FROM tp_rounds WHERE title = 'Inaktiv $RT_N'")
check "an active round whose activation is blocked by now comes back deactivated, and says so" '[ -n "$RT_IR" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $RT_IR")" = 0 ] && echo "$RT_X" | has "Die Runde bleibt deaktiviert"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_IR/delete"
DBQ "DELETE FROM tp_round_trash WHERE title = 'Inaktiv $RT_N'"
# A round with a booking is not a case for the trash; the booking goes with the box ticked, as before
RT_B=$(mkround "Gebucht+$RT_N" none); RT_BS=$(slugof "$RT_B")
book "$RT_BS" "Familie Papierkorb" "" >/dev/null
RT_C0=$(DBQ "SELECT COUNT(*) FROM tp_round_trash")
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RT_B/delete"
check "with a booking: deleted at once, not in the trash" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $RT_B")" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_round_trash")" = "$RT_C0" ]'
# Nothing to do (in-process, script on stdin for the coverage run): an unknown round or entry, a round with a booking
RT_B2=$(mkround "Gebucht-Zwei+$RT_N" none); RT_BS2=$(slugof "$RT_B2")
book "$RT_BS2" "Familie Zwei" "" >/dev/null
RT_NUL=$($DC exec -T app php -- /srv/app "$RT_B2" 2>&1 <<'PHP'
<?php
[, $root, $booked] = $argv;
require "$root/app/bootstrap.php";
var_export([App\RoundTrash::put(99999999), App\RoundTrash::put((int) $booked), App\RoundTrash::restore(99999999)]);
PHP
)
check "an unknown round or entry, or a round that has a booking by now: nothing changes" '[ "$(echo "$RT_NUL" | tr -d " \n")" = "array(0=>NULL,1=>NULL,2=>NULL,)" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $RT_B2")" = 1 ]' || printf '      %s\n' "$RT_NUL"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RT_B2/delete"
# Clean-up after KEEP_DAYS, and the entries of a deleted account go with it
RT_R2=$(mkround "Abgelaufen+$RT_N" none)
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$RT_R2/delete"
DBQ "UPDATE tp_round_trash SET deleted_at = NOW() - INTERVAL 15 DAY WHERE title = 'Abgelaufen $RT_N'"
$DC exec -T app php /srv/app/bin/cron.php >/dev/null 2>&1
check "the clean-up deletes entries after 14 days" '[ -z "$(DBQ "SELECT id FROM tp_round_trash WHERE title = \"Abgelaufen $RT_N\"")" ]'
RT_R3=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$RT_J" --data-urlencode "csrf=$RT_T" -d "title=Lehrkraft-Runde-$RT_N&booking_limit=none&is_active=0&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$LD1&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
curl -s -o /dev/null -b "$RT_J" -d "csrf=$RT_T" "$B/admin/round/$RT_R3/delete"
check "a teacher sees their own entry in their trash" 'curl -s -b "$RT_J" "$B/admin/trash" | has "Lehrkraft-Runde-$RT_N"'
RT_UID=$(DBQ "SELECT id FROM tp_users WHERE username = '$RT_U'")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "confirm=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$RT_UID/delete"
check "the entries of a deleted account go with it" '[ "$(DBQ "SELECT COUNT(*) FROM tp_round_trash WHERE user_id = $RT_UID")" = 0 ] && [ -z "$(DBQ "SELECT id FROM tp_users WHERE id = $RT_UID")" ]'
rm -f "$RT_J"
DBQ "DELETE FROM tp_round_trash WHERE title IN ('Besetzt $RT_N')"
