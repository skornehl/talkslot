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
// Data protection officer: the school's is a required placeholder; a private operator usually has none, but has to check
// that before going live (placeholder to delete or replace, tests/unit.d/legal.php)
$wrong = array_keys(array_filter($privacy, static fn (string $t, string $n): bool => !preg_match(str_contains($n, 'private')
        ? '/^\*\*[^*]*(Datenschutzbeauftragte|data protection officer)[^*]*\*\* (nicht benannt|none appointed)[^\n]*\[(BITTE PRÜFEN|PLEASE CHECK)/mui'
        : '/^\*\*[^*]*(Datenschutzbeauftragte|data protection officer)[^*]*\*\* \[(KONTAKTDATEN|CONTACT DETAILS)/mui', $t)
    || (bool) preg_match('/private[ns]? Angebot|private service|jederzeit selbst|yourself at any time/u', $t)
    || !preg_match('/Änderungsfrist, die die Lehrkraft|deadline the teacher has set/u', $t) || !preg_match('/zur Vertraulichkeit verpflichtet|bound to confidentiality/u', $t), ARRAY_FILTER_USE_BOTH));
check('all privacy templates: data protection officer to be filled in or checked, no "private service", cancelling only until the deadline, administrators bound to confidentiality'
    . ($wrong ? ' (' . implode(', ', $wrong) . ')' : ''), !$wrong);
check('legal notices without "private service"', !preg_match('/privat/i', $tpl('imprint.de') . $tpl('imprint.en')));
$parity = array_filter(['imprint', 'privacy', 'privacy-private'], static fn (string $n): bool => count($ph($tpl("$n.de"))) !== count($ph($tpl("$n.en"))));
check('German and English templates have the same number of placeholders' . ($parity ? ' (' . implode(', ', $parity) . ')' : ''), !$parity);
// Templates up to v1.6 described a private service: their privacy policy is an old template of that type, their
// legal notice is still the current one for a private operator (Expert review P0-2b)
$oldHashes = array_merge(...array_values(App\Migrations::OLD_PAGE_HASHES));
check('templates up to v1.5.0 (unedited) are recognised as templates of a private service',
    !array_diff(['3885e997cd3bbf330158fdccc730f7fe1b93044379cc2df060ea5e0e7b6396ec', 'ffbcc88fbd8b760efc7c4f173bb4e8850517392f88f9ffc0100da3aad921253f'], App\Migrations::OLD_PAGE_HASHES['private'])
    && App\Migrations::CURRENT_PAGE_HASHES['imprint-private.de'] === '036e210b629357dfbf768868e3a5f249c888d3052f301170703b196336058f43'
    && App\Migrations::CURRENT_PAGE_HASHES['imprint-private.en'] === 'f15521ceaa18e97c98190e448cc95eb6130c4cf571164b09819bb40dd7af22c8');
check('no current template counts as old', !array_intersect(App\Migrations::CURRENT_PAGE_HASHES, $oldHashes));
check('variants: the school template comes first, loading only existing files',
    array_values(App\LegalTexts::VARIANTS['privacy'])[0] === 'privacy' && array_values(App\LegalTexts::VARIANTS['imprint'])[0] === 'imprint'
    && App\LegalTexts::template('privacy', 'private', 'en') === $tpl('privacy-private.en') && App\LegalTexts::template('privacy', 'school', 'de') === $publicDe
    && App\LegalTexts::template('privacy', 'school', 'pl') === null && App\LegalTexts::template('imprint', 'public', 'de') === null
    && App\LegalTexts::template('privacy', 'school', '../de') === null);
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

echo "Language choice for parents (header, browser hint, invitation text)\n";
$parentText = static fn (string $lang, string $key): string => (require APP_ROOT . "/app/lang/$lang.php")[$key];
// Browser language: only a suggestion, and only while the visitor has not chosen a language
$suggest = static function (string $header, ?string $chosen = null): ?string {
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;
    App\I18n::init($chosen);
    return App\I18n::suggested();
};
check('browser language: preferred offered language is suggested, the page stays in the default language',
    $suggest('tr-TR,tr;q=0.9,de;q=0.8') === 'tr' && App\I18n::lang() === 'de' && $suggest('TR') === 'tr' && $suggest('uk-UA') === 'uk');
