<?php
// Privacy: legal texts in existing installations (see README.md in this directory)
echo "Privacy: version markers of the legal texts – sections and placeholders\n";
// Expert review P1-11: edited texts are never replaced by a newer template. Admins see what the template changed
// since the text was based on it (version marker), or for an older text how it differs from the template.
$pvTpl = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/app/templates/pages/$name.md");
$pvFixture = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/tests/fixtures/$name.md");
// Like an admin (and legal_fill in tests/smoke.sh): every placeholder in single brackets filled in, the web host typed in
$pvFill = static fn (string $text, bool $host = true): string => str_replace('[[HOSTER]]', $host ? 'Beispiel Hosting GmbH, Serverweg 1, 12345 Serverstadt' : '[[HOSTER]]',
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
check('filled in within its line: a placeholder in a line takes in no following line, one alone on its line only if the text no longer has it',
    !$pvFills("- **Hosting:** Beispiel GmbH\n- **E-Mail-Versand:** über den Mailserver", "- **Hosting:** [[HOSTER]]")
    && !$pvFills("- **Hosting:** [[HOSTER]]\n- **E-Mail-Versand:** über den Mailserver", "- **Hosting:** [[HOSTER]]")
    && !$pvFills("Verantwortlich:\n[[VERANTWORTLICHE]]\nEin Satz, den die Vorlage nicht mehr hat.", "Verantwortlich:\n[[VERANTWORTLICHE]]")
    && $pvFills("Verantwortlich:\n[[VERANTWORTLICHE]]\nWeiter.", "Verantwortlich:\n[[VERANTWORTLICHE]]\nWeiter.") && $pvFills("Verantwortlich:\nWeiter.", "Verantwortlich:\n[[VERANTWORTLICHE]]\nWeiter.")
    && $pvFills("A\nLDI NRW, Düsseldorf.", "A\n[BEHÖRDE], Düsseldorf.") && !$pvFills("A LDI NRW, Düsseldorf.", "A\n[BEHÖRDE], Düsseldorf."));
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
    $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvAdded) === [['key' => 'Neu in der Vorlage', 'heading' => '# 12. Neu in der Vorlage', 'dropped' => false, 'diff' => [['+', '# 12. Neu in der Vorlage'], ['+', 'Neuer Satz.']]]]
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped)) === ['8. Sicherheit'] && $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped)[0]['diff'][0][0] === '-'
    && $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped)[0]['dropped'] && $pvChanges($pvDropped, $pvPrivate, $pvDropped) === []);
// A newer template that drops a line right after a placeholder (the paragraph after [[VERANTWORTLICHE]], the line
// after "- **Hosting:** [[HOSTER]]") or turns the wording of a line into a placeholder: the placeholder must not
// take in what the text still has
$pvMail = '- **E-Mail-Versand:** über den Mailserver des Hosters (siehe oben).';
$pvNext = ['controller' => (string) preg_replace('/^Das gilt für alles, was auf dieser Seite.*\n\n/mu', '', $pvPrivate, 1),
    'host' => str_replace("$pvMail\n", '', $pvPrivate), 'token' => str_replace($pvMail, '- **E-Mail-Versand:** [[MAILVERSAND]]', $pvPrivate)];
check('marker: a line the template dropped right after a placeholder, or worded as a placeholder now, is listed (web host filled in or not)',
    !in_array($pvPrivate, $pvNext, true)
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvNext['controller'])) === ['1. Verantwortliche']
    && in_array(['-', 'Das gilt für alles, was auf dieser Seite mit Ihren Angaben geschieht (Buchen, Ändern, Absagen, die dabei gespeicherten Daten, Schutz vor Missbrauch), und für die E-Mails, die die Terminvergabe verschickt – auch für die Zusammenfassungen an die Lehrkraft bis zu ihrem Versand.'], $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvNext['controller'])[0]['diff'], true)
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvNext['host'])) === ['6. Empfänger'] && $pvTitles($pvChanges($pvFill($pvPrivate, false), $pvPrivate, $pvNext['host'])) === ['6. Empfänger']
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvNext['token'])) === ['6. Empfänger'] && $pvTitles($pvChanges($pvFill($pvPrivate, false), $pvPrivate, $pvNext['token'])) === ['6. Empfänger']);
check('no marker: a line after [[VERANTWORTLICHE]] or after a line with [[HOSTER]] that the template does not have is a difference',
    $pvTitles($pvChanges($pvFill($pvPrivate), null, $pvNext['controller'])) === ['1. Verantwortliche'] && $pvTitles($pvChanges($pvFill($pvPrivate), null, $pvNext['host'])) === ['6. Empfänger']
    && $pvTitles($pvChanges($pvFill($pvPrivate, false), null, $pvNext['host'])) === ['6. Empfänger']);
