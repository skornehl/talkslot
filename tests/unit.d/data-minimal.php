<?php
// Data-minimal wording (see README.md in this directory)
echo "Data-minimal wording: the child's name, the log\n";
// Expert review P2-4: the name field asks for the child's name in every parent language and the booking limit is one
// appointment per child (UX-14), and booking events in the log carry their date only (DS-18): with the time they
// could still be matched to a person for 90 days.
$dmChild = ['de' => 'Name des Kindes', 'en' => 'Child’s name', 'pl' => 'Imię i nazwisko dziecka', 'ru' => 'Имя и фамилия ребёнка',
    'uk' => 'Імʼя та прізвище дитини', 'tr' => 'Çocuğun adı ve soyadı'];
$dmLangs = array_map(static fn (string $f): string => basename($f, '.php'), glob(APP_ROOT . '/app/lang/*.php') ?: []);
sort($dmLangs);
$dmBad = array_keys(array_filter($dmChild, static fn (string $label, string $lang): bool => (require APP_ROOT . "/app/lang/$lang.php")['form.name'] !== $label, ARRAY_FILTER_USE_BOTH));
check('booking form: the name field asks for the child\'s (full) name in every parent language' . ($dmBad ? ' (' . implode(', ', $dmBad) . ')' : ''),
    !$dmBad && (static function (array $langs): array { sort($langs); return $langs; })(array_keys($dmChild)) === $dmLangs);
$dmRound = static fn (string $limit): string => App\I18n::with('de', static fn (): string => App\View::capture('public/round', [
    'round' => ['id' => 1, 'slug' => 'runde-7b', 'title' => 'Elternsprechtag 7b', 'teacher_name' => 'Frau Beispiel', 'description' => '', 'location' => '',
        'booking_limit' => $limit, 'booking_deadline_hours' => 0, 'cancel_deadline_hours' => 0, 'remind_parents' => 0],
    'days' => [['date' => '2030-11-13', 'location' => '', 'slots' => [['id' => 41, 'start_time' => '15:45:00', 'end_time' => '16:00:00']]]],
    'csrf' => '1.abc', 'access' => '', 'errors' => [], 'old' => [],
]));
$dmHint = static fn (string $html): string => (string) (preg_match('#<p class="hint" id="name-hint">(.*?)</p>#s', $html, $m) ? $m[1] : '');
check('name hint: first and last name with the class, nothing else; the sibling rule once, in the info box (one appointment per child)',
    $dmHint($dmRound('round')) === 'Vor- und Nachname, bei Bedarf mit Klasse (z. B. „Mia Schmidt, 7b“). Weitere Angaben sind nicht nötig.'
    && $dmHint($dmRound('none')) === $dmHint($dmRound('round')) && substr_count($dmRound('round'), 'Für Geschwister') === 1
    && str_contains($dmRound('round'), 'Pro Kind ist nur ein Termin möglich.') && str_contains($dmRound('day'), 'Pro Kind ist nur ein Termin pro Tag möglich.')
    && str_contains($dmRound('none'), 'Bitte buchen Sie nur einen Termin pro Kind.'));
// Every log message about a booking takes the date only: the five calls, none with a time
$dmSrc = (string) file_get_contents(APP_ROOT . '/app/src/Controller/AdminController.php');
$dmCalls = preg_match_all("/__\\('Buchung am \\{date\\}[^']*', \\[[^\\]]*\\]/", $dmSrc, $dmMsgs);
$dmTimed = array_values(array_filter($dmMsgs[0], static fn (string $call): bool => str_contains($call, 'start_time')));
check('log: the five booking events with the date only' . ($dmTimed ? ' (with a time: ' . implode(' | ', $dmTimed) . ')' : " ($dmCalls found)"),
    $dmCalls === 5 && !$dmTimed && !preg_match("/__\\('Buchung \\{slot\\}/", $dmSrc));