check('browser language: no suggestion if the default language is preferred',
    $suggest('de-DE,de;q=0.9,en;q=0.8') === null && $suggest('fr, de;q=0.9, en;q=0.8') === null);
check('browser language: highest q among the offered languages wins, the first one on a tie',
    $suggest('fr-FR,fr;q=0.9,en;q=0.8,de;q=0.7') === 'en' && $suggest('en;q=0.5, uk') === 'uk' && $suggest('ru;q=0.8, uk;q=0.8') === 'ru'
    && $suggest('pl;q=0.3,tr;q=0.300') === 'pl');
check('browser language: q=0, "*", unknown or malformed ranges are ignored',
    $suggest('tr;q=0') === null && $suggest('*') === null && $suggest('') === null && $suggest('it-IT, xx') === null
    && $suggest('tr;q=2') === null && $suggest('tr;q=abc') === null && $suggest('tr-<script>') === null);
check('browser language: no suggestion once a language was chosen (?lang=), whatever it is',
    $suggest('tr', 'de') === null && $suggest('tr', 'en') === null && $suggest('tr', 'tr') === null);
check('choice is reset for the next request (init without a valid language)', $suggest('tr', 'en') === null && $suggest('tr', 'xx') === 'tr' && !App\I18n::explicit());
App\Config::load(['languages' => ['de', 'en']] + $cfg);
check('browser language: languages that are not offered are never suggested', $suggest('tr, en;q=0.5') === 'en' && $suggest('tr') === null);
App\Config::load($cfg);

check('text in a given language, also for the admin language; missing → default language → key',
    App\I18n::textIn('en', 'round.limit_round') === $parentText('en', 'round.limit_round')
    && App\I18n::textIn('xx', 'round.one_only') === $parentText('de', 'round.one_only')
    && App\I18n::textIn('de', 'gibt.es.nicht') === 'gibt.es.nicht' && App\I18n::textIn('de', 'weekdays') === 'weekdays' && t('weekdays') === 'weekdays');
check('notice on multiple bookings follows booking_limit (round page and invitation)',
    App\BookingService::limitNotice(['booking_limit' => 'round']) === 'round.limit_round' && App\BookingService::limitNotice(['booking_limit' => 'day']) === 'round.limit_day'
    && App\BookingService::limitNotice(['booking_limit' => 'none']) === 'round.one_only' && App\BookingService::limitNotice([]) === 'round.one_only');

// Header of the parent pages: language names as text above the content, in the way the page allows switching
$_SERVER['REQUEST_URI'] = '/elternsprechtag-7b';
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
App\I18n::init(null);
$page = static fn (array $vars = []): string => App\View::capture('layout', $vars + ['content' => '<h1>Elternsprechtag</h1>', 'title' => 'T']);
$names = ['de' => 'Deutsch', 'en' => 'English', 'pl' => 'Polski', 'ru' => 'Русский', 'uk' => 'Українська', 'tr' => 'Türkçe'];
$p = $page();
$nav = (string) strstr((string) strstr($p, '<nav aria-label="Sprache" class="lang-switch">'), '</nav>', true);
$links = array_filter(array_keys($names), static fn ($c) => str_contains($nav, '<a href="/elternsprechtag-7b?lang=' . $c . '" hreflang="' . $c . '" lang="' . $c . '"'
    . ($c === 'de' ? ' aria-current="true"' : '') . '>' . $names[$c] . '</a>'));
check('header: every language as a link in its own name, current one marked (' . count($links) . ' of 6)', count($links) === 6 && substr_count($nav, '<a ') === 6);
check('header: language choice in the site header, before the content and its heading',
    $nav !== '' && strpos($p, 'class="lang-switch"') > strpos($p, '<header') && strpos($p, 'class="lang-switch"') < strpos($p, '</header>') && strpos($p, '</header>') < strpos($p, '<h1>'));
