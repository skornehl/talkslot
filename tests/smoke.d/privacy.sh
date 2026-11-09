# Privacy: legal texts in existing installations, information in all parent languages in two levels, no required
# checkbox (see README.md in this directory)
echo "Privacy: version markers of the legal texts"
# Expert review P1-11: edited legal texts are never replaced by a newer template. Each text keeps the template it
# was stored from or reconciled with (settings legal_base.<page>.<lang>); admins see what a newer template changed
# since, or for a text from before the markers how it differs, and can take over the sections. Texts and markers
# are set up in PHP like an older version or an earlier template would have left them.
PV_PHP=$(cat <<'PHP'
require "/srv/app/app/bootstrap.php";
// Like an admin (and legal_fill in smoke.sh): placeholders in single brackets filled in, the web host typed in
$fill = static fn (string $t): string => str_replace('[[HOSTER]]', 'Beispiel Hosting GmbH, Serverweg 1, 12345 Serverstadt',
    (string) preg_replace('/(?<!\[)\[\p{Lu}[^\[\]\p{Ll}\n]{3,}\](?![\](])/u', 'Testangabe', $t));
$put = static fn (string $key, string $lang, string $text) => App\Db::run('UPDATE pages SET content = ? WHERE page_key = ? AND lang = ?', [$text, $key, $lang]);
$marker = static fn (string $key, string $lang, ?string $content, string $type = 'school') => App\Settings::set("legal_base.$key.$lang", $content === null ? null
    : json_encode(['type' => $type, 'content' => $content], JSON_UNESCAPED_UNICODE));
// "current" = the marker is the current template of the operator type, "-" = none
$state = static fn (string $key, string $lang): string => ($b = App\LegalTexts::base($key, $lang)) === null ? '-'
    : ($b['content'] === App\LegalTexts::template($key, (string) App\Operator::type(), $lang) ? 'current' : 'old');
// An earlier school template (German): the first item of section 5 missing, a sentence in section 6 it no longer has
$cur = (string) App\LegalTexts::template('privacy', 'school', 'de');
$l = explode("\n", $cur);
$h5 = (int) key(preg_grep('/^# 5\./', $l));
$first5 = (int) key(array_filter(array_slice($l, $h5 + 1, null, true), static fn (string $x): bool => trim($x) !== ''));
array_splice($l, (int) key(preg_grep('/^# 6\./', $l)) + 1, 0, ['', 'Diesen Satz gab es nur in der alten Vorlage.']);
$added = $l[$first5];
array_splice($l, $first5, 1);
$old = implode("\n", $l);
PHP
)
pv_php() { ALERTS "$PV_PHP $1"; }
pv_textarea() { $DC exec -T app php -r 'preg_match("#<textarea[^>]*name=\"content\"[^>]*>(.*?)</textarea>#s", stream_get_contents(STDIN), $m); echo html_entity_decode($m[1] ?? "", ENT_QUOTES | ENT_HTML5, "UTF-8");'; }
pv_save() { # pv_save <lang> <content> [base] [base_hash]: the privacy policy form under "Texte"
  curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$(curl -s -b "$J" "$B/admin" | csrf)" --data-urlencode "content=$2" ${3:+-d "base=$3"} ${4:+-d "base_hash=$4"} "$B/admin/page/privacy/$1"
}
pv_hash() { grep -o 'name="base_hash" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"$//'; } # of the template the form shows
pv_audit() { DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE message = '$1'"; }
check "every text has the current template of its operator type as its marker (installer, operator type, saved texts), no notes" '[ "$(pv_php '\''foreach (["imprint", "privacy"] as $k) { foreach (["de", "en"] as $g) { echo $state($k, $g), " "; } }'\'')" = "current current current current " ] && ! curl -s -b "$J" "$B/admin" | grep -qE "Die Vorlagen wurden geändert|weichen von der aktuellen Vorlage ab"'
# The installer stores the texts with their templates as markers (here: the legal notice in German missing)
PV_INSTALL=$(pv_php 'App\Db::run("DELETE FROM pages WHERE page_key = ? AND lang = ?", ["imprint", "de"]); $marker("imprint", "de", null);
  App\Installer::storePages("private");
  echo App\Db::value("SELECT content FROM pages WHERE page_key = ? AND lang = ?", ["imprint", "de"]) === App\LegalTexts::template("imprint", "private", "de") ? "stored " : "missing ",
    App\LegalTexts::base("imprint", "de")["type"] ?? "-", " ", App\LegalTexts::base("privacy", "de")["type"] ?? "-";
  App\Db::run("DELETE FROM pages WHERE page_key = ? AND lang = ?", ["imprint", "de"]); App\Installer::storePages("school");')
check "installer: a missing text gets the template with it as its marker, the others stay as they are ($PV_INSTALL)" '[ "$PV_INSTALL" = "stored private school" ] && [ "$(pv_php '\''echo $state("imprint", "de");'\'')" = current ]'

