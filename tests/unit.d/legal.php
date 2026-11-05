<?php
// Legal texts and the operator type: operated by the school or privately (see README.md in this directory)
echo "Legal texts: operator type and templates\n";
// Expert review P0-2b: TalkSlot can be used at a school, but run by the school (a public body) or privately
// (a private person, association or independent provider). The type decides the templates and the notes.
$legalTpl = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/app/templates/pages/$name.md");
$legalFixture = static fn (string $name): string => (string) file_get_contents(APP_ROOT . "/tests/fixtures/$name.md");
check('operator types: school and private, a template of both pages for each in German and English',
    App\Operator::TYPES === ['school', 'private'] && array_keys(App\LegalTexts::VARIANTS['imprint']) === App\Operator::TYPES
    && array_keys(App\LegalTexts::VARIANTS['privacy']) === App\Operator::TYPES
    && !array_filter(App\Operator::TYPES, static fn (string $t): bool => in_array(null, [App\LegalTexts::template('imprint', $t, 'de'),
        App\LegalTexts::template('imprint', $t, 'en'), App\LegalTexts::template('privacy', $t, 'de'), App\LegalTexts::template('privacy', $t, 'en')], true)));
check('operator type labels (installer, texts page) in German and English',
    App\Operator::typeLabel('school') === 'Wird von der Schule betrieben (öffentliche Stelle)'
    && App\Operator::typeLabel('private') === 'Wird privat betrieben (Privatperson, Verein, freier Träger)'
    && App\AdminI18n::with('en', static fn () => App\Operator::typeLabel('private')) === 'Operated privately (private person, association, independent provider)');
check('installer: the operator type is required, only school or private',
    App\Installer::validateType('school') === null && App\Installer::validateType('private') === null
    && App\Installer::validateType('') === 'Bitte wählen Sie die Betriebsart: Wird die Terminvergabe von der Schule oder privat betrieben?'
    && App\Installer::validateType('public') !== null && App\Installer::validateType('School') !== null);
$installError = '';
try {
    App\Installer::install('sabrina', 'Kaffee-Wolke-Tisch-17', '', '', 'öffentlich');
} catch (Throwable $e) { // any error: without the check it would be one of the database (there is none here)
    $installError = $e->getMessage();
}
check('installer: no installation without a valid operator type (refused before the database is touched)', str_contains($installError, 'Betriebsart'));
// What the installer stores (Installer::install() writes exactly these): the templates of the chosen type. Pages
// installed as the other type would keep their wrong text, as pages_version is set at the same time.
$installed = static fn (string $type): array => array_map(static fn (array $texts): array => array_map(static fn (string $text): string => hash('sha256', $text), $texts),
    App\Installer::pages($type, ['de', 'en', 'it']));
$legalHash = static fn (string $name): string => hash('sha256', $legalTpl($name));
check('installer, operated privately: the private legal notice and privacy policy (de/en), nothing for a language without a template',
    $installed('private') === ['imprint' => ['de' => $legalHash('imprint-private.de'), 'en' => $legalHash('imprint-private.en')],
        'privacy' => ['de' => $legalHash('privacy-private.de'), 'en' => $legalHash('privacy-private.en')]]
    && $installed('private')['imprint']['de'] === App\Migrations::CURRENT_PAGE_HASHES['imprint-private.de']);
check('installer, operated by the school: the school templates (de/en)',
    $installed('school') === ['imprint' => ['de' => $legalHash('imprint.de'), 'en' => $legalHash('imprint.en')],
        'privacy' => ['de' => $legalHash('privacy.de'), 'en' => $legalHash('privacy.en')]]);