// The other way round, with the current template: an earlier one had such a line (the text's marker)
$pvLog = (string) current(preg_grep('/^- \*\*Server-Protokolle:\*\*/u', explode("\n", $pvPrivate)));
$pvEarlier = ['controller' => str_replace("[[VERANTWORTLICHE]]\n", "[[VERANTWORTLICHE]]\n\nDiesen Satz gab es nur in der früheren Vorlage.\n", $pvPrivate),
    'host' => str_replace("- **Hosting:** [[HOSTER]]\n", "- **Hosting:** [[HOSTER]]\n- **Früher:** ein Empfänger, den es nicht mehr gibt.\n", $pvPrivate),
    'token' => str_replace('- **Hosting:** [[HOSTER]]', '- **Hosting:** bei dem Hoster, den die frühere Vorlage nannte', $pvPrivate),
    'tail' => str_replace($pvLog, "$pvLog Diesen Satz gab es nur in der früheren Vorlage.", $pvPrivate)];
$pvFollows = static fn (string $text, ?array $base = null): bool => App\LegalTexts::follows('privacy', 'de', $text, 'private', $base);
check('marker of an earlier template with such a line, or one whose line with a placeholder said more: the text does not follow the current template',
    count(array_unique([$pvPrivate, ...array_values($pvEarlier)])) === 5 && str_starts_with($pvLog, '- **Server-Protokolle:**')
    && !array_filter($pvEarlier, static fn (string $old): bool => $pvFollows($pvFill($old), ['type' => 'private', 'content' => $old]))
    && $pvTitles($pvChanges($pvFill($pvEarlier['tail']), $pvEarlier['tail'], $pvPrivate)) === ['3. Welche Daten verarbeitet werden']
    && $pvTitles($pvChanges($pvFill($pvEarlier['token']), $pvEarlier['token'], $pvPrivate)) === ['6. Empfänger']
    && $pvFollows($pvFill($pvPrivate), ['type' => 'private', 'content' => $pvPrivate]));
$pvWithPlaceholder = str_replace($pvMail, str_replace('Mailserver des', 'Mailserver [DES HOSTERS ODER EIN EIGENER] des', $pvMail), $pvPrivate);
check('marker: a line of the current template is never the wording of an earlier one, even if it fills in a line of it that had a placeholder more',
    $pvWithPlaceholder !== $pvPrivate && $pvChanges($pvFill($pvPrivate), $pvWithPlaceholder, $pvPrivate) === []
    && $pvTitles($pvChanges($pvFill($pvWithPlaceholder), $pvWithPlaceholder, $pvPrivate)) === ['6. Empfänger']);
$pvRenamed = str_replace('[ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE MIT ADRESSE]', '[AUFSICHTSBEHÖRDE]', $pvPrivate);
check('marker: a placeholder that only got a new name is filled in by the text as before (not listed, kept on taking over)',
    $pvRenamed !== $pvPrivate && $pvChanges($pvFill($pvRenamed), $pvRenamed, $pvPrivate) === [] && $pvFollows($pvFill($pvRenamed), ['type' => 'private', 'content' => $pvRenamed])
    && substr_count(App\LegalTexts::merge($pvFill($pvRenamed), $pvPrivate, ['Ihre Rechte'], $pvRenamed), 'Testangabe') === substr_count($pvFill($pvPrivate), 'Testangabe'));
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
$pvOwnSentence = (string) preg_replace('/^# 8\. .*$/mu', "$0\n\nUnser eigener Satz.", $pvFill($pvPrivate), 1);
check('with a marker of the operator type a text follows the template if it has what the template changed since (own sentences elsewhere are fine); a marker of the other type counts as none',
    $pvFollows($pvOwnSentence, ['type' => 'private', 'content' => $pvV17]) && $pvFollows($pvOwnSentence, ['type' => 'private', 'content' => $pvPrivate])
    && !$pvFollows($pvOwnSentence, ['type' => 'school', 'content' => $pvPrivate]) && !$pvFollows($pvOwnSentence)
    && !$pvFollows($pvFill($pvV17), ['type' => 'private', 'content' => $pvV17]) && !$pvFollows($pvOwn, ['type' => 'private', 'content' => $pvV17])
    && $pvFollows(App\LegalTexts::merge($pvOwn, $pvPrivate, array_column($pvKnown, 'key'), $pvV17), ['type' => 'private', 'content' => $pvV17])
    && !$pvFollows(App\LegalTexts::merge($pvOwn, $pvPrivate, array_column($pvKnown, 'key'), $pvV17)));