# The template changed since the text was based on it: the sections, what changed, taking them over
# (with an own sentence in a section the template changed: not taken over, listed to check)
PV_ADDED=$(pv_php '$put("privacy", "de", str_replace("alten Vorlage.", "alten Vorlage.\n\nUnser eigener Satz zu den Empfängern.", $fill($old))); $marker("privacy", "de", $old); echo e($added);')
D=$(curl -s -b "$J" "$B/admin")
check "newer template: note on the dashboard with the changed sections of the text, not for the others" 'echo "$D" | grep -qF "Die Vorlagen wurden geändert, seit diese Texte auf ihnen beruhen – Datenschutzerklärung (DE): 5. " && echo "$D" | grep -qE "Datenschutzerklärung \(DE\): 5\. [^;,]+, 6\. [^;,]+\. Bearbeitete Texte werden nicht automatisch ersetzt" && ! echo "$D" | grep -qF "(EN): 5." && ! echo "$D" | grep -qF "weichen von der aktuellen Vorlage ab"'
# Only this note, not as one of the notes that name the template files (those may appear, e.g. the web host of the
# translations of the privacy policy, which the main script does not fill in)
check "newer template: marked in the list of texts, the note there without the file of the privacy template" 'TX=$(curl -s -b "$J" "$B/admin/texts"); echo "$TX" | grep -qF "Datenschutzerklärung (DE)</a> <span class=\"small muted\">– Vorlage geändert</span>" && ! echo "$TX" | grep -qF "Datenschutzerklärung (EN)</a> <span" && ! echo "$TX" | grep -qF "Impressum (DE)</a> <span" && echo "$TX" | grep -qE "data-legal-outdated><p>Die Vorlagen wurden geändert[^<]*</p></div>" && [ "$(echo "$TX" | grep -o "Die Vorlagen wurden geändert" | wc -l)" = 1 ]'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/de")
check "newer template: the form shows the lines the template removed and added, to take over or confirm" 'echo "$PF" | grep -qF "Die Vorlage „Von der Schule betrieben“ wurde geändert, seit dieser Text auf ihr beruht." && echo "$PF" | grep -qF "<span class=\"visually-hidden\">neu in der Vorlage:</span> $PV_ADDED</li>" && echo "$PF" | grep -qF "<span class=\"visually-hidden\">bisher in der Vorlage:</span> Diesen Satz gab es nur in der alten Vorlage.</li>" && echo "$PF" | grep -qF "href=\"/admin/page/privacy/de?merge=1\"" && echo "$PF" | grep -qF "name=\"base\" value=\"school\">" && [ "$(echo "$PF" | grep -c "<summary>")" = 2 ]'
PV_MERGED=$(curl -s -b "$J" "$B/admin/page/privacy/de?merge=1")
PV_TEXT=$(echo "$PV_MERGED" | pv_textarea)
check "taking over the sections: the form gets them from the template, the filled-in details stay, nothing stored yet" 'echo "$PV_MERGED" | grep -qF "Abschnitte aus der aktuellen Vorlage „Von der Schule betrieben“ übernommen: 5. " && echo "$PV_MERGED" | grep -qF "<input type=\"hidden\" name=\"base\" value=\"school\">" && ! echo "$PV_MERGED" | grep -qF "data-legal-update" && echo "$PV_TEXT" | grep -qF "Testangabe" && ! echo "$PV_TEXT" | grep -qF "alten Vorlage" && [ "$(DBQ "SELECT LOCATE(\"alten Vorlage\", content) > 0 FROM tp_pages WHERE page_key = \"privacy\" AND lang = \"de\"")" = 1 ]'
check "taking over: the lines of the text left out are listed to check (here the own sentence), only these" 'echo "$PV_MERGED" | grep -qF "data-legal-lost" && echo "$PV_MERGED" | grep -qF "<span class=\"visually-hidden\">nicht übernommen:</span> Unser eigener Satz zu den Empfängern.</li>" && [ "$(echo "$PV_MERGED" | grep -c "nicht übernommen:</span>")" = 1 ] && ! echo "$PV_TEXT" | grep -qF "Unser eigener Satz" && ! echo "$PF" | grep -qF "data-legal-lost"'
# Confirmed while the template changed meanwhile (here: the form's hash is not that of the current template)
pv_save de "$(echo "$PF" | pv_textarea)" school 0000
check "confirmed while the template changed meanwhile: stored, but not recorded as reconciled, the admin is told" '[ "$(pv_php '\''echo $state("privacy", "de");'\'')" = old ] && curl -s -b "$J" "$B/admin/page/privacy/de" | grep -qF "Text gespeichert, aber nicht als abgeglichen vermerkt" && [ "$(pv_audit "Datenschutzerklärung (DE) geändert und mit dem aktuellen Stand der Vorlage „Von der Schule betrieben“ abgeglichen")" = 0 ]'
pv_save de "$PV_TEXT" school "$(echo "$PV_MERGED" | pv_hash)"
check "saved: the text has the wording of the template, its marker is the current template, no note, in the log" '[ "$(pv_php '\''echo $state("privacy", "de"), App\LegalTexts::follows("privacy", "de", (string) App\Db::value("SELECT content FROM pages WHERE page_key = ? AND lang = ?", ["privacy", "de"]), "school") ? " follows" : "";'\'')" = "current follows" ] && ! curl -s -b "$J" "$B/admin" | grep -qF "Die Vorlagen wurden geändert" && [ "$(pv_audit "Datenschutzerklärung (DE) geändert und mit dem aktuellen Stand der Vorlage „Von der Schule betrieben“ abgeglichen")" = 1 ]'

