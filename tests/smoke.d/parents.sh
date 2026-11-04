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
check "only the texts of offered languages count" '[ "$(DBQ "SELECT COUNT(*) FROM tp_pages WHERE lang=\"fr\"")" = 1 ] && D=$(curl -s -b "$J" "$B/admin") && echo "$D" | grep -qF "Datenschutzerklärung (DE): [KONTAKTDATEN" && ! echo "$D" | grep -qF "(FR):"'
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

echo "Parents are not notified automatically (admin notices)"
# Deleting bookings, days, rounds and accounts, and a new date, location or teacher, send nothing to the
# parents (yet): dialogs, hints and messages say so. The mail option of an entry is ticked by default, and
# saving an entry with it sends the current details (also without a change, e.g. after a new date).
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

# Delete dialogs: with and without an address, day and round with the number of bookings
PG=$(curl -s -b "$J" "$B/admin/round/$RN" | oneline)
check "delete entry with address: parents are NOT notified" 'echo "$PG" | has "Eintrag von „Hinweis Mit Mail“ löschen? Der Slot wird wieder frei.~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren.\""'
check "delete entry without address: please inform in person" 'echo "$PG" | has "Eintrag von „Hinweis Ohne Mail“ löschen? Der Slot wird wieder frei.~~Die Eltern werden NICHT automatisch benachrichtigt. Für diesen Eintrag ist keine E-Mail-Adresse hinterlegt – bitte persönlich informieren.\""'
check "delete day: number of bookings, parents NOT notified, print view first" 'echo "$PG" | has "Diesen Tag mit allen Slots und 2 Buchung(en) löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Vorher in der Druckansicht nachsehen, wer gebucht hat – nach dem Löschen ist das nicht mehr möglich.\""'
check "delete round: number of bookings, parents NOT notified" 'echo "$PG" | has "Runde „Hinweistest“ mit allen Tagen und 3 Buchung(en) endgültig löschen?~~Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren. Vorher in der Druckansicht nachsehen"'

# Changing date or location: notice in the forms, message afterwards (only if booked parents are affected)
check "edit day: notice with the number of bookings, location hint" 'P=$(curl -s -b "$J" "$B/admin/day/${PDAYS[0]}/edit"); echo "$P" | has "An diesem Tag sind 2 Termin(e) gebucht. Die Eltern werden über Änderungen an Datum, Zeiten oder Ort NICHT automatisch benachrichtigt" && echo "$P" | has "Nur ausfüllen, wenn der Tag nicht am Ort der Runde stattfindet. Bereits gebuchte Eltern werden bei einer Änderung NICHT automatisch benachrichtigt."'
check "edit round: location hint about booked parents (not for a new round)" 'curl -s -b "$J" "$B/admin/round/$RN/edit" | has "Bereits gebuchte Eltern werden bei einer Änderung NICHT automatisch benachrichtigt." && ! curl -s -b "$J" "$B/admin/round/new" | has "Bereits gebuchte Eltern"'
pday() { curl -s -L -b "$J" -d "csrf=$T&day_date=$2&start_time=${4:-14:00}&end_time=15:00&slot_minutes=15" --data-urlencode "location=$3" "$B/admin/day/$1/edit"; }
# The round page shows the delete dialogs ("werden NICHT …"); the message after saving says "wurden NICHT …"
check "day saved unchanged: no message about parents" 'R=$(pday "${PDAYS[0]}" "$PD1" ""); echo "$R" | has "Tag gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "day gets the location of the round as its own: same place, no message" 'R=$(pday "${PDAYS[0]}" "$PD1" "Raum 1"); echo "$R" | has "Tag gespeichert." && ! echo "$R" | has "wurden NICHT automatisch"'
check "new location of a day: parents of its bookings NOT notified, how to send them the new details" 'pday "${PDAYS[0]}" "$PD1" "Raum 2" | has "Die Eltern von 2 Buchung(en) wurden NICHT automatisch benachrichtigt – bitte selbst informieren. Eltern mit E-Mail-Adresse erhalten die neuen Daten in ihrer Sprache, wenn Sie ihren Eintrag bearbeiten und mit „Änderung per E-Mail mitteilen“ speichern."'
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
check "conflict after a change: rebooking does not inform parents by itself" 'pday "${PDAYS[1]}" "$PD3" "" 14:30 | has "als Konflikt markiert. Bitte umbuchen oder löschen. Die Eltern werden dabei NICHT automatisch benachrichtigt: Beim Umbuchen „Änderung per E-Mail mitteilen“ angehakt lassen" && curl -s -b "$J" "$B/admin/round/$RN" | has "Bitte umbuchen oder löschen. Die Eltern werden dabei NICHT automatisch benachrichtigt"'

# After deleting: message with the name (the dialog needs JavaScript)
check "entry deleted: parents NOT notified, named with their address (no longer shown anywhere)" 'curl -s -L -b "$J" -d "csrf=$T" "$B/admin/booking/$PBA/delete" | has "Die Eltern („Hinweis Mit Mail“, $PM) wurden NICHT automatisch benachrichtigt – bitte selbst informieren."'
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
