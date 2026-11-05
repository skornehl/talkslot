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
check "text form offers the templates of both operator types (the one of the type first), none without a template file" 'echo "$PF" | grep -q "href=\"/admin/page/privacy/de?template=school\">Von der Schule betrieben</a> (passend zur Betriebsart) · <a href=\"/admin/page/privacy/de?template=private\">Privat betrieben</a>" && echo "$PF" | grep -q "lit. b und f DSGVO" && curl -s -b "$J" "$B/admin/page/imprint/en" | grep -q "href=\"/admin/page/imprint/en?template=private\">Privat betrieben</a>" && ! curl -s -b "$J" "$B/admin/page/privacy/pl" | grep -q "?template="'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/de?template=private")
check "loading the template \"Privat betrieben\" fills the form, but stores nothing yet" 'echo "$PF" | grep -q "Vorlage „Privat betrieben“ geladen" && echo "$PF" | grep -qF "auf Art. 6 Abs. 1 lit. f DSGVO beruht" && ! curl -s "$B/datenschutz" | grep -qF "lit. f DSGVO" && ! curl -s -b "$J" "$B/admin/page/privacy/de?template=xyz" | grep -q "geladen"'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PT" --data-urlencode "content@$ROOT/app/templates/pages/privacy-private.de.md" "$B/admin/page/privacy/de"
D=$(curl -s -b "$J" "$B/admin")
check "saved with open placeholders: admin notice names them per text (shortened), active rounds stay online" 'echo "$D" | grep -qF "Impressum bzw. Datenschutzerklärung enthalten noch offene Platzhalter in eckigen Klammern – Datenschutzerklärung (DE): [BITTE PRÜFEN UND DANN DIESE KLAMMER L…], [SPEICHERDAUER BEIM HOSTER ERFRAGEN UN…], [HOSTER MIT ANSCHRIFT], [LAND], [ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE M…]. Solange sie nicht ersetzt sind, lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar." && ! echo "$D" | grep -qF "Datenschutzerklärung (EN):" && curl -s -b "$J" "$B/admin/texts" | grep -qF "[SPEICHERDAUER BEIM HOSTER ERFRAGEN UN…]" && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$PLS")" = 200 ]'
NR=$(curl -s -w '\n%{http_code}' -b "$J" --data-urlencode "csrf=$PT" -d "title=Platzhalter+neu&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new")
check "new active round refused with the reason, nothing created" '[ "$(echo "$NR" | tail -1)" = 200 ] && echo "$NR" | grep -qF "ersetzen Sie bitte unter „Texte“ die offenen Platzhalter in eckigen Klammern: Datenschutzerklärung (DE)." && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE title=\"Platzhalter neu\"")" ]'
TG=$(curl -s -L -b "$J" -d "csrf=$PT" "$B/admin/round/$PLI/toggle")
check "activating an inactive round refused (admin)" 'echo "$TG" | grep -qF "offenen Platzhalter in eckigen Klammern: Datenschutzerklärung (DE)." && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLI")" = 0 ]'
TG=$(curl -s -L -c "$JP" -b "$JP" -d "csrf=$(curl -s -c "$JP" -b "$JP" "$B/admin" | csrf)" "$B/admin/round/$PLR/toggle")
check "teacher: notice on the overview, activation refused with a matching message" 'curl -s -c "$JP" -b "$JP" "$B/admin" | grep -q "enthalten noch offene Platzhalter (Aufgabe der Admins)" && echo "$TG" | grep -q "wenn ein Admin die offenen Platzhalter in Impressum bzw. Datenschutzerklärung ausgefüllt hat" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$PLR")" = 0 ]'
# Language that is not offered: parents never see it, so it does not block
DBQ "INSERT INTO tp_pages (page_key, lang, content, updated_at) VALUES ('privacy', 'fr', '[ADRESSE]', NOW())"
check "only the texts of offered languages count" '[ "$(DBQ "SELECT COUNT(*) FROM tp_pages WHERE lang=\"fr\"")" = 1 ] && D=$(curl -s -b "$J" "$B/admin") && echo "$D" | grep -qF "Datenschutzerklärung (DE): [BITTE PRÜFEN" && ! echo "$D" | grep -qF "(FR):"'
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

