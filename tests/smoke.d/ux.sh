# Usability: ways on for parents and guidance for admins (expert review, UX; see README.md in this directory)
echo "Ways on for parents: contact for questions, not found, no free slot (expert review P2-1)"
UT=$(curl -s -b "$J" "$B/admin" | csrf)
URUN=$(date +%s)
ux_admin=$(DBQ "SELECT id FROM tp_users WHERE username='$USER'")
# ux_round <title> <code required 0/1> → id of a new active round: one day in 10 days, two slots of 30 minutes
ux_round() {
  curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$UT" --data-urlencode "title=$1" \
    -d "is_active=1&code_required=$2&booking_limit=none&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+10 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" \
    "$B/admin/round/new" | grep -o '[0-9]*$'
}
# Profile of the admin with a contact for parents; all other fields as they are
ux_profile() {
  curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" --data-urlencode "parent_contact=$1" \
    --data-urlencode "display_name=$(DBQ "SELECT display_name FROM tp_users WHERE id=$ux_admin")" \
    --data-urlencode "email=$(DBQ "SELECT COALESCE(email, '') FROM tp_users WHERE id=$ux_admin")" \
    --data-urlencode "reply_to=$(DBQ "SELECT COALESCE(reply_to, '') FROM tp_users WHERE id=$ux_admin")" \
    -d "lang=de&summary_evening=$(DBQ "SELECT summary_evening FROM tp_users WHERE id=$ux_admin")&summary_deadline=$(DBQ "SELECT summary_deadline FROM tp_users WHERE id=$ux_admin")" \
    "$B/admin/profile"
}
DBQ "DELETE FROM tp_settings WHERE name = 'parent_contact'" # a clean start, also after an aborted run
UX_PROFILE=$(DBQ "SELECT CONCAT_WS('|', display_name, summary_evening, summary_deadline, COALESCE(summary_names, 'NULL')) FROM tp_users WHERE id=$ux_admin")
UX_LANG=$(DBQ "SELECT COALESCE(CONCAT('\'', lang, '\''), 'NULL') FROM tp_users WHERE id=$ux_admin")

# Migration 23 on a database from before: the column is added, nothing else changes
UX_LATEST=$(grep -o 'LATEST = [0-9]*' "$ROOT/app/src/Migrations.php" | grep -o '[0-9]*$')
DBQ "ALTER TABLE tp_users DROP COLUMN parent_contact; UPDATE tp_settings SET value = '22' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 23: contact for parents per account (empty), schema up to date" \
  '[ "$(DBQ "SELECT COUNT(*) FROM tp_users WHERE parent_contact IS NOT NULL")" = 0 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$UX_LATEST" ]'

# Not found: no contact set yet, the code input as the way on; "too many requests" gets the start page instead
UX_NF=$(curl -s -w '\n%{http_code}' "$B/gibt-es-nicht-$URUN")
check "not found: 404 with the code input of the start page; while no contact is set, the way to the imprint" \
  'echo "$UX_NF" | tail -1 | grep -qx 404 && echo "$UX_NF" | has "Haben Sie einen Code aus Ihrer Einladung?" && echo "$UX_NF" | has "data-code-form" && echo "$UX_NF" | has "name=\"code\"" && ! echo "$UX_NF" | has "data-contact>" && echo "$UX_NF" | has "(siehe <a href=\"/impressum\">Impressum</a>)"'

# The contact for all, set by an admin under "Texte": one line, shown escaped, logged
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" --data-urlencode $'parent_contact=  Sekretariat,\r\nTel. 01234 5678  <b>' "$B/admin/texts/contact"
UX_NF=$(curl -s "$B/gibt-es-nicht-$URUN")
check "contact for all: stored in one line, on \"not found\" (escaped), in the log, prefilled under \"Texte\"" \
  '[ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"parent_contact\"")" = "Sekretariat, Tel. 01234 5678 <b>" ] && echo "$UX_NF" | has "<p class=\"contact\" data-contact>Bei Fragen: Sekretariat, Tel. 01234 5678 &lt;b&gt;</p>" && curl -s -b "$J" "$B/admin/log" | has "Kontakt für Eltern geändert: Sekretariat, Tel. 01234 5678 &lt;b&gt;" && curl -s -b "$J" "$B/admin/texts" | has "name=\"parent_contact\" maxlength=\"300\" value=\"Sekretariat, Tel. 01234 5678 &lt;b&gt;\""'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" --data-urlencode "parent_contact=$(printf 'x%.0s' $(seq 1 301))" "$B/admin/texts/contact"
check "contact for all: over 300 characters refused, the previous one stays" \
  '[ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"parent_contact\"")" = "Sekretariat, Tel. 01234 5678 <b>" ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" -d "parent_contact=Sekretariat+Tel.+01234+5678" "$B/admin/texts/contact"