echo "Privacy: version markers of the legal texts – taking over sections\n";
$pvMerge = [App\LegalTexts::class, 'merge'];
$pvMerged = $pvMerge($pvOwn, $pvPrivate, array_column($pvUnknown, 'key'));
check('taken over: the text then has the wording of the template, filled-in lines of these sections kept, new placeholders to fill in',
    $pvTitles($pvChanges($pvMerged, null, $pvPrivate)) === ['12. Kontakt'] && substr_count($pvMerged, 'Testangabe') === 2 && str_contains($pvMerged, 'in Protokolldateien. Testangabe')
    && App\LegalTexts::placeholders($pvMerged) === ['[[VERANTWORTLICHE]]', '[BITTE PRÜFEN UND DANN DIESE KLAMMER LÖSCHEN: IST EINE ODER EINER BENANNT (Z. B. BEI EINEM VEREIN ODER FREIEN TRÄGER), STATT DES SATZES DAVOR DEREN KONTAKTDATEN ANGEBEN.]']
    && !str_contains($pvMerged, 'alle darüber gebuchten Termine'));
check('taken over without a marker: a section only the text has stays (it may be the operator\'s own); with a marker that has it, the template dropped it: removed',
    str_ends_with($pvMerged, "# 12. Kontakt\n\nEigener Abschnitt.\n") && $pvUnknown[6]['dropped'] && !$pvUnknown[0]['dropped']
    && !str_contains($pvMerge($pvFill($pvPrivate), $pvDropped, ['Sicherheit'], $pvPrivate), '# 8. Sicherheit')
    && str_contains($pvMerge($pvFill($pvPrivate), $pvDropped, ['Sicherheit']), '# 8. Sicherheit')
    && str_ends_with($pvMerge($pvOwn, $pvPrivate, array_column($pvKnown, 'key'), $pvV17), "# 12. Kontakt\n\nEigener Abschnitt.\n"));
$pvStale = $pvMerge($pvFill($pvEarlier['token']), $pvPrivate, ['Empfänger'], $pvEarlier['token']);
$pvTail = $pvMerge($pvFill($pvEarlier['tail']), $pvPrivate, ['Welche Daten verarbeitet werden'], $pvEarlier['tail']);
check('taken over with a marker: a line of the earlier template is not kept as if it filled in a placeholder of the current one',
    str_contains($pvStale, "- **Hosting:** [[HOSTER]]\n") && !str_contains($pvStale, 'frühere Vorlage') && $pvChanges($pvStale, $pvEarlier['token'], $pvPrivate) === []
    && str_contains($pvMerge($pvFill($pvEarlier['token']), $pvPrivate, ['Empfänger']), 'frühere Vorlage')
    && str_contains($pvTail, $pvLog) && !str_contains($pvTail, 'früheren Vorlage') && $pvChanges($pvTail, $pvEarlier['tail'], $pvPrivate) === []);
$pvOperator = str_replace('[[VERANTWORTLICHE]]', "Förderverein XY e. V.\nvertreten durch den Vorstand", $pvFill($pvV17));
$pvOperatorMerged = $pvMerge($pvOperator, $pvPrivate, array_column($pvKnown, 'key'), $pvV17);
check('taken over: what the text has in place of a line that is only a placeholder ([[VERANTWORTLICHE]]) stays there',
    str_contains($pvOperatorMerged, "Terminvergabe ist:\n\nFörderverein XY e. V.\nvertreten durch den Vorstand\n\nDas gilt für alles")
    && !str_contains($pvOperatorMerged, '[[VERANTWORTLICHE]]') && $pvChanges($pvOperatorMerged, $pvV17, $pvPrivate) === []);
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
$pvUpdate = static fn (bool $known, ?array $changes = null): array => ['type' => 'private', 'known' => $known, 'base' => null, 'changes' => $changes ?? array_slice($known ? $pvKnown : $pvUnknown, 0, 2)];
$pvForm = static fn (?array $update, bool $merged = false, ?string $loaded = null): string => App\View::capture('admin/page_form', ['title' => 'x',
    'key' => 'privacy', 'lang' => 'de', 'content' => '', 'variants' => ['private', 'school'], 'loaded' => $loaded, 'type' => 'private', 'update' => $update, 'merged' => $merged, 'templateHash' => 'c0ffee']);