echo "Language choice for parents"
# Own rounds (fresh slots), one per setting for multiple bookings; removed again at the end
T=$(curl -s -b "$J" "$B/admin" | csrf)
IVR=$(mkround Einladung+Runde round); IVD=$(mkround Einladung+Tag day); IVN=$(mkround Einladung+frei none)
IVS=$(slugof "$IVR")
LANGP=$(curl -s -D - -H "Accept-Language: tr-TR,tr;q=0.9,de;q=0.8" "$B/$IVS")
check "language names in the header, above the heading (booking form: buttons that keep the input)" 'echo "$LANGP" | tr -d "\n" | grep -q "<header.*class=\"lang-switch\".*lang=\"tr\">Türkçe</button>.*</header>.*<h1>" && [ "$(echo "$LANGP" | grep -c "^ *<button type=\"submit\" form=\"booking-form\" name=\"switch_lang\" value=\"[a-z]*\" formnovalidate class=\"button-link\" lang=\"[a-z]*\"\( aria-current=\"true\"\)\?>[^<]*</button>$")" = 6 ] && ! echo "$LANGP" | grep -q "flags/"'
check "Enter books: an invisible plain submit button of the booking form comes before the language buttons" '[ "$(echo "$LANGP" | grep -n "data-default-submit" | cut -d: -f1)" -lt "$(echo "$LANGP" | grep -n "name=\"switch_lang\"" | head -1 | cut -d: -f1)" ]'
check "browser language Turkish: page stays German, a button in Turkish is offered, no cookie, Vary" 'echo "$LANGP" | grep -q "<html lang=\"de\">" && echo "$LANGP" | grep -q "<div class=\"alert alert-info lang-hint\" lang=\"tr\"><button type=\"submit\" form=\"booking-form\" name=\"switch_lang\" value=\"tr\" formnovalidate class=\"button-link\" lang=\"tr\">Bu sayfayı Türkçe görüntüle</button></div>" && ! echo "$LANGP" | grep -qi "^set-cookie" && echo "$LANGP" | grep -qi "^vary: accept-language"'
HINT=$(curl -s -H "Accept-Language: fr-FR,fr;q=0.9,en;q=0.8,de;q=0.7" "$B/" | grep -o '<a href="[^"]*" hreflang="en" lang="en">View this page in English</a>' | sed 's/<a href="//;s/".*//')
check "home page: the first offered browser language is linked in that language and opens the page in it ($HINT)" '[ "$HINT" = "/?lang=en" ] && curl -s "$B$HINT" | grep -q "<html lang=\"en\">"'
check "hint only while the page is shown in another language than the preferred one" 'curl -s -H "Accept-Language: tr" "$B/$IVS" | grep -q "lang-hint" && ! curl -s -H "Accept-Language: de-DE,de;q=0.9,en;q=0.8" "$B/" | grep -q "lang-hint" && ! curl -s -H "Accept-Language: tr" "$B/$IVS?lang=tr" | grep -q "lang-hint" && ! curl -s -H "Accept-Language: tr" "$B/$IVS?lang=en" | grep -q "lang-hint"'
SWH=$(curl -s -w '\n%{http_code}' -H "Accept-Language: uk" --data-urlencode "csrf=$(echo "$LANGP" | csrf)" -d "lang=de&switch_lang=uk&name=Olena+Movna&privacy=1" "$B/$IVS")
check "switching via the hint keeps the input and books nothing" '[ "$(echo "$SWH" | tail -1)" = 200 ] && echo "$SWH" | grep -q "<html lang=\"uk\">" && echo "$SWH" | grep -q "value=\"Olena Movna\"" && echo "$SWH" | grep -q "value=\"uk\" formnovalidate class=\"button-link\" lang=\"uk\" aria-current=\"true\">Українська</button>" && ! echo "$SWH" | grep -q "lang-hint"'

