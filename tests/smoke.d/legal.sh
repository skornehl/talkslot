# Legal texts and the operator type (see README.md in this directory)
echo "Legal texts: operator type (operated by the school or privately)"
# Expert review P0-2b: TalkSlot can be used at a school, but run by the school (a public body) or privately (a
# private person, association or independent provider). The admins choose it with the details for imprint and
# privacy policy; it decides the templates, and texts nobody has edited follow it. Texts are stored by hand like
# an earlier version would have left them (lg_put), the type is removed to play an installation from before it.
LT=$(curl -s -b "$J" "$B/admin" | csrf)
LG_CONTACT=$'Sabrina Test\nTeststraße 1\n12345 Teststadt\nE-Mail: sabrina@example.org'
lg_file() { sha256sum < "$ROOT/app/templates/pages/$1.md" | cut -c1-64; }
lg_hash() { DBQ "SELECT SHA2(content, 256) FROM tp_pages WHERE page_key = '$1' AND lang = '$2'"; }
lg_type() { DBQ "SELECT CONCAT(COALESCE(MAX(IF(name = 'operator_type', value, NULL)), '-'), '/', COALESCE(MAX(IF(name = 'operator_type_auto', value, NULL)), '-')) FROM tp_settings"; }
lg_save() { # lg_save [type] [cookie jar]: the details form under "Texte" (without a type: form without the choice), page after the redirect
  curl -s -L -b "${2:-$J}" --data-urlencode "csrf=$LT" --data-urlencode "controller_contact=$LG_CONTACT" ${1:+--data-urlencode "operator_type=$1"} "$B/admin/texts/operator"
}
lg_put() { # lg_put <key> <lang> <file>: stores a text as it is
  php -r '$p = new PDO("mysql:host=127.0.0.1;dbname=" . (getenv("TP_DB") ?: "talkslot") . ";charset=utf8mb4", "app", "app-dev-only"); $p->prepare("UPDATE tp_pages SET content = ? WHERE page_key = ? AND lang = ?")->execute([file_get_contents($argv[3]), $argv[1], $argv[2]]);' "$1" "$2" "$3" 2>/dev/null \
    || $DC exec -T app php -r '$p = new PDO("mysql:host=db;dbname=talkslot;charset=utf8mb4", "app", "app-dev-only"); $p->prepare("UPDATE tp_pages SET content = ? WHERE page_key = ? AND lang = ?")->execute([file_get_contents("php://stdin"), $argv[1], $argv[2]]);' "$1" "$2" < "$3"
}
lg_update() { # lg_update: the first request after an update of the templates, without a type chosen so far
  DBQ "DELETE FROM tp_settings WHERE name IN ('operator_type', 'operator_type_auto'); UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
  curl -s -o /dev/null "$B/"
}
LG_PRIVACY_DE=$(lg_hash privacy de); LG_PRIVACY_EN=$(lg_hash privacy en)
check "installed as operated by the school: type stored, legal notice from the school template, chosen on the texts page" '[ "$(lg_type)" = school/- ] && [ "$(lg_hash imprint de)" = "$(lg_file imprint.de)" ] && [ "$(lg_hash imprint en)" = "$(lg_file imprint.en)" ] && curl -s -b "$J" "$B/admin/texts" | grep -q "value=\"school\" required[^>]* checked"'