check('no flags on the parent pages, no language choice left in the footer', !str_contains($p, 'flags/') && !str_contains((string) strstr($p, '<footer'), 'lang='));
$_SERVER['REQUEST_URI'] = '/%5C%5Cevil.example';
check('header: language links only for harmless paths, otherwise to the start page', str_contains($page(), '<a href="/?lang=tr" hreflang="tr"'));
$_SERVER['REQUEST_URI'] = '/datenschutz';
check('header: privacy page keeps the round (r=)', str_contains($page(['privacyRound' => 'runde-7b']), '<a href="/datenschutz?lang=en&amp;r=runde-7b" hreflang="en"'));
$_SERVER['REQUEST_URI'] = '/elternsprechtag-7b';
$f = $page(['langSwitchForm' => 'booking-form', 'langSwitchAccess' => ['action' => '/elternsprechtag-7b', 'access' => '1.abc']]);
$firstSwitch = (int) strpos($f, 'name="switch_lang"');
check('booking form: the language buttons submit it with all input (switch_lang)', substr_count($f, '<button type="submit" form="booking-form" name="switch_lang" value="') === 6
    && str_contains($f, 'value="uk" formnovalidate class="button-link" lang="uk">Українська</button>') && !str_contains($f, 'name="step" value="switch"'));
check('booking form: an invisible plain submit button comes first, so Enter in a field books instead of switching',
    preg_match('#<button type="submit" form="booking-form" class="visually-hidden" tabindex="-1" aria-hidden="true" data-default-submit>#', $f) === 1
    && strpos($f, 'data-default-submit') < $firstSwitch && !str_contains($page(), 'data-default-submit'));
$a = $page(['langSwitchAccess' => ['action' => '/elternsprechtag-7b', 'access' => '1.abc']]);
check('protected round without a booking form: small forms keep the access', substr_count($a, '<input type="hidden" name="step" value="switch"><input type="hidden" name="access" value="1.abc"><input type="hidden" name="lang" value="') === 6
    && str_contains($a, '<form method="post" action="/elternsprechtag-7b" class="inline">') && !str_contains($a, 'switch_lang'));

// Hint in the browser language: a link in that language, no automatic switch
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'tr-TR,tr;q=0.9';
App\I18n::init(null);
$h = $page();
check('hint: link in the browser language above the content, page itself stays German',
    str_contains($h, '<main id="main" class="wrap" tabindex="-1">' . "\n" . '<div class="alert alert-info lang-hint" lang="tr"><a href="/elternsprechtag-7b?lang=tr" hreflang="tr" lang="tr">Bu sayfayı Türkçe görüntüle</a></div>')
    && str_contains($h, '<html lang="de">'));
check('hint: on the booking form it switches like the other language buttons',
    str_contains($page(['langSwitchForm' => 'booking-form']), '<div class="alert alert-info lang-hint" lang="tr"><button type="submit" form="booking-form" name="switch_lang" value="tr" formnovalidate class="button-link" lang="tr">Bu sayfayı Türkçe görüntüle</button></div>'));
check('hint: the switch form is not nested in a paragraph (valid HTML)', str_contains($page(['langSwitchAccess' => ['action' => '/x', 'access' => '1.abc']]), '<div class="alert alert-info lang-hint" lang="tr"><form method="post" action="/x" class="inline">'));
App\I18n::init('de');
check('hint: not shown once a language was chosen', !str_contains($page(), 'lang-hint'));
App\I18n::init(null);
check('every parent language has its own hint text', count(array_unique(array_map(static fn ($l) => App\I18n::textIn($l, 'lang.suggest'), App\I18n::languages()))) === count(App\I18n::languages()));
App\Config::load(['languages' => ['de']] + $cfg);
$one = $page(['langSwitchForm' => 'booking-form']);
check('only one language: no language choice, no hint, no extra button', !str_contains($one, 'lang-switch') && !str_contains($one, 'lang-hint') && !str_contains($one, 'data-default-submit'));
App\Config::load($cfg);
unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['REQUEST_URI']);
App\I18n::init(null);