# Invitation text: direct link per further language, sentence on multiple bookings as on the round page
inv() { curl -s -b "$J" "$B/admin/round/$1" | sed -n '/<pre class="invitation">/,/<\/pre>/p'; }
INV=$(inv "$IVR")
check "invitation: one line per further language with its name and a direct link" 'echo "$INV" | grep -qx "English: $B/$IVS?lang=en" && echo "$INV" | grep -qx "Polski: $B/$IVS?lang=pl" && echo "$INV" | grep -qx "Русский: $B/$IVS?lang=ru" && echo "$INV" | grep -qx "Українська: $B/$IVS?lang=uk" && echo "$INV" | grep -qx "Türkçe: $B/$IVS?lang=tr" && ! echo "$INV" | grep -q "lang=de"'
check "invitation: the language links are in the mail link too" 'curl -s -b "$J" "$B/admin/round/$IVR" | grep -o "href=\"mailto:[^\"]*\"" | grep -q "T%C3%BCrk%C3%A7e%3A%20http[^\"]*%3Flang%3Dtr"'
check "invitation: one appointment per person → the sibling rule of the round page" 'echo "$INV" | grep -q "^Pro Person ist nur ein Termin möglich. Für Geschwister buchen Sie bitte jeweils mit dem Namen des Kindes. Nach der Buchung erhalten Sie" && ! echo "$INV" | grep -q "Bitte buchen Sie nur einen Termin" && curl -s "$B/$IVS" | grep -q "Pro Person ist nur ein Termin möglich. Für Geschwister"'
check "invitation: one per day and no limit → the sentence of the respective round page" 'inv "$IVD" | grep -q "^Pro Person ist nur ein Termin pro Tag möglich. Für Geschwister buchen Sie bitte jeweils mit dem Namen des Kindes. Nach der Buchung" && inv "$IVN" | grep -q "^Bitte buchen Sie nur einen Termin. Nach der Buchung" && curl -s "$B/$(slugof "$IVN")" | grep -q ">Bitte buchen Sie nur einen Termin.<"'
for r in $IVR $IVD $IVN; do curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$r/delete"; done
check "test rounds of the invitation removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id IN ($IVR, $IVD, $IVN)")" = 0 ]'

echo "Parents and changes by the teacher (admin notices)"
# Deleting bookings, days, rounds and accounts, and a new date, location or teacher, mail the parents only with
# the box "Eltern per E-Mail informieren" ticked and only those who can be mailed (tests/smoke.d/notifications.sh);
# otherwise dialogs, hints and messages say that they are NOT notified. These requests leave the box unticked.
# The mail option of an entry is ticked by default, and saving an entry with it sends the current details
# (also without a change, e.g. after a new date).
T=$(curl -s -b "$J" "$B/admin" | csrf)
PRUN=$(date +%s)
PM="hinweis-$PRUN@example.org"
pmails() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$1"'"),true)["messages_count"] ?? "?";'; }
# Text of the newest mail to $1
pmailtext() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$1"'"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";'; }
oneline() { tr '\n' '~'; }   # dialogs contain line breaks
PD1=$(date -d '+40 day' +%F); PD2=$(date -d '+41 day' +%F); PD3=$(date -d '+42 day' +%F)
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweistest&location=Raum+1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$PD1&day_dates%5B%5D=$PD2&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
RN=$(echo "$loc" | grep -o '[0-9]*$')
PG=$(curl -s -b "$J" "$B/admin/round/$RN")
check "round without bookings: delete dialog without a notice about parents" '[ -n "$RN" ] && echo "$PG" | has "Runde „Hinweistest“ mit allen Tagen und Buchungen endgültig löschen?\"" && ! echo "$PG" | has "NICHT automatisch"'
PS=($(echo "$PG" | grep -o '/admin/slot/[0-9]*/book' | grep -o '[0-9]\+'))
PDAYS=($(echo "$PG" | grep -o 'id="day-[0-9]*"' | grep -o '[0-9]\+'))

# Manual entries: mail ticked by default, also after an error without an address; a choice made by hand stays
check "new entry: mail option ticked by default" 'curl -s -b "$J" "$B/admin/slot/${PS[0]}/book" | has "name=\"send_mail\" value=\"1\" data-mail-for=\"email\" checked"'
check "new entry after an error: ticked while no address is entered, unticked by hand stays unticked" 'curl -s -b "$J" -d "csrf=$T&name=&lang=de" "$B/admin/slot/${PS[0]}/book" | has "data-mail-for=\"email\" checked" && ! curl -s -b "$J" -d "csrf=$T&name=&email=$PM&lang=de" "$B/admin/slot/${PS[0]}/book" | has "data-mail-for=\"email\" checked"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=de&send_mail=1" "$B/admin/slot/${PS[0]}/book"
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Ohne+Mail&lang=de" "$B/admin/slot/${PS[1]}/book"
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Tag+Zwei&email=tag2-$PRUN@example.org&lang=de" "$B/admin/slot/${PS[4]}/book"
PBA=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hinweis Mit Mail'")
PBB=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hinweis Ohne Mail'")
check "edit entry: mail option ticked by default" '[ "$(pmails "$PM")" = 1 ] && curl -s -b "$J" "$B/admin/booking/$PBA/edit" | has "data-mail-for=\"email\" checked"'
check "edit form: the mail also goes out without a change here (hint)" 'curl -s -b "$J" "$B/admin/booking/$PBA/edit" | has "aria-describedby=\"mail-hint\"" && curl -s -b "$J" "$B/admin/booking/$PBA/edit" | has "geht auch ohne Änderung hier hinaus, z. B. nachdem Datum oder Ort des Tages geändert wurden."'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=de" "$B/admin/booking/$PBA/edit")
check "saving an entry with the mail unticked sends none" 'echo "$R" | has "Eintrag gespeichert." && ! echo "$R" | has "per E-Mail mitgeteilt" && [ "$(pmails "$PM")" = 1 ]'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=de&send_mail=1" "$B/admin/booking/$PBA/edit")
check "saving an entry unchanged with the mail ticked sends the current details" 'echo "$R" | has "Eintrag gespeichert. Änderung per E-Mail mitgeteilt." && [ "$(pmails "$PM")" = 2 ]'
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hinweis+Mit+Mail&email=$PM&lang=en&send_mail=1" "$B/admin/booking/$PBA/edit")
check "a real change is mailed" 'echo "$R" | has "Änderung per E-Mail mitgeteilt" && [ "$(pmails "$PM")" = 3 ]'