# Change to "privately": the unedited legal notice and the unedited translations of the privacy policy (pl, ru, uk, tr;
# expert review P1-9) follow, the edited privacy policy stays and gets a note
LP=$(lg_save private)
check "changed to private: unedited legal notice (de/en) and translations replaced and announced, edited privacy policy kept" 'echo "$LP" | grep -qF "Angaben für Impressum und Datenschutz gespeichert. 6 unveränderte Vorlagentexte wurden durch die Vorlagen der neuen Betriebsart ersetzt." && [ "$(lg_hash privacy uk)" = "$(lg_file privacy-private.uk)" ] && [ "$(lg_type)" = private/- ] && [ "$(lg_hash imprint de)" = "$(lg_file imprint-private.de)" ] && [ "$(lg_hash imprint en)" = "$(lg_file imprint-private.en)" ] && [ "$(lg_hash privacy de)" = "$LG_PRIVACY_DE" ] && [ "$(lg_hash privacy en)" = "$LG_PRIVACY_EN" ]'
check "parents see the legal notice of a private, non-commercial service; the change is in the log with the number of texts replaced" 'curl -s "$B/impressum" | grep -q "privates, nicht kommerzielles Angebot" && curl -s "$B/impressum?lang=en" | grep -q "private, non-commercial service" && curl -s -b "$J" "$B/admin/log" | grep -qF "Betriebsart geändert: Wird privat betrieben (Privatperson, Verein, freier Träger) (6 unveränderte Vorlagentexte ersetzt)"'
D=$(curl -s -b "$J" "$B/admin")
check "privately operated: note on the school wording in the texts, none on the old template (\"private service\")" 'echo "$D" | grep -qF "Impressum bzw. Datenschutzerklärung (DE, EN) enthalten noch Angaben der Vorlage für Schulen" && ! echo "$D" | grep -qF "folgen noch der alten Vorlage" && curl -s -b "$J" "$B/admin/texts" | grep -qF "Die Vorlage steht in <code>app/templates/pages/privacy-private.de.md</code>"'
check "privately operated: the example in the details field names a person, association or provider, not the school" 'curl -s -b "$J" "$B/admin/texts" | grep -qF "placeholder=\"Vorname Nachname bzw. Name des Vereins oder Trägers&#10;Vertreten durch: Vorname Nachname (bei Verein oder Träger)&#10;"'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/de?template=school")
check "\"Vorlage laden\": the private template first and marked, the school template with a note that it does not fit" 'echo "$PF" | grep -q "?template=private\">Privat betrieben</a> (passend zur Betriebsart) · <a href=\"/admin/page/privacy/de?template=school\">Von der Schule betrieben</a>" && echo "$PF" | grep -qF "Sie passt nicht zur gewählten Betriebsart „Privat betrieben“."'
check "saved again with the same type: nothing replaced; form without the type: unchanged" 'P=$(lg_save private); echo "$P" | grep -qF "Angaben für Impressum und Datenschutz gespeichert." && ! echo "$P" | grep -q "Vorlagentexte wurden" && lg_save "" >/dev/null && [ "$(lg_type)" = private/- ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message LIKE \"Betriebsart bestätigt%\"")" = 0 ]'
check "unknown operator type rejected, nothing changed" 'lg_save öffentlich | grep -qF "Bitte wählen Sie die Betriebsart: Wird die Terminvergabe von der Schule oder privat betrieben?" && [ "$(lg_type)" = private/- ]'
# Edited text from up to v1.5: privately operated, only cancelling "at any time" is wrong
LGT=$(mktemp)
cp "$ROOT/tests/fixtures/privacy.de.v1.5.md" "$LGT"; printf '\nGeändert.\n' >> "$LGT"; lg_put privacy de "$LGT"
D=$(curl -s -b "$J" "$B/admin")
check "privately operated, edited text up to v1.5: note on \"at any time\" only, not on the old template" 'echo "$D" | grep -qF "Die Datenschutzerklärung (DE) sagt noch, Eltern könnten ihren Termin „jederzeit“ selbst absagen." && ! echo "$D" | grep -qF "folgen noch der alten Vorlage"'
check "privately operated, edited text up to v1.5: note that the operator is named as controller for all booked appointments" 'echo "$D" | grep -qF "Die Datenschutzerklärung (DE) nennt den Betreiber noch als Verantwortlichen für „alle darüber gebuchten Termine“" && ! echo "$D" | grep -qF "Die Datenschutzerklärung (EN) nennt den Betreiber"'