# Edited before the markers existed: the sections in which the text differs; the admin confirms the reconciliation
pv_php '$en = (string) App\LegalTexts::template("privacy", "school", "en");
  $put("privacy", "en", (string) preg_replace("/^# 8\. .*$/mu", "$0\n\nOur own sentence on security.", $fill($en), 1) . "\n# 12. Video consultations\n\nOur own section.\n"); $marker("privacy", "en", null);'
D=$(curl -s -b "$J" "$B/admin")
check "no marker: note with the sections that differ from the template (filled-in placeholders do not count), own ones too" 'echo "$D" | grep -qE "Diese Texte weichen von der aktuellen Vorlage ab, und es ist nicht bekannt, mit welchem Stand der Vorlage sie zuletzt abgeglichen wurden – Datenschutzerklärung \(EN\): 8\. [^;,]+, 12\. Video consultations\. " && ! echo "$D" | grep -qF "Die Vorlagen wurden geändert" && curl -s -b "$J" "$B/admin/texts" | grep -qF "Datenschutzerklärung (EN)</a> <span class=\"small muted\">– weicht von der Vorlage ab</span>"'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/en")
check "no marker: the form shows the lines only in the text, with the box to confirm the reconciliation" 'echo "$PF" | grep -qF "Mit welchem Stand der Vorlage „Von der Schule betrieben“ dieser Text zuletzt abgeglichen wurde, ist nicht bekannt" && echo "$PF" | grep -qF "<span class=\"visually-hidden\">nur in Ihrem Text:</span> Our own sentence on security.</li>" && [ "$(echo "$PF" | grep -c "<summary>")" = 2 ] && echo "$PF" | grep -qF "<input type=\"checkbox\" id=\"base\" name=\"base\" value=\"school\">"'
PV_MERGED=$(curl -s -b "$J" "$B/admin/page/privacy/en?merge=1")
check "no marker, taking over: the section only in the text stays and is named as kept, not as taken over; the own sentence listed as left out" 'echo "$PV_MERGED" | grep -qF "Abschnitte aus der aktuellen Vorlage „Von der Schule betrieben“ übernommen: 8. " && echo "$PV_MERGED" | grep -qF "Unverändert, weil es sie nur in Ihrem Text gibt (eigene oder aus einer früheren Vorlage – bitte prüfen, ob sie noch zutreffen): 12. Video consultations." && echo "$PV_MERGED" | pv_textarea | grep -qF "Our own section." && ! echo "$PV_MERGED" | pv_textarea | grep -qF "Our own sentence on security." && echo "$PV_MERGED" | grep -qF "nicht übernommen:</span> Our own sentence on security.</li>"'
PV_TEXT=$(echo "$PF" | pv_textarea)
pv_save en "$PV_TEXT"
check "saved without the box: still noted, no marker (the text does not have the wording of the template)" '[ "$(pv_php '\''echo $state("privacy", "en");'\'')" = - ] && curl -s -b "$J" "$B/admin" | grep -qF "Datenschutzerklärung (EN): 8. " && [ "$(pv_audit "Datenschutzerklärung (EN) geändert")" -ge 1 ]'
pv_save en "$PV_TEXT" school "$(echo "$PF" | pv_hash)"
check "saved with the box: the own sentence stays, the marker is the current template, no note, in the log" '[ "$(pv_php '\''echo $state("privacy", "en");'\'')" = current ] && [ "$(DBQ "SELECT LOCATE(\"Our own sentence on security.\", content) > 0 FROM tp_pages WHERE page_key = \"privacy\" AND lang = \"en\"")" = 1 ] && ! curl -s -b "$J" "$B/admin" | grep -qF "weichen von der aktuellen Vorlage ab" && [ "$(pv_audit "Datenschutzerklärung (EN) geändert und mit dem aktuellen Stand der Vorlage „Von der Schule betrieben“ abgeglichen")" = 1 ]'

