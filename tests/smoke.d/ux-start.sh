# Usability: "Bereit für den Start?" and "Was ist neu" for admins (expert review P2-13; see README.md in this directory)
echo "Ready to start, what's new (expert review P2-13)"
RT=$(curl -s -b "$J" "$B/admin" | csrf)
RS_ADMIN=$(DBQ "SELECT id FROM tp_users WHERE username='$USER'")
# Operated by the school for these checks (the data protection officer is a point only then)
RS_TYPE=$(DBQ "SELECT value FROM tp_settings WHERE name='operator_type'")
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('operator_type', 'school')"
rs_check() { curl -s -b "$J" "$B/admin/start" | grep -o "data-check=\"$1\" data-state=\"[a-z]*\"" | sed 's/.*state="//;s/"$//'; }
RS=$(curl -s -w '\n%{http_code}' -b "$J" "$B/admin/start")
check "checklist page: legal texts, data protection officer (operated by the school), mail, cron job, server, rights" \
  'echo "$RS" | tail -1 | grep -qx 200 && [ "$(echo "$RS" | grep -o "data-check=\"[a-z]*\"" | tr "\n" " ")" = "data-check=\"legal\" data-check=\"dpo\" data-check=\"mail\" data-check=\"cron\" data-check=\"server\" data-check=\"rights\" " ] && echo "$RS" | has "Versand über SMTP von termine@example.org" && [ "$(rs_check mail)" = check ] && [ "$(rs_check server)" = done ]'
check "linked from \"Texte\"" 'curl -s -b "$J" "$B/admin/texts" | has "href=\"/admin/start\""'

# Legal texts and the data protection officer: the school's placeholder back in the German privacy policy
RS_PRIV=$(DBQ "SELECT content FROM tp_pages WHERE page_key='privacy' AND lang='de'" | md5sum)
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nDatenschutzbeauftragte: [KONTAKTDATEN DER/DES DATENSCHUTZBEAUFTRAGTEN DER SCHULE]') WHERE page_key='privacy' AND lang='de'"
RS=$(curl -s -b "$J" "$B/admin/start")
check "open placeholder of the data protection officer: both points to do, with the language and the way to the text" \
  '[ "$(rs_check legal)" = todo ] && [ "$(rs_check dpo)" = todo ] && echo "$RS" | has "In der Datenschutzerklärung (DE) fehlen noch die Kontaktdaten" && echo "$RS" | has "href=\"/admin/page/privacy/de\">Datenschutzerklärung bearbeiten"'
DBQ "UPDATE tp_pages SET content = SUBSTRING_INDEX(content, '\n\nDatenschutzbeauftragte: [KONTAKTDATEN', 1) WHERE page_key='privacy' AND lang='de'"
check "placeholder filled: done again (text as before)" '[ "$(rs_check dpo)" = done ] && [ "$(DBQ "SELECT content FROM tp_pages WHERE page_key=\"privacy\" AND lang=\"de\"" | md5sum)" = "$RS_PRIV" ]'

# Cron job: last run, never, switched off is a matter of the configuration (not tested here)
RS_CRON=$(DBQ "SELECT CONCAT_WS('|', (SELECT value FROM tp_settings WHERE name='last_cron'), (SELECT value FROM tp_settings WHERE name='cron_watch_since'))")
# An alert for this outage counts as sent just now, so that the late run below sends no alert mail
RS_SENT=$(DBQ "SELECT value FROM tp_settings WHERE name='cron_alert_sent'")
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('cron_alert_sent', '$(date +%s)')"
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('last_cron', '$(date +%s)')"
check "cron job with a recent run: done, with its time" '[ "$(rs_check cron)" = done ] && curl -s -b "$J" "$B/admin/start" | has "Zuletzt vollständig gelaufen am $(date +%d.%m.%Y)"'
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('last_cron', '$(date -d '2026-11-01 08:15' +%s)')"
check "cron job late: to do, since when" '[ "$(rs_check cron)" = todo ] && curl -s -b "$J" "$B/admin/start" | has "Seit dem 01.11.2026 08:15 Uhr nicht mehr vollständig gelaufen."'
DBQ "DELETE FROM tp_settings WHERE name='last_cron'; REPLACE INTO tp_settings (name, value) VALUES ('cron_watch_since', UNIX_TIMESTAMP() - 86400 * 2)"
check "cron job never run: to do, with the guide" '[ "$(rs_check cron)" = todo ] && curl -s -b "$J" "$B/admin/start" | has "Noch kein vollständiger Lauf verzeichnet."'
DBQ "DELETE FROM tp_settings WHERE name IN ('last_cron', 'cron_watch_since', 'cron_alert_sent')"
[ -n "$RS_SENT" ] && DBQ "INSERT INTO tp_settings (name, value) VALUES ('cron_alert_sent', '$RS_SENT')"
[ -n "${RS_CRON%%|*}" ] && DBQ "INSERT INTO tp_settings (name, value) VALUES ('last_cron', '${RS_CRON%%|*}')"
[ -n "${RS_CRON#*|}" ] && [ "${RS_CRON#*|}" != "$RS_CRON" ] && DBQ "INSERT INTO tp_settings (name, value) VALUES ('cron_watch_since', '${RS_CRON#*|}')"

