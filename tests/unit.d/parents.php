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