// Invitation text: direct link per further language, the booking rule as on the round page
$invite = static fn (array $round, string $lang = 'de'): string => App\AdminI18n::with($lang, static fn () => App\Invitation::text(
    $round + ['title' => 'Elternsprechtag 7b', 'slug' => 'elternsprechtag-7b', 'location' => '', 'code' => 'K7MP3QXA', 'code_required' => 0, 'booking_limit' => 'round'], ['display_name' => 'Frau Berger']));
$inv = $invite([]);
$lines = explode("\n", $inv);
$base = abs_url('/elternsprechtag-7b');
$expected = array_map(static fn ($c) => $names[$c] . ': ' . $base . '?lang=' . $c, ['en', 'pl', 'ru', 'uk', 'tr']);
$at = array_search($base, $lines, true);
check('invitation: one line per further language with its name and a direct link, right below the link',
    $at !== false && $lines[$at + 1] === '' && array_slice($lines, $at + 2, 5) === $expected && !str_contains($inv, 'lang=de') && !str_contains($inv, 'Deutsch'));
check('invitation: one appointment per person → sibling rule of the round page',
    str_contains($inv, $parentText('de', 'round.limit_round') . ' Nach der Buchung erhalten Sie einen persönlichen Link') && !str_contains($inv, $parentText('de', 'round.one_only')));
check('invitation: per day and without a limit → the matching sentence',
    str_contains($invite(['booking_limit' => 'day']), $parentText('de', 'round.limit_day') . ' Nach der Buchung')
    && str_contains($invite(['booking_limit' => 'none']), $parentText('de', 'round.one_only') . ' Nach der Buchung'));
check('invitation in English: the sentence of the English round page',
    str_contains($invite(['booking_limit' => 'day'], 'en'), $parentText('en', 'round.limit_day') . ' After booking, you will receive a personal link'));
App\Config::load(['default_language' => 'en'] + $cfg);
$invEn = $invite([]);
check('invitation: the language of the plain link (default) gets no line of its own', str_contains($invEn, "\nDeutsch: $base?lang=de\n") && !str_contains($invEn, 'lang=en'));
App\Config::load(['languages' => ['de']] + $cfg);
check('invitation: only one language → no language lines', !str_contains($invite([]), '?lang='));
App\Config::load($cfg);
App\I18n::set('de');

echo "Admin: parents are notified of changes, or told that they are not\n";
// Manual entry: the mail option is ticked by default (the controller passes send_mail = true) and stays ticked
// while no address is entered, so it is on as soon as one is typed (app.js follows the e-mail field)
$entryForm = static fn (?array $booking, array $old): string => App\View::capture('admin/booking_form', [
    'title' => 'Eintrag', 'round' => ['id' => 1, 'title' => 'Elternsprechtag'], 'booking' => $booking, 'moveOptions' => [], 'errors' => [],
    'slot' => ['id' => 5, 'day_date' => '2030-01-07', 'start_time' => '14:00:00', 'end_time' => '14:15:00'],
    'old' => $old + ['name' => 'Max', 'email' => '', 'lang' => 'de', 'send_mail' => true, 'move_to' => ''],
]);
$mailBox = static fn (string $html): string => preg_match('/<input type="checkbox" id="send_mail"[^>]*>/', $html, $m) ? $m[0] : '';
check('entry form: mail option tied to the e-mail field, ticked by default',
    str_contains($mailBox($entryForm(null, [])), ' data-mail-for="email" checked')
    && str_contains($mailBox($entryForm(null, ['email' => 'eltern@example.org'])), ' checked'));
check('entry form: unticked by hand (form shown again after an error) stays unticked',
    !str_contains($mailBox($entryForm(null, ['email' => 'eltern@example.org', 'send_mail' => false])), 'checked')
    && str_contains($mailBox($entryForm(['id' => 9], ['email' => '', 'send_mail' => false])), 'checked'));
