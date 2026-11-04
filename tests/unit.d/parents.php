<?php
// Parents, texts and privacy (see README.md in this directory)
echo "Privacy: no browser page translation in the admin area\n";
// The <html> line carries translate="no" and class="notranslate", the head the meta for Chrome. Works on the
// rendered page and on the template source (one tag per line).
$notranslate = static fn (string $html): bool => (bool) preg_match('/^<html\b.*\stranslate="no"(?=[\s>]).*\sclass="notranslate"/m', $html)
    && str_contains($html, '<meta name="google" content="notranslate">');
$adminPage = App\View::capture('admin/layout', ['content' => '<p>Max Muster</p>', 'title' => 'Runde', 'flash' => []]);
check('admin layout: translate="no", class and meta notranslate', $notranslate($adminPage) && str_contains($adminPage, '<html lang="de" translate="no"'));
$printPage = App\View::capture('admin/print', ['round' => ['id' => 1, 'title' => 'Elternsprechtag'], 'days' => [], 'showEmail' => true, 'dayFilter' => 0]);
check('print view: translate="no", class and meta notranslate', $notranslate($printPage));
$roots = array_filter(glob(APP_ROOT . '/app/templates/admin/*.php') ?: [], static fn (string $f): bool => str_contains((string) file_get_contents($f), '<html'));
$unmarked = array_filter($roots, static fn (string $f): bool => !$notranslate((string) file_get_contents($f)));
check('every standalone admin page is marked (' . count($roots) . ' templates' . ($unmarked ? ', missing: ' . implode(', ', array_map('basename', $unmarked)) : '') . ')',
    count($roots) >= 2 && !$unmarked);
// Parents may need a language beyond the six offered, and only see their own data there
check('parent pages stay translatable', !str_contains(App\View::capture('layout', ['content' => '', 'title' => 'T']), 'notranslate'));

echo "Privacy: legal text templates and open placeholders\n";
$ph = [App\LegalTexts::class, 'placeholders'];
check('open placeholders: upper case in square brackets (umlauts, Cyrillic, [[UNKNOWN]]), each once, in order',
    $ph("Logs: [SPEICHERDAUER BEIM HOSTER ERFRAGEN.]\nBehörde: [ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE MIT ADRESSE], Server in [LAND] und [LAND]. [[UNBEKANNT]] [АДРЕС ШКОЛЫ] [§ 120 SCHULG NRW]")
    === ['[SPEICHERDAUER BEIM HOSTER ERFRAGEN.]', '[ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE MIT ADRESSE]', '[LAND]', '[[UNBEKANNT]]', '[АДРЕС ШКОЛЫ]']);
// [ДОМ дом]: without /u the Cyrillic lower-case letters are bytes that do not count as lower case
check('no placeholders: Markdown link, mixed case (also Cyrillic), short abbreviation, empty brackets, invalid UTF-8',
    $ph("[DSGVO-TEXT](https://eur-lex.europa.eu/) [Hinweis] [Größe der Schule] [ДОМ дом] [EU] [x] [] [LAND\nX]") === [] && $ph("[LAND] \xC3") === []);
$tpl = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/app/templates/pages/$name.md");
$publicDe = $tpl('privacy.de');
$publicEn = $tpl('privacy.en');
check('public school (de): Art. 6(1)(e) and (3) with the state school act, objection under Art. 21 for (e), no (b)/(f), Art. 20 explained instead of listed',
    str_contains($publicDe, 'Art. 6 Abs. 1 lit. e, Abs. 3 DSGVO in Verbindung mit [VORSCHRIFT IM SCHULGESETZ DES LANDES')
    && str_contains($publicDe, 'Soweit die Verarbeitung auf Art. 6 Abs. 1 lit. e DSGVO beruht') && !preg_match('/lit\. [bf]\b/', $publicDe)
    && !str_contains($publicDe, 'Recht auf **Datenübertragbarkeit**') && str_contains($publicDe, 'Art. 20 Abs. 3 Satz 2 DSGVO'));
check('public school (en): the same in English',
    str_contains($publicEn, 'Art. 6(1)(e) and (3) GDPR in conjunction with [PROVISION OF THE STATE SCHOOL ACT')
    && str_contains($publicEn, 'where processing is based on Art. 6(1)(e) GDPR') && !preg_match('/6\(1\)\([bf]\)/', $publicEn)
    && !str_contains($publicEn, 'right to **data portability**') && str_contains($publicEn, 'Art. 20(3), second sentence'));
