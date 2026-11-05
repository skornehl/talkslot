# Privacy: information in all parent languages, in two levels, no required checkbox (see README.md in this directory)
echo "Privacy: booking form without the required checkbox, deadlines before and after booking"
# Expert review P1-9: informing is enough (Art. 13 GDPR), nothing to tick; first level who/what for/deletion with the
# link, the rest folded away; the chosen appointment and the deadlines before the button; the deadline for changes
# with date and time on the confirmation page and in the mail, which links the privacy notice in the parents' language.
PV_T=$(curl -s -b "$J" "$B/admin" | csrf)
PV_RUN=$(date +%s%N)
PV_DAY=$(date -d '+9 day' +%F)
PV_R=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$PV_T" -d "title=Datenschutz+Fristen&is_active=1&booking_limit=none&booking_deadline_hours=24&cancel_deadline_hours=12&location=Raum+A+112&day_dates%5B%5D=$PV_DAY&start_time=15:45&end_time=16:15&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
PV_S=$(curl -s -b "$J" "$B/admin/round/$PV_R" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
PV_P=$(curl -s "$B/$PV_S")
PV_SLOT=$(echo "$PV_P" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
check "round with deadlines active although the translations still have open placeholders" '[ -n "$PV_S" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $PV_R")" = 1 ]'
check "booking form: no checkbox for the privacy notice; who, what for, deletion and the link, the rest folded away" '! echo "$PV_P" | grep -q "name=\"privacy\"" && echo "$PV_P" | grep -qF "<li>Verantwortlich für diese Terminvergabe: Sabrina Test</li>" && echo "$PV_P" | grep -qF "<summary>Mehr zum Datenschutz</summary>" && echo "$PV_P" | grep -qF "<a href=\"/datenschutz?r=$PV_S\" target=\"_blank\" rel=\"noopener\">Alle Datenschutzhinweise</a>" && echo "$PV_P" | grep -qF "(Art. 6 Abs. 1 lit. e DSGVO). Eine Einwilligung ist dafür nicht nötig."'
check "booking form: deadlines in the info box, the chosen appointment above the button (empty until chosen)" 'echo "$PV_P" | grep -qF "<span data-deadlines>Buchen können Sie bis 24 Stunden vor dem Termin. Ändern oder absagen können Sie online bis 12 Stunden vor dem Termin.</span>" && echo "$PV_P" | grep -qF "<p class=\"booking-summary\" aria-live=\"polite\" data-slot-summary=\"Ihr Termin: {when}\"></p>" && echo "$PV_P" | grep -q "value=\"$PV_SLOT\" required data-when=\"[A-Z][a-z]*, $(date -d "$PV_DAY" +%d.%m.%Y), 15:45–16:00 Uhr, Ort: Raum A 112\""'
PV_ERR=$(curl -s --data-urlencode "csrf=$(echo "$PV_P" | csrf)" -d "name=&slot_id=$PV_SLOT" "$B/$PV_S")
check "after an error the chosen appointment is shown by the server, and no error about privacy" 'echo "$PV_ERR" | grep -q "data-slot-summary=\"Ihr Termin: {when}\">Ihr Termin: [A-Z][a-z]*, $(date -d "$PV_DAY" +%d.%m.%Y), 15:45–16:00 Uhr, Ort: Raum A 112</p>" && ! echo "$PV_ERR" | grep -q "Datenschutzerklärung zur Kenntnis"'
PV_MAIL="datenschutz-$PV_RUN@example.org"
PV_LOC=$(curl -s -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(echo "$PV_P" | csrf)" --data-urlencode "email=$PV_MAIL" -d "name=Ohne+Haekchen&slot_id=$PV_SLOT" "$B/$PV_S")
check "booking without any privacy field succeeds" 'echo "$PV_LOC" | grep -q "/b/[A-Za-z0-9_-]\{43\}"'
PV_DEADLINE="Ändern oder absagen können Sie den Termin online bis [A-Z][a-z]*, $(date -d "$PV_DAY" +%d.%m.%Y), 03:45 Uhr\."
check "confirmation page: until when changing and cancelling work online, with date and time" 'curl -s "$PV_LOC" | grep -q "<p class=\"hint\" data-change-deadline>$PV_DEADLINE</p>"'
PV_TXT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$PV_MAIL"'"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "confirmation mail: the deadline with date and time, the link to the privacy notice in the footer" 'echo "$PV_TXT" | grep -q "^$PV_DEADLINE" && echo "$PV_TXT" | grep -qF "Datenschutzhinweise: $B/datenschutz?lang=de" && ! echo "$PV_TXT" | grep -qF "siehe Datenschutzhinweise"'
PV_P=$(curl -s "$B/$PV_S?lang=pl")
PV_MAIL2="ochrona-$PV_RUN@example.org"
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$PV_P" | csrf)" --data-urlencode "email=$PV_MAIL2" -d "lang=pl&name=Polski+Rodzic&slot_id=$(echo "$PV_P" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')" "$B/$PV_S"
PV_TXT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$PV_MAIL2"'"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "in Polish: deadlines on the round page, deadline and privacy link in the mail" 'echo "$PV_P" | grep -qF "Rezerwacja jest możliwa najpóźniej 24 godziny przed terminem. Zmiana lub odwołanie online są możliwe najpóźniej 12 godzin przed terminem." && echo "$PV_TXT" | grep -q "^Zmiana lub odwołanie terminu online są możliwe do: [^,]*, [0-9]* [^ ]* [0-9]*, 04:00\." && echo "$PV_TXT" | grep -qF "Informacja o ochronie danych: $B/datenschutz?lang=pl"'

echo "Privacy: the privacy policy in all parent languages"
pv_page() { DBQ "SELECT SHA2(content, 256) FROM tp_pages WHERE page_key = '$1' AND lang = '$2'"; }
pv_tpl() { sha256sum < "$ROOT/app/templates/pages/$1.md" | cut -c1-64; }
check "installed: the translated privacy policies (school template), the legal notice only in German and English" '[ "$(pv_page privacy pl)" = "$(pv_tpl privacy.pl)" ] && [ "$(pv_page privacy uk)" = "$(pv_tpl privacy.uk)" ] && [ -z "$(pv_page imprint tr)" ]'
PV_D=$(curl -s -b "$J" "$B/admin")
check "admins: the translations are not complete yet, rounds can be activated all the same; no note on blocking" 'echo "$PV_D" | grep -qF "Übersetzungen noch nicht vollständig: Datenschutzerklärung (PL, RU, TR, UK)." && ! echo "$PV_D" | grep -q "offene Platzhalter" && curl -s -b "$J" "$B/admin/texts" | grep -qF "Das Impressum gibt es nur auf Deutsch und Englisch."'
PV_PP=$(curl -s "$B/datenschutz?lang=pl")
check "parents in Polish while the translation has open placeholders: the English text, marked as such, with a notice in Polish" 'echo "$PV_PP" | grep -qF "<p class=\"alert alert-info\" data-content-lang=\"en\">Ten tekst nie jest jeszcze dostępny po polsku. Poniżej znajduje się wersja w tym języku: English.</p>" && echo "$PV_PP" | grep -q "<div lang=\"en\"><blockquote>This is a translation. The German version is authoritative.</blockquote>" && echo "$PV_PP" | grep -q "<html lang=\"pl\"" && ! echo "$PV_PP" | grep -qF "[KONTAKTDATEN"'
check "legal notice in Turkish: there is none, the English one with a notice in Turkish" 'curl -s "$B/impressum?lang=tr" | grep -qF "data-content-lang=\"en\">Bu metin henüz Türkçe olarak mevcut değil."'
check "privacy policy in German: no notice, no language of its own" '! curl -s "$B/datenschutz" | grep -q "data-content-lang"'
# Filled in like an admin: the same details as in the German version
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PV_T" --data-urlencode "content=$(perl -CSD -pe 's/\[\[HOSTER\]\]/'"$SMOKE_HOST"'/g; s/(?<!\[)\[\p{Lu}[^\[\]\p{Ll}\n]{3,}\](?![\](])/Testangabe/g' "$ROOT/app/templates/pages/privacy.pl.md")" "$B/admin/page/privacy/pl"
PV_PP=$(curl -s "$B/datenschutz?lang=pl")
check "filled in: Polish parents get the Polish text, the German version authoritative, the operator's details in it" 'echo "$PV_PP" | grep -qF "<blockquote>To jest tłumaczenie. Wiążąca jest wersja niemiecka.</blockquote>" && echo "$PV_PP" | grep -qF "Teststraße 1" && ! echo "$PV_PP" | grep -q "data-content-lang" && curl -s -b "$J" "$B/admin" | grep -qF "Übersetzungen noch nicht vollständig: Datenschutzerklärung (RU, TR, UK)."'
# Emptied: the admins do not want to offer it; parents get the English text, no note any more
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PV_T" --data-urlencode "content=" "$B/admin/page/privacy/pl"
check "emptied: Polish parents get the English text again" 'curl -s "$B/datenschutz?lang=pl" | grep -qF "data-content-lang=\"en\">Ten tekst"'

# An installation from before the translations: the update stores them where there is none (or an unedited template)
DBQ "DELETE FROM tp_pages WHERE page_key = 'privacy' AND lang IN ('pl', 'ru', 'uk', 'tr'); UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "update: the translations of the operator type are stored, the update is logged" '[ "$(pv_page privacy ru)" = "$(pv_tpl privacy.ru)" ] && [ "$(pv_page privacy tr)" = "$(pv_tpl privacy.tr)" ] && [ "$(pv_page privacy pl)" = "$(pv_tpl privacy.pl)" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE actor = \"TalkSlot\" AND message = \"4 unveränderte Vorlagentexte beim Update durch die aktuellen Vorlagen ersetzt\"")" -ge 1 ]'

# A text edited before: still mentions the required checkbox – a note to adjust section 4
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nMit dem Pflicht-Häkchen im Buchungsformular bestätigen Sie nur, dass Sie diese Hinweise zur Kenntnis genommen haben.') WHERE page_key = 'privacy' AND lang = 'de'"
check "edited text that still mentions the required checkbox: note for the admins" 'curl -s -b "$J" "$B/admin" | grep -qF "Die Datenschutzerklärung (DE) erwähnt noch das Pflicht-Häkchen im Buchungsformular."'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '\n\nMit dem Pflicht-Häkchen im Buchungsformular bestätigen Sie nur, dass Sie diese Hinweise zur Kenntnis genommen haben.', '') WHERE page_key = 'privacy' AND lang = 'de'"
check "… gone once adjusted" '! curl -s -b "$J" "$B/admin" | grep -qF "Pflicht-Häkchen"'

# Back to the state of the main script
curl -s -o /dev/null -b "$J" -d "csrf=$PV_T&confirm=1" "$B/admin/round/$PV_R/delete"
check "cleaned up: round gone, translations unedited templates again" '[ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $PV_R")" ] && [ "$(pv_page privacy pl)" = "$(pv_tpl privacy.pl)" ]'