# Delete dialogs: box ticked (data-confirm) and unticked (data-confirm-off); with and without an address, an
# entry whose parents never got a mail, day and round with the number of bookings
PG=$(curl -s -b "$J" "$B/admin/round/$RN" | oneline)
check "delete entry whose parents got a mail: box ticked → cancellation by mail, unticked → NOT notified" 'echo "$PG" | has "data-confirm=\"Buchung von „Hinweis Mit Mail“ löschen? Der Termin wird wieder frei.~~Die Eltern erhalten eine Absage per E-Mail.\" data-confirm-off=\"Buchung von „Hinweis Mit Mail“ löschen? Der Termin wird wieder frei.~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren.\""'
check "delete entry without address: please inform in person, no box" 'echo "$PG" | has "data-confirm=\"Buchung von „Hinweis Ohne Mail“ löschen? Der Termin wird wieder frei.~~Die Eltern werden NICHT automatisch benachrichtigt. Für diesen Eintrag ist keine E-Mail-Adresse hinterlegt – bitte persönlich informieren.\">"'
check "delete entry by the teacher that never got a mail: not notified, please inform in person, no box" 'echo "$PG" | has "data-confirm=\"Buchung von „Hinweis Tag Zwei“ löschen? Der Termin wird wieder frei.~~Die Eltern haben zu diesem Eintrag nie eine E-Mail erhalten und werden deshalb nicht benachrichtigt – bitte persönlich informieren.\">"'
check "the box only where a mail can go: one entry, its day and the round" '[ "$(echo "$PG" | grep -o "<input type=\"checkbox\" name=\"notify\" value=\"1\" checked> Eltern per E-Mail informieren" | wc -l)" = 3 ]'
check "delete day: number of bookings, a cancellation for the one with an address, the other in person; unticked NOT notified, print view first" 'echo "$PG" | has "Diesen Tag mit allen Terminen und 2 Buchung(en) löschen?~~Die Eltern von 1 Buchung(en) erhalten eine Absage per E-Mail. 1 Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren; die Namen stehen danach in der Meldung.\" data-confirm-off=\"Diesen Tag mit allen Terminen und 2 Buchung(en) löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Vorher in der Druckansicht nachsehen, wer gebucht hat – nach dem Löschen ist das nicht mehr möglich.\""'
check "delete day where nobody can be mailed: NOT notified with the box ticked or not" 'echo "$PG" | has "data-confirm=\"Diesen Tag mit allen Terminen und 1 Buchung(en) löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Vorher in der Druckansicht nachsehen, wer gebucht hat – nach dem Löschen ist das nicht mehr möglich.\""'
check "delete round: number of bookings, who gets a cancellation; unticked NOT notified" 'echo "$PG" | has "Runde „Hinweistest“ mit allen Tagen und 3 Buchung(en) endgültig löschen?~~Die Eltern von 1 Buchung(en) erhalten eine Absage per E-Mail. 2 Buchung(en) ohne E-Mail an die Eltern" && echo "$PG" | has "data-confirm-off=\"Runde „Hinweistest“ mit allen Tagen und 3 Buchung(en) endgültig löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Vorher in der Druckansicht nachsehen"'