// Edit form: the mail carries the current details, so it also goes out without a change (e.g. after a new date)
$hinted = static fn (string $html): bool => str_contains($mailBox($html), 'aria-describedby="mail-hint"')
    && str_contains($html, '<p class="hint hint-indent" id="mail-hint">Die E-Mail nennt die aktuellen Termindaten in der Sprache der Eltern und geht auch ohne Änderung hier hinaus');
check('edit form: hint that the mail also goes out without a change, not for a new entry',
    $hinted($entryForm(['id' => 9], ['email' => 'eltern@example.org'])) && !str_contains($entryForm(null, []), 'mail-hint'));
// Day form: with upcoming bookings whose parents can be mailed, the box "Eltern per E-Mail informieren" (ticked)
// and how many get no mail; if none can be mailed, the warning that nobody is told; nothing without bookings
$dayForm = static fn (int $upcoming, int $mailable = 0): string => App\View::capture('admin/day_form', [
    'day' => ['id' => 3, 'day_date' => '2030-01-07', 'start_time' => '14:00:00', 'end_time' => '16:00:00', 'slot_minutes' => 15, 'location' => null],
    'round' => ['id' => 1, 'title' => 'Elternsprechtag', 'location' => 'Raum 1.12'], 'parents' => ['upcoming' => $upcoming, 'mailable' => $mailable], 'errors' => [],
    'dayData' => ['dates' => [], 'start' => '14:00', 'end' => '16:00', 'minutes' => 15, 'breaks' => []], 'bookedSlots' => [],
]);
$notifyBox = '<input type="checkbox" id="notify" name="notify" value="1" checked>';
check('day form: parents with an address get the new details (box ticked), the others are counted',
    str_contains($dayForm(3, 2), 'An diesem Tag sind 3 Termin(e) gebucht. Ändern sich Datum, Uhrzeit oder Ort eines Termins, erhalten die Eltern die neuen Daten per E-Mail, solange „Eltern per E-Mail informieren“ angehakt ist.')
    && str_contains($dayForm(3, 2), '1 Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren.') && str_contains($dayForm(3, 2), $notifyBox)
    && !str_contains($dayForm(2, 2), 'ohne E-Mail an die Eltern') && !str_contains($dayForm(2, 2), 'NICHT automatisch'));
check('day form: nobody to mail → warning that the parents are not told, no box; without bookings neither',
    str_contains($dayForm(2), 'An diesem Tag sind 2 Termin(e) gebucht. Die Eltern werden über Änderungen an Datum, Zeiten oder Ort NICHT automatisch benachrichtigt')
    && !str_contains($dayForm(2), 'name="notify"')
    && !str_contains($dayForm(0), 'NICHT automatisch') && !str_contains($dayForm(0), 'name="notify"') && !str_contains($dayForm(0), 'Termin(e) gebucht'));
check('English: the hints are translated',
    App\AdminI18n::with('en', static fn () => str_contains($dayForm(1), '1 appointment(s) booked on this day. Parents are NOT notified automatically')
        && str_contains($dayForm(1, 1), 'the parents receive the new details by email as long as “Inform parents by email” is ticked.')));

echo "Appointment page: keeping the link (copy, calendar, share, mail), siblings on one device\n";
$mToken = str_repeat('Tk', 21) . 'x';
$mBooking = ['id' => 5, 'round_id' => 7, 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Berger', 'name' => 'Max Muster', 'location' => '',
    'day_date' => '2030-01-07', 'start_time' => '14:00:00', 'end_time' => '14:15:00', 'cancel_deadline_hours' => 24];
