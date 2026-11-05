<?php
// Privacy: legal texts in existing installations, information in all parent languages in two levels, no required
// checkbox (see README.md in this directory)
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
check('line diff with placeholders: a line with little fixed text ("- [WEITERE …]") does not take a line away from the line it is (identical lines first)',
    $pvDiff(['- **A:** eins', '- **B:** zwei'], ['- **A:** eins', '- [WEITERE EMPFÄNGER]', '- **B:** zwei'], true) === [[' ', '- **A:** eins'], ['+', '- [WEITERE EMPFÄNGER]'], [' ', '- **B:** zwei']]
    && $pvDiff(['- **A:** eins', '- **C:** drei'], ['- **A:** eins', '- [WEITERE EMPFÄNGER]'], true) === [[' ', '- **A:** eins'], [' ', '- **C:** drei']]);

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
    $pvTitles($pvKnown) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '4. Rechtsgrundlagen', '5. Speicherdauer und Löschung', '6. Empfänger', '9. Ihre Rechte']
    && in_array(['-', 'Verantwortlich im Sinne der DSGVO für diese Terminvergabe und alle darüber gebuchten Termine ist:'], $pvKnown[0]['diff'], true)
    && in_array(['+', 'Verantwortlich im Sinne der DSGVO für diese Terminvergabe ist:'], $pvKnown[0]['diff'], true)
    && !array_filter(array_merge(...array_column($pvKnown, 'diff')), static fn (array $d): bool => $d[0] === ' ' || str_contains($d[1], 'Testangabe')));
$pvOwn = str_replace('Die Verbindung zu dieser Seite ist per TLS', 'Unsere Verbindung ist per TLS', $pvFill($pvV17)) . "\n# 12. Kontakt\n\nEigener Abschnitt.\n";
$pvApplied = str_replace('und alle darüber gebuchten Termine ist:', 'ist:', $pvOwn);
check('marker: own changes in sections the template did not change are not listed, nor sections the text already took over',
    $pvTitles($pvChanges($pvOwn, $pvV17, $pvPrivate)) === $pvTitles($pvKnown)
    && !in_array('1. Verantwortliche', $pvTitles($pvChanges(App\LegalTexts::merge($pvApplied, $pvPrivate, ['Verantwortliche']), $pvV17, $pvPrivate)), true)
    && $pvChanges($pvOwn, $pvPrivate, $pvPrivate) === []);
// A placeholder a newer template inserts mid-line (blanks around it) or before a punctuation mark: the blank
// left where it is removed must not make the line seem to say more than the earlier one (talkslot PR #41)
$pvMail = '- **E-Mail-Versand:** über den Mailserver des Hosters (siehe oben).';
$pvMid = str_replace($pvMail, '- **E-Mail-Versand:** über den Mailserver des Hosters [ODER NAME UND ANSCHRIFT DES MAILDIENSTES] (siehe oben).', $pvPrivate);
$pvPunct = str_replace('Art. 77 DSGVO),', 'Art. 77 DSGVO) [FRIST],', $pvPrivate);
check('marker: a placeholder the template inserts mid-line or before a punctuation mark is reported (the text does not follow then)',
    str_contains($pvPrivate, $pvMail) && str_contains($pvPrivate, 'Art. 77 DSGVO),')
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvMid)) === ['6. Empfänger']
    && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvPunct)) === ['9. Ihre Rechte']);
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
// A newer template that only adds a placeholder, without new fixed text: a note to check at the end of a line, a
// second detail after [[HOSTER]], a paragraph of its own. A placeholder may be filled in by nothing, so the text's
// earlier line would seem to fill in the new one
$pvNote = '[BITTE PRÜFEN: NUTZEN SIE EINEN EXTERNEN MAILDIENST? DANN HIER MIT ANSCHRIFT NENNEN.]';
$pvContract = '[AUFTRAGSVERARBEITUNG NACH ART. 28 DSGVO: VERTRAG VOM]';
$pvAlone = '[BITTE PRÜFEN UND DANN LÖSCHEN: NUTZEN SIE EINEN EXTERNEN MAILDIENST? DANN OBEN NENNEN.]';
$pvPlus = ['note' => str_replace($pvMail, "$pvMail $pvNote", $pvPrivate), 'host' => str_replace('- **Hosting:** [[HOSTER]]', "- **Hosting:** [[HOSTER]] $pvContract", $pvPrivate),
    'alone' => str_replace($pvMail, "$pvMail\n\n$pvAlone", $pvPrivate)];
check('marker: a placeholder the template added to a line or as a paragraph of its own is listed (web host filled in or not), a renamed one is not',
    count(array_unique([$pvPrivate, ...array_values($pvPlus)])) === 4
    && !array_filter($pvPlus, static fn (string $next): bool => $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $next)) !== ['6. Empfänger']
        || $pvTitles($pvChanges($pvFill($pvPrivate, false), $pvPrivate, $next)) !== ['6. Empfänger'])
    && in_array(['+', "$pvMail $pvNote"], $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvPlus['note'])[0]['diff'], true)
    && in_array(['+', $pvAlone], $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvPlus['alone'])[0]['diff'], true)
    && $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvRenamed) === []);
// The other way round, with the current template: the marker is an earlier one without such a placeholder
$pvWithout = ['note' => (string) preg_replace('/^(\*\*Datenschutzbeauftragte .*?)\h\[BITTE PRÜFEN[^\]]*\]$/mu', '$1', $pvPrivate),
    'alone' => str_replace("[[VERANTWORTLICHE]]\n\n", '', $pvPrivate)];
check('marker of an earlier template without a placeholder the current one has (a note to check, a paragraph of its own): the text does not follow it',
    !in_array($pvPrivate, $pvWithout, true) && count(App\LegalTexts::placeholders($pvWithout['note'])) === count(App\LegalTexts::placeholders($pvPrivate)) - 1
    && !array_filter($pvWithout, static fn (string $old): bool => $pvFollows($pvFill($old), ['type' => 'private', 'content' => $old]))
    && $pvTitles($pvChanges($pvFill($pvWithout['note']), $pvWithout['note'], $pvPrivate)) === ['1. Verantwortliche']
    && $pvTitles($pvChanges($pvFill($pvWithout['alone']), $pvWithout['alone'], $pvPrivate)) === ['1. Verantwortliche']);
