<?php
// Data-minimal wording (see README.md in this directory)
echo "Data-minimal wording: the child's name, the log\n";
// Expert review P2-4: the name field asks for the child's name in every parent language (UX-14), and booking events
// in the log carry their date only (DS-18): with the time they could still be matched to a person for 90 days.
$dmChild = ['de' => 'Kindes', 'en' => 'Child', 'pl' => 'dziecka', 'ru' => 'ребёнка', 'uk' => 'дитини', 'tr' => 'Çocuğun'];
$dmBad = array_keys(array_filter($dmChild, static fn (string $word, string $lang): bool
    => !str_contains(App\I18n::textIn($lang, 'form.name'), $word) || App\I18n::textIn($lang, 'form.name.siblings') === 'form.name.siblings', ARRAY_FILTER_USE_BOTH));
check('booking form: the name field asks for the child\'s name, with a sibling hint, in every parent language' . ($dmBad ? ' (' . implode(', ', $dmBad) . ')' : ''),
    !$dmBad && array_keys($dmChild) == App\I18n::languages());
$dmRound = static fn (string $limit): string => App\I18n::with('de', static fn (): string => App\View::capture('public/round', [
    'round' => ['id' => 1, 'slug' => 'runde-7b', 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Beispiel', 'description' => '', 'location' => '',
        'booking_limit' => $limit, 'booking_deadline_hours' => 0, 'cancel_deadline_hours' => 0, 'remind_parents' => 0],
    'days' => [['date' => '2030-11-13', 'location' => '', 'slots' => [['id' => 41, 'start_time' => '15:45:00', 'end_time' => '16:00:00']]]],
    'csrf' => '1.abc', 'access' => '', 'errors' => [], 'old' => [],
]));
$dmHint = static fn (string $html): string => (string) (preg_match('#<p class="hint" id="name-hint">(.*?)</p>#s', $html, $m) ? $m[1] : '');
check('name hint: first and last name with the class; the sibling rule only where the round limits bookings',
    $dmHint($dmRound('none')) === 'Vor- und Nachname, bei Bedarf mit Klasse (z. B. „Mia Schmidt, 7b“). Mehr ist nicht nötig.'
    && str_ends_with($dmHint($dmRound('round')), 'Mehr ist nicht nötig. Für Geschwister buchen Sie bitte je Kind einen eigenen Termin mit dessen Namen.')
    && str_contains($dmHint($dmRound('day')), 'Für Geschwister'));
// Every log message about a booking takes the date only: no start time in the arguments of these messages
$dmSrc = (string) file_get_contents(APP_ROOT . '/app/src/Controller/AdminController.php');
preg_match_all("/__\\('Buchung[^']*'[^;]*/", $dmSrc, $dmMsgs);
$dmTimed = array_values(array_filter($dmMsgs[0], static fn (string $call): bool => str_contains($call, 'start_time')));
check('log: booking events with the date only (' . count($dmMsgs[0]) . ' messages' . ($dmTimed ? '; with a time: ' . implode(' | ', $dmTimed) : '') . ')',
    count($dmMsgs[0]) >= 5 && !$dmTimed);