# Dashboard: the checklist only until the first round has been public, then the warnings as before
RS_USED=$(DBQ "SELECT COALESCE(GROUP_CONCAT(id), '0') FROM tp_rounds WHERE was_used = 1")
DBQ "UPDATE tp_rounds SET was_used = 0"
RS_D=$(curl -s -b "$J" "$B/admin")
check "dashboard before the first round went public: the checklist with what is still open" \
  'echo "$RS_D" | has "data-readiness-summary" && echo "$RS_D" | has "Bereit für den Start?" && echo "$RS_D" | has "Bitte prüfen: E-Mail-Versand" && echo "$RS_D" | has "href=\"/admin/start\""'
DBQ "UPDATE tp_rounds SET was_used = 1 WHERE id IN ($RS_USED)"
check "dashboard once a round has been public: no checklist" '! curl -s -b "$J" "$B/admin" | has "data-readiness-summary"'

# What's new: accounts from before the notes see them (teachers only theirs) until they confirm; new accounts none
RS_CREATED=$(DBQ "SELECT created_at FROM tp_users WHERE id=$RS_ADMIN")
DBQ "UPDATE tp_users SET created_at = '2026-01-01 00:00:00' WHERE id = $RS_ADMIN; DELETE FROM tp_settings WHERE name = 'news_seen:$RS_ADMIN'"
RS_D=$(curl -s -b "$J" "$B/admin")
check "what's new for an admin from before: the notes, also those for admins" \
  'echo "$RS_D" | has "data-whats-new" && echo "$RS_D" | has "eine eigene Antwortadresse für Eltern angeben" && echo "$RS_D" | has "„Bereit für den Start?“ zeigt auf einer Seite"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$RT" "$B/admin/news"
check "confirmed: not shown again" '! curl -s -b "$J" "$B/admin" | has "data-whats-new" && [ -n "$(DBQ "SELECT value FROM tp_settings WHERE name=\"news_seen:$RS_ADMIN\"")" ]'
RS_PH=$($DC exec -T app php -r 'echo password_hash("Neuigkeiten-Passwort-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('neu-$RT', '$RS_PH', 'teacher', 'Herr Neu', NULL, 1, '2026-01-01 00:00:00')"
RS_T=$(DBQ "SELECT id FROM tp_users WHERE username='neu-$RT'")
RS_J=$(mktemp)
curl -s -o /dev/null -c "$RS_J" -b "$RS_J" -d "csrf=$(curl -s -c "$RS_J" -b "$RS_J" "$B/admin/login" | csrf)&username=neu-$RT&password=Neuigkeiten-Passwort-1" "$B/admin/login"
RS_D=$(curl -s -b "$RS_J" "$B/admin")
check "what's new for a teacher from before: only the notes for teachers" \
  'echo "$RS_D" | has "data-whats-new" && echo "$RS_D" | has "eine eigene Antwortadresse für Eltern angeben" && ! echo "$RS_D" | has "„Bereit für den Start?“ zeigt"'
DBQ "UPDATE tp_users SET created_at = '2099-01-01 00:00:00' WHERE id = $RS_T"
check "a new account (created after the notes): none of them" '! curl -s -b "$RS_J" "$B/admin" | has "data-whats-new"'
check "checklist page for admins only" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$RS_J" "$B/admin/start")" = 404 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$RT&confirm=1&notify=0" "$B/admin/users/$RS_T/delete"
check "account deleted: its confirmation of the notes goes with it" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE id=$RS_T")" ] && [ -z "$(DBQ "SELECT name FROM tp_settings WHERE name=\"news_seen:$RS_T\"")" ]'
rm -f "$RS_J"
DBQ "UPDATE tp_users SET created_at = '$RS_CREATED' WHERE id = $RS_ADMIN; DELETE FROM tp_settings WHERE name = 'news_seen:$RS_ADMIN'"
DBQ "UPDATE tp_settings SET value = '$RS_TYPE' WHERE name = 'operator_type'"
check "operated privately: no point for the school's data protection officer" 'DBQ "UPDATE tp_settings SET value = \"private\" WHERE name = \"operator_type\""; R=$(rs_check dpo); DBQ "UPDATE tp_settings SET value = \"$RS_TYPE\" WHERE name = \"operator_type\""; [ -z "$R" ]'