// Privately operated: the legal notice of a private, non-commercial service as up to v1.5 (unchanged, so unedited
// texts of those installations stay as they are), the privacy policy with a data protection officer paragraph for
// private persons and teachers who use the service
$imprintPrivate = $legalTpl('imprint-private.de') . "\n" . $legalTpl('imprint-private.en');
check('private legal notice: "private, non-commercial service" (de/en), operator filled in, nothing to fill in by hand',
    str_contains($imprintPrivate, 'Es handelt sich um ein privates, nicht kommerzielles Angebot.') && str_contains($imprintPrivate, 'It is a private, non-commercial service.')
    && substr_count($imprintPrivate, '[[VERANTWORTLICHE]]') === 2 && App\LegalTexts::placeholders(str_replace('[[VERANTWORTLICHE]]', '', $imprintPrivate)) === []);
check('school legal notice: no "private" service', !preg_match('/privat/i', $legalTpl('imprint.de') . $legalTpl('imprint.en')));
$privateDe = $legalTpl('privacy-private.de');
$privateEn = $legalTpl('privacy-private.en');
check('private privacy policy: no data protection officer as the rule for private persons, a placeholder to check it (de/en)',
    str_contains($privateDe, '**Datenschutzbeauftragte bzw. Datenschutzbeauftragter:** nicht benannt, da hierzu keine gesetzliche Pflicht besteht (Art. 37 DSGVO, § 38 BDSG). [BITTE PRÜFEN')
    && str_contains($privateEn, '**Data protection officer:** none appointed, as there is no legal obligation to do so (Art. 37 GDPR, section 38 BDSG). [PLEASE CHECK')
    && in_array('[BITTE PRÜFEN UND DANN DIESE KLAMMER LÖSCHEN: IST EINE ODER EINER BENANNT (Z. B. BEI EINEM VEREIN ODER FREIEN TRÄGER), STATT DES SATZES DAVOR DEREN KONTAKTDATEN ANGEBEN.]', App\LegalTexts::placeholders($privateDe), true));
check('private privacy policy: teachers use the service of the operator (not "on behalf of and under the instructions")',
    str_contains($privateDe, 'nutzen dieses Angebot der verantwortlichen Stelle.') && str_contains($privateEn, 'use this service of the controller.')
    && !str_contains($privateDe . $privateEn, 'Weisung') && !str_contains($privateDe . $privateEn, 'instructions'));
check('private legal notice: German and English with the same number of placeholders',
    count(App\LegalTexts::placeholders($legalTpl('imprint-private.de'))) === count(App\LegalTexts::placeholders($legalTpl('imprint-private.en'))));
check('fixtures are the shipped templates (v1.5 privacy policy de/en, v1.7.0 private privacy policy)',
    in_array(hash('sha256', $legalFixture('privacy.de.v1.5')), App\Migrations::OLD_PAGE_HASHES['private'], true)
    && in_array(hash('sha256', $legalFixture('privacy.en.v1.5')), App\Migrations::OLD_PAGE_HASHES['private'], true)
    && in_array(hash('sha256', $legalFixture('privacy-private.de.v1.7')), App\Migrations::OLD_PAGE_HASHES['private'], true));

echo "Legal texts: unedited templates follow the operator type\n";
$v15 = $legalFixture('privacy.de.v1.5');
check('unedited: current templates of both types, earlier ones by the type they were written for',
    App\LegalTexts::templateType('privacy', $legalTpl('privacy.de')) === 'school' && App\LegalTexts::templateType('privacy', $privateEn) === 'private'
    && App\LegalTexts::templateType('imprint', $legalTpl('imprint.en')) === 'school' && App\LegalTexts::templateType('imprint', $legalTpl('imprint-private.de')) === 'private'
    && App\LegalTexts::templateType('privacy', $v15) === 'private' && App\LegalTexts::templateType('privacy', $legalFixture('privacy-private.de.v1.7')) === 'private');
check('edited: one character more, or the template of the other page',
    App\LegalTexts::templateType('privacy', $legalTpl('privacy.de') . ' ') === null && App\LegalTexts::templateType('privacy', $legalTpl('imprint-private.de')) === null
    && App\LegalTexts::templateType('imprint', $privateDe) === null && App\LegalTexts::templateType('unknown', $privateDe) === null);
