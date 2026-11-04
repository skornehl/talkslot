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

echo "Privacy: legal texts, template variants and open placeholders"
PT=$(curl -s -b "$J" "$B/admin" | csrf)
pl_round() { # pl_round <title> <active 0/1> [user id] → id of the new round
  curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$PT" --data-urlencode "title=$1" -d "is_active=$2&user_id=${3:-}&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$'
}
PLA=$(pl_round "Platzhalter aktiv" 1)
PLI=$(pl_round "Platzhalter inaktiv" 0)
PLS=$(curl -s -b "$J" "$B/admin/round/$PLA" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
check "texts without open placeholders: round activated, no notice" '[ -n "$PLA" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLA")" = 1 ] && ! curl -s -b "$J" "$B/admin" | grep -q "offene Platzhalter"'
# A teacher with a password (for the teacher's view)
PLH=$($DC exec -T app php -r 'echo password_hash("Platzhalter-Passwort-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('platzhalter@example.org', '$PLH', 'teacher', 'Frau Platzhalter', 'platzhalter@example.org', 1, NOW())"
PLU=$(DBQ "SELECT id FROM tp_users WHERE username='platzhalter@example.org'")
PLR=$(pl_round "Platzhalter Lehrkraft" 0 "$PLU")
JP=$(mktemp)
curl -s -o /dev/null -c "$JP" -b "$JP" -d "csrf=$(curl -s -c "$JP" -b "$JP" "$B/admin/login" | csrf)&username=platzhalter@example.org&password=Platzhalter-Passwort-1" "$B/admin/login"

PF=$(curl -s -b "$J" "$B/admin/page/privacy/de")
check "text form offers both templates of the privacy policy, the legal notice its template, none without a template file" 'echo "$PF" | grep -q "href=\"/admin/page/privacy/de?template=public\">Öffentliche Schule</a>" && echo "$PF" | grep -q "href=\"/admin/page/privacy/de?template=private\">Privater Träger</a>" && echo "$PF" | grep -q "lit. b und f DSGVO" && curl -s -b "$J" "$B/admin/page/imprint/en" | grep -q "href=\"/admin/page/imprint/en?template=default\">Vorlage</a>" && ! curl -s -b "$J" "$B/admin/page/privacy/pl" | grep -q "?template="'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/de?template=private")
check "loading the template \"Privater Träger\" fills the form, but stores nothing yet" 'echo "$PF" | grep -q "Vorlage „Privater Träger“ geladen" && echo "$PF" | grep -qF "auf Art. 6 Abs. 1 lit. f DSGVO beruht" && ! curl -s "$B/datenschutz" | grep -qF "lit. f DSGVO" && ! curl -s -b "$J" "$B/admin/page/privacy/de?template=xyz" | grep -q "geladen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PT" --data-urlencode "content@$ROOT/app/templates/pages/privacy-private.de.md" "$B/admin/page/privacy/de"
D=$(curl -s -b "$J" "$B/admin")
check "saved with open placeholders: admin notice names them per text (shortened), active rounds stay online" 'echo "$D" | grep -qF "Impressum bzw. Datenschutzerklärung enthalten noch offene Platzhalter in eckigen Klammern – Datenschutzerklärung (DE): [KONTAKTDATEN DER/DES DATENSCHUTZBEAUF…], [SPEICHERDAUER BEIM HOSTER ERFRAGEN UN…], [HOSTER MIT ANSCHRIFT], [LAND], [ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE M…]. Solange sie nicht ersetzt sind, lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar." && ! echo "$D" | grep -qF "Datenschutzerklärung (EN):" && curl -s -b "$J" "$B/admin/texts" | grep -qF "[SPEICHERDAUER BEIM HOSTER ERFRAGEN UN…]" && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$PLS")" = 200 ]'
NR=$(curl -s -w '\n%{http_code}' -b "$J" --data-urlencode "csrf=$PT" -d "title=Platzhalter+neu&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
check "new active round refused with the reason, nothing created" '[ "$(echo "$NR" | tail -1)" = 200 ] && echo "$NR" | grep -qF "ersetzen Sie bitte unter „Texte“ die offenen Platzhalter in eckigen Klammern: Datenschutzerklärung (DE)." && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE title=\"Platzhalter neu\"")" ]'
TG=$(curl -s -L -b "$J" -d "csrf=$PT" "$B/admin/round/$PLI/toggle")
check "activating an inactive round refused (admin)" 'echo "$TG" | grep -qF "offenen Platzhalter in eckigen Klammern: Datenschutzerklärung (DE)." && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLI")" = 0 ]'
TG=$(curl -s -L -c "$JP" -b "$JP" -d "csrf=$(curl -s -c "$JP" -b "$JP" "$B/admin" | csrf)" "$B/admin/round/$PLR/toggle")
check "teacher: notice on the overview, activation refused with a matching message" 'curl -s -c "$JP" -b "$JP" "$B/admin" | grep -q "enthalten noch offene Platzhalter (Aufgabe der Admins)" && echo "$TG" | grep -q "wenn ein Admin die offenen Platzhalter in Impressum bzw. Datenschutzerklärung ausgefüllt hat" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLR")" = 0 ]'
# Language that is not offered: parents never see it, so it does not block
DBQ "INSERT INTO tp_pages (page_key, lang, content, updated_at) VALUES ('privacy', 'fr', '[ADRESSE]', NOW())"
check "only the texts of offered languages count" '! curl -s -b "$J" "$B/admin" | grep -qF "(FR):"'
DBQ "DELETE FROM tp_pages WHERE lang = 'fr'"

# Private operator: template filled in, a Markdown link with capitals is not a placeholder
legal_fill privacy-private
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\n[DSGVO-TEXT](https://eur-lex.europa.eu/eli/reg/2016/679/oj)') WHERE page_key='privacy' AND lang='de'"
curl -s -o /dev/null -b "$J" -d "csrf=$PT" "$B/admin/round/$PLI/toggle"
check "all placeholders filled: notices gone, round can be activated, privacy policy of the private operator online" '! curl -s -b "$J" "$B/admin" | grep -q "offene Platzhalter" && ! curl -s -c "$JP" -b "$JP" "$B/admin" | grep -q "offene Platzhalter" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLI")" = 1 ] && curl -s "$B/datenschutz" | grep -qF "auf Art. 6 Abs. 1 lit. f DSGVO beruht"'
# Web host not configured and [[HOSTER]] back in the text: its placeholder blocks as well
DBQ "UPDATE tp_pages SET content = REPLACE(content, '$SMOKE_HOST', '[[HOSTER]]') WHERE page_key='privacy' AND lang='en'"
curl -s -o /dev/null -b "$J" -d "csrf=$PT" "$B/admin/round/$PLI/toggle"
TG=$(curl -s -L -b "$J" -d "csrf=$PT" "$B/admin/round/$PLI/toggle")
check "unconfigured web host is an open placeholder too (deactivating works, activating not)" 'echo "$TG" | grep -qF "offenen Platzhalter in eckigen Klammern: Datenschutzerklärung (EN)." && curl -s -b "$J" "$B/admin" | grep -qF "Datenschutzerklärung (EN): [WEB HOST WITH ADDRESS], [COUNTRY]" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLI")" = 0 ]'
# Texts edited before the new templates: notice to compare them with the new template
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nEine Datenschutzbeauftragte bzw. ein Datenschutzbeauftragter ist für dieses private Angebot nicht bestellt.') WHERE page_key='privacy' AND lang='de'"
check "admin notice: text still follows the old template (private service)" 'curl -s -b "$J" "$B/admin" | grep -qF "Impressum bzw. Datenschutzerklärung (DE) folgen noch der alten Vorlage" && curl -s -b "$J" "$B/admin/texts" | grep -qF "(DE) folgen noch der alten Vorlage"'

# Back to the state of the main script: public school template filled in
legal_fill
check "notices gone again with the filled-in template" '! curl -s -b "$J" "$B/admin" | grep -qE "offene Platzhalter|alten Vorlage"'
for r in $PLA $PLI $PLR; do curl -s -o /dev/null -b "$J" -d "csrf=$PT&confirm=1" "$B/admin/round/$r/delete"; done
DBQ "DELETE FROM tp_users WHERE id = $PLU"
rm -f "$JP"