// Without a marker (edited before them): where the text differs from the current template
$pvUnknown = $pvChanges($pvOwn, null, $pvPrivate);
check('no marker: the sections that differ from the template (also own changes and own sections), lines only in the text and only in the template',
    $pvTitles($pvUnknown) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '4. Rechtsgrundlagen', '5. Speicherdauer und Löschung', '6. Empfänger', '8. Sicherheit', '9. Ihre Rechte', '12. Kontakt']
    && $pvUnknown[5]['diff'][0][0] === '-' && str_starts_with($pvUnknown[5]['diff'][0][1], 'Unsere Verbindung ist per TLS') && $pvUnknown[5]['diff'][1][0] === '+'
    && end($pvUnknown)['diff'] === [['-', '# 12. Kontakt'], ['-', 'Eigener Abschnitt.']]
    && !array_filter($pvUnknown[6]['diff'], static fn (array $d): bool => str_contains($d[1], 'Aufsichtsbehörde zu beschweren'))); // filled in, the same
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
    str_ends_with($pvMerged, "# 12. Kontakt\n\nEigener Abschnitt.\n") && $pvUnknown[7]['dropped'] && !$pvUnknown[0]['dropped']
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
$pvPlusMerged = static fn (string $k, bool $host = true): string => $pvMerge($pvFill($pvPrivate, $host), $pvPlus[$k], ['Empfänger'], $pvPrivate);
$pvLost = [App\LegalTexts::class, 'lost'];
check('taken over: a placeholder the template only added comes into the text (web host filled in or not), the other details stay',
    !array_filter(['note' => $pvNote, 'host' => $pvContract, 'alone' => $pvAlone], static fn (string $p, string $k): bool => !in_array($p, App\LegalTexts::placeholders($pvPlusMerged($k)), true)
        || !in_array($p, App\LegalTexts::placeholders($pvPlusMerged($k, false)), true), ARRAY_FILTER_USE_BOTH)
    && str_contains($pvPlusMerged('note'), "- **Hosting:** Beispiel Hosting GmbH, Serverweg 1, 12345 Serverstadt\n$pvMail $pvNote\n")
    && str_contains($pvPlusMerged('alone'), "$pvMail\n\n$pvAlone\n- **Übersetzung") && str_contains($pvPlusMerged('host', false), "- **Hosting:** [[HOSTER]] $pvContract\n")
    && substr_count($pvPlusMerged('note'), 'Testangabe') === substr_count($pvFill($pvPrivate), 'Testangabe'));
// The template adds a list item with little fixed text: inserted once, the other items each once
$pvItem = str_replace($pvMail, "$pvMail\n- [WEITERE EMPFÄNGER, Z. B. EIN EXTERNER MAILDIENST, MIT ANSCHRIFT]", $pvPrivate);
$pvItemMerged = $pvMerge($pvFill($pvPrivate), $pvItem, ['Empfänger'], $pvPrivate);
check('taken over: a new list item that is little more than a placeholder is inserted where the template has it, no other item twice',
    str_contains($pvItemMerged, "$pvMail\n- [WEITERE EMPFÄNGER, Z. B. EIN EXTERNER MAILDIENST, MIT ANSCHRIFT]\n- **Übersetzung")
    && substr_count($pvItemMerged, '- **Übersetzung der Texte der Lehrkraft:**') === 1 && $pvTitles($pvChanges($pvFill($pvPrivate), $pvPrivate, $pvItem)) === ['6. Empfänger']);
// Details that go on in the lines below a filled-in placeholder (name, address and e-mail of the school's data
// protection officer; the web host with its address on an indented line) stay when the section is taken over
$pvSchool = $pvTpl('privacy.de');
$pvDuty = 'nutzen sie im Rahmen ihrer dienstlichen Aufgaben für die Schule.';
$pvSchoolOld = str_replace($pvDuty, 'nutzen sie dienstlich.', $pvSchool);
$pvDpo = "Frau Erika Muster, Schulamt Beispielstadt\nBehördlicher Datenschutz, Amtsweg 2, 12345 Beispielstadt\ndatenschutz@schulamt-beispiel.de";
$pvDpoText = $pvFill(str_replace('[KONTAKTDATEN DER/DES DATENSCHUTZBEAUFTRAGTEN DER SCHULE]', $pvDpo, $pvSchoolOld));
$pvDpoMerged = $pvMerge($pvDpoText, $pvSchool, ['Verantwortliche'], $pvSchoolOld);
$pvHostText = str_replace('- **Hosting:** [[HOSTER]]', "- **Hosting:** Beispiel GmbH,\n  Serverweg 1, 12345 Serverstadt", $pvFill($pvEarlier['host'], false));
$pvHostMerged = $pvMerge($pvHostText, $pvPrivate, ['Empfänger'], $pvEarlier['host']);
check('taken over: details that go on in the lines below a filled-in placeholder stay (with or without a marker), the next list item is not part of them',
    $pvSchoolOld !== $pvSchool && $pvTitles($pvChanges($pvDpoText, $pvSchoolOld, $pvSchool)) === ['1. Verantwortliche']
    && str_contains($pvDpoMerged, "$pvDuty\n\n**Datenschutzbeauftragte bzw. Datenschutzbeauftragter der Schule:** $pvDpo\n\n# 2.")
    && str_contains($pvMerge($pvDpoText, $pvSchool, ['Verantwortliche']), "Schule:** $pvDpo\n\n# 2.") && $pvLost($pvDpoText, $pvDpoMerged, ['Verantwortliche'], $pvSchoolOld) === []
    && str_contains($pvHostMerged, "- **Hosting:** Beispiel GmbH,\n  Serverweg 1, 12345 Serverstadt\n$pvMail\n") && !str_contains($pvHostMerged, 'Früher')
    && $pvLost($pvHostText, $pvHostMerged, ['Empfänger'], $pvEarlier['host']) === []);
$pvDpoPara = $pvFill(str_replace('[KONTAKTDATEN DER/DES DATENSCHUTZBEAUFTRAGTEN DER SCHULE]', "$pvDpo\n\nEin eigener Absatz.", $pvSchoolOld));
$pvHostItem = str_replace("Serverstadt\n", "Serverstadt\n- **Eigener Dienst:** Beispiel\n", $pvHostText);
check('taken over: a paragraph after a blank line or a new list item is no longer part of the details above, it is listed as not taken over',
    str_contains($pvMerge($pvDpoPara, $pvSchool, ['Verantwortliche'], $pvSchoolOld), "Schule:** $pvDpo\n\n# 2.")
    && $pvLost($pvDpoPara, $pvMerge($pvDpoPara, $pvSchool, ['Verantwortliche'], $pvSchoolOld), ['Verantwortliche'], $pvSchoolOld) === ['Ein eigener Absatz.']
    && str_contains($pvMerge($pvHostItem, $pvPrivate, ['Empfänger'], $pvEarlier['host']), "Serverstadt\n$pvMail\n")
    && $pvLost($pvHostItem, $pvMerge($pvHostItem, $pvPrivate, ['Empfänger'], $pvEarlier['host']), ['Empfänger'], $pvEarlier['host']) === ['- **Eigener Dienst:** Beispiel']);