$replace = [App\LegalTexts::class, 'replacement'];
check('privately operated: unedited school texts (v1.7.0) and old texts become the private templates, missing ones are filled in',
    $replace('privacy', 'de', $legalTpl('privacy.de'), 'private') === $privateDe && $replace('imprint', 'en', $legalTpl('imprint.en'), 'private') === $legalTpl('imprint-private.en')
    && $replace('privacy', 'de', $v15, 'private') === $privateDe && $replace('privacy', 'de', $legalFixture('privacy-private.de.v1.7'), 'private') === $privateDe
    && $replace('privacy', 'en', null, 'private') === $privateEn);
check('operated by the school: unedited private texts (also those up to v1.5) become the school templates',
    $replace('privacy', 'de', $privateDe, 'school') === $legalTpl('privacy.de') && $replace('imprint', 'de', $legalTpl('imprint-private.de'), 'school') === $legalTpl('imprint.de')
    && $replace('privacy', 'de', $v15, 'school') === $legalTpl('privacy.de'));
check('never replaced: edited texts, texts that already are the template, languages without a template',
    $replace('privacy', 'de', $privateDe . "\nErgänzung", 'school') === null && $replace('privacy', 'de', str_replace('Lehrkraft', 'Lehrerin', $v15), 'private') === null
    && $replace('privacy', 'de', $privateDe, 'private') === null && $replace('imprint', 'de', $legalTpl('imprint.de'), 'school') === null
    && $replace('privacy', 'pl', null, 'school') === null && $replace('privacy', 'pl', 'Polityka prywatności', 'private') === null);
$detect = [App\LegalTexts::class, 'detectType'];
$v15Install = ['imprint' => ['de' => $legalTpl('imprint-private.de'), 'en' => $legalTpl('imprint-private.en')],
    'privacy' => ['de' => $v15, 'en' => $legalFixture('privacy.en.v1.5')]];
check('installation without a type, all texts unedited up to v1.5 (private service): "private", own texts in other languages do not count',
    $detect($v15Install) === 'private' && $detect(array_merge_recursive($v15Install, ['privacy' => ['pl' => 'Polityka prywatności']])) === 'private'
    && $detect(['privacy' => ['de' => $legalFixture('privacy-private.de.v1.7')]]) === 'private');
check('installation without a type: one edited text, the school templates (v1.7.0) or no texts → the admins choose',
    $detect(array_replace_recursive($v15Install, ['privacy' => ['en' => 'Eigene Datenschutzerklärung']])) === null
    && $detect(['imprint' => ['de' => $legalTpl('imprint.de')], 'privacy' => ['de' => $legalTpl('privacy.de')]]) === null
    && $detect(['imprint' => ['de' => $legalTpl('imprint-private.de')], 'privacy' => ['de' => $legalTpl('privacy.de')]]) === null && $detect([]) === null);

echo "Legal texts: notes depend on the operator type\n";
$mis = [App\LegalTexts::class, 'mismatches'];
check('operated by the school: the old template (private service, cancelling "at any time") – only there',
    $mis($v15, 'school') === ['old'] && $mis($legalFixture('privacy.en.v1.5'), 'school') === ['old'] && $mis($legalTpl('imprint-private.de'), 'school') === ['old']
    && $mis($v15, 'private') === ['anytime'] && $mis($legalTpl('imprint-private.de'), 'private') === [] && $mis($v15, null) === ['anytime']);
check('operated by the school: legal bases of a private operator (Art. 6(1)(b), (f)), not the school templates',
    $mis($privateDe, 'school') === ['private'] && $mis($privateEn, 'school') === ['private'] && $mis($legalTpl('privacy.de'), 'school') === []
    && $mis($legalTpl('privacy.en'), 'school') === [] && $mis($legalTpl('imprint.de'), 'school') === []);