# Changing date or location: notice in the forms, message afterwards (only if booked parents are affected)
check "edit day: the parents with an address get the new details (box ticked), the number of the others" 'P=$(curl -s -b "$J" "$B/admin/day/${PDAYS[0]}/edit" | oneline); echo "$P" | has "An diesem Tag sind 2 Termin(e) gebucht. Ändern sich Datum, Uhrzeit oder Ort eines Termins, erhalten die Eltern die neuen Daten per E-Mail, solange „Eltern per E-Mail informieren“ angehakt ist. 1 Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren." && echo "$P" | has "<input type=\"checkbox\" id=\"notify\" name=\"notify\" value=\"1\" checked>" && echo "$P" | has "Nur ausfüllen, wenn der Tag nicht am Ort der Runde stattfindet.</p>" && ! echo "$P" | has "NICHT automatisch"'
check "edit day where nobody can be mailed: NOT notified, no box" 'P=$(curl -s -b "$J" "$B/admin/day/${PDAYS[1]}/edit"); echo "$P" | has "An diesem Tag sind 1 Termin(e) gebucht. Die Eltern werden über Änderungen an Datum, Zeiten oder Ort NICHT automatisch benachrichtigt" && ! echo "$P" | has "name=\"notify\""'
check "edit round: box for booked parents with the number of the others (not for a new round)" 'P=$(curl -s -b "$J" "$B/admin/round/$RN/edit" | oneline); echo "$P" | has "name=\"notify\" value=\"1\" checked aria-describedby=\"notify-hint\">" && echo "$P" | has "Ändern sich Ort oder Lehrkraft der Runde, erhalten die Eltern gebuchter Termine die neuen Daten per E-Mail in ihrer Sprache. 2 Buchung(en) ohne E-Mail an die Eltern" && ! echo "$P" | has "Bereits gebuchte Eltern" && ! curl -s -b "$J" "$B/admin/round/new" | has "name=\"notify\""'
pday() { curl -s -L -b "$J" -d "csrf=$T&day_date=$2&start_time=${4:-14:00}&end_time=15:00&slot_minutes=15" --data-urlencode "location=$3" "$B/admin/day/$1/edit"; }
# The round page shows the delete dialogs ("werden NICHT …"); the message after saving says "wurden NICHT …"
check "day saved unchanged: no message about parents" 'R=$(pday "${PDAYS[0]}" "$PD1" ""); echo "$R" | has "Tag gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "day gets the location of the round as its own: same place, no message" 'R=$(pday "${PDAYS[0]}" "$PD1" "Raum 1"); echo "$R" | has "Tag gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "new location of a day, box unticked: parents of its bookings NOT notified, how to send them the new details" 'pday "${PDAYS[0]}" "$PD1" "Raum 2" | has "Die Eltern von 2 Buchung(en) wurden NICHT automatisch benachrichtigt – bitte selbst informieren. Eltern mit E-Mail-Adresse erhalten die neuen Daten in ihrer Sprache, wenn Sie ihren Eintrag bearbeiten und mit „Änderung per E-Mail mitteilen“ speichern."'
check "new date of a day: parents NOT notified" 'pday "${PDAYS[1]}" "$PD3" "" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt"'
# … and how they are told: the entry saved unchanged with the mail ticked sends the new date
PT2="tag2-$PRUN@example.org"
PBT=$(DBQ "SELECT id FROM tp_bookings WHERE name='Hinweis Tag Zwei'")
R=$(curl -s -L -b "$J" -d "csrf=$T&name=Hinweis+Tag+Zwei&email=$PT2&lang=de&send_mail=1" "$B/admin/booking/$PBT/edit")
PTXT=$(pmailtext "$PT2")
# "Neuer Termin: <weekday>, <d.m.Y>, 14:00–14:15 Uhr"
check "after a new date: entry saved unchanged with the mail ticked → mail with the new date" '[ -n "$PBT" ] && echo "$R" | has "Änderung per E-Mail mitgeteilt." && [ "$(pmails "$PT2")" = 1 ] && echo "$PTXT" | grep "^Neuer Termin: " | has ", $(date -d "$PD3" +%d.%m.%Y), 14:00"' \
  || echo "$PTXT" | head -8