$manage = static fn (array $vars = []): string => App\View::capture('public/manage', $vars + [
    'booking' => $mBooking, 'token' => $mToken, 'canChange' => true, 'canMove' => true, 'days' => [], 'csrf' => '1.abc',
    'done' => '', 'mailed' => false, 'remember' => false, 'errors' => [], 'open' => '', 'mailTo' => '',
]);
$mp = $manage(['days' => [['date' => '2030-01-08', 'slots' => [['id' => 9, 'start_time' => '15:00:00', 'end_time' => '15:15:00']]]]]);
$mLink = abs_url('/b/' . $mToken);
// One form per action: [action => opening tag of its <details> or '' outside of one]
$section = static fn (string $html, string $id): string => (string) (preg_match('#<details class="card" id="' . $id . '"[^>]*>.*?</details>#s', $html, $m) ? $m[0] : '');
check('copy: button with the personal link, hidden until JavaScript runs, texts in the page language',
    str_contains($mp, '<button type="button" class="button" hidden data-copy="' . e($mLink) . '" data-copy-done="Kopiert ✓" data-copy-prompt="Link kopieren:">Link kopieren</button>')
    && str_contains(App\I18n::with('en', $manage), 'data-copy-done="Copied ✓" data-copy-prompt="Copy link:">Copy link</button>'));
check('calendar: a POST button on the appointment link (action=ics with the form token), no address of its own',
    (bool) preg_match('#<form method="post" action="/b/' . $mToken . '" class="inline" data-calendar>\s*<input type="hidden" name="csrf" value="1\.abc">\s*<input type="hidden" name="action" value="ics">\s*<button type="submit" class="button">In Kalender eintragen</button>#', $mp)
    && !str_contains($mp, '.ics"'));
check('share: hidden unless the device offers it, the hint about the link is always shown',
    str_contains($mp, 'hidden data-share="' . e($mLink) . '" data-share-title="Elternsprechtag 7b" data-share-text="' . e(fmt_slot('2030-01-07', '14:00:00', '14:15:00')) . '" aria-describedby="share-hint">Teilen …</button>')
    && str_contains($mp, '<p class="small" id="share-hint">Wer diesen Link hat, kann den Termin ändern oder absagen.'));
check('moving and cancelling are folded away (closed details) below the link',
    str_contains($section($mp, 'change'), '<details class="card" id="change">') && str_contains($section($mp, 'change'), 'name="action" value="move"')
    && str_contains($section($mp, 'cancel'), '<details class="card" id="cancel">') && str_contains($section($mp, 'cancel'), 'name="action" value="cancel"')
    && strpos($mp, 'data-copy=') < strpos($mp, 'id="change"'));
check('after an error the section of the sent form is open, the others stay closed',
    str_contains($manage(['open' => 'cancel', 'errors' => ['x']]), '<details class="card" id="cancel" open>') && str_contains($manage(['open' => 'cancel']), '<details class="card" id="change">')
    && str_contains($manage(['open' => 'move']), '<details class="card" id="change" open>') && str_contains($manage(['open' => 'move']), '<details class="card" id="confirm-mail">'));
$mailForm = $section($manage(['open' => 'mail', 'mailTo' => 'tippfehler@example', 'errors' => ['x']]), 'confirm-mail');
check('confirmation by mail: own form (action=mail, required address), entered address kept after an error',
    str_contains($section($mp, 'confirm-mail'), 'name="action" value="mail"') && str_contains($section($mp, 'confirm-mail'), 'name="email" required maxlength="254"')
    && str_contains($section($mp, 'confirm-mail'), 'value=""') && str_contains($mailForm, '<details class="card" id="confirm-mail" open>') && str_contains($mailForm, 'value="tippfehler@example"'));
check('confirmation by mail is offered after the deadline too, moving and cancelling are not',
    ($locked = $manage(['canChange' => false, 'canMove' => false])) !== '' && str_contains($locked, 'id="confirm-mail"') && str_contains($locked, 'data-calendar')
    && !str_contains($locked, 'id="cancel"') && !str_contains($locked, 'id="change"'));
check('message after sending the confirmation; unknown done values show nothing',
    str_contains($manage(['done' => 'sent']), '<p><strong>Die Bestätigung wurde an die angegebene E-Mail-Adresse gesendet.</strong></p>') && !str_contains($manage(['done' => 'x']), 'alert-success'));
