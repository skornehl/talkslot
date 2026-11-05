<?php
// Privacy: legal texts in existing installations (see README.md in this directory)
echo "Privacy: version markers of the legal texts – sections and placeholders\n";
// Expert review P1-11: edited texts are never replaced by a newer template. Admins see what the template changed
// since the text was based on it (version marker), or for an older text how it differs from the template.
$pvTpl = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/app/templates/pages/$name.md");
$pvFixture = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/tests/fixtures/$name.md");
// Like an admin (and legal_fill in tests/smoke.sh): every placeholder in single brackets filled in, the web host typed in
$pvFill = static fn (string $text): string => str_replace('[[HOSTER]]', 'Beispiel Hosting GmbH, Serverweg 1, 12345 Serverstadt',
    (string) preg_replace('/(?<!\[)\[\p{Lu}[^\[\]\p{Ll}\n]{3,}\](?![\](])/u', 'Testangabe', $text));
$pvFills = [App\LegalTexts::class, 'fills'];
check('filled in: a placeholder by any text, by several lines, or by nothing together with the blank in front of it',
    $pvFills('Behörde: LDI NRW, Düsseldorf.', 'Behörde: [ZUSTÄNDIGE BEHÖRDE MIT ADRESSE].')
    && $pvFills("Verantwortlich:\nSchule am See\nSeeweg 1", "Verantwortlich:\n[[VERANTWORTLICHE]]")
    && $pvFills('Nicht benannt (Art. 37 DSGVO).', 'Nicht benannt (Art. 37 DSGVO). [BITTE PRÜFEN UND DANN LÖSCHEN.]')
    && $pvFills('Ohne Platzhalter.', 'Ohne Platzhalter.') && $pvFills('[LAND]', '[LAND]'));
check('not filled in: other wording around the placeholder, a text without placeholders that differs, regex characters taken literally',
    !$pvFills('Aufsicht: LDI NRW.', 'Behörde: [ZUSTÄNDIGE BEHÖRDE].') && !$pvFills('Ohne Platzhalter!', 'Ohne Platzhalter.')
    && !$pvFills('Art 6 (1) e', 'Art. 6 (1) [BUCHSTABE]') && $pvFills('Art. 6 (1) e', 'Art. 6 (1) [BUCHSTABE]'));
$pvSections = App\LegalTexts::sections("Einleitung\n\n# 1. Verantwortliche\n\nText\n\n## Unterpunkt\n\nMehr\n# 12. Neu\nA\n# Neu\nB");
check('sections: by first-level heading without its number, the introduction as "", subheadings stay inside, a heading used twice kept apart',
    array_keys($pvSections) === ['', 'Verantwortliche', 'Neu', 'Neu (2)'] && $pvSections['']['heading'] === null
    && $pvSections['Verantwortliche']['heading'] === '# 1. Verantwortliche' && $pvSections['Verantwortliche']['lines'] === ['', 'Text', '', '## Unterpunkt', '', 'Mehr']
    && $pvSections['Neu (2)']['lines'] === ['B'] && array_keys(App\LegalTexts::sections($pvTpl('privacy.de')))[6] === 'Empfänger');
check('section titles for admins: heading without "#", the introduction by name',
    App\LegalTexts::sectionTitle('# 5. Speicherdauer und Löschung') === '5. Speicherdauer und Löschung' && App\LegalTexts::sectionTitle(null) === 'Einleitung'
    && App\AdminI18n::with('en', static fn () => App\LegalTexts::sectionTitle(null)) === 'Introduction');
$pvDiff = [App\LegalTexts::class, 'diff'];
check('line diff: removed and added lines in order, lines in both marked as such',
    $pvDiff(['a', 'b', 'c'], ['a', 'x', 'c', 'd']) === [[' ', 'a'], ['-', 'b'], ['+', 'x'], [' ', 'c'], ['+', 'd']] && $pvDiff([], []) === []
    && $pvDiff(['a'], []) === [['-', 'a']]);
check('line diff with placeholders: a filled-in line is the same (the line of the text kept), a line that is only a placeholder is not filled in by any line',
    $pvDiff(['Land: Deutschland', 'Eigener Satz'], ['Land: [LAND]', '[[VERANTWORTLICHE]]'], true) === [[' ', 'Land: Deutschland'], ['-', 'Eigener Satz'], ['+', '[[VERANTWORTLICHE]]']]
    && $pvDiff(['Land: Deutschland'], ['Land: [LAND]']) === [['-', 'Land: Deutschland'], ['+', 'Land: [LAND]']]);