# The update to this version: once, the texts with the wording of the current templates get them as their markers
pv_php '$marker("imprint", "de", null); $marker("privacy", "de", null); $marker("privacy", "en", null);'
DBQ "UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "update: markers for the unedited legal notice and the filled-in privacy policy, none for the text with an own sentence" '[ "$(pv_php '\''echo $state("imprint", "de"), " ", $state("privacy", "de"), " ", $state("privacy", "en");'\'')" = "current current -" ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name = \"pages_version\"")" != alt ]'
# An unedited earlier template is known by its marker, even if OLD_PAGE_HASHES lacks it
PV_REPLACED=$(pv_audit "1 unveränderte Vorlagentexte beim Update durch die aktuellen Vorlagen ersetzt")
pv_php '$put("privacy", "de", $old); $marker("privacy", "de", $old);'
DBQ "UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "update: an unedited earlier template (by its marker) becomes the current one, in the log" '[ "$(pv_php '\''echo App\Db::value("SELECT content FROM pages WHERE page_key = ? AND lang = ?", ["privacy", "de"]) === App\LegalTexts::template("privacy", "school", "de") ? "replaced " : "kept ", $state("privacy", "de");'\'')" = "replaced current" ] && [ "$(pv_audit "1 unveränderte Vorlagentexte beim Update durch die aktuellen Vorlagen ersetzt")" = $((PV_REPLACED + 1)) ]'

# A marker of the other operator type (the text was stored from the private template, the operator type is school):
# not reconciled with this template, so the text is compared with it as a whole
pv_php '$put("privacy", "en", (string) preg_replace("/^# 8\. .*$/mu", "$0\n\nOur own sentence on security.", $fill((string) App\LegalTexts::template("privacy", "school", "en")), 1));
  $marker("privacy", "en", (string) App\LegalTexts::template("privacy", "private", "en"), "private");'
D=$(curl -s -b "$J" "$B/admin")
PF=$(curl -s -b "$J" "$B/admin/page/privacy/en")
check "marker of the other operator type: noted as differing (not as a changed template), with the box for this type" 'echo "$D" | grep -qE "Diese Texte weichen von der aktuellen Vorlage ab[^<]*Datenschutzerklärung \(EN\): 8\. [^;,]+\. " && ! echo "$D" | grep -qF "Die Vorlagen wurden geändert" && curl -s -b "$J" "$B/admin/texts" | grep -qF "Datenschutzerklärung (EN)</a> <span class=\"small muted\">– weicht von der Vorlage ab</span>" && echo "$PF" | grep -qF "Mit welchem Stand der Vorlage „Von der Schule betrieben“ dieser Text zuletzt abgeglichen wurde, ist nicht bekannt" && echo "$PF" | grep -qF "<input type=\"checkbox\" id=\"base\" name=\"base\" value=\"school\">"'
# On an update a text with a marker gets the current template as its marker once it has what the template changed
# since (an own sentence elsewhere is fine), not while it lacks it
pv_php '$put("privacy", "de", (string) preg_replace("/^# 8\. .*$/mu", "$0\n\nUnser eigener Satz.", $fill($cur), 1)); $marker("privacy", "de", $old);
  $put("privacy", "en", $fill((string) App\LegalTexts::template("privacy", "school", "en")) . "\nOld sentence."); $marker("privacy", "en", (string) App\LegalTexts::template("privacy", "school", "en") . "\nOld sentence.");'
DBQ "UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "update: a text with a marker that has what the template changed since gets the current one, one that lacks it keeps its own" '[ "$(pv_php '\''echo $state("privacy", "de"), " ", $state("privacy", "en");'\'')" = "current old" ] && curl -s -b "$J" "$B/admin" | grep -qE "Die Vorlagen wurden geändert, seit diese Texte auf ihnen beruhen – Datenschutzerklärung \(EN\): 11\. "'