# Back to "school": the unedited private legal notice follows again; a private privacy policy gets a note
check "changed to school: unedited legal notice back to the school template, the old private text gets its note" 'lg_save school | grep -qF "6 unveränderte Vorlagentexte" && [ "$(lg_type)" = school/- ] && [ "$(lg_hash imprint de)" = "$(lg_file imprint.de)" ] && curl -s -b "$J" "$B/admin" | grep -qF "Impressum bzw. Datenschutzerklärung (DE) folgen noch der alten Vorlage"'
legal_fill privacy-private
D=$(curl -s -b "$J" "$B/admin")
check "operated by the school, privacy policy of a private operator: note on Art. 6(1)(b)/(f), none on the school wording" 'echo "$D" | grep -qF "Die Datenschutzerklärung (DE, EN) stützt sich auf Art. 6 Abs. 1 lit. b bzw. f DSGVO wie die Vorlage „Privat betrieben“." && ! echo "$D" | grep -qF "Angaben der Vorlage für Schulen" && ! echo "$D" | grep -qF "alten Vorlage"'

echo "Legal texts: installations from before the operator type"
# Up to v1.5, unedited: the templates described a private service – that stays the meaning
for l in de en; do lg_put imprint $l "$ROOT/app/templates/pages/imprint-private.$l.md"; lg_put privacy $l "$ROOT/tests/fixtures/privacy.$l.v1.5.md"; done
lg_update
check "update from v1.5 with unedited texts: operated privately, the privacy policy becomes the current private template, the legal notice stays" '[ "$(lg_type)" = private/1 ] && [ "$(lg_hash privacy de)" = "$(lg_file privacy-private.de)" ] && [ "$(lg_hash privacy en)" = "$(lg_file privacy-private.en)" ] && [ "$(lg_hash imprint de)" = "$(lg_file imprint-private.de)" ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name = \"pages_version\"")" != alt ]'
check "taken over and replaced on the update: in the log by the application (\"TalkSlot\", not a deleted account)" 'L=$(curl -s -b "$J" "$B/admin/log"); echo "$L" | grep -qF "<td>TalkSlot</td>" && echo "$L" | grep -qF "Betriebsart beim Update übernommen: Wird privat betrieben (Privatperson, Verein, freier Träger) (alle Texte waren unveränderte Vorlagen dieser Betriebsart" && echo "$L" | grep -qF "6 unveränderte Vorlagentexte beim Update durch die aktuellen Vorlagen ersetzt" && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE user_id IS NULL AND actor = \"TalkSlot\" AND message LIKE \"Betriebsart beim Update%\"")" = 1 ]'
check "admins are told the type was taken over, until one of them saves it" 'curl -s -b "$J" "$B/admin" | grep -qF "Die Betriebsart wurde beim Update auf „Wird privat betrieben (Privatperson, Verein, freier Träger)“ gesetzt" && curl -s -b "$J" "$B/admin/texts" | grep -q "value=\"private\" required[^>]* checked" && lg_save private >/dev/null && [ "$(lg_type)" = private/- ] && ! curl -s -b "$J" "$B/admin" | grep -qF "beim Update auf"'
check "… the admin who saved it confirmed the type, in the log under their name" '[ "$(DBQ "SELECT CONCAT(actor, \": \", message) FROM tp_audit_log WHERE message LIKE \"Betriebsart bestätigt%\"")" = "Frau Sabrina Test: Betriebsart bestätigt (beim Update übernommen): Wird privat betrieben (Privatperson, Verein, freier Träger)" ]'