echo "Privacy: version markers of the legal texts – what a newer template changed\n";
$pvChanges = [App\LegalTexts::class, 'changes'];
$pvTitles = static fn (array $changes): array => array_map(static fn (array $c): string => App\LegalTexts::sectionTitle($c['heading']), $changes);
$pvPrivate = $pvTpl('privacy-private.de');
$pvV17 = $pvFixture('privacy-private.de.v1.7');
check('nothing to do: the current template with its placeholders filled in (with or without a marker), all templates de/en',
    !array_filter(['privacy.de', 'privacy.en', 'privacy-private.de', 'privacy-private.en', 'imprint.de', 'imprint-private.en'],
        static fn (string $n): bool => $pvChanges($pvFill($pvTpl($n)), null, $pvTpl($n)) !== [] || $pvChanges($pvFill($pvTpl($n)), $pvTpl($n), $pvTpl($n)) !== []));
// With a marker: the private template of v1.7.0 → the current one (talkslot PRs #38, #39 and the outbox)
$pvKnown = $pvChanges($pvFill($pvV17), $pvV17, $pvPrivate);
check('marker of v1.7.0, text filled in: exactly the sections the template changed since, with its old and new lines',
    $pvTitles($pvKnown) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '4. Rechtsgrundlagen', '5. Speicherdauer und Löschung', '9. Ihre Rechte']
    && in_array(['-', 'Verantwortlich im Sinne der DSGVO für diese Terminvergabe und alle darüber gebuchten Termine ist:'], $pvKnown[0]['diff'], true)
    && in_array(['+', 'Verantwortlich im Sinne der DSGVO für diese Terminvergabe ist:'], $pvKnown[0]['diff'], true)
    && !array_filter(array_merge(...array_column($pvKnown, 'diff')), static fn (array $d): bool => $d[0] === ' ' || str_contains($d[1], 'Testangabe')));
$pvOwn = str_replace('Die Verbindung zu dieser Seite ist per TLS', 'Unsere Verbindung ist per TLS', $pvFill($pvV17)) . "\n# 12. Kontakt\n\nEigener Abschnitt.\n";
$pvApplied = str_replace('und alle darüber gebuchten Termine ist:', 'ist:', $pvOwn);
check('marker: own changes in sections the template did not change are not listed, nor sections the text already took over',
    $pvTitles($pvChanges($pvOwn, $pvV17, $pvPrivate)) === $pvTitles($pvKnown)
    && !in_array('1. Verantwortliche', $pvTitles($pvChanges(App\LegalTexts::merge($pvApplied, $pvPrivate, ['Verantwortliche']), $pvV17, $pvPrivate)), true)
    && $pvChanges($pvOwn, $pvPrivate, $pvPrivate) === []);
$pvAdded = $pvPrivate . "\n# 12. Neu in der Vorlage\n\nNeuer Satz.\n";
$pvDropped = (string) preg_replace('/# 8\. Sicherheit.*?(?=# 9\.)/su', '', $pvPrivate);
check('marker: a section new in the template (all lines added), one it dropped while the text still has it (all lines removed)',
    $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvAdded) === [['key' => 'Neu in der Vorlage', 'heading' => '# 12. Neu in der Vorlage', 'diff' => [['+', '# 12. Neu in der Vorlage'], ['+', 'Neuer Satz.']]]]
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped)) === ['8. Sicherheit'] && $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped)[0]['diff'][0][0] === '-'
    && $pvChanges($pvDropped, $pvPrivate, $pvDropped) === []);
// Without a marker (edited before them): where the text differs from the current template
$pvUnknown = $pvChanges($pvOwn, null, $pvPrivate);
check('no marker: the sections that differ from the template (also own changes and own sections), lines only in the text and only in the template',
    $pvTitles($pvUnknown) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '4. Rechtsgrundlagen', '5. Speicherdauer und Löschung', '8. Sicherheit', '9. Ihre Rechte', '12. Kontakt']
    && $pvUnknown[4]['diff'][0][0] === '-' && str_starts_with($pvUnknown[4]['diff'][0][1], 'Unsere Verbindung ist per TLS') && $pvUnknown[4]['diff'][1][0] === '+'
    && end($pvUnknown)['diff'] === [['-', '# 12. Kontakt'], ['-', 'Eigener Abschnitt.']]
    && !array_filter($pvUnknown[5]['diff'], static fn (array $d): bool => str_contains($d[1], 'Aufsichtsbehörde zu beschweren'))); // filled in, the same
