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

echo "Admin: parents are not notified automatically\n";
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
// Day form: with bookings a warning that the parents hear nothing about a new date, time or place
$dayForm = static fn (int $bookings): string => App\View::capture('admin/day_form', [
    'day' => ['id' => 3, 'day_date' => '2030-01-07', 'start_time' => '14:00:00', 'end_time' => '16:00:00', 'slot_minutes' => 15, 'location' => null],
    'round' => ['id' => 1, 'title' => 'Elternsprechtag', 'location' => 'Raum 1.12'], 'bookings' => $bookings, 'errors' => [],
]);
check('day form: warning with the number of bookings, none without bookings',
    str_contains($dayForm(2), 'An diesem Tag sind 2 Termin(e) gebucht. Die Eltern werden über Änderungen an Datum, Zeiten oder Ort NICHT automatisch benachrichtigt')
    && !str_contains($dayForm(0), 'NICHT automatisch benachrichtigt – bitte selbst')
    && str_contains($dayForm(0), 'Bereits gebuchte Eltern werden bei einer Änderung NICHT automatisch benachrichtigt.'));
check('English: the warnings are translated',
    App\AdminI18n::with('en', static fn () => str_contains($dayForm(1), '1 appointment(s) booked on this day. Parents are NOT notified automatically')));