$pvOwnOnly = [array_merge(end($pvUnknown), ['heading' => '# 12. Videosprechstunde'])];
$pvDroppedChange = $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped);
$pvForms = ['known' => $pvForm($pvUpdate(true)), 'unknown' => $pvForm($pvUpdate(false)), 'merged' => $pvForm($pvUpdate(true), true),
    'loaded' => $pvForm(null, false, 'school'), 'none' => $pvForm(null),
    'mergedOwn' => $pvForm($pvUpdate(false, [$pvUnknown[0], ...$pvOwnOnly]), true), 'mergedDropped' => $pvForm($pvUpdate(true, [$pvKnown[0], ...$pvDroppedChange]), true),
    'ownOnly' => $pvForm($pvUpdate(false, $pvOwnOnly)), 'unknownOwn' => $pvForm($pvUpdate(false, [$pvUnknown[0], ...$pvOwnOnly]))];
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
check('page form: the template it shows goes with the form (base_hash), so that saving records no newer one',
    str_contains($pvForms['known'], '<input type="hidden" name="base_hash" value="c0ffee">') && str_contains($pvForms['merged'], 'name="base_hash" value="c0ffee"')
    && str_contains($pvForms['loaded'], 'name="base_hash" value="c0ffee"') && !str_contains($pvForms['none'], 'base_hash'));
check('page form, sections taken over: removed and kept sections named as such, the differences still shown for comparison',
    str_contains($pvForms['mergedOwn'], 'übernommen: 1. Verantwortliche. Unverändert, weil es sie nur in Ihrem Text gibt (eigene oder aus einer früheren Vorlage – bitte prüfen, ob sie noch zutreffen): 12. Videosprechstunde.')
    && !str_contains($pvForms['mergedOwn'], 'Entfernt') && str_contains($pvForms['mergedOwn'], '<summary>12. Videosprechstunde</summary>') && str_contains($pvForms['mergedOwn'], 'Zum Vergleich')
    && str_contains($pvForms['mergedDropped'], 'übernommen: 1. Verantwortliche. Entfernt, weil die Vorlage sie nicht mehr enthält: 8. Sicherheit.') && !str_contains($pvForms['mergedDropped'], 'Unverändert')
    && !str_contains($pvForms['merged'], 'Entfernt') && !str_contains($pvForms['merged'], 'Unverändert') && !str_contains($pvForms['merged'], '?merge=1'));
check('page form, no marker: sections only in the text are left as they are when taking over; if there is nothing else, no button, only the box',
    str_contains($pvForms['unknownOwn'], '?merge=1') && str_contains($pvForms['unknownOwn'], 'Abschnitte, die es nur in Ihrem Text gibt, bleiben beim Übernehmen unverändert.')
    && !str_contains($pvForms['unknown'], 'bleiben beim Übernehmen unverändert')
    && !str_contains($pvForms['ownOnly'], '?merge=1') && str_contains($pvForms['ownOnly'], 'Diese Abschnitte gibt es nur in Ihrem Text.')
    && str_contains($pvForms['ownOnly'], 'name="base" value="private">') && !App\LegalTexts::mergeable($pvUpdate(false, $pvOwnOnly)) && App\LegalTexts::mergeable($pvUpdate(true, $pvDroppedChange)));
$pvTexts = static fn (array $texts): string => (string) (new ReflectionMethod(App\Controller\AdminController::class, 'outdatedTexts'))->invoke(null, $texts);
check('note on the dashboard: per text its sections, more than three as their number, in the admin language',
    $pvTexts([['key' => 'privacy', 'lang' => 'de', 'changes' => array_slice($pvKnown, 0, 2)], ['key' => 'imprint', 'lang' => 'en', 'changes' => [['heading' => null]]]])
        === 'Datenschutzerklärung (DE): 1. Verantwortliche, 2. Zweck der Verarbeitung; Impressum (EN): Einleitung'
    && $pvTexts([['key' => 'privacy', 'lang' => 'en', 'changes' => $pvUnknown]]) === 'Datenschutzerklärung (EN): 7 Abschnitte'
    && App\AdminI18n::with('en', static fn () => $pvTexts([['key' => 'privacy', 'lang' => 'en', 'changes' => $pvUnknown]])) === 'Privacy policy (EN): 7 sections');
check('privacy templates: the same numbered first-level sections for both operator types in German and English (compared per section)',
    count(array_unique(array_map(static fn (string $n): string => implode(',', preg_match_all('/^# (\d+)\. /m', $pvTpl($n), $m) ? $m[1] : []),
        ['privacy.de', 'privacy.en', 'privacy-private.de', 'privacy-private.en']))) === 1 && preg_match_all('/^# \d+\. /m', $pvPrivate) >= 10);