check('no marker: a text of the other operator type differs in the sections that differ between both templates',
    $pvTitles($pvChanges($pvFill($pvTpl('privacy.de')), null, $pvPrivate)) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '4. Rechtsgrundlagen', '5. Speicherdauer und Löschung', '6. Empfänger', '9. Ihre Rechte']);
check('a text with the wording of the current template follows it (placeholders filled in), an outdated one does not',
    App\LegalTexts::follows('privacy', 'de', $pvFill($pvPrivate), 'private') && !App\LegalTexts::follows('privacy', 'de', $pvFill($pvV17), 'private')
    && !App\LegalTexts::follows('privacy', 'pl', $pvFill($pvPrivate), 'private'));

echo "Privacy: version markers of the legal texts – taking over sections\n";
$pvMerge = [App\LegalTexts::class, 'merge'];
$pvMerged = $pvMerge($pvOwn, $pvPrivate, array_column($pvUnknown, 'key'));
check('taken over: the text then has the wording of the template, filled-in lines of these sections kept, new placeholders to fill in',
    $pvChanges($pvMerged, null, $pvPrivate) === [] && substr_count($pvMerged, 'Testangabe') === 2 && str_contains($pvMerged, 'in Protokolldateien. Testangabe')
    && App\LegalTexts::placeholders($pvMerged) === ['[[VERANTWORTLICHE]]', '[BITTE PRÜFEN UND DANN DIESE KLAMMER LÖSCHEN: IST EINE ODER EINER BENANNT (Z. B. BEI EINEM VEREIN ODER FREIEN TRÄGER), STATT DES SATZES DAVOR DEREN KONTAKTDATEN ANGEBEN.]']
    && !str_contains($pvMerged, 'Eigener Abschnitt') && !str_contains($pvMerged, 'alle darüber gebuchten Termine'));
$pvPartial = $pvMerge($pvOwn, $pvPrivate, ['Rechtsgrundlagen']);
check('taken over: only the sections chosen, the others as they were (own sections too)',
    $pvTitles($pvChanges($pvPartial, null, $pvPrivate)) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '5. Speicherdauer und Löschung', '8. Sicherheit', '9. Ihre Rechte', '12. Kontakt']
    && str_contains($pvPartial, 'Unsere Verbindung ist per TLS') && str_ends_with($pvPartial, "# 12. Kontakt\n\nEigener Abschnitt.\n")
    && $pvMerge($pvPrivate, $pvPrivate, []) === $pvPrivate && $pvMerge($pvFill($pvTpl('imprint.de')), $pvTpl('imprint.de'), []) === $pvFill($pvTpl('imprint.de')));
$pvOld = "Intro\n\n# 1. Eins\n\nA\n\n# 2. Drei\n\nC\n";
$pvRenumbered = "Intro\n\n# 1. Eins\n\nA\n\n# 2. Zwei\n\nB\n\n# 3. Mehr\n\nB2\n\n# 4. Drei\n\nC\n";
check('sections new in the template, and one only renumbered, count as changed; taken over where the template has them, in its order',
    array_column($pvChanges($pvOld, $pvOld, $pvRenumbered), 'key') === ['Zwei', 'Mehr', 'Drei']
    && $pvChanges($pvOld, $pvOld, $pvRenumbered)[2]['diff'] === [['-', '# 2. Drei'], ['+', '# 4. Drei']]
    && $pvMerge($pvOld, $pvRenumbered, array_column($pvChanges($pvOld, null, $pvRenumbered), 'key')) === $pvRenumbered
    && $pvMerge("# 1. Eins\n\nA", "# 0. Vorweg\n\nV\n\n# 1. Eins\n\nA\n", ['Vorweg']) === "# 0. Vorweg\n\nV\n\n# 1. Eins\n\nA\n");

echo "Privacy: version markers of the legal texts – unedited texts and the page form\n";
$pvReplace = [App\LegalTexts::class, 'replacement'];
check('unedited: a text that is exactly the template of its marker is replaced, even without its hash in OLD_PAGE_HASHES',
    $pvReplace('privacy', 'de', $pvAdded, 'private', $pvAdded) === $pvPrivate && $pvReplace('privacy', 'de', $pvAdded, 'private') === null
    && $pvReplace('privacy', 'de', $pvAdded, 'private', $pvPrivate) === null && $pvReplace('privacy', 'de', $pvAdded . ' ', 'private', $pvAdded) === null);