# Back to the state of the main script: privacy policy filled in, every marker current
legal_fill
check "back: texts filled in, markers current, no notes" '[ "$(pv_php '\''foreach (["imprint", "privacy"] as $k) { foreach (["de", "en"] as $g) { echo $state($k, $g), " "; } }'\'')" = "current current current current " ] && ! curl -s -b "$J" "$B/admin" | grep -qE "Die Vorlagen wurden geändert|weichen von der aktuellen Vorlage ab|offene Platzhalter"'

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
check "booking form: no checkbox for the privacy notice; who, what for, deletion and the link, the rest folded away" '! echo "$PV_P" | grep -q "name=\"privacy\"" && echo "$PV_P" | grep -qF "<li>Verantwortlich für diese Terminvergabe: Sabrina Test</li>" && echo "$PV_P" | grep -qF "<summary>Mehr zum Datenschutz</summary>" && echo "$PV_P" | grep -qF "<a href=\"/datenschutz?r=$PV_S\" target=\"_blank\" rel=\"noopener\">Alle Datenschutzhinweise, auch zu Ihren Rechten (u. a. Auskunft, Löschung, Widerspruch, Beschwerde)</a>" && echo "$PV_P" | grep -qF "(Art. 6 Abs. 1 lit. e DSGVO). Eine Einwilligung ist dafür nicht nötig."'
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
pv_mail() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$1"'%20subject:'"$2"'"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";'; }
PV_M=$(curl -s "$PV_LOC")
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$PV_M" | csrf)" -d "action=move&slot_id=$(echo "$PV_P" | grep -o 'value="[0-9]*" required' | sed -n 2p | grep -o '[0-9]*')" "${PV_LOC%%\?*}"
check "change mail: the deadline with date and time as well" 'pv_mail "$PV_MAIL" ge%C3%A4ndert | grep -q "^Ändern oder absagen können Sie den Termin online bis [A-Z][a-z]*, $(date -d "$PV_DAY" +%d.%m.%Y), 04:00 Uhr\."'
# Round deactivated: moving no longer works, only cancelling – the appointment page says so
DBQ "UPDATE tp_rounds SET is_active = 0 WHERE id = $PV_R"
PV_M=$(curl -s "$PV_LOC")
check "round inactive: the appointment page names the deadline for cancelling only" 'echo "$PV_M" | grep -q "<p class=\"hint\" data-change-deadline>Absagen können Sie den Termin online bis [A-Z][a-z]*, $(date -d "$PV_DAY" +%d.%m.%Y), 04:00 Uhr\.</p>"'
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$PV_M" | csrf)" -d "action=cancel&confirm=1" "${PV_LOC%%\?*}"
check "cancellation mail: no deadline any more" 'T2=$(pv_mail "$PV_MAIL" abgesagt); echo "$T2" | grep -qF "Datenschutzhinweise: " && ! echo "$T2" | grep -q "absagen können Sie den Termin online bis"'
DBQ "UPDATE tp_rounds SET is_active = 1 WHERE id = $PV_R"
PV_P=$(curl -s "$B/$PV_S?lang=pl")
PV_MAIL2="ochrona-$PV_RUN@example.org"
curl -s -o /dev/null --data-urlencode "csrf=$(echo "$PV_P" | csrf)" --data-urlencode "email=$PV_MAIL2" -d "lang=pl&name=Polski+Rodzic&slot_id=$(echo "$PV_P" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')" "$B/$PV_S"
PV_TXT=$($DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query=to:'"$PV_MAIL2"'"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";')
check "in Polish: deadlines on the round page, deadline and privacy link in the mail" 'echo "$PV_P" | grep -qF "Rezerwacja jest możliwa najpóźniej 24 godziny przed terminem. Zmiana lub odwołanie online są możliwe najpóźniej 12 godzin przed terminem." && echo "$PV_TXT" | grep -q "^Zmiana lub odwołanie terminu online są możliwe do: [^,]*, [0-9]* [^ ]* [0-9]*, 03:45\." && echo "$PV_TXT" | grep -qF "Informacja o ochronie danych: $B/datenschutz?lang=pl"'

echo "Privacy: the privacy policy in all parent languages"
pv_page() { DBQ "SELECT SHA2(content, 256) FROM tp_pages WHERE page_key = '$1' AND lang = '$2'"; }
pv_tpl() { sha256sum < "$ROOT/app/templates/pages/$1.md" | cut -c1-64; }
check "installed: the translated privacy policies (school template), the legal notice only in German and English" '[ "$(pv_page privacy pl)" = "$(pv_tpl privacy.pl)" ] && [ "$(pv_page privacy uk)" = "$(pv_tpl privacy.uk)" ] && [ -z "$(pv_page imprint tr)" ]'
PV_D=$(curl -s -b "$J" "$B/admin")
check "admins: the translations are not complete yet, rounds can be activated all the same; no note on blocking" 'echo "$PV_D" | grep -qF "Übersetzungen noch nicht vollständig: Datenschutzerklärung (PL, RU, TR, UK)." && ! echo "$PV_D" | grep -q "offene Platzhalter" && curl -s -b "$J" "$B/admin/texts" | grep -qF "Das Impressum gibt es nur auf Deutsch und Englisch."'
check "readiness checklist: open placeholders in the translations do not count as blocking, no link to a translation for the data protection officer" 'P=$(curl -s -b "$J" "$B/admin/readiness"); ! echo "$P" | grep -qF "Hier fehlt noch etwas (siehe unten)" && ! echo "$P" | grep -qF "/admin/page/privacy/pl"'
PV_PP=$(curl -s "$B/datenschutz?lang=pl")
check "parents in Polish while the translation has open placeholders: the English text, marked as such, with a notice in Polish" 'echo "$PV_PP" | grep -qF "<p class=\"alert alert-info\" data-content-lang=\"en\">Ten tekst nie jest dostępny po polsku. Poniżej znajduje się wersja w tym języku: <span lang=\"en\">English</span>.</p>" && echo "$PV_PP" | grep -q "<div lang=\"en\"><blockquote>This is a translation. The German version is authoritative.</blockquote>" && echo "$PV_PP" | grep -q "<html lang=\"pl\"" && ! echo "$PV_PP" | grep -qF "[KONTAKTDATEN"'
check "legal notice in Turkish: there is none, the English one with a notice in Turkish" 'curl -s "$B/impressum?lang=tr" | grep -qF "data-content-lang=\"en\">Bu metin Türkçe olarak mevcut değil."'
check "privacy policy in German: no notice, no language of its own" '! curl -s "$B/datenschutz" | grep -q "data-content-lang"'
# Filled in like an admin: the same details as in the German version
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PV_T" --data-urlencode "content=$(perl -CSD -pe 's/\[\[HOSTER\]\]/'"$SMOKE_HOST"'/g; s/(?<!\[)\[\p{Lu}[^\[\]\p{Ll}\n]{3,}\](?![\](])/Testangabe/g' "$ROOT/app/templates/pages/privacy.pl.md")" "$B/admin/page/privacy/pl"
PV_PP=$(curl -s "$B/datenschutz?lang=pl")
check "filled in: Polish parents get the Polish text, the German version authoritative, the operator's details in it" 'echo "$PV_PP" | grep -qF "<blockquote>To jest tłumaczenie. Wiążąca jest wersja niemiecka.</blockquote>" && echo "$PV_PP" | grep -qF "Teststraße 1" && ! echo "$PV_PP" | grep -q "data-content-lang" && curl -s -b "$J" "$B/admin" | grep -qF "Übersetzungen noch nicht vollständig: Datenschutzerklärung (RU, TR, UK)."'
# Emptied: the admins do not want to offer it; parents get the English text, no note any more
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PV_T" --data-urlencode "content=" "$B/admin/page/privacy/pl"
check "emptied: Polish parents get the English text again" 'curl -s "$B/datenschutz?lang=pl" | grep -qF "data-content-lang=\"en\">Ten tekst"'
# … also once the template changed since (here: a marker of an earlier template): an emptied text is not an edited one
pv_php '$marker("privacy", "pl", "# 1. Alte Vorlage\n");'
check "emptied: no note on a newer template or on wording for it" 'D=$(curl -s -b "$J" "$B/admin"); ! echo "$D" | grep -qF "(PL)" && ! echo "$D" | grep -qF ", PL"'

# The German version is authoritative: a complete translation saved before the German text gets a note until it is saved
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$PV_T" --data-urlencode "content=$(perl -CSD -pe 's/\[\[HOSTER\]\]/'"$SMOKE_HOST"'/g; s/(?<!\[)\[\p{Lu}[^\[\]\p{Ll}\n]{3,}\](?![\](])/Testangabe/g' "$ROOT/app/templates/pages/privacy.pl.md")" "$B/admin/page/privacy/pl"
PV_DE_AT=$(DBQ "SELECT updated_at FROM tp_pages WHERE page_key = 'privacy' AND lang = 'de'")
DBQ "UPDATE tp_pages SET updated_at = '$PV_DE_AT' WHERE page_key = 'privacy' AND lang IN ('en', 'pl')"
check "translation saved with or after the German text: no note" '! curl -s -b "$J" "$B/admin" | grep -qF "Die deutsche Datenschutzerklärung wurde geändert"'
DBQ "UPDATE tp_pages SET updated_at = '$PV_DE_AT' - INTERVAL 1 DAY WHERE page_key = 'privacy' AND lang = 'pl'"
check "German text saved after the Polish translation: the admins are asked to check it (not the incomplete ones)" 'curl -s -b "$J" "$B/admin" | grep -qF "Die deutsche Datenschutzerklärung wurde geändert, nachdem diese Fassungen zuletzt gespeichert wurden: PL. Maßgeblich ist die deutsche Fassung"'
pv_save pl "$(curl -s -b "$J" "$B/admin/page/privacy/pl" | pv_textarea)"
check "… saved again: the note is gone" '! curl -s -b "$J" "$B/admin" | grep -qF "Die deutsche Datenschutzerklärung wurde geändert"'

pv_save de "$(curl -s -b "$J" "$B/admin/page/privacy/de" | pv_textarea)"
check "German text saved unchanged: not newer, no note on the translations" '[ "$(DBQ "SELECT updated_at FROM tp_pages WHERE page_key = \"privacy\" AND lang = \"de\"")" = "$PV_DE_AT" ] && ! curl -s -b "$J" "$B/admin" | grep -qF "Die deutsche Datenschutzerklärung wurde geändert"'
# The German text says more than the template, the translations are still the template: however recently they were saved
PV_OWN="\n\nUnser eigener Absatz zur Terminvergabe."
DBQ "UPDATE tp_pages SET content = CONCAT(content, '$PV_OWN') WHERE page_key = 'privacy' AND lang = 'de'"
check "German text beyond the template, complete translations still the template: admins are asked to carry the changes over" 'curl -s -b "$J" "$B/admin" | grep -qF "Ihre deutsche Datenschutzerklärung weicht von der Vorlage ab. Diese Fassungen sind noch die Vorlage mit Ihren Angaben: EN, PL. Maßgeblich"'
DBQ "UPDATE tp_pages SET content = CONCAT(content, '$PV_OWN') WHERE page_key = 'privacy' AND lang = 'pl'"
check "… a translation with the changes is no longer listed" 'curl -s -b "$J" "$B/admin" | grep -qF "Diese Fassungen sind noch die Vorlage mit Ihren Angaben: EN. Maßgeblich"'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '$PV_OWN', '') WHERE page_key = 'privacy' AND lang IN ('de', 'pl')"
check "… German text back to the template: no note" '! curl -s -b "$J" "$B/admin" | grep -qF "weicht von der Vorlage ab. Diese Fassungen"'