$pvOwnLine = str_replace($pvMail, "$pvMail\n\nUnser eigener Satz zu den Empfängern.", $pvFill($pvPrivate));
$pvBelow = (string) preg_replace('/^(Sie haben zudem das Recht.*Testangabe\.)$/mu', "$1\nDirekt darunter eine eigene Zeile.", $pvFill($pvRenamed));
check('taken over: the line below one with a placeholder inside it is not part of its details; own lines where the template added a placeholder do not stand in for it',
    $pvBelow !== $pvFill($pvRenamed)
    && $pvLost($pvBelow, $pvMerge($pvBelow, $pvPrivate, ['Ihre Rechte'], $pvRenamed), ['Ihre Rechte'], $pvRenamed) === ['Direkt darunter eine eigene Zeile.']
    && str_contains($pvMerge($pvOwnLine, $pvPlus['alone'], ['Empfänger'], $pvPrivate), "$pvMail\n\n$pvAlone\n")
    && $pvLost($pvOwnLine, $pvMerge($pvOwnLine, $pvPlus['alone'], ['Empfänger'], $pvPrivate), ['Empfänger'], $pvPrivate) === ['Unser eigener Satz zu den Empfängern.']);
check('taken over: the lines of the text the sections taken over no longer have are listed for the admin (own lines, details in a line the template changed), not the earlier wording of the template',
    $pvLost($pvOwnLine, $pvMerge($pvOwnLine, $pvPlus['note'], ['Empfänger'], $pvPrivate), ['Empfänger'], $pvPrivate) === ['Unser eigener Satz zu den Empfängern.']
    && $pvLost($pvFill($pvPrivate), $pvPlusMerged('host'), ['Empfänger'], $pvPrivate) === ['- **Hosting:** Beispiel Hosting GmbH, Serverweg 1, 12345 Serverstadt']
    && $pvLost($pvFill($pvPrivate, false), $pvPlusMerged('host', false), ['Empfänger'], $pvPrivate) === [] && $pvLost($pvOwnLine, $pvOwnLine, [], $pvPrivate) === []
    && $pvLost($pvFill($pvV17), $pvMerge($pvFill($pvV17), $pvPrivate, array_column($pvKnown, 'key'), $pvV17), array_column($pvKnown, 'key'), $pvV17) === ['**Datenschutzbeauftragte bzw. Datenschutzbeauftragter:** Testangabe']);