# Edited texts and no type: the admins have to choose; until then no round can be activated, active ones stay online
legal_fill
lg_update
LGH=$($DC exec -T app php -r 'echo password_hash("Betriebsart-Passwort-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('wahl-lehrkraft@example.org', '$LGH', 'teacher', 'Frau Wahl', 'wahl-lehrkraft@example.org', 1, NOW())"
LGU=$(DBQ "SELECT id FROM tp_users WHERE username = 'wahl-lehrkraft@example.org'")
LGR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$LT" -d "title=Noch+ohne+Wahl&is_active=0&user_id=$LGU&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+12 day' +%F)&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
JL=$(mktemp)
curl -s -o /dev/null -c "$JL" -b "$JL" -d "csrf=$(curl -s -c "$JL" -b "$JL" "$B/admin/login" | csrf)&username=wahl-lehrkraft@example.org&password=Betriebsart-Passwort-1" "$B/admin/login"
D=$(curl -s -b "$J" "$B/admin")
check "edited texts, no type: \"Bitte Betriebsart wählen\" for admins, no type preselected, the texts stay" '[ "$(lg_type)" = -/- ] && echo "$D" | grep -qF "Bitte Betriebsart wählen: Wird die Terminvergabe von der Schule oder privat betrieben?" && echo "$D" | grep -qF "Bis dahin lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar." && ! curl -s -b "$J" "$B/admin/texts" | grep -q "name=\"operator_type\"[^>]* checked" && [ "$(lg_hash imprint de)" = "$(lg_file imprint-private.de)" ]'
TG=$(curl -s -L -b "$J" -d "csrf=$LT" "$B/admin/round/$LGR/toggle")
check "no type: activating refused for admins with the reason, active rounds stay online" 'echo "$TG" | grep -qF "Bevor Runden aktiviert werden können, wählen Sie bitte unter „Texte“ die Betriebsart" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $LGR")" = 0 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/$SLUG")" = 200 ]'
TG=$(curl -s -L -c "$JL" -b "$JL" -d "csrf=$(curl -s -c "$JL" -b "$JL" "$B/admin" | csrf)" "$B/admin/round/$LGR/toggle")
check "no type: teachers see that the admins have to choose, their activation is refused" 'curl -s -c "$JL" -b "$JL" "$B/admin" | grep -qF "Die Admins müssen noch wählen, ob die Terminvergabe von der Schule oder privat betrieben wird." && echo "$TG" | grep -qF "wenn ein Admin die Betriebsart (von der Schule oder privat betrieben) gewählt hat" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $LGR")" = 0 ]'

# v1.7.0 had replaced unedited texts by the school templates: no type is guessed from those, choosing "private"
# turns them back into the private templates
for l in de en; do lg_put imprint $l "$ROOT/app/templates/pages/imprint.$l.md"; lg_put privacy $l "$ROOT/app/templates/pages/privacy.$l.md"; done
lg_update
check "unedited school templates (v1.7.0), no type: the admins choose, nothing guessed" '[ "$(lg_type)" = -/- ] && curl -s -b "$J" "$B/admin" | grep -qF "Bitte Betriebsart wählen"'
check "choosing private: all four texts become the private templates" 'lg_save private | grep -qF "4 unveränderte Vorlagentexte" && [ "$(lg_hash imprint de)" = "$(lg_file imprint-private.de)" ] && [ "$(lg_hash imprint en)" = "$(lg_file imprint-private.en)" ] && [ "$(lg_hash privacy de)" = "$(lg_file privacy-private.de)" ] && [ "$(lg_hash privacy en)" = "$(lg_file privacy-private.en)" ] && ! curl -s -b "$J" "$B/admin" | grep -qF "Bitte Betriebsart wählen"'

# Back to the state of the main script: operated by the school, its templates, privacy policy filled in
lg_save school > /dev/null
legal_fill
check "back to the school: its templates, notes gone, the example names the school again" 'curl -s -b "$J" "$B/admin/texts" | grep -qF "placeholder=\"Name der Schule&#10;" && [ "$(lg_type)" = school/- ] && [ "$(lg_hash imprint de)" = "$(lg_file imprint.de)" ] && [ "$(lg_hash imprint en)" = "$(lg_file imprint.en)" ] && ! curl -s -b "$J" "$B/admin" | grep -qE "Betriebsart|alten Vorlage|Vorlage für Schulen|offene Platzhalter"'
curl -s -o /dev/null -b "$J" -d "csrf=$LT&confirm=1" "$B/admin/round/$LGR/delete"
DBQ "DELETE FROM tp_users WHERE id = $LGU"
rm -f "$JL" "$LGT"