# A page without a text in any fallback language (English, German): parents would get none, no round can be activated.
# The German text alone empty: every translation refers to a version that does not exist
DBQ "INSERT INTO tp_pages (page_key, lang, content, updated_at) SELECT 'pv-backup', lang, content, updated_at FROM tp_pages WHERE page_key = 'privacy' AND lang IN ('de', 'en'); UPDATE tp_pages SET content = '' WHERE page_key = 'privacy' AND lang = 'de'"
DBQ "UPDATE tp_rounds SET is_active = 0 WHERE id = $PV_R"
pv_activate() { curl -s -L -b "$J" --data-urlencode "csrf=$PV_T" -d "title=Datenschutz+Fristen&is_active=1&booking_limit=none&booking_deadline_hours=24&cancel_deadline_hours=12&location=Raum+A+112&user_id=$(DBQ "SELECT user_id FROM tp_rounds WHERE id = $PV_R")" "$B/admin/round/$PV_R/edit"; }
check "German privacy policy empty, English filled in: noted, and a round cannot be activated" 'curl -s -b "$J" "$B/admin" | grep -qF "Die deutsche Datenschutzerklärung ist leer." && pv_activate | grep -qF "hinterlegen Sie bitte unter „Texte“ die deutsche Datenschutzerklärung: Auf sie verweisen" && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $PV_R")" = 0 ]'
DBQ "UPDATE tp_pages SET content = '' WHERE page_key = 'privacy' AND lang = 'en'"
PV_X=$(pv_activate)
check "privacy policy empty in English and German: noted, and a round cannot be activated" 'curl -s -b "$J" "$B/admin" | grep -qF "Für Datenschutzerklärung gibt es in keiner dieser Sprachen einen Text: EN, DE." && echo "$PV_X" | grep -qF "hinterlegen Sie bitte unter „Texte“ Datenschutzerklärung in mindestens einer dieser Sprachen: EN, DE." && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $PV_R")" = 0 ]'
DBQ "UPDATE tp_pages p JOIN tp_pages b ON b.page_key = 'pv-backup' AND b.lang = p.lang SET p.content = b.content WHERE p.page_key = 'privacy'; DELETE FROM tp_pages WHERE page_key = 'pv-backup'"
check "… restored: no note" '! curl -s -b "$J" "$B/admin" | grep -qF "in keiner dieser Sprachen einen Text"'