$pvPartial = $pvMerge($pvOwn, $pvPrivate, ['Rechtsgrundlagen']);
check('taken over: only the sections chosen, the others as they were (own sections too)',
    $pvTitles($pvChanges($pvPartial, null, $pvPrivate)) === ['1. Verantwortliche', '2. Zweck der Verarbeitung', '5. Speicherdauer und Löschung', '6. Empfänger', '8. Sicherheit', '9. Ihre Rechte', '12. Kontakt']
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
$pvForm = static fn (?array $update, bool $merged = false, ?string $loaded = null, array $lost = []): string => App\View::capture('admin/page_form', ['title' => 'x',
    'key' => 'privacy', 'lang' => 'de', 'content' => '', 'variants' => ['private', 'school'], 'loaded' => $loaded, 'type' => 'private', 'update' => $update, 'merged' => $merged,
    'lost' => $lost, 'templateHash' => 'c0ffee']);
$pvOwnOnly = [array_merge(end($pvUnknown), ['heading' => '# 12. Videosprechstunde'])];
$pvDroppedChange = $pvChanges($pvFill($pvPrivate), $pvPrivate, $pvDropped);
$pvForms = ['known' => $pvForm($pvUpdate(true)), 'unknown' => $pvForm($pvUpdate(false)), 'merged' => $pvForm($pvUpdate(true), true),
    'loaded' => $pvForm(null, false, 'school'), 'none' => $pvForm(null),
    'mergedOwn' => $pvForm($pvUpdate(false, [$pvUnknown[0], ...$pvOwnOnly]), true), 'mergedDropped' => $pvForm($pvUpdate(true, [$pvKnown[0], ...$pvDroppedChange]), true),
    'ownOnly' => $pvForm($pvUpdate(false, $pvOwnOnly)), 'unknownOwn' => $pvForm($pvUpdate(false, [$pvUnknown[0], ...$pvOwnOnly])),
    'mergedLost' => $pvForm($pvUpdate(true), true, null, ['Unser eigener Satz <zu> den Empfängern.', '- **Hosting:** Beispiel GmbH'])];
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
check('page form, sections taken over: the lines of the text that are not taken over are listed to check (each one, escaped), else no such list',
    str_contains($pvForms['mergedLost'], 'Aus Ihrem Text nicht übernommen (eigene Zeilen oder Ihre Angaben in Zeilen, die die Vorlage geändert hat) – bitte prüfen, ob etwas davon wieder in den Text gehört:')
    && str_contains($pvForms['mergedLost'], '<li class="diff-del"><span aria-hidden="true">−</span> <span class="visually-hidden">nicht übernommen:</span> Unser eigener Satz &lt;zu&gt; den Empfängern.</li>')
    && str_contains($pvForms['mergedLost'], 'nicht übernommen:</span> - **Hosting:** Beispiel GmbH</li>') && str_contains($pvForms['mergedLost'], 'data-legal-lost')
    && !str_contains($pvForms['merged'], 'data-legal-lost') && !str_contains($pvForms['known'], 'data-legal-lost')
    && str_contains(App\AdminI18n::with('en', static fn () => $pvForm($pvUpdate(true), true, null, ['x'])), 'Not taken over from your text'));
$pvMarker = static fn (string $type, ?string $template): ?string => (new ReflectionMethod(App\LegalTexts::class, 'marker'))->invoke(null, $type, $template);
check('version marker as stored: operator type and wording of the template; none without a template or for one too long for settings.value (an older one is cleared)',
    json_decode((string) $pvMarker('private', $pvPrivate), true) === ['type' => 'private', 'content' => $pvPrivate] && $pvMarker('school', null) === null
    && $pvMarker('school', str_repeat('ä', 33000)) === null && $pvMarker('school', str_repeat('a', 64000)) !== null);
$pvTexts = static fn (array $texts): string => (string) (new ReflectionMethod(App\Controller\AdminController::class, 'outdatedTexts'))->invoke(null, $texts);
check('note on the dashboard: per text its sections, more than three as their number, in the admin language',
    $pvTexts([['key' => 'privacy', 'lang' => 'de', 'changes' => array_slice($pvKnown, 0, 2)], ['key' => 'imprint', 'lang' => 'en', 'changes' => [['heading' => null]]]])
        === 'Datenschutzerklärung (DE): 1. Verantwortliche, 2. Zweck der Verarbeitung; Impressum (EN): Einleitung'
    && $pvTexts([['key' => 'privacy', 'lang' => 'en', 'changes' => $pvUnknown]]) === 'Datenschutzerklärung (EN): 8 Abschnitte'
    && App\AdminI18n::with('en', static fn () => $pvTexts([['key' => 'privacy', 'lang' => 'en', 'changes' => $pvUnknown]])) === 'Privacy policy (EN): 8 sections');
check('privacy templates: the same numbered first-level sections for both operator types in German and English (compared per section)',
    count(array_unique(array_map(static fn (string $n): string => implode(',', preg_match_all('/^# (\d+)\. /m', $pvTpl($n), $m) ? $m[1] : []),
        ['privacy.de', 'privacy.en', 'privacy-private.de', 'privacy-private.en']))) === 1 && preg_match_all('/^# \d+\. /m', $pvPrivate) >= 10);

echo "Privacy: the privacy policy in all parent languages\n";
// Expert review P1-9: the full privacy policy existed only in German and English, while the parent pages are offered
// in six languages (Art. 12(1) GDPR). Translations of both templates, with the German placeholders, the German version
// authoritative.
$pvCfg = (static function (): array { return require dirname(__DIR__, 2) . '/config/config.php'; })();
$pvTpl = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/app/templates/pages/$name.md");
$pvTranslations = ['pl' => 'To jest tłumaczenie. Wiążąca jest wersja niemiecka.', 'ru' => 'Это перевод. Юридически обязательной является версия на немецком языке.',
    'uk' => 'Це переклад. Юридично обовʼязковою є версія німецькою мовою.', 'tr' => 'Bu bir çeviridir. Bağlayıcı olan Almanca metindir.'];
$pvSections = static fn (string $text): int => preg_match_all('/^# \d+\. /m', $text);
$pvSorted = static function (array $list): array { sort($list); return $list; };
foreach (App\LegalTexts::VARIANTS['privacy'] as $pvType => $pvName) {
    $pvDe = $pvTpl("$pvName.de");
    $pvBad = [];
    foreach ($pvTranslations as $pvLang => $pvNote) {
        $pvText = (string) App\LegalTexts::template('privacy', $pvType, $pvLang);
        if ($pvText === '' || !str_starts_with($pvText, "> $pvNote\n\n")) {
            $pvBad[] = "$pvLang: note";
        }
        // The same placeholders as the German template, so that admins can copy their details from there
        if ($pvSorted(App\LegalTexts::placeholders($pvText)) !== $pvSorted(App\LegalTexts::placeholders($pvDe))) {
            $pvBad[] = "$pvLang: placeholders";
        }
        if ($pvSections($pvText) !== $pvSections($pvDe) || substr_count($pvText, "\n- ") !== substr_count($pvDe, "\n- ")) {
            $pvBad[] = "$pvLang: structure";
        }
        // The notes on the operator type also find the wording of the translations (GDPR cited as 6(1)(e), art. 6 ust. 1
        // lit. e), also once the admins have filled in the German placeholders (e.g. [VORSCHRIFT IM SCHULGESETZ …])
        $pvOther = $pvType === 'school' ? 'private' : 'school';
        $pvFilled = str_replace(App\LegalTexts::placeholders($pvText), 'Testangabe', $pvText);
        if (App\LegalTexts::mismatches($pvFilled, $pvType) !== [] || App\LegalTexts::mismatches($pvFilled, $pvOther) !== [$pvOther === 'school' ? 'private' : 'school']
            || App\LegalTexts::mismatches($pvText, $pvOther) !== [$pvOther === 'school' ? 'private' : 'school']) {
            $pvBad[] = "$pvLang: markers";
        }
        if (App\LegalTexts::templateType('privacy', $pvText) !== $pvType) {
            $pvBad[] = "$pvLang: unedited template";
        }
    }
    check("privacy policy ($pvType): translations into pl, ru, uk and tr with the note that the German version is authoritative, "
        . 'the placeholders and sections of the German one, recognised by the notes and as unedited' . ($pvBad ? ' (' . implode(', ', $pvBad) . ')' : ''), !$pvBad);
}
check('English templates: the German version is authoritative too; the German ones have no such note',
    str_starts_with($pvTpl('privacy.en'), "> This is a translation. The German version is authoritative.\n\n")
    && str_starts_with($pvTpl('privacy-private.en'), "> This is a translation. The German version is authoritative.\n\n")
    && !str_starts_with($pvTpl('privacy.de'), '>') && !str_starts_with($pvTpl('privacy-private.de'), '>'));
check('no template mentions the required checkbox any more, they say that booking needs no consent (de/en)',
    !array_filter(glob(APP_ROOT . '/app/templates/pages/*.md') ?: [], static fn (string $f): bool => App\LegalTexts::obsolete((string) file_get_contents($f)) !== [])
    && str_contains($pvTpl('privacy.de'), 'Für die Terminbuchung brauchen Sie keine Einwilligung zu erteilen')
    && str_contains($pvTpl('privacy-private.en'), 'You do not need to give consent to book an appointment'));
check('texts up to now with the checkbox get a note, whatever the operator type',
    App\LegalTexts::obsolete((string) file_get_contents(APP_ROOT . '/tests/fixtures/privacy.de.v1.5.md')) === ['checkbox']
    && App\LegalTexts::obsolete('By ticking the required box in the booking form you only confirm …') === ['checkbox'] && App\LegalTexts::obsolete('Häkchen') === []
    && App\LegalTexts::obsolete('Mit dem Pflichthäkchen bestätigen Sie …') === ['checkbox'] && App\LegalTexts::obsolete('Das Häkchen im Buchungsformular …') === ['checkbox']
    && App\LegalTexts::obsolete('Tick the required checkbox …') === ['checkbox']);
$pvInstalled = App\Installer::pages('school', ['de', 'en', 'pl', 'ru', 'uk', 'tr']);
check('installer: the privacy policy in all six languages, the legal notice in German and English (the others fall back to English)',
    array_keys($pvInstalled['privacy']) === ['de', 'en', 'pl', 'ru', 'uk', 'tr'] && array_keys($pvInstalled['imprint']) === ['de', 'en']
    && $pvInstalled['privacy']['tr'] === $pvTpl('privacy.tr'));
check('existing installations: a missing translation is filled in, an unedited one follows the operator type, an edited one stays',
    App\LegalTexts::replacement('privacy', 'uk', null, 'school') === $pvTpl('privacy.uk')
    && App\LegalTexts::replacement('privacy', 'pl', $pvTpl('privacy.pl'), 'private') === $pvTpl('privacy-private.pl')
    && App\LegalTexts::replacement('privacy', 'pl', str_replace('[[HOSTER]]', 'Hoster GmbH', $pvTpl('privacy.pl')), 'private') === null
    && App\LegalTexts::replacement('privacy', 'ru', $pvTpl('privacy.ru'), 'school') === null);
check('installations from before the operator type: translations do not count for the guess (there were none up to v1.6)',
    App\LegalTexts::detectType(['privacy' => ['de' => (string) file_get_contents(APP_ROOT . '/tests/fixtures/privacy.de.v1.5.md'), 'pl' => $pvTpl('privacy.pl')]]) === 'private');

echo "Privacy: which text parents get\n";
$pvPick = static fn (array $texts, array $order, array $fallback = ['en', 'de']): ?string => App\LegalTexts::pick($texts, $order, $fallback);
$pvOrder = ['pl', 'en', 'de'];
check('own language if complete; with open placeholders, empty or missing: English, then the default language',
    $pvPick(['pl' => 'Polityka', 'en' => 'Policy', 'de' => 'Hinweise'], $pvOrder) === 'pl'
    && $pvPick(['pl' => 'Inspektor: [KONTAKTDATEN DER/DES DATENSCHUTZBEAUFTRAGTEN DER SCHULE]', 'en' => 'Policy'], $pvOrder) === 'en'
    && $pvPick(['pl' => "  \n", 'en' => 'Policy'], $pvOrder) === 'en' && $pvPick(['en' => 'Policy', 'de' => 'Hinweise'], $pvOrder) === 'en'
    && $pvPick(['pl' => 'x [LAND]', 'de' => 'Hinweise'], $pvOrder) === 'de');
check('English and the default language are shown as before even with open placeholders (they keep rounds from going public)',
    $pvPick(['pl' => 'x [LAND]', 'en' => 'Host: [WEB HOST WITH ADDRESS]', 'de' => 'Hinweise'], $pvOrder) === 'en'
    && $pvPick(['de' => 'Hoster: [HOSTER MIT ANSCHRIFT]', 'en' => 'Policy'], ['de', 'en', 'de']) === 'de');
check('only incomplete translations: the first of them (as before), no text at all: null',
    $pvPick(['pl' => 'x [LAND]'], $pvOrder) === 'pl' && $pvPick(['pl' => '', 'en' => ' '], $pvOrder) === null
    && $pvPick([], $pvOrder) === null && $pvPick(['it' => 'Testo'], $pvOrder) === null);
check('fallback languages: English and the default language (German)', App\LegalTexts::fallbackLanguages() === ['en', 'de']);
$pvOpen = [['key' => 'privacy', 'lang' => 'de', 'placeholders' => ['[LAND]']], ['key' => 'privacy', 'lang' => 'pl', 'placeholders' => ['[LAND]']],
    ['key' => 'imprint', 'lang' => 'en', 'placeholders' => ['[X Y Z]']], ['key' => 'privacy', 'lang' => 'tr', 'placeholders' => ['[LAND]']]];
check('open placeholders: in German and English they keep rounds from going public, in a translation they do not',
    App\LegalTexts::splitOpen($pvOpen) === [[$pvOpen[0], $pvOpen[2]], [$pvOpen[1], $pvOpen[3]]] && App\LegalTexts::splitOpen([]) === [[], []]);
App\Config::load(['default_language' => 'tr'] + $pvCfg);
check('German stays a fallback language (the authoritative version), another default language is one as well (and keeps rounds from going public)',
    App\LegalTexts::fallbackLanguages() === ['en', 'de', 'tr'] && App\LegalTexts::splitOpen($pvOpen)[0] === [$pvOpen[0], $pvOpen[2], $pvOpen[3]]);
App\Config::load(['languages' => ['en', 'pl'], 'default_language' => 'en'] + $pvCfg);
check('German not offered: not a fallback language either', App\LegalTexts::fallbackLanguages() === ['en']);
// English not offered: a stored English text (e.g. from before) is neither checked by open() nor editable for admins,
// so parents never get it; its placeholders do not keep rounds from going public
App\Config::load(['languages' => ['de', 'pl', 'tr'], 'default_language' => 'de'] + $pvCfg);
check('English not offered: only the default language is a fallback, open placeholders in English do not block',
    App\LegalTexts::fallbackLanguages() === ['de'] && App\LegalTexts::splitOpen($pvOpen)[0] === [$pvOpen[0]]
    && $pvPick(['pl' => 'x [LAND]', 'en' => 'Policy', 'de' => 'Hinweise'], ['pl', ...App\LegalTexts::fallbackLanguages()], App\LegalTexts::fallbackLanguages()) === 'de');
App\Config::load($pvCfg);

App\Config::load(['hosting' => ['en' => 'Example Hosting GmbH', 'pl' => 'Hosting Przykład sp. z o.o.']] + $pvCfg);
check('web host: a translation takes its own text, otherwise the English one; German needs its own',
    App\Hosting::source('pl') === 'pl' && App\Hosting::source('uk') === 'en' && App\Hosting::source('de') === null && App\Hosting::source('en') === 'en'
    && App\Hosting::fill('[[HOSTER]]', 'uk') === 'Example Hosting GmbH' && App\Hosting::fill('[[HOSTER]]', 'pl') === 'Hosting Przykład sp. z o.o.'
    && str_starts_with(App\Hosting::fill('[[HOSTER]]', 'de'), '[HOSTER MIT ANSCHRIFT]'));
App\Config::load(['hosting' => ['de' => 'Beispiel Hosting GmbH', 'en' => '']] + $pvCfg);
check('web host: without an English text the German one, without any the English placeholder',
    App\Hosting::source('tr') === 'de' && App\Hosting::fill('[[HOSTER]]', 'tr') === 'Beispiel Hosting GmbH' && App\Hosting::source('en') === null);
App\Config::load(['hosting' => []] + $pvCfg);
check('web host: nothing configured, the translation shows the placeholder', App\Hosting::source('ru') === null && str_starts_with(App\Hosting::fill('[[HOSTER]]', 'ru'), '[WEB HOST WITH ADDRESS]'));
App\Config::load($pvCfg);

$pvPage = static fn (string $lang, string $contentLang, string $html = '<p>Text</p>'): string => App\I18n::with($lang, static fn (): string
    => App\View::capture('public/page', ['title' => t('footer.privacy'), 'html' => $html, 'contentLang' => $contentLang]));
check('page in another language than the parents\': a notice in their language, the text marked with its language (screen readers)',
    str_contains($pvPage('pl', 'en'), '<p class="alert alert-info" data-content-lang="en">Ten tekst nie jest dostępny po polsku. Poniżej znajduje się wersja w tym języku: <span lang="en">English</span>.</p>')
    && str_contains($pvPage('pl', 'en'), '<div lang="en"><p>Text</p></div>') && str_contains($pvPage('tr', 'de'), 'Aşağıda şu dilde yer alıyor: <span lang="de">Deutsch</span>.'));
check('page in the parents\' language: no notice, no language of its own; no text: "not added yet"',
    !str_contains($pvPage('pl', 'pl'), 'data-content-lang') && !str_contains($pvPage('pl', 'pl'), 'lang=') && str_contains($pvPage('pl', 'pl'), '<p>Text</p>')
    && str_contains($pvPage('ru', 'en', ''), 'Этот текст ещё не добавлен.') && !str_contains($pvPage('ru', 'en', ''), 'data-content-lang'));

echo "Privacy: booking form without the required checkbox, information in two levels\n";
$pvSlots = [['date' => '2030-11-13', 'location' => 'Raum A 112', 'slots' => [['id' => 41, 'start_time' => '15:45:00', 'end_time' => '16:00:00'], ['id' => 42, 'start_time' => '16:00:00', 'end_time' => '16:15:00']]],
    ['date' => '2030-11-14', 'location' => '', 'slots' => [['id' => 43, 'start_time' => '15:45:00', 'end_time' => '16:00:00']]]];
$pvRound = ['id' => 7, 'slug' => 'runde-7b', 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Berger', 'booking_limit' => 'round', 'location' => 'Hauptgebäude',
    'booking_deadline_hours' => 24, 'cancel_deadline_hours' => 1];
$pvForm = static fn (array $vars = [], string $lang = 'de'): string => App\I18n::with($lang, static fn (): string => App\View::capture('public/round', $vars + [
    'round' => $pvRound, 'days' => $pvSlots, 'csrf' => '1.abc', 'access' => '', 'errors' => [], 'old' => [], 'controller' => 'Gesamtschule am See', 'operatorType' => 'school',
]));
$pvF = $pvForm();
$pvBox = (string) (preg_match('#<section class="privacy-summary".*?</section>#s', $pvF, $pvM) ? $pvM[0] : '');
check('no checkbox for the privacy notice; nothing in the form is required for it',
    $pvF !== '' && !str_contains($pvF, 'name="privacy"') && !str_contains($pvF, 'zur Kenntnis genommen') && substr_count($pvF, 'type="checkbox"') === 1);
check('first level: who is responsible, the purpose and the deletion, then the link to the full notice',
    (bool) preg_match('#<ul>\s*<li>Verantwortlich für diese Terminvergabe: Gesamtschule am See</li>\s*<li>Zweck: nur die Organisation und Durchführung Ihres Gesprächs\. Keine Weitergabe an Dritte zu anderen Zwecken, kein Tracking, keine Cookies\.</li>\s*<li>Löschung: In der Terminvergabe werden Ihre Angaben am Tag nach dem Termin automatisch gelöscht, wenn Sie absagen, sofort\.</li>\s*</ul>\s*<details>#', $pvBox)
    && str_contains($pvBox, '<p class="privacy-link"><a href="/datenschutz?r=runde-7b" target="_blank" rel="noopener">Alle Datenschutzhinweise, auch zu Ihren Rechten (u. a. Auskunft, Löschung, Widerspruch, Beschwerde)</a></p>'));
$pvDetails = (string) (preg_match('#<details>.*?</details>#s', $pvBox, $pvM) ? $pvM[0] : '');
check('second level folded away: data, the teacher\'s mail (names only if the school allows them), legal basis (operated by the school), rights and complaint',
    str_contains($pvDetails, '<summary>Mehr zum Datenschutz</summary>') && !str_contains($pvDetails, '<details open')
    && str_contains($pvDetails, 'Gespeichert werden nur der eingetragene Name') && str_contains($pvDetails, 'Zusammenfassungen per E-Mail an die Lehrkraft enthalten keine E-Mail-Adressen, Uhrzeit und Namen nur, wenn das für die Schule freigegeben ist.')
    && str_contains($pvDetails, '(Art. 6 Abs. 1 lit. e DSGVO). Eine Einwilligung ist dafür nicht nötig.') && str_contains($pvDetails, 'bei einer Datenschutz-Aufsichtsbehörde beschweren'));
check('legal basis by operator type: privately operated Art. 6(1)(b) and the school\'s rules for the meeting, without a type none',
    str_contains($pvForm(['operatorType' => 'private']), 'die Vereinbarung Ihres Termins (Art. 6 Abs. 1 lit. b DSGVO). Eine Einwilligung ist dafür nicht nötig. Für das Gespräch selbst gelten die Regeln der Schule.')
    && !str_contains($pvForm(['operatorType' => 'private']), 'lit. e') && !str_contains($pvForm(['operatorType' => null]), 'Rechtsgrundlage')
    && !str_contains($pvForm(['controller' => '']), 'Verantwortlich für diese Terminvergabe'));
check('the privacy box comes after the details and the remember-me checkbox, right before the chosen appointment and the button',
    strpos($pvF, 'id="remember"') < strpos($pvF, 'data-privacy-summary') && strpos($pvF, 'data-privacy-summary') < strpos($pvF, 'data-slot-summary')
    && strpos($pvF, 'data-slot-summary') < strpos($pvF, '<button type="submit"'));
check('the box in every parent language (Turkish: no Art. 6 of the German act, the GDPR)',
    str_contains($pvForm([], 'tr'), '<li>Bu randevu sisteminin veri sorumlusu: Gesamtschule am See</li>') && str_contains($pvForm([], 'tr'), '(GDPR madde 6(1)(e))')
    && str_contains($pvForm([], 'uk'), '<summary>Докладніше про захист даних</summary>') && str_contains($pvForm([], 'ru'), 'Полная информация о защите данных, в том числе о ваших правах (доступ, удаление, возражение, жалоба и др.)</a>'));

check('chosen appointment above the button: empty and announced politely while nothing is chosen, the text for each slot on its radio',
    str_contains($pvF, '<p class="booking-summary" aria-live="polite" data-slot-summary="Ihr Termin: {when}"></p>')
    && str_contains($pvF, 'value="41" required data-when="Mittwoch, 13.11.2030, 15:45–16:00 Uhr, Ort: Raum A 112">')
    && str_contains($pvF, 'value="43" required data-when="Donnerstag, 14.11.2030, 15:45–16:00 Uhr, Ort: Hauptgebäude">'));
check('chosen appointment after an error or a language switch: filled in by the server, in the language of the page',
    str_contains($pvForm(['old' => ['slot_id' => '42', 'name' => 'Max']]), 'data-slot-summary="Ihr Termin: {when}">Ihr Termin: Mittwoch, 13.11.2030, 16:00–16:15 Uhr, Ort: Raum A 112</p>')
    && str_contains($pvForm(['old' => ['slot_id' => '42']], 'en'), '>Your appointment: Wednesday, 13 November 2030, 16:00–16:15, Location: Raum A 112</p>')
    && str_contains($pvForm(['old' => ['slot_id' => '99']]), 'data-slot-summary="Ihr Termin: {when}"></p>'));
check('slot label without a location: date and time only', slot_label(['date' => '2030-11-14'], ['start_time' => '09:00:00', 'end_time' => '09:15:00'], []) === 'Donnerstag, 14.11.2030, 09:00–09:15 Uhr');

echo "Privacy: deadlines before booking, on the appointment page and in the mails\n";
check('round page: booking deadline and deadline for changes in the info box, singular and plural',
    str_contains($pvF, 'Für Geschwister buchen Sie bitte jeweils mit dem Namen des Kindes.<br><span data-deadlines>Buchen können Sie bis 24 Stunden vor dem Termin. Ändern oder absagen können Sie online bis 1 Stunde vor dem Termin.</span></p>'));
check('a deadline of 0 hours restricts nothing and is not mentioned',
    str_contains($pvForm(['round' => ['booking_deadline_hours' => 0] + $pvRound]), '<span data-deadlines>Ändern oder absagen können Sie online bis 1 Stunde vor dem Termin.</span>')
    && !str_contains($pvForm(['round' => ['booking_deadline_hours' => 0, 'cancel_deadline_hours' => 0] + $pvRound]), 'data-deadlines'));
check('the deadlines in the other languages with the right plural forms',
    str_contains($pvForm(['round' => ['booking_deadline_hours' => 22] + $pvRound], 'pl'), 'Rezerwacja jest możliwa najpóźniej 22 godziny przed terminem. Zmiana lub odwołanie online są możliwe najpóźniej 1 godzinę przed terminem.')
    && str_contains($pvForm(['round' => ['booking_deadline_hours' => 21, 'cancel_deadline_hours' => 12] + $pvRound], 'ru'), 'Записаться можно не позднее чем за 21 час до встречи. Изменить или отменить запись онлайн можно не позднее чем за 12 часов до встречи.')
    && str_contains($pvForm([], 'uk'), 'не пізніше ніж за 24 години до зустрічі') && str_contains($pvForm([], 'tr'), 'Randevu, randevu saatinden en geç 24 saat önce alınabilir.'));
$pvPlural = static fn (string $lang, array $ns): string => App\I18n::with($lang, static fn (): string => implode('|', array_map('fmt_hours', $ns)));
check('plural forms of hours: German/English one/other, Polish and Russian/Ukrainian three forms, Turkish one',
    $pvPlural('de', [0, 1, 2, 24]) === '0 Stunden|1 Stunde|2 Stunden|24 Stunden' && $pvPlural('en', [1, 12]) === '1 hour|12 hours'
    && $pvPlural('pl', [1, 2, 4, 5, 12, 14, 22, 25, 112]) === '1 godzinę|2 godziny|4 godziny|5 godzin|12 godzin|14 godzin|22 godziny|25 godzin|112 godzin'
    && $pvPlural('ru', [1, 3, 5, 11, 21, 24, 111]) === '1 час|3 часа|5 часов|11 часов|21 час|24 часа|111 часов'
    && $pvPlural('uk', [1, 2, 12, 21, 48]) === '1 годину|2 години|12 годин|21 годину|48 годин' && $pvPlural('tr', [1, 24]) === '1 saat|24 saat');
$pvRules = array_filter(App\I18n::languages(), static fn (string $l): bool => !(static function () use ($l): bool {
    $strings = require APP_ROOT . "/app/lang/$l.php";
    return isset(['one' => 2, 'pl' => 3, 'east-slavic' => 3, 'none' => 1][$strings['plural'] ?? ''])
        && count($strings['hours'] ?? []) === ['one' => 2, 'pl' => 3, 'east-slavic' => 3, 'none' => 1][$strings['plural']];
})());
check('every parent language names a known plural rule and as many forms of "hours" as it needs' . ($pvRules ? ' (' . implode(', ', $pvRules) . ')' : ''), !$pvRules);
check('unknown key or rule: the key itself, the rule "one"', App\I18n::plural('gibt.es.nicht', 3) === 'gibt.es.nicht'
    && App\I18n::pluralForm('xx', 1) === 0 && App\I18n::pluralForm('xx', 2) === 1 && App\I18n::pluralForm('pl', -2) === 1);

$pvBooking = ['day_date' => '2030-11-13', 'start_time' => '15:45:00', 'end_time' => '16:00:00', 'cancel_deadline_hours' => 24];
check('deadline for changes: the start minus the hours of the round, open until then',
    App\BookingService::changeDeadline($pvBooking)->format('Y-m-d H:i') === '2030-11-12 15:45'
    && App\BookingService::changeDeadline(['cancel_deadline_hours' => 0] + $pvBooking)->format('Y-m-d H:i') === '2030-11-13 15:45'
    && App\BookingService::canChange($pvBooking) && !App\BookingService::canChange(['day_date' => date('Y-m-d', time() + 3600 * 10), 'start_time' => date('H:i:s', time() + 3600 * 10), 'cancel_deadline_hours' => 12] + $pvBooking));
check('… in words with date and time while open, otherwise that it has passed',
    App\BookingService::changeNotice($pvBooking) === 'Ändern oder absagen können Sie den Termin online bis Dienstag, 12.11.2030, 15:45 Uhr.'
    && App\I18n::with('pl', static fn (): string => App\BookingService::changeNotice($pvBooking)) === 'Zmiana lub odwołanie terminu online są możliwe do: wtorek, 12 listopada 2030, 15:45.'
    && App\BookingService::changeNotice(['day_date' => '2020-01-01', 'cancel_deadline_hours' => 1] + $pvBooking) === 'Änderungen und Absagen sind online nur bis 1 Stunde vor dem Termin möglich. Bitte wenden Sie sich direkt an die Lehrkraft.');
check('… only cancelling while moving is not possible (round or teacher inactive); with 0 hours, once it has started, no "0 hours"',
    App\BookingService::changeNotice($pvBooking, false) === 'Absagen können Sie den Termin online bis Dienstag, 12.11.2030, 15:45 Uhr.'
    && App\BookingService::changeNotice(['day_date' => '2020-01-01', 'cancel_deadline_hours' => 0] + $pvBooking) === 'Online lässt sich der Termin nicht mehr ändern oder absagen, weil er bereits begonnen hat. Bitte wenden Sie sich direkt an die Lehrkraft.');
// Across a change of daylight saving time the deadline keeps the time of day (PHP counts the hours on the local clock),
// shown and enforced alike (canChange() uses the same date): 48 hours before Monday 16:00 is Saturday 16:00
check('deadline for changes across the change of daylight saving time: the same time of day, shown as enforced',
    App\BookingService::changeDeadline(['day_date' => '2027-11-01', 'start_time' => '16:00:00', 'cancel_deadline_hours' => 48])->format('Y-m-d H:i') === '2027-10-30 16:00'
    && App\BookingService::changeDeadline(['day_date' => '2027-03-29', 'start_time' => '16:00:00', 'cancel_deadline_hours' => 48])->format('Y-m-d H:i') === '2027-03-27 16:00');
check('date and time', fmt_datetime(new DateTimeImmutable('2026-11-10 15:45')) === 'Dienstag, 10.11.2026, 15:45 Uhr'
    && App\I18n::with('en', static fn (): string => fmt_datetime(new DateTimeImmutable('2026-11-10 09:05'))) === 'Tuesday, 10 November 2026, 09:05');
$pvManage = static fn (bool $canChange, array $booking = [], ?bool $canMove = null): string => App\View::capture('public/manage', [
    'booking' => $booking + ['id' => 5, 'round_id' => 7, 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Berger', 'name' => 'Max Muster', 'location' => ''] + $pvBooking,
    'token' => str_repeat('Tk', 21) . 'x', 'canChange' => $canChange, 'canMove' => $canMove ?? $canChange, 'days' => [], 'csrf' => '1.abc',
    'done' => 'booked', 'mailed' => false, 'remember' => false, 'errors' => [], 'open' => '', 'mailTo' => '',
]);
check('appointment page: until when changing and cancelling work online, or that the time is up',
    str_contains($pvManage(true), '<p class="hint" data-change-deadline>Ändern oder absagen können Sie den Termin online bis Dienstag, 12.11.2030, 15:45 Uhr.</p>')
    && str_contains($pvManage(false, ['day_date' => '2020-01-01', 'cancel_deadline_hours' => 12]), '<p class="alert">Änderungen und Absagen sind online nur bis 12 Stunden vor dem Termin möglich.')
    && !str_contains($pvManage(false, ['day_date' => '2020-01-01']), 'data-change-deadline')
    && str_contains($pvManage(true, [], false), '<p class="hint" data-change-deadline>Absagen können Sie den Termin online bis Dienstag, 12.11.2030, 15:45 Uhr.</p>'));

echo "Privacy: texts in all parent languages\n";
$pvKeys = ['page.fallback', 'round.deadline.booking', 'round.deadline.change', 'round.selected', 'privacy.summary.controller', 'privacy.summary.purpose',
    'privacy.summary.deletion', 'privacy.summary.more', 'privacy.summary.data', 'privacy.summary.teacher', 'privacy.summary.basis.school',
    'privacy.summary.basis.private', 'privacy.summary.rights', 'privacy.summary.link', 'manage.deadline', 'manage.deadline_cancel', 'manage.locked',
    'manage.locked_started', 'mail.reminder.body_locked', 'mail.footer', 'mail.footer.copy'];
$pvPh = static fn (string $s): array => preg_match_all('/\{\w+\}/', $s, $m) ? $m[0] : [];
$pvUntranslated = array_filter($pvKeys, static fn (string $k): bool => count(array_unique(array_map(static fn ($l) => App\I18n::textIn($l, $k), App\I18n::languages()))) !== count(App\I18n::languages())
    || count(array_unique(array_map(static fn ($l) => implode(',', $pvPh(App\I18n::textIn($l, $k))), App\I18n::languages()))) !== 1);
check('new and changed texts in every parent language, each with the same placeholders' . ($pvUntranslated ? ' (' . implode(', ', $pvUntranslated) . ')' : ''), !$pvUntranslated);
$pvGone = array_filter(['form.privacy.before', 'form.privacy.link', 'form.privacy.after', 'err.privacy', 'privacy.summary.items'],
    static fn (string $k): bool => (bool) array_filter(App\I18n::languages(), static fn (string $l): bool => array_key_exists($k, require APP_ROOT . "/app/lang/$l.php")));
check('the texts of the checkbox are gone in every language' . ($pvGone ? ' (' . implode(', ', $pvGone) . ')' : ''), !$pvGone);
check('mail footer: the link to the privacy notice in the parents\' language instead of "see privacy notice"',
    !array_filter(App\I18n::languages(), static fn (string $l): bool => !str_ends_with(App\I18n::textIn($l, 'mail.footer'), ': {privacy}') || !str_ends_with(App\I18n::textIn($l, 'mail.footer.copy'), ': {privacy}'))
    && !str_contains(App\I18n::textIn('de', 'mail.footer'), 'siehe Datenschutzhinweise'));
check('reminder after the deadline for changes: the same details and link, but no invitation to cancel through it',
    !array_filter(App\I18n::languages(), static fn (string $l): bool => $pvPh(App\I18n::textIn($l, 'mail.reminder.body_locked')) !== $pvPh(App\I18n::textIn($l, 'mail.reminder.body')))
    && !str_contains(App\I18n::textIn('de', 'mail.reminder.body_locked'), 'absagen') && str_contains(App\I18n::textIn('de', 'mail.reminder.body'), 'sagen Sie den Termin bitte über diesen Link ab'));
check('first level: the rights including the right to object (Art. 21(4) GDPR) named in the link, in every language',
    !array_filter(['de' => 'Widerspruch', 'en' => 'objection', 'pl' => 'sprzeciw', 'ru' => 'возражение', 'uk' => 'заперечення', 'tr' => 'itiraz'],
        static fn (string $word, string $l): bool => !str_contains(App\I18n::textIn($l, 'privacy.summary.link'), $word) || !str_contains(App\I18n::textIn($l, 'privacy.summary.rights'), mb_substr($word, 0, 6)), ARRAY_FILTER_USE_BOTH));
