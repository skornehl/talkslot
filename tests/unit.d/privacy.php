<?php
// Privacy: information in all parent languages, in two levels, no required checkbox (see README.md in this directory)
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
    && App\LegalTexts::obsolete('By ticking the required box in the booking form you only confirm …') === ['checkbox'] && App\LegalTexts::obsolete('Häkchen') === []);
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
$pvPick = [App\LegalTexts::class, 'pick'];
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
check('another default language falls back as well (and keeps rounds from going public)', App\LegalTexts::fallbackLanguages() === ['en', 'tr']
    && App\LegalTexts::splitOpen($pvOpen)[0] === [$pvOpen[2], $pvOpen[3]]);
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
    str_contains($pvPage('pl', 'en'), '<p class="alert alert-info" data-content-lang="en">Ten tekst nie jest jeszcze dostępny po polsku. Poniżej znajduje się wersja w tym języku: English.</p>')
    && str_contains($pvPage('pl', 'en'), '<div lang="en"><p>Text</p></div>') && str_contains($pvPage('tr', 'de'), 'Aşağıda şu dilde yer alıyor: Deutsch.'));
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
    (bool) preg_match('#<ul>\s*<li>Verantwortlich für diese Terminvergabe: Gesamtschule am See</li>\s*<li>Zweck: nur die Organisation und Durchführung Ihres Gesprächs\. Keine Weitergabe, kein Tracking, keine Cookies\.</li>\s*<li>Löschung: In der Terminvergabe werden Ihre Angaben am Tag nach dem Termin automatisch gelöscht, bei einer Absage sofort\.</li>\s*</ul>\s*<details>#', $pvBox)
    && str_contains($pvBox, '<p class="privacy-link"><a href="/datenschutz?r=runde-7b" target="_blank" rel="noopener">Alle Datenschutzhinweise</a></p>'));
$pvDetails = (string) (preg_match('#<details>.*?</details>#s', $pvBox, $pvM) ? $pvM[0] : '');
check('second level folded away: data, the teacher\'s mail, legal basis (operated by the school), rights and complaint',
    str_contains($pvDetails, '<summary>Mehr zum Datenschutz</summary>') && !str_contains($pvDetails, '<details open')
    && str_contains($pvDetails, 'Gespeichert werden nur der eingetragene Name') && str_contains($pvDetails, 'bekommt die Lehrkraft auch per E-Mail')
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
    && str_contains($pvForm([], 'uk'), '<summary>Докладніше про захист даних</summary>') && str_contains($pvForm([], 'ru'), 'Полная информация о защите данных</a>'));

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
check('date and time', fmt_datetime(new DateTimeImmutable('2026-11-10 15:45')) === 'Dienstag, 10.11.2026, 15:45 Uhr'
    && App\I18n::with('en', static fn (): string => fmt_datetime(new DateTimeImmutable('2026-11-10 09:05'))) === 'Tuesday, 10 November 2026, 09:05');
$pvManage = static fn (bool $canChange, array $booking = []): string => App\View::capture('public/manage', [
    'booking' => $booking + ['id' => 5, 'round_id' => 7, 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Berger', 'name' => 'Max Muster', 'location' => ''] + $pvBooking,
    'token' => str_repeat('Tk', 21) . 'x', 'canChange' => $canChange, 'canMove' => $canChange, 'days' => [], 'csrf' => '1.abc',
    'done' => 'booked', 'mailed' => false, 'remember' => false, 'errors' => [], 'open' => '', 'mailTo' => '',
]);
check('appointment page: until when changing and cancelling work online, or that the time is up',
    str_contains($pvManage(true), '<p class="hint" data-change-deadline>Ändern oder absagen können Sie den Termin online bis Dienstag, 12.11.2030, 15:45 Uhr.</p>')
    && str_contains($pvManage(false, ['day_date' => '2020-01-01', 'cancel_deadline_hours' => 12]), '<p class="alert">Änderungen und Absagen sind online nur bis 12 Stunden vor dem Termin möglich.')
    && !str_contains($pvManage(false, ['day_date' => '2020-01-01']), 'data-change-deadline'));

echo "Privacy: texts in all parent languages\n";
$pvKeys = ['page.fallback', 'round.deadline.booking', 'round.deadline.change', 'round.selected', 'privacy.summary.controller', 'privacy.summary.purpose',
    'privacy.summary.deletion', 'privacy.summary.more', 'privacy.summary.data', 'privacy.summary.teacher', 'privacy.summary.basis.school',
    'privacy.summary.basis.private', 'privacy.summary.rights', 'privacy.summary.link', 'manage.deadline', 'manage.locked', 'mail.footer', 'mail.footer.copy'];
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
