# Usability: "Bereit für den Start?" and "Was ist neu" for admins (expert review P2-13; see README.md in this directory)
echo "Ready to start, what's new (expert review P2-13)"
RT=$(curl -s -b "$J" "$B/admin" | csrf)
RS_ADMIN=$(DBQ "SELECT id FROM tp_users WHERE username='$USER'")
# rs_setting <name>: its value, or "-" if there is none (for an exact restore with rs_restore <name> <saved>)
rs_setting() { DBQ "SELECT COALESCE((SELECT value FROM tp_settings WHERE name='$1'), '-')"; }
rs_restore() { if [ "$2" = - ]; then DBQ "DELETE FROM tp_settings WHERE name='$1'"; else DBQ "REPLACE INTO tp_settings (name, value) VALUES ('$1', '$2')"; fi; }
rs_check() { curl -s -b "$J" "$B/admin/start" | grep -o "data-check=\"$1\" data-state=\"[a-z]*\"" | sed 's/.*state="//;s/"$//'; }
# Operated by the school for these checks (the data protection officer is a point only then)
RS_TYPE=$(rs_setting operator_type)
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('operator_type', 'school')"
RS=$(curl -s -w '\n%{http_code}' -b "$J" "$B/admin/start")
check "checklist page: legal texts, data protection officer (operated by the school), mail, cron job, server, rights" \
  'echo "$RS" | tail -1 | grep -qx 200 && [ "$(echo "$RS" | grep -o "data-check=\"[a-z]*\"" | tr "\n" " ")" = "data-check=\"legal\" data-check=\"dpo\" data-check=\"contact\" data-check=\"mail\" data-check=\"cron\" data-check=\"server\" data-check=\"rights\" " ] && [ "$(rs_check server)" = done ]'
check "linked from \"Texte\" and from the overview" 'curl -s -b "$J" "$B/admin/texts" | has "href=\"/admin/start\"" && curl -s -b "$J" "$B/admin" | has "Einrichtung prüfen: Bereit für den Start?"'
check "mail: done once an invited account has signed in (the invitation arrived)" '[ "$(rs_check mail)" = done ] && curl -s -b "$J" "$B/admin/start" | has "von termine@example.org"'
RS_LOGINS=$(DBQ "SELECT CONCAT('UPDATE tp_users SET last_login_at = ', QUOTE(last_login_at), ' WHERE id = ', id, ';') FROM tp_users WHERE last_login_at IS NOT NULL AND id <> (SELECT MIN(id) FROM tp_users)")
DBQ "UPDATE tp_users SET last_login_at = NULL WHERE id <> (SELECT m FROM (SELECT MIN(id) AS m FROM tp_users) x)"
check "mail without such a sign-in: please check, how to test it" '[ "$(rs_check mail)" = check ] && curl -s -b "$J" "$B/admin/start" | has "Laden Sie unter „Benutzer“ eine Lehrkraft ein."'
[ -n "$RS_LOGINS" ] && DBQ "$RS_LOGINS"

# Legal texts and the data protection officer: the school's placeholder back in the German privacy policy
RS_PRIV=$(DBQ "SELECT content FROM tp_pages WHERE page_key='privacy' AND lang='de'" | md5sum)
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nDatenschutzbeauftragte: [KONTAKTDATEN DER/DES DATENSCHUTZBEAUFTRAGTEN DER SCHULE]') WHERE page_key='privacy' AND lang='de'"
RS=$(curl -s -b "$J" "$B/admin/start")
check "open placeholder of the data protection officer: both points to do, with the language and the way to the text" \
  '[ "$(rs_check legal)" = todo ] && [ "$(rs_check dpo)" = todo ] && echo "$RS" | has "In der Datenschutzerklärung (DE) fehlen noch die Kontaktdaten" && echo "$RS" | has "href=\"/admin/page/privacy/de\">Datenschutzerklärung bearbeiten"'
DBQ "UPDATE tp_pages SET content = SUBSTRING_INDEX(content, '\n\nDatenschutzbeauftragte: [KONTAKTDATEN', 1) WHERE page_key='privacy' AND lang='de'"
check "placeholder filled: done again (text as before)" '[ "$(rs_check dpo)" = done ] && [ "$(DBQ "SELECT content FROM tp_pages WHERE page_key=\"privacy\" AND lang=\"de\"" | md5sum)" = "$RS_PRIV" ]'

# Cron job: recent run, late, never (an alert for this outage counts as sent just now: no alert mail)
RS_LAST=$(rs_setting last_cron); RS_SINCE=$(rs_setting cron_watch_since); RS_SENT=$(rs_setting cron_alert_sent)
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('cron_alert_sent', '$(date +%s)'), ('last_cron', '$(date +%s)')"
check "cron job with a recent run: done, with its time" '[ "$(rs_check cron)" = done ] && curl -s -b "$J" "$B/admin/start" | has "Zuletzt vollständig gelaufen am $(date +%d.%m.%Y)"'
DBQ "REPLACE INTO tp_settings (name, value) VALUES ('last_cron', '$(date -d '2026-11-01 08:15' +%s)')"
check "cron job late: to do, since when, with what to check" '[ "$(rs_check cron)" = todo ] && R=$(curl -s -b "$J" "$B/admin/start") && echo "$R" | has "Seit dem 01.11.2026" && echo "$R" | has "nicht mehr vollständig gelaufen (Aufgabe der technischen Betreuung)" && echo "$R" | has "php bin/cron.php -v"'
DBQ "DELETE FROM tp_settings WHERE name='last_cron'; REPLACE INTO tp_settings (name, value) VALUES ('cron_watch_since', UNIX_TIMESTAMP() - 86400 * 2)"
check "cron job never run: to do, with the guide" '[ "$(rs_check cron)" = todo ] && curl -s -b "$J" "$B/admin/start" | has "Noch kein vollständiger Lauf verzeichnet."'
rs_restore last_cron "$RS_LAST"; rs_restore cron_watch_since "$RS_SINCE"; rs_restore cron_alert_sent "$RS_SENT"