check('remember-me entry carries the date of the appointment (after booking and when updating)',
    str_contains($manage(['remember' => true]), '<div hidden data-remember-round="7" data-remember-url="/b/' . $mToken . '" data-remember-label="' . e(fmt_slot('2030-01-07', '14:00:00', '14:15:00')) . '" data-remember-date="2030-01-07"></div>')
    && str_contains($mp, '<div hidden data-update-round="7" data-remember-url="/b/' . $mToken . '" data-remember-label="') && str_contains($mp, 'data-remember-date="2030-01-07"></div>'));
check('cancelled booking and a link that leads nowhere: the browser drops the entry of that link',
    str_contains(App\View::capture('public/cancelled', ['booking' => $mBooking + ['is_active' => 1, 'slug' => 'runde-7b'], 'forgetUrl' => '/b/' . $mToken]), '<div hidden data-forget-url="/b/' . $mToken . '"></div>')
    && str_contains(App\View::capture('public/notfound', ['title' => 'Nicht gefunden', 'text' => 'weg', 'forgetUrl' => '/b/' . $mToken]), '<div hidden data-forget-url="/b/' . $mToken . '"></div>')
    && !str_contains(App\View::capture('public/notfound', ['title' => 'Nicht gefunden', 'text' => 'weg']), 'data-forget-url'));
// Round page: one item per remembered booking (template), "book another" without deleting anything
$roundPage = static fn (array $old): string => App\View::capture('public/round', [
    'round' => ['id' => 7, 'slug' => 'runde-7b', 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Berger', 'booking_limit' => 'round'],
    'days' => [], 'csrf' => '1.abc', 'access' => '', 'errors' => [], 'old' => $old,
]);
$rp = $roundPage([]);
check('round page: list with an item template per remembered booking, per-item "forget" with the hint that the booking stays',
    str_contains($rp, '<ul class="remembered-list" data-remembered-list></ul>') && (bool) preg_match('#<template data-remembered-item>\s*<li>.*data-remembered-link.*data-remembered-forget>Merker auf diesem Gerät löschen</button>.*</li>\s*</template>#s', $rp)
    && str_contains($rp, '<p class="hint">Das löscht nur den Merker auf diesem Gerät. Ihr Termin bleibt bestehen.</p>'));
check('round page: "book another appointment (sibling)" button, hidden until JavaScript finds an entry',
    str_contains($rp, '<button type="button" class="button" hidden data-remembered-another>Weiteren Termin buchen (z. B. für ein Geschwisterkind)</button>'));
check('round page: the form stays open after a submission (language switch, error), not on a plain visit',
    str_contains($roundPage(['name' => 'Max']), '<div id="booking-area" data-keep-open>') && str_contains($rp, '<div id="booking-area">'));
$newKeys = ['manage.copy', 'manage.copied', 'manage.calendar', 'manage.share.hint', 'manage.mail', 'manage.mail.text', 'manage.mail.limit', 'mail.footer.copy', 'round.remembered.another', 'round.remembered.forget_hint'];
$untranslated = array_filter($newKeys, static fn (string $k): bool => count(array_unique(array_map(static fn ($l) => App\I18n::textIn($l, $k), App\I18n::languages()))) !== count(App\I18n::languages()));
check('new texts of the appointment page exist in every parent language' . ($untranslated ? ' (same text: ' . implode(', ', $untranslated) . ')' : ''), !$untranslated);
check('mail footer for a confirmation sent from the page: says why and that the address is not stored, same placeholders as the normal one',
    str_contains(App\I18n::textIn('de', 'mail.footer.copy'), 'auf der Seite Ihres Termins eingegeben') && str_contains(App\I18n::textIn('en', 'mail.footer.copy'), 'is not stored')
    && array_filter(App\I18n::languages(), static fn ($l) => preg_match_all('/\{\w+\}/', App\I18n::textIn($l, 'mail.footer.copy'), $a) && preg_match_all('/\{\w+\}/', App\I18n::textIn($l, 'mail.footer'), $b) && $a[0] === $b[0]) === App\I18n::languages());