# An installation from before the translations: the update stores them where there is none (or an unedited template)
DBQ "DELETE FROM tp_pages WHERE page_key = 'privacy' AND lang IN ('pl', 'ru', 'uk', 'tr'); UPDATE tp_settings SET value = 'alt' WHERE name = 'pages_version'"
curl -s -o /dev/null "$B/"
check "update: the translations of the operator type are stored, the update is logged" '[ "$(pv_page privacy ru)" = "$(pv_tpl privacy.ru)" ] && [ "$(pv_page privacy tr)" = "$(pv_tpl privacy.tr)" ] && [ "$(pv_page privacy pl)" = "$(pv_tpl privacy.pl)" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log WHERE actor = \"TalkSlot\" AND message = \"4 unveränderte Vorlagentexte beim Update durch die aktuellen Vorlagen ersetzt\"")" -ge 1 ]'

# A text edited before: still mentions the required checkbox – a note to adjust section 4
DBQ "UPDATE tp_pages SET content = CONCAT(content, '\n\nMit dem Pflicht-Häkchen im Buchungsformular bestätigen Sie nur, dass Sie diese Hinweise zur Kenntnis genommen haben.') WHERE page_key = 'privacy' AND lang = 'de'"
check "edited text that still mentions the required checkbox: note for the admins" 'curl -s -b "$J" "$B/admin" | grep -qF "Die Datenschutzerklärung (DE) erwähnt noch das Pflicht-Häkchen im Buchungsformular."'
DBQ "UPDATE tp_pages SET content = REPLACE(content, '\n\nMit dem Pflicht-Häkchen im Buchungsformular bestätigen Sie nur, dass Sie diese Hinweise zur Kenntnis genommen haben.', '') WHERE page_key = 'privacy' AND lang = 'de'"
check "… gone once adjusted" '! curl -s -b "$J" "$B/admin" | grep -qF "Pflicht-Häkchen"'