UX_LOGS=$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE 'Kontakt für Eltern geändert%'")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" -d "parent_contact=Sekretariat++Tel.+01234+5678+" "$B/admin/texts/contact"
check "contact for all saved again unchanged: no new log entry" '[ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"Kontakt für Eltern geändert%\"")" = "$UX_LOGS" ]'
check "start page after a wrong code: the contact for all" 'curl -s -d "code=ZZZZ-ZZZZ" "$B/" | has "Bei Fragen: Sekretariat Tel. 01234 5678" && ! curl -s "$B/" | has "data-contact"'
UX_MNF=$(curl -s -w '\n%{http_code}' "$B/b/$(printf 'A%.0s' $(seq 1 43))?lang=en")
check "unknown appointment link (English): 404 with the way on and the contact" \
  'echo "$UX_MNF" | tail -1 | grep -qx 404 && echo "$UX_MNF" | has "Do you have a code from your invitation?" && echo "$UX_MNF" | has "If you have questions: Sekretariat Tel. 01234 5678"'

# Rounds: the teacher's own contact, otherwise the one for all; a round with code shows only the one for all
UXR=$(ux_round "UX Kontakt $URUN" 0); UXS=$(slugof "$UXR")
UXC=$(ux_round "UX Code $URUN" 1); UXCS=$(slugof "$UXC")
check "test rounds created and active" '[ -n "$UXR" ] && [ -n "$UXC" ] && [ "$(DBQ "SELECT SUM(is_active) FROM tp_rounds WHERE id IN ($UXR, $UXC)")" = 2 ]'
check "round without a contact of the teacher: the one for all" 'curl -s "$B/$UXS" | has "Bei Fragen: Sekretariat Tel. 01234 5678"'
ux_profile $'  Frau Test,\n Raum 1.12 '
check "profile: own contact stored in one line, prefilled" \
  '[ "$(DBQ "SELECT parent_contact FROM tp_users WHERE id=$ux_admin")" = "Frau Test, Raum 1.12" ] && curl -s -b "$J" "$B/admin/profile" | has "value=\"Frau Test, Raum 1.12\" placeholder=" && curl -s -b "$J" "$B/admin/profile" | has "Ohne Eintrag sehen Eltern den allgemeinen Kontakt: Sekretariat Tel. 01234 5678"'
check "round page: the teacher's own contact" 'P=$(curl -s "$B/$UXS"); echo "$P" | has "Bei Fragen: Frau Test, Raum 1.12" && ! echo "$P" | has "Sekretariat"'
check "round with code: before the code only the contact for all (the round's details stay hidden)" \
  'P=$(curl -s "$B/$UXCS"); echo "$P" | has "Bei Fragen: Sekretariat Tel. 01234 5678" && ! echo "$P" | has "Frau Test"'
ux_profile "$(printf 'y%.0s' $(seq 1 301))"
check "profile: contact over 300 characters refused" '[ "$(DBQ "SELECT parent_contact FROM tp_users WHERE id=$ux_admin")" = "Frau Test, Raum 1.12" ]'
# A profile form opened before the update (without the field) keeps the contact
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" --data-urlencode "display_name=$(DBQ "SELECT display_name FROM tp_users WHERE id=$ux_admin")" \
  --data-urlencode "email=$(DBQ "SELECT COALESCE(email, '') FROM tp_users WHERE id=$ux_admin")" --data-urlencode "reply_to=$(DBQ "SELECT COALESCE(reply_to, '') FROM tp_users WHERE id=$ux_admin")" \
  -d "lang=de&summary_evening=$(DBQ "SELECT summary_evening FROM tp_users WHERE id=$ux_admin")&summary_deadline=$(DBQ "SELECT summary_deadline FROM tp_users WHERE id=$ux_admin")" "$B/admin/profile"
check "profile form from before the update (no contact field): the contact stays" '[ "$(DBQ "SELECT parent_contact FROM tp_users WHERE id=$ux_admin")" = "Frau Test, Raum 1.12" ]'

# No free slot: why
UXB=$(book "$UXS" "Erika Muster" "")
check "round with free slots: no notice" '[[ "$UXB" == 303* ]] && ! curl -s "$B/$UXS" | has "data-unavailable"'
UXM=$(echo "$UXB" | grep -o '/b/[A-Za-z0-9_-]*')
check "appointment page: the teacher's contact" 'curl -s "$B$UXM" | has "Bei Fragen: Frau Test, Raum 1.12"'
DBQ "UPDATE tp_rounds SET cancel_deadline_hours = 720 WHERE id = $UXR"
check "appointment page after the deadline for changes: the contact is part of that notice" \
  'P=$(curl -s "$B$UXM"); echo "$P" | has "<div class=\"alert\" data-locked>" && [ "$(echo "$P" | grep -c "data-contact>")" = 1 ] && echo "$P" | sed -n "/data-locked/,/<\/div>/p" | has "Bei Fragen: Frau Test, Raum 1.12"'
DBQ "UPDATE tp_rounds SET cancel_deadline_hours = 0 WHERE id = $UXR"
book "$UXS" "Max Muster" "" > /dev/null
check "all slots booked: \"all taken\" (German, English), the contact inside the notice only" \
  'P=$(curl -s "$B/$UXS"); echo "$P" | has "data-unavailable=\"full\">" && echo "$P" | has "Alle Termine sind vergeben. Wenn jemand absagt, wird wieder ein Termin frei." && [ "$(echo "$P" | grep -c "data-contact>")" = 1 ] && echo "$P" | sed -n "/data-unavailable/,/<\/div>/p" | has "Bei Fragen: Frau Test, Raum 1.12" && curl -s "$B/$UXS?lang=en" | has "All time slots have been taken."'