# Dashboard: the checklist only until the first round has been public, then the warnings as before; remembered,
# so that it does not come back when the rounds of the first day have been deleted
RS_STARTED=$(rs_setting readiness_started)
RS_USED=$(DBQ "SELECT COALESCE(GROUP_CONCAT(id), '0') FROM tp_rounds WHERE was_used = 1")
DBQ "UPDATE tp_rounds SET was_used = 0; DELETE FROM tp_settings WHERE name = 'readiness_started'"
RS_D=$(curl -s -b "$J" "$B/admin")
check "dashboard before the first round went public: the checklist, with the way to the page" \
  'echo "$RS_D" | has "data-readiness-summary" && echo "$RS_D" | has "Bereit für den Start?" && echo "$RS_D" | has "Alle Punkte mit Anleitung ansehen"'
DBQ "UPDATE tp_rounds SET was_used = 1 WHERE id IN ($RS_USED)"
check "dashboard once a round has been public: no checklist" '! curl -s -b "$J" "$B/admin" | has "data-readiness-summary" && [ "$(rs_setting readiness_started)" = 1 ]'
DBQ "UPDATE tp_rounds SET was_used = 0"
check "… also when no such round is left (deleted after the deletion period)" '! curl -s -b "$J" "$B/admin" | has "data-readiness-summary"'
DBQ "UPDATE tp_rounds SET was_used = 1 WHERE id IN ($RS_USED)"
rs_restore readiness_started "$RS_STARTED"

# What's new: accounts from before the update see the notes (teachers only theirs) until they confirm; a new
# installation brings them as nothing new (smoke.sh installed this one), accounts created after the update see none
RS_ARRIVED=$(rs_setting news_arrived); RS_SEEN=$(rs_setting "news_seen:$RS_ADMIN")
RS_CREATED=$(DBQ "SELECT created_at FROM tp_users WHERE id=$RS_ADMIN")
check "new installation: the notes so far are nothing new" '! curl -s -b "$J" "$B/admin" | has "data-whats-new"'
DBQ "DELETE FROM tp_settings WHERE name IN ('news_arrived', 'news_seen:$RS_ADMIN'); UPDATE tp_users SET created_at = '2026-01-01 00:00:00' WHERE id = $RS_ADMIN"
RS_D=$(curl -s -b "$J" "$B/admin")
check "after an update, for an admin from before: the notes, also those for admins, each with its page" \
  'echo "$RS_D" | has "Neu seit dem letzten Update" && echo "$RS_D" | has "eine eigene Antwortadresse für Eltern angeben" && echo "$RS_D" | has "„Bereit für den Start?“ zeigt auf einer Seite" && echo "$RS_D" | has "<a href=\"/admin/profile\">Ansehen</a>"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$RT" "$B/admin/news"
check "confirmed: not shown again" '! curl -s -b "$J" "$B/admin" | has "data-whats-new" && [ "$(rs_setting "news_seen:$RS_ADMIN")" != - ]'
RS_PH=$($DC exec -T app php -r 'echo password_hash("Neuigkeiten-Passwort-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('neu-$RT', '$RS_PH', 'teacher', 'Herr Neu', NULL, 1, '2026-01-01 00:00:00')"
RS_T=$(DBQ "SELECT id FROM tp_users WHERE username='neu-$RT'")
RS_J=$(mktemp)
curl -s -o /dev/null -c "$RS_J" -b "$RS_J" -d "csrf=$(curl -s -c "$RS_J" -b "$RS_J" "$B/admin/login" | csrf)&username=neu-$RT&password=Neuigkeiten-Passwort-1" "$B/admin/login"
RS_D=$(curl -s -b "$RS_J" "$B/admin")
check "for a teacher from before: only the notes for teachers" \
  'echo "$RS_D" | has "data-whats-new" && echo "$RS_D" | has "eine eigene Antwortadresse für Eltern angeben" && ! echo "$RS_D" | has "„Bereit für den Start?“ zeigt"'
DBQ "UPDATE tp_users SET created_at = '2099-01-01 00:00:00' WHERE id = $RS_T"
check "an account created after the update: none of them" '! curl -s -b "$RS_J" "$B/admin" | has "data-whats-new"'
check "checklist page for admins only" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$RS_J" "$B/admin/start")" = 404 ]'
curl -s -o /dev/null -b "$RS_J" --data-urlencode "csrf=$(curl -s -b "$RS_J" "$B/admin" | csrf)" "$B/admin/news"
curl -s -o /dev/null -b "$J" -d "csrf=$RT&confirm=1&notify=0" "$B/admin/users/$RS_T/delete"
check "account deleted: its confirmation of the notes goes with it" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE id=$RS_T")" ] && [ "$(rs_setting "news_seen:$RS_T")" = - ]'
rm -f "$RS_J"
DBQ "UPDATE tp_users SET created_at = '$RS_CREATED' WHERE id = $RS_ADMIN"
rs_restore news_arrived "$RS_ARRIVED"; rs_restore "news_seen:$RS_ADMIN" "$RS_SEEN"

rs_restore operator_type "$RS_TYPE"
check "operated privately: no point for the school's data protection officer" 'rs_restore operator_type private; R=$(rs_check dpo); rs_restore operator_type "$RS_TYPE"; [ -z "$R" ]'
