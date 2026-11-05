# Privacy: legal texts in existing installations (see README.md in this directory)
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
$marker = static fn (string $key, string $lang, ?string $content) => App\Settings::set("legal_base.$key.$lang", $content === null ? null
    : json_encode(['type' => 'school', 'content' => $content], JSON_UNESCAPED_UNICODE));
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
pv_save() { # pv_save <lang> <content> [base]: the privacy policy form under "Texte"
  curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$(curl -s -b "$J" "$B/admin" | csrf)" --data-urlencode "content=$2" ${3:+-d "base=$3"} "$B/admin/page/privacy/$1"
}
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
PV_ADDED=$(pv_php '$put("privacy", "de", $fill($old)); $marker("privacy", "de", $old); echo e($added);')
D=$(curl -s -b "$J" "$B/admin")
check "newer template: note on the dashboard with the changed sections of the text, not for the others" 'echo "$D" | grep -qF "Die Vorlagen wurden geändert, seit diese Texte auf ihnen beruhen – Datenschutzerklärung (DE): 5. " && echo "$D" | grep -qE "Datenschutzerklärung \(DE\): 5\. [^;,]+, 6\. [^;,]+\. Bearbeitete Texte werden nicht automatisch ersetzt" && ! echo "$D" | grep -qF "(EN): 5." && ! echo "$D" | grep -qF "weichen von der aktuellen Vorlage ab"'
check "newer template: marked in the list of texts" 'TX=$(curl -s -b "$J" "$B/admin/texts"); echo "$TX" | grep -qF "Datenschutzerklärung (DE)</a> <span class=\"small muted\">– Vorlage geändert</span>" && ! echo "$TX" | grep -qF "Datenschutzerklärung (EN)</a> <span" && ! echo "$TX" | grep -qF "Impressum (DE)</a> <span"'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/de")
check "newer template: the form shows the lines the template removed and added, to take over or confirm" 'echo "$PF" | grep -qF "Die Vorlage „Von der Schule betrieben“ wurde geändert, seit dieser Text auf ihr beruht." && echo "$PF" | grep -qF "<span class=\"visually-hidden\">neu in der Vorlage:</span> $PV_ADDED</li>" && echo "$PF" | grep -qF "<span class=\"visually-hidden\">bisher in der Vorlage:</span> Diesen Satz gab es nur in der alten Vorlage.</li>" && echo "$PF" | grep -qF "href=\"/admin/page/privacy/de?merge=1\"" && echo "$PF" | grep -qF "name=\"base\" value=\"school\">" && [ "$(echo "$PF" | grep -c "<summary>")" = 2 ]'
PV_MERGED=$(curl -s -b "$J" "$B/admin/page/privacy/de?merge=1")
PV_TEXT=$(echo "$PV_MERGED" | pv_textarea)
check "taking over the sections: the form gets them from the template, the filled-in details stay, nothing stored yet" 'echo "$PV_MERGED" | grep -qF "Abschnitte aus der aktuellen Vorlage „Von der Schule betrieben“ übernommen: 5. " && echo "$PV_MERGED" | grep -qF "<input type=\"hidden\" name=\"base\" value=\"school\">" && ! echo "$PV_MERGED" | grep -qF "data-legal-update" && echo "$PV_TEXT" | grep -qF "Testangabe" && ! echo "$PV_TEXT" | grep -qF "alten Vorlage" && [ "$(DBQ "SELECT LOCATE(\"alten Vorlage\", content) > 0 FROM tp_pages WHERE page_key = \"privacy\" AND lang = \"de\"")" = 1 ]'
pv_save de "$PV_TEXT" school
check "saved: the text has the wording of the template, its marker is the current template, no note, in the log" '[ "$(pv_php '\''echo $state("privacy", "de"), App\LegalTexts::follows("privacy", "de", (string) App\Db::value("SELECT content FROM pages WHERE page_key = ? AND lang = ?", ["privacy", "de"]), "school") ? " follows" : "";'\'')" = "current follows" ] && ! curl -s -b "$J" "$B/admin" | grep -qF "Die Vorlagen wurden geändert" && [ "$(pv_audit "Datenschutzerklärung (DE) geändert und mit dem aktuellen Stand der Vorlage „Von der Schule betrieben“ abgeglichen")" = 1 ]'

# Edited before the markers existed: the sections in which the text differs; the admin confirms the reconciliation
pv_php '$en = (string) App\LegalTexts::template("privacy", "school", "en");
  $put("privacy", "en", (string) preg_replace("/^# 8\. .*$/mu", "$0\n\nOur own sentence on security.", $fill($en), 1)); $marker("privacy", "en", null);'
D=$(curl -s -b "$J" "$B/admin")
check "no marker: note with the sections that differ from the template (filled-in placeholders do not count)" 'echo "$D" | grep -qE "Diese Texte weichen von der aktuellen Vorlage ab, und es ist nicht bekannt, mit welchem Stand der Vorlage sie zuletzt abgeglichen wurden – Datenschutzerklärung \(EN\): 8\. [^;,]+\. " && ! echo "$D" | grep -qF "Die Vorlagen wurden geändert" && curl -s -b "$J" "$B/admin/texts" | grep -qF "Datenschutzerklärung (EN)</a> <span class=\"small muted\">– weicht von der Vorlage ab</span>"'
PF=$(curl -s -b "$J" "$B/admin/page/privacy/en")
check "no marker: the form shows the lines only in the text, with the box to confirm the reconciliation" 'echo "$PF" | grep -qF "Mit welchem Stand der Vorlage „Von der Schule betrieben“ dieser Text zuletzt abgeglichen wurde, ist nicht bekannt" && echo "$PF" | grep -qF "<span class=\"visually-hidden\">nur in Ihrem Text:</span> Our own sentence on security.</li>" && [ "$(echo "$PF" | grep -c "<summary>")" = 1 ] && echo "$PF" | grep -qF "<input type=\"checkbox\" id=\"base\" name=\"base\" value=\"school\">"'
PV_TEXT=$(echo "$PF" | pv_textarea)
pv_save en "$PV_TEXT"
check "saved without the box: still noted, no marker (the text does not have the wording of the template)" '[ "$(pv_php '\''echo $state("privacy", "en");'\'')" = - ] && curl -s -b "$J" "$B/admin" | grep -qF "Datenschutzerklärung (EN): 8. " && [ "$(pv_audit "Datenschutzerklärung (EN) geändert")" -ge 1 ]'
pv_save en "$PV_TEXT" school
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

# Back to the state of the main script: privacy policy filled in, every marker current
legal_fill
check "back: texts filled in, markers current, no notes" '[ "$(pv_php '\''foreach (["imprint", "privacy"] as $k) { foreach (["de", "en"] as $g) { echo $state($k, $g), " "; } }'\'')" = "current current current current " ] && ! curl -s -b "$J" "$B/admin" | grep -qE "Die Vorlagen wurden geändert|weichen von der aktuellen Vorlage ab|offene Platzhalter"'