check('operated privately: wording of the school template (Art. 6(1)(e), school act, the school\'s data protection officer)',
    $mis($legalTpl('privacy.de'), 'private') === ['school'] && $mis($legalTpl('privacy.en'), 'private') === ['school']
    && $mis('**Datenschutzbeauftragter der Schule:** Herr Muster', 'private') === ['school'] && $mis("The school's data protection officer", 'private') === ['school']
    && $mis($privateDe, 'private') === [] && $mis($privateEn, 'private') === [] && $mis($legalTpl('imprint.de'), 'private') === []);
check('no type chosen yet: only cancelling "at any time", nothing about the school or private wording',
    $mis($legalTpl('privacy.de'), null) === [] && $mis($privateDe, null) === [] && $mis('You can cancel your appointment yourself at any time.', null) === ['anytime']);

echo "Legal texts: operator type in the forms\n";
$typeForm = static fn (?string $type): string => App\View::capture('admin/_operator_type', ['operatorType' => $type]);
$checked = static fn (string $html): array => preg_match_all('/value="(\w+)" required[^>]* checked>/', $html, $m) ? $m[1] : [];
check('choice of the operator type: both required radio buttons with a hint, the stored one checked, none without a type',
    substr_count($typeForm(null), 'type="radio"') === 2 && substr_count($typeForm(null), 'name="operator_type" value=') === 2
    && $checked($typeForm(null)) === [] && $checked($typeForm('private')) === ['private'] && $checked($typeForm('school')) === ['school']
    && str_contains($typeForm(null), 'Vereinbarung nach Art. 28 DSGVO') && str_contains($typeForm(null), 'Lehrkräfte nutzen sein Angebot'));
$pageForm = static fn (array $variants, ?string $loaded, ?string $type): string => App\View::capture('admin/page_form', ['title' => 'x',
    'key' => 'privacy', 'lang' => 'de', 'content' => '', 'variants' => $variants, 'loaded' => $loaded, 'type' => $type]);
$pf = $pageForm(['private', 'school'], null, 'private');
check('"Vorlage laden": the template of the operator type first and marked',
    strpos($pf, '?template=private">Privat betrieben</a> (passend zur Betriebsart)') < strpos($pf, '?template=school">Von der Schule betrieben</a>')
    && substr_count($pf, 'passend zur Betriebsart') === 1 && !str_contains($pageForm(['school', 'private'], null, null), 'passend zur Betriebsart'));
check('loading the template of the other type: note that it does not match the operator type',
    str_contains($pageForm(['private', 'school'], 'school', 'private'), 'Vorlage „Von der Schule betrieben“ geladen.')
    && str_contains($pageForm(['private', 'school'], 'school', 'private'), 'Sie passt nicht zur gewählten Betriebsart „Privat betrieben“.')
    && !str_contains($pageForm(['private', 'school'], 'private', 'private'), 'passt nicht') && !str_contains($pageForm(['school', 'private'], 'private', null), 'passt nicht'));
$dashboardTeacher = static fn (bool $typeMissing): string => App\View::capture('admin/dashboard', ['rounds' => [], 'title' => 'x', 'showOwner' => false,
    'profileHints' => [], 'legalHints' => [], 'operatorMissing' => false, 'typeMissing' => $typeMissing, 'textsOpen' => true, 'configExposed' => [], 'unusedAccounts' => 0, 'mailsLate' => null]);
check('teachers: notice that the admins still have to choose the operator type (before the one on open placeholders)',
    str_contains($dashboardTeacher(true), 'Die Admins müssen noch wählen, ob die Terminvergabe von der Schule oder privat betrieben wird.')
    && !str_contains($dashboardTeacher(false), 'Die Admins müssen noch wählen') && str_contains($dashboardTeacher(false), 'offene Platzhalter (Aufgabe der Admins)'));