pround() { curl -s -L -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweistest&booking_deadline_hours=0&cancel_deadline_hours=0" --data-urlencode "location=$1" "$B/admin/round/$RN/edit"; }
check "new location of the round: only bookings on days without their own location count" 'pround "Aula" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt – bitte selbst informieren. Eltern mit E-Mail-Adresse erhalten die neuen Daten"'
check "round saved with the same location: no message" 'R=$(pround "Aula"); echo "$R" | has "Runde gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "conflict after a change: the parents learn of it when the teacher moves or deletes the booking" 'pday "${PDAYS[1]}" "$PD3" "" 14:30 | has "als Konflikt markiert. Bitte umbuchen oder löschen. Die Eltern erfahren davon, wenn Sie den Termin umbuchen (mit „Änderung per E-Mail mitteilen“) oder löschen (mit „Eltern per E-Mail informieren“)" && curl -s -b "$J" "$B/admin/round/$RN" | has "Bitte umbuchen oder löschen. Die Eltern erfahren davon, wenn Sie den Termin umbuchen"'

# After deleting: message with the name (the dialog needs JavaScript)
check "entry deleted with the box unticked: parents NOT notified, named with their address (no longer shown anywhere)" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/booking/$PBA/delete" | has "Die Eltern („Hinweis Mit Mail“, $PM) wurden NICHT automatisch benachrichtigt – bitte selbst informieren."'
check "entry without address deleted: please inform in person" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/booking/$PBB/delete" | has "Die Eltern („Hinweis Ohne Mail“) wurden NICHT automatisch benachrichtigt; es war keine E-Mail-Adresse hinterlegt – bitte persönlich informieren."'
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Drei&lang=de" "$B/admin/slot/${PS[2]}/book"
check "day with a booking deleted: parents NOT notified" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/day/${PDAYS[0]}/delete" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt"'
check "round with a booking deleted: parents NOT notified" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$RN/delete" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$RN")" ]'

# Deleting an account with bookings
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Hinweis&email=lk-$PRUN@example.org&role=teacher" "$B/admin/users/new"
PU=$(DBQ "SELECT id FROM tp_users WHERE username='lk-$PRUN@example.org'")
check "account without bookings: delete dialog without a notice about parents" '[ -n "$PU" ] && ! curl -s -b "$J" "$B/admin/users/$PU/edit" | has "NICHT automatisch"'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweis+Konto&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$PD1&start_time=14:00&end_time=15:00&slot_minutes=15" "$B/admin/round/new")
RK=$(echo "$loc" | grep -o '[0-9]*$')
curl -s -o /dev/null -b "$J" -d "csrf=$T&name=Hinweis+Konto&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$RK" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
# The mails name the teacher: handing a round with bookings over is a change the parents are not told about
check "round with a booking handed to another teacher: parents NOT notified" 'curl -s -L -b "$J" --data-urlencode "csrf=$T" -d "title=Hinweis+Konto&user_id=$PU&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$RK/edit" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt – bitte selbst informieren. Eltern mit E-Mail-Adresse erhalten die neuen Daten" && [ "$(DBQ "SELECT user_id FROM tp_rounds WHERE id=$RK")" = "$PU" ]'
check "account with bookings: delete dialog says parents are NOT notified" 'curl -s -b "$J" "$B/admin/users/$PU/edit" | oneline | has "mit allen Runden und Buchungen endgültig löschen?~~Die Eltern von 1 Buchung(en) werden NICHT automatisch benachrichtigt – bitte selbst informieren.\""'
check "account deleted: parents NOT notified" 'curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$PU/delete" | has "Die Eltern von 1 Buchung(en) wurden NICHT automatisch benachrichtigt" && [ -z "$(DBQ "SELECT id FROM tp_users WHERE id=$PU")" ]'