DBQ "DELETE b FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $UXR AND b.name = 'Max Muster'; UPDATE tp_rounds SET booking_deadline_hours = 720 WHERE id = $UXR"
check "a slot free, but within the booking deadline: \"deadline passed\"" \
  'P=$(curl -s "$B/$UXS"); echo "$P" | has "data-unavailable=\"closed\">" && echo "$P" | has "Die Buchungsfrist ist abgelaufen. Sie können hier keinen Termin mehr buchen."'
DBQ "UPDATE tp_slots s JOIN tp_round_days d ON d.id = s.day_id SET s.is_blocked = 1 WHERE d.round_id = $UXR"
check "free slot blocked by the teacher: counts as taken" 'curl -s "$B/$UXS" | has "data-unavailable=\"full\""'
DBQ "UPDATE tp_round_days SET day_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE round_id = $UXR"
check "no upcoming slots: as before \"no free appointments\"" \
  'P=$(curl -s "$B/$UXS"); echo "$P" | has "data-unavailable=\"none\">" && echo "$P" | has "Zurzeit sind keine freien Termine verfügbar."'

echo "Accounts: username of new accounts (expert review P2-1)"
UXF=$(curl -s -b "$J" "$B/admin/users/new")
check "new account: username offered (optional, not required)" \
  'echo "$UXF" | has "<input type=\"text\" id=\"username\" name=\"username\" maxlength=\"254\"" && echo "$UXF" | has "Leer = die E-Mail-Adresse."'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" -d "display_name=Herr+UX&email=ux-$URUN@example.org&username=hux$URUN&role=teacher&lang=de" "$B/admin/users/new"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$UT" -d "display_name=Frau+UX&email=ux2-$URUN@example.org&username=&role=teacher&lang=de" "$B/admin/users/new"
UXE=$(curl -s -b "$J" --data-urlencode "csrf=$UT" -d "display_name=Frau+UX&email=keine-adresse&username=&role=teacher&lang=de" "$B/admin/users/new")
check "new account with an invalid address and no username: only the address is named, the username stays empty" \
  'echo "$UXE" | has "Bitte eine gültige E-Mail-Adresse angeben" && ! echo "$UXE" | has "Benutzername: 3–254 Zeichen" && echo "$UXE" | has "name=\"username\" maxlength=\"254\" autocapitalize=\"none\" spellcheck=\"false\" autocomplete=\"off\" value=\"\""'
UXE=$(curl -s -b "$J" --data-urlencode "csrf=$UT" -d "display_name=Frau+UX&email=ux3-$URUN@example.org&role=teacher&lang=de" --data-urlencode "username=$(DBQ "SELECT email FROM tp_users WHERE id=$ux_admin")" "$B/admin/users/new")
check "new account: another account's e-mail address as username refused, nothing created" \
  'echo "$UXE" | has "Für diese E-Mail-Adresse bzw. diesen Benutzernamen gibt es bereits ein Konto." && [ -z "$(DBQ "SELECT id FROM tp_users WHERE email=\"ux3-$URUN@example.org\"")" ]'
check "new account: the chosen username, without one the e-mail address" \
  '[ "$(DBQ "SELECT username FROM tp_users WHERE email=\"ux-$URUN@example.org\"")" = "hux$URUN" ] && [ "$(DBQ "SELECT username FROM tp_users WHERE email=\"ux2-$URUN@example.org\"")" = "ux2-$URUN@example.org" ]'

# Clean-up: contacts (then no contact anywhere: the dead end points to the imprint), rounds, accounts
DBQ "DELETE FROM tp_settings WHERE name = 'parent_contact'; UPDATE tp_users SET parent_contact = NULL, lang = $UX_LANG WHERE id = $ux_admin"
check "no contact set: the notice points to the imprint instead" \
  'P=$(curl -s "$B/$UXS"); ! echo "$P" | has "data-contact>" && echo "$P" | sed -n "/data-unavailable/,/<\/div>/p" | has "Bei Fragen wenden Sie sich bitte an die Schule bzw. den Betreiber dieser Seite (siehe <a href=\"/impressum\">Impressum</a>)."'
for r in "$UXR" "$UXC"; do curl -s -o /dev/null -b "$J" -d "csrf=$UT&confirm=1" "$B/admin/round/$r/delete"; done
DBQ "DELETE FROM tp_users WHERE email IN ('ux-$URUN@example.org', 'ux2-$URUN@example.org')"
check "clean-up: profile of the admin as before" '[ "$(DBQ "SELECT CONCAT_WS(\"|\", display_name, summary_evening, summary_deadline, COALESCE(summary_names, \"NULL\")) FROM tp_users WHERE id=$ux_admin")" = "$UX_PROFILE" ]'