$pvUpdate = static fn (bool $known): array => ['type' => 'private', 'known' => $known, 'changes' => array_slice($known ? $pvKnown : $pvUnknown, 0, 2)];
$pvForm = static fn (?array $update, bool $merged = false, ?string $loaded = null): string => App\View::capture('admin/page_form', ['title' => 'x',
    'key' => 'privacy', 'lang' => 'de', 'content' => '', 'variants' => ['private', 'school'], 'loaded' => $loaded, 'type' => 'private', 'update' => $update, 'merged' => $merged]);
$pvForms = ['known' => $pvForm($pvUpdate(true)), 'unknown' => $pvForm($pvUpdate(false)), 'merged' => $pvForm($pvUpdate(true), true),
    'loaded' => $pvForm(null, false, 'school'), 'none' => $pvForm(null)];
check('page form, newer template: what changed per section (old and new lines), take over the sections or confirm the reconciliation',
    str_contains($pvForms['known'], 'Die Vorlage „Privat betrieben“ wurde geändert, seit dieser Text auf ihr beruht.')
    && str_contains($pvForms['known'], '<summary>1. Verantwortliche</summary>') && str_contains($pvForms['known'], '<summary>2. Zweck der Verarbeitung</summary>')
    && str_contains($pvForms['known'], '<li class="diff-del"><span aria-hidden="true">−</span> <span class="visually-hidden">bisher in der Vorlage:</span> Verantwortlich im Sinne der DSGVO für diese Terminvergabe und alle darüber gebuchten Termine ist:</li>')
    && str_contains($pvForms['known'], '<li class="diff-add"><span aria-hidden="true">+</span> <span class="visually-hidden">neu in der Vorlage:</span> Verantwortlich im Sinne der DSGVO für diese Terminvergabe ist:</li>')
    && str_contains($pvForms['known'], 'href="/admin/page/privacy/de?merge=1"') && str_contains($pvForms['known'], '<input type="checkbox" id="base" name="base" value="private">')
    && !str_contains($pvForms['known'], 'type="hidden" name="base"'));
check('page form, no marker: the differences to the template (only in the text, only in the template)',
    str_contains($pvForms['unknown'], 'Mit welchem Stand der Vorlage „Privat betrieben“ dieser Text zuletzt abgeglichen wurde, ist nicht bekannt')
    && str_contains($pvForms['unknown'], 'nur in Ihrem Text:') && str_contains($pvForms['unknown'], 'nur in der Vorlage:') && !str_contains($pvForms['unknown'], 'bisher in der Vorlage:'));
check('page form, sections taken over or a template loaded: the form is marked as based on that template, no notice, no checkbox',
    str_contains($pvForms['merged'], 'Abschnitte aus der aktuellen Vorlage „Privat betrieben“ übernommen: 1. Verantwortliche, 2. Zweck der Verarbeitung.')
    && str_contains($pvForms['merged'], '<input type="hidden" name="base" value="private">') && !str_contains($pvForms['merged'], 'data-legal-update')
    && str_contains($pvForms['loaded'], '<input type="hidden" name="base" value="school">') && !str_contains($pvForms['loaded'], 'type="checkbox"')
    && !str_contains($pvForms['none'], 'name="base"') && !str_contains($pvForms['none'], 'data-legal'));
$pvTexts = static fn (array $texts): string => (string) (new ReflectionMethod(App\Controller\AdminController::class, 'outdatedTexts'))->invoke(null, $texts);
check('note on the dashboard: per text its sections, more than three as their number, in the admin language',
    $pvTexts([['key' => 'privacy', 'lang' => 'de', 'changes' => array_slice($pvKnown, 0, 2)], ['key' => 'imprint', 'lang' => 'en', 'changes' => [['heading' => null]]]])
        === 'Datenschutzerklärung (DE): 1. Verantwortliche, 2. Zweck der Verarbeitung; Impressum (EN): Einleitung'
    && $pvTexts([['key' => 'privacy', 'lang' => 'en', 'changes' => $pvUnknown]]) === 'Datenschutzerklärung (EN): 7 Abschnitte'
    && App\AdminI18n::with('en', static fn () => $pvTexts([['key' => 'privacy', 'lang' => 'en', 'changes' => $pvUnknown]])) === 'Privacy policy (EN): 7 sections');