echo "Appointment page: keeping the link (copy, calendar file, confirmation by mail)"
# Booking without an e-mail address, remembered on this device; own round, removed again at the end
T=$(curl -s -b "$J" "$B/admin" | csrf)
KL=$(mkround Link+behalten none); KLS=$(slugof "$KL")
KP=$(curl -s "$B/$KLS")
KLOC=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$KP" | csrf)" -d "slot_id=$(echo "$KP" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')&name=Link+Behalten&privacy=1&remember=1" "$B/$KLS")
KT=$(echo "$KLOC" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
KM=$(curl -s "$KLOC"); KMC=$(echo "$KM" | csrf)
KD=$(DBQ "SELECT d.day_date FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id WHERE b.name = 'Link Behalten'")
check "booked without e-mail: copy and calendar buttons, change and cancel folded away, remembered with the date" '[ ${#KT} = 43 ] && echo "$KM" | has "hidden data-copy=\"$B/b/$KT\" data-copy-done=\"Kopiert ✓\"" && echo "$KM" | has "<input type=\"hidden\" name=\"action\" value=\"ics\">" && echo "$KM" | has "<details class=\"card\" id=\"cancel\">" && echo "$KM" | has "<details class=\"card\" id=\"change\">" && echo "$KM" | has "data-remember-round=\"$KL\"" && echo "$KM" | has "data-remember-date=\"$KD\""'
unfold() { tr -d '\r' | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n //g'; }   # iCalendar continuation lines
KI=$(curl -s -D - --data-urlencode "csrf=$KMC" -d "action=ics" "$B/b/$KT" | unfold)
check "calendar file by POST: download with the appointment and the personal link, no cookie" 'echo "$KI" | grep -qi "^content-type: text/calendar; charset=utf-8" && echo "$KI" | grep -qi "^content-disposition: attachment; filename=\"termin.ics\"" && echo "$KI" | has "BEGIN:VCALENDAR" && echo "$KI" | has "SUMMARY:Link behalten" && echo "$KI" | has "DESCRIPTION:Termin ändern oder absagen: $B/b/$KT?lang=de" && ! echo "$KI" | grep -qi "^set-cookie"'
check "calendar file in the language of the page" 'curl -s --data-urlencode "csrf=$KMC" -d "action=ics" "$B/b/$KT?lang=en" | unfold | has "DESCRIPTION:Change or cancel: $B/b/$KT?lang=en"'
check "calendar file only with a valid form token, otherwise the page with the error" 'X=$(curl -s -D - -d "csrf=1700000000.x&action=ics" "$B/b/$KT"); echo "$X" | grep -qi "^content-type: text/html" && echo "$X" | has "Das Formular ist abgelaufen" && ! echo "$X" | has "BEGIN:VCALENDAR" && curl -s --data-urlencode "csrf=$KMC" -d "action=ics" "$B/b/$KT" | has "BEGIN:VCALENDAR"'
# With the usual minimum time of a form (bot brake), the calendar file still comes right after loading the page.
# Without a sender address no mail goes out: the page says so (the attempt counts against the limit).
# Script on stdin (not php -r), so that a coverage run sees these lines; arguments: app root, token
KF=$($DC exec -T app php -- /srv/app "$KT" 2>&1 <<'PHP'
<?php
[, $root, $token] = $argv;
require "$root/app/bootstrap.php";
App\Config::load(["csrf_min_age_seconds" => 30] + (require "$root/config/config.php"));
$id = (int) App\Db::value("SELECT id FROM bookings WHERE name = ?", ["Link Behalten"]);
App\I18n::set("de");
$_SERVER["REMOTE_ADDR"] = "198.51.100.30"; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REQUEST_URI"] = "/b/$token";
$send = static function (array $post) use ($token): string {
    $_POST = $post;
    ob_start();
    (new App\Controller\PublicController())->dispatch("/b/$token", "POST");
    return (string) ob_get_clean();
};
$ics = $send(["csrf" => App\Csrf::publicToken("manage:" . $id), "action" => "ics"]);
$cancel = $send(["csrf" => App\Csrf::publicToken("manage:" . $id), "action" => "cancel", "confirm" => "1"]);
App\Config::load(["mail" => ["transport" => "smtp", "from_address" => ""]] + (require "$root/config/config.php"));
$mail = $send(["csrf" => App\Csrf::publicToken("manage:" . $id), "action" => "mail", "email" => "ohne-absender@example.org"]);
echo (str_starts_with($ics, "BEGIN:VCALENDAR") ? "ics" : "no-ics") . "|" . (str_contains($cancel, "Das ging zu schnell") ? "too-fast" : "?")
    . "|" . (str_contains($mail, "Die E-Mail konnte nicht gesendet werden.") ? "mail-failed" : "?");
App\Db::run("DELETE FROM rate_limits WHERE bucket IN (?, ?)", ["manage", "confirm"]);
PHP
)
check "calendar file without the minimum time of a form, cancelling still with it; a mail that cannot be sent is reported ($KF)" '[ "$KF" = "ics|too-fast|mail-failed" ]'

# Confirmation by mail afterwards: to an address entered on the page, not stored, at most twice
KMAIL="kopie-$(date +%s)@example.org"
X=$(curl -s --data-urlencode "csrf=$KMC" -d "action=mail&email=kaputt" "$B/b/$KT")
check "confirmation by mail: invalid address → message, the form stays open with the input" 'echo "$X" | has "Die E-Mail-Adresse ist ungültig." && echo "$X" | has "<details class=\"card\" id=\"confirm-mail\" open>" && echo "$X" | has "value=\"kaputt\"" && [ "$(pmails "$KMAIL")" = 0 ]'
KR=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf=$KMC" --data-urlencode "email=$KMAIL" -d "action=mail" "$B/b/$KT")
check "confirmation by mail: sent, back on the page with a message" '[ "$KR" = "303 $B/b/$KT?done=sent" ] && curl -s "${KR#* }" | has "Die Bestätigung wurde an die angegebene E-Mail-Adresse gesendet." && [ "$(pmails "$KMAIL")" = 1 ]'
KTXT=$(pmailtext "$KMAIL")
check "the mail: confirmation with the personal link and calendar file, the footer says where the address came from" 'echo "$KTXT" | has "Ihr Termin wurde gebucht." && echo "$KTXT" | has "$B/b/$KT?lang=de" && echo "$KTXT" | has "weil diese Adresse auf der Seite Ihres Termins eingegeben wurde. Die Adresse wird in der Terminvergabe nicht gespeichert." && $DC exec -T app php -r "echo file_get_contents(\"http://mail:8025/api/v1/search?query=to:$KMAIL\");" | has "\"Attachments\":1"'
check "… and the address is not stored with the booking" 'echo "$KTXT" | has "Ihr Termin wurde gebucht." && [ "$(DBQ "SELECT COALESCE(email, \"-\") FROM tp_bookings WHERE name = \"Link Behalten\"")" = - ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE email = \"$KMAIL\"")" = 0 ]'
KR=$(curl -s -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$KMC" --data-urlencode "email=$KMAIL" -d "action=mail" "$B/b/$KT?lang=en")
check "second time from the English page: the mail in English" '[ "$KR" = 303 ] && [ "$(pmails "$KMAIL")" = 2 ] && pmailtext "$KMAIL" | has "your appointment has been booked" && pmailtext "$KMAIL" | has "because this address was entered on the page of your appointment"'
X=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$KMC" --data-urlencode "email=$KMAIL" -d "action=mail" "$B/b/$KT")
check "at most twice per booking within 24 hours (no mail relay): the third one is refused" '[ "$(echo "$X" | tail -1)" = 429 ] && echo "$X" | has "schon zu oft gesendet" && [ "$(pmails "$KMAIL")" = 2 ]'

# After the deadline for changes: calendar file and confirmation still work, cancelling does not
DBQ "UPDATE tp_rounds SET cancel_deadline_hours = 10000 WHERE id = $KL; DELETE FROM tp_rate_limits WHERE bucket = 'confirm'"
KM2=$(curl -s "$B/b/$KT")
check "after the deadline: calendar file and confirmation mail are still offered and work" 'echo "$KM2" | has "Änderungen und Absagen sind online nur bis" && ! echo "$KM2" | has "value=\"cancel\"" && echo "$KM2" | has "value=\"ics\"" && echo "$KM2" | has "id=\"confirm-mail\"" && curl -s --data-urlencode "csrf=$KMC" -d "action=ics" "$B/b/$KT" | has "BEGIN:VCALENDAR" && [ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$KMC" --data-urlencode "email=$KMAIL" -d "action=mail" "$B/b/$KT")" = 303 ] && [ "$(pmails "$KMAIL")" = 3 ]'
DBQ "UPDATE tp_rounds SET cancel_deadline_hours = 0 WHERE id = $KL; DELETE FROM tp_rate_limits WHERE bucket = 'confirm'"

# Round page: one item per remembered booking, "book another" keeps the entries; the form stays open after a submission
KP2=$(curl -s "$B/$KLS")
check "round page: list for remembered bookings and the sibling button; the form stays open after a language switch" 'echo "$KP2" | has "<ul class=\"remembered-list\" data-remembered-list></ul>" && echo "$KP2" | has "hidden data-remembered-another>Weiteren Termin buchen" && echo "$KP2" | has "<div id=\"booking-area\">" && curl -s --data-urlencode "csrf=$(echo "$KP2" | csrf)" -d "lang=de&switch_lang=en&name=Geschwister" "$B/$KLS" | has "<div id=\"booking-area\" data-keep-open>"'
check "unknown action: the page again, the booking unchanged" 'X=$(curl -s -w "\n%{http_code}" --data-urlencode "csrf=$KMC" -d "action=gibts-nicht" "$B/b/$KT"); [ "$(echo "$X" | tail -1)" = 200 ] && echo "$X" | has "value=\"$B/b/$KT\"" && ! echo "$X" | has "form-errors" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name = \"Link Behalten\"")" = 1 ]'
KC=$(curl -s --data-urlencode "csrf=$KMC" -d "action=cancel&confirm=1" "$B/b/$KT")
X=$(curl -s -w '\n%{http_code}' "$B/b/$KT")
check "cancelled, and the link leading nowhere afterwards: both remove the remembered entry of this link" 'echo "$KC" | has "<div hidden data-forget-url=\"/b/$KT\"></div>" && [ "$(echo "$X" | tail -1)" = 404 ] && echo "$X" | has "<div hidden data-forget-url=\"/b/$KT\"></div>" && ! curl -s "$B/gibt-es-nicht" | has "data-forget-url"'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$KL/delete"
check "test round of the appointment page removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $KL")" = 0 ]'