# A reminder after the deadline for changes (48 hours, the appointment tomorrow): no invitation to cancel through the
# link, the notice to contact the teacher instead
PV_RM=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$PV_T" -d "title=Erinnerung+nach+Frist&remind_parents_field=1&remind_parents=1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=48&day_dates%5B%5D=$(date -d '+1 day' +%F)&start_time=09:00&end_time=10:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
PV_MAIL3="erinnerung-$PV_RUN@example.org"
book "$(slugof "$PV_RM")" "Erinnerung Spaet" "$PV_MAIL3" > /dev/null
DBQ "UPDATE tp_bookings SET created_at = NOW() - INTERVAL 2 DAY, updated_at = NOW() - INTERVAL 2 DAY, notified_at = notified_at - INTERVAL 2 DAY WHERE name = 'Erinnerung Spaet'"
$DC exec -T app php /srv/app/bin/cron.php > /dev/null
PV_TXT=$(pv_mail "$PV_MAIL3" Erinnerung)
check "reminder after the deadline for changes: the link to the appointment, no invitation to cancel, the notice to contact the teacher" 'echo "$PV_TXT" | grep -q "^Ihr Termin online:" && ! echo "$PV_TXT" | grep -q "sagen Sie den Termin bitte über diesen Link ab" && echo "$PV_TXT" | grep -qF "Änderungen und Absagen sind online nur bis 48 Stunden vor dem Termin möglich. Bitte wenden Sie sich direkt an die Lehrkraft."'
curl -s -o /dev/null -b "$J" -d "csrf=$PV_T&confirm=1" "$B/admin/round/$PV_RM/delete"

# Back to the state of the main script
curl -s -o /dev/null -b "$J" -d "csrf=$PV_T&confirm=1" "$B/admin/round/$PV_R/delete"
check "cleaned up: round gone, translations unedited templates again" '[ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $PV_R")" ] && [ "$(pv_page privacy pl)" = "$(pv_tpl privacy.pl)" ]'