check('private operator (de/en): contract and legitimate interests, objection for (f), data portability',
    str_contains($tpl('privacy-private.de'), 'Art. 6 Abs. 1 lit. b DSGVO') && str_contains($tpl('privacy-private.de'), 'Soweit die Verarbeitung auf Art. 6 Abs. 1 lit. f DSGVO beruht')
    && str_contains($tpl('privacy-private.de'), 'Recht auf **Datenübertragbarkeit**')
    && str_contains($tpl('privacy-private.en'), 'Art. 6(1)(b) GDPR') && str_contains($tpl('privacy-private.en'), 'where processing is based on Art. 6(1)(f) GDPR'));
$privacy = ['privacy.de' => $publicDe, 'privacy.en' => $publicEn, 'privacy-private.de' => $tpl('privacy-private.de'), 'privacy-private.en' => $tpl('privacy-private.en')];
$wrong = array_keys(array_filter($privacy, static fn (string $t): bool => !preg_match('/^\*\*[^*]*(Datenschutzbeauftragte|data protection officer)[^*]*\*\* \[(KONTAKTDATEN|CONTACT DETAILS)/mui', $t)
    || (bool) preg_match('/private[ns]? Angebot|private service|jederzeit selbst|yourself at any time/u', $t)
    || !preg_match('/Änderungsfrist, die die Lehrkraft|deadline the teacher has set/u', $t) || !preg_match('/zur Vertraulichkeit verpflichtet|bound to confidentiality/u', $t)));
check('all privacy templates: required placeholder for the data protection officer, no "private service", cancelling only until the deadline, administrators bound to confidentiality'
    . ($wrong ? ' (' . implode(', ', $wrong) . ')' : ''), !$wrong);
check('legal notices without "private service"', !preg_match('/privat/i', $tpl('imprint.de') . $tpl('imprint.en')));
$parity = array_filter(['imprint', 'privacy', 'privacy-private'], static fn (string $n): bool => count($ph($tpl("$n.de"))) !== count($ph($tpl("$n.en"))));
check('German and English templates have the same number of placeholders' . ($parity ? ' (' . implode(', ', $parity) . ')' : ''), !$parity);
$oldHashes = (new ReflectionClassConstant(App\Migrations::class, 'OLD_PAGE_HASHES'))->getValue();
check('templates up to v1.5.0 (unedited) are replaced on update',
    !array_diff(['036e210b629357dfbf768868e3a5f249c888d3052f301170703b196336058f43', 'f15521ceaa18e97c98190e448cc95eb6130c4cf571164b09819bb40dd7af22c8',
        '3885e997cd3bbf330158fdccc730f7fe1b93044379cc2df060ea5e0e7b6396ec', 'ffbcc88fbd8b760efc7c4f173bb4e8850517392f88f9ffc0100da3aad921253f'], $oldHashes));
check('no current template counts as old (an unedited "private operator" text is not switched to "public school")',
    !array_intersect(App\Migrations::CURRENT_PAGE_HASHES, $oldHashes));
check('variants: the first one is the installed template, loading only existing files',
    array_values(App\LegalTexts::VARIANTS['privacy'])[0] === 'privacy' && array_values(App\LegalTexts::VARIANTS['imprint'])[0] === 'imprint'
    && App\LegalTexts::template('privacy', 'private', 'en') === $tpl('privacy-private.en') && App\LegalTexts::template('privacy', 'public', 'de') === $publicDe
    && App\LegalTexts::template('privacy', 'public', 'pl') === null && App\LegalTexts::template('imprint', 'private', 'de') === null
    && App\LegalTexts::template('privacy', 'public', '../de') === null);
// As parents see it: the details of the operator and the web host do not count as open placeholders, a missing web host does
$contact = new ReflectionProperty(App\Operator::class, 'contact');
$contact->setValue(null, "Gesamtschule am See\nE-Mail: info@example.org");
App\Config::load(['hosting' => ['de' => 'Beispiel Hosting GmbH, Weg 1']] + $cfg);
$text = "Verantwortlich: [[VERANTWORTLICHE]]\n\n- **Hosting:** [[HOSTER]]";
check('rendered for parents: operator and configured web host filled in, nothing open',
    App\LegalTexts::render($text, 'de', '?') === "Verantwortlich: Gesamtschule am See\nE-Mail: info@example.org\n\n- **Hosting:** Beispiel Hosting GmbH, Weg 1"
    && $ph(App\LegalTexts::render($text, 'de', '?')) === []);
check('web host not configured: its placeholders are open', $ph(App\LegalTexts::render($text, 'en', '?')) === ['[WEB HOST WITH ADDRESS]', '[COUNTRY]']);
App\Config::load($cfg);
$contact->setValue(null, null);
