<?php
// Mails to parents and teachers: mail queue (outbox), reminders (see README.md in this directory)
echo "Mail queue (outbox)\n";
$cfg = (static function (): array { return require dirname(__DIR__, 2) . '/config/config.php'; })();
$schema = (string) file_get_contents(APP_ROOT . '/app/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS mail_outbox \((.*?)\n\) ENGINE/s', $schema, $outboxTable);
preg_match_all('/^  ([a-z_]+) [A-Z]/m', $outboxTable[1] ?? '', $outboxColumns);
// Data minimisation: no address, subject, text or token in the queue; it goes with the booking or account.
// Only the cancellation of a deleted booking keeps what its mail needs (payload, see Notifier::cancellationData())
check('outbox in schema.sql: references only (a payload only for cancellations), deleted with the booking or account',
    $outboxColumns[1] === ['id', 'kind', 'booking_id', 'user_id', 'ref', 'payload', 'priority', 'attempts', 'next_attempt_at', 'created_at']
    && str_contains($outboxTable[1], 'FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE')
    && str_contains($outboxTable[1], 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'));
$steps = (new ReflectionClassConstant(App\Migrations::class, 'STEPS'))->getValue();
$created = implode("\n", array_filter(array_merge($steps[20] ?? [], $steps[21] ?? []),
    static fn (string $sql): bool => str_contains($sql, 'CREATE TABLE IF NOT EXISTS mail_outbox') || str_contains($sql, 'ALTER TABLE mail_outbox')));
$norm = static fn (string $s): string => (string) preg_replace('/\s+/', ' ', $s);
check('migrations 20 and 21 create the same outbox (columns, keys, cascades)', $created !== '' && !array_filter(
    array_map('trim', explode(",\n", $outboxTable[1] ?? '')),
    static fn (string $definition): bool => !str_contains($norm($created), $norm($definition))
));
check('kinds fit the column, single mails before summaries before reminders',
    max(array_map('strlen', array_keys(App\Outbox::KINDS))) <= 20
    && App\Outbox::KINDS['booked'] === App\Outbox::KINDS['changed'] && App\Outbox::KINDS['changed'] < App\Outbox::KINDS['summary_evening']
    && App\Outbox::KINDS['summary_evening'] === App\Outbox::KINDS['summary_deadline'] && App\Outbox::KINDS['summary_deadline'] < App\Outbox::KINDS['reminder']);
$inMinutes = static fn (string $at): int => (int) round((strtotime($at) - time()) / 60);
check('retries: at once, then after 5, 15, 30, 60, 120 and 240 minutes, never longer',
    array_map(static fn (int $failures): int => $inMinutes(App\Outbox::retryAt($failures)), [0, 1, 2, 3, 4, 5, 6, 7, 50]) === [0, 5, 15, 30, 60, 120, 240, 240, 240]);
check('a mail is retried for at most 48 hours, overdue ones are shown after 2', App\Outbox::MAX_AGE_HOURS === 48 && App\Outbox::LATE_HOURS === 2);
$lateHours = static function (int $hours) use ($cfg): int { App\Config::load(['cron_alert_hours' => $hours] + $cfg); return App\Outbox::lateHours(); };
check('overdue mails: not before the cron monitoring would warn (12 → 12, 3 → 3), at least 2 hours (also with it off), before mails are given up (72 → 47)', [$lateHours(12), $lateHours(3), $lateHours(1), $lateHours(0), $lateHours(72)] === [12, 3, 2, 2, 47]);
App\Config::load($cfg);
$sample = (array) ((require dirname(__DIR__, 2) . '/config/config.sample.php')['mail_queue'] ?? []);
check('config.sample.php: mail_queue with the defaults of the code', $sample === ['batch' => 100, 'seconds' => 20, 'hourly_limit' => 0, 'after_response' => true]);

// After a page request: without PHP-FPM/LiteSpeed the response cannot be ended early, so nothing is sent
check('CLI (like Apache mod_php): the response cannot be ended early', App\Http::finishResponse() === false);
$finished = false;
App\Config::load(['mail_queue' => ['after_response' => false]] + $cfg);
check('turned off: nothing after the response, not even a database query', App\Outbox::afterResponse(static function () use (&$finished): bool {
    return $finished = true;
}) === 0 && !$finished);
App\Config::load($cfg);
check('booking without an address: nothing sent, nothing queued', App\Notifier::sendOrQueue(['id' => 1, 'email' => null], 'booked', null) === null
    && App\Notifier::sendOrQueue([], 'changed', null) === null);

// One SMTP connection per run of the outbox (keep-alive): a minimal SMTP server in a subprocess counts the
// connections (EHLO) and mails; STOP ends it and it prints "<connections>|<mails>"
$server = proc_open([PHP_BINARY, '-r', '
    $s = stream_socket_server("tcp://127.0.0.1:0");
    echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n";
    $connections = $mails = 0;
    $stop = false;
    while (!$stop && ($c = stream_socket_accept($s, 10))) {
        fwrite($c, "220 test\r\n");
        $data = false;
        while (($line = fgets($c)) !== false) {
            $cmd = strtoupper(substr($line, 0, 4));
            if ($data) {
                if (rtrim($line) === ".") {
                    $data = false;
                    $mails++;
                    fwrite($c, "250 queued\r\n");
                }
            } elseif ($cmd === "QUIT" || $cmd === "STOP") {
                $stop = $cmd === "STOP";
                fwrite($c, "221 bye\r\n");
                break;
            } else {
                $connections += (int) ($cmd === "EHLO");
                $data = $cmd === "DATA";
                fwrite($c, $data ? "354 go on\r\n" : "250 ok\r\n");
            }
        }
        fclose($c);
    }
    echo "$connections|$mails\n";'], [1 => ['pipe', 'w']], $serverPipes);
$port = (int) fgets($serverPipes[1]);
App\Config::load(['mail' => ['transport' => 'smtp', 'from_address' => 'absender@example.org', 'smtp' => ['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'user' => '', 'pass' => '']] + $cfg['mail']] + $cfg);
$sent = App\Mailer::batch(static fn (): array => array_map(static fn (string $to): bool => App\Mailer::send($to, 'S', 'B'), ['a@example.org', 'b@example.org', 'c@example.org']));
$sent[] = App\Mailer::send('d@example.org', 'S', 'B') && App\Mailer::send('e@example.org', 'S', 'B');
$stop = stream_socket_client('tcp://127.0.0.1:' . $port);
fgets($stop);
fwrite($stop, "STOP\r\n");
fgets($stop);
$counted = trim((string) fgets($serverPipes[1]));
proc_close($server);
check("batch: three mails over one SMTP connection, single mails one each ($counted)", $sent === [true, true, true, true] && $counted === '3|5');

// After an error the failed connection is dropped, the next mail connects again
App\Config::load(['alert_email' => 'betrieb@example.org', 'mail' => ['transport' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'user' => '', 'pass' => '']] + $cfg['mail']] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$alerts = [];
App\Alert::$transport = static function (string $to, string $subject) use (&$alerts): bool { $alerts[] = $subject; return true; };
$smtp = new ReflectionProperty(App\Mailer::class, 'smtp');
$results = App\Mailer::batch(static fn (): array => [App\Mailer::send('a@example.org', 'S', 'B'), $smtp->getValue(), App\Mailer::send('b@example.org', 'S', 'B')]);
check('batch: SMTP unreachable → the connection is dropped after the error, each mail fails on its own, reported once',
    $results === [false, null, false] && $alerts === ['[TalkSlot] Mailversand fehlgeschlagen'] && $smtp->getValue() === null);
App\Config::load(['mail' => ['transport' => 'none'] + $cfg['mail']] + $cfg);
check('batch: returns the result of the run', App\Mailer::batch(static fn (): string => App\Mailer::send('a@example.org', 'S', 'B') ? 'sent' : 'failed') === 'sent');
@unlink(App\Alert::$stateFile);
App\Alert::$transport = null;
App\Alert::$stateFile = null;
App\Config::load($cfg);

echo "Parents told about changes by the teacher\n";
// Calendar files: the title names the teacher; a cancellation removes the entry (same UID, METHOD:CANCEL)
App\Config::load(['mail' => ['from_address' => 'termine@example.org'] + $cfg['mail'], 'app_name' => 'Schule "am See"'] + $cfg);
$created = date('Y-m-d H:i:s', time() - 3600);
$pBooking = ['id' => 3, 'title' => 'Elternsprechtag 3b', 'teacher_name' => 'Frau Berger', 'name' => 'Max Muster', 'location' => 'Raum 1',
    'day_date' => '2030-12-02', 'start_time' => '14:00:00', 'end_time' => '14:15:00', 'created_at' => $created, 'updated_at' => date('Y-m-d H:i:s', strtotime($created) + 300)];
$prop = static fn (string $ics, string $name): ?string => preg_match('/^' . $name . '([:;].*)$/m', str_replace(["\r\n ", "\r"], '', $ics), $m) ? $m[1] : null;
$published = App\IcsExport::forParent($pBooking, 'Ändern: https://example.org/b/abc');
$cancelIcs = App\IcsExport::cancelForParent($pBooking);
check('calendar file: title = round and teacher (several teachers on one evening), only the round without a teacher name',
    $prop($published, 'SUMMARY') === ':Elternsprechtag 3b – Frau Berger' && $prop($published, 'X-WR-CALNAME') === ':Elternsprechtag 3b'
    && $prop(App\IcsExport::forParent(['teacher_name' => ' '] + $pBooking), 'SUMMARY') === ':Elternsprechtag 3b');
check('cancellation as calendar file: METHOD:CANCEL, same UID and title, STATUS:CANCELLED, a SEQUENCE above the last change',
    $prop($cancelIcs, 'METHOD') === ':CANCEL' && $prop($published, 'METHOD') === ':PUBLISH' && $prop($cancelIcs, 'UID') === $prop($published, 'UID')
    && $prop($cancelIcs, 'SUMMARY') === ':Elternsprechtag 3b – Frau Berger' && $prop($cancelIcs, 'STATUS') === ':CANCELLED' && $prop($published, 'STATUS') === null
    && (int) substr((string) $prop($cancelIcs, 'SEQUENCE'), 1) > (int) substr((string) $prop($published, 'SEQUENCE'), 1)
    && $prop($cancelIcs, 'DTSTART') === $prop($published, 'DTSTART'));
check('cancellation: organizer is the sender address of the installation (quotes removed from the name), no address of a person',
    $prop($cancelIcs, 'ORGANIZER') === ';CN="Schule am See":mailto:termine@example.org' && $prop($published, 'ORGANIZER') === null
    && !str_contains($cancelIcs, 'example.org/b/'));
App\Config::load(['mail' => ['from_address' => ''] + $cfg['mail']] + $cfg);
check('cancellation without a sender address: no organizer line', $prop(App\IcsExport::cancelForParent($pBooking), 'ORGANIZER') === null);
App\Config::load($cfg);

// Who is mailed: parents who booked themselves, or got a mail about the teacher's entry; appointments still ahead
$entry = ['email' => 'eltern@example.org', 'source' => 'admin', 'notified_at' => null];
check('mailable: parents who booked with an address, and entries by the teacher once a mail reached the parents',
    App\ScheduleService::mailable(['source' => 'parent'] + $entry) && App\ScheduleService::mailable(['notified_at' => '2030-01-01 10:00:00'] + $entry)
    && !App\ScheduleService::mailable($entry) && !App\ScheduleService::mailable(['email' => '', 'source' => 'parent'] + $entry)
    && !App\ScheduleService::mailable(['email' => null, 'notified_at' => '2030-01-01 10:00:00'] + $entry));
$now = App\Time::now();
check('upcoming: an appointment later today counts, one that has begun does not',
    App\ScheduleService::upcoming(['day_date' => $now->format('Y-m-d'), 'start_time' => $now->modify('+1 minute')->format('H:i:s')])
    && !App\ScheduleService::upcoming(['day_date' => $now->format('Y-m-d'), 'start_time' => $now->modify('-1 minute')->format('H:i:s')])
    && !App\ScheduleService::upcoming(['day_date' => '2020-01-01', 'start_time' => '23:59:00']));

// What a cancellation keeps once the booking is gone: what the mail needs, no token, no link, no description
$data = App\Notifier::cancellationData($pBooking + ['round_id' => 7, 'email' => 'eltern@example.org', 'lang' => 'de', 'source_lang' => 'de',
    'description' => 'Beschreibung', 'teacher_email' => 'berger@example.org', 'token_hash' => str_repeat('a', 64), 'reminder_token_hash' => str_repeat('b', 64),
    'mail_token_hash' => null, 'source' => 'parent', 'slug' => 'runde-3b', 'code' => 'K7MP3QXA']);
check('cancellation data: only what the mail needs (address, language, name, appointment, teacher), no hash, link or code',
    array_keys($data) === ['booking_id', 'round_id', 'email', 'lang', 'name', 'title', 'auto_translated', 'source_lang', 'teacher_name', 'teacher_email', 'day_date', 'start_time', 'end_time', 'location', 'created_at']
    && $data['booking_id'] === 3 && $data['title'] === 'Elternsprechtag 3b' && $data['auto_translated'] === false
    && !array_filter($data, static fn ($v): bool => is_string($v) && (str_contains($v, 'aaaa') || str_contains($v, 'bbbb') || str_contains($v, 'runde-3b') || str_contains($v, 'K7MP'))));
$sampleLimits = (array) ((require dirname(__DIR__, 2) . '/config/config.sample.php')['rate_limits'] ?? []);
check('config.sample.php: mails to parents per teacher account and hour with the defaults of the code (30 from entries, the bulk limit after changes)',
    ($sampleLimits['teacher_mail'] ?? null) === [30, 3600] && ($sampleLimits['teacher_bulk'] ?? null) === [App\ScheduleService::BULK_LIMIT, 3600]
    && App\ScheduleService::BULK_LIMIT >= 100);
check('outbox: a single cancellation goes first like other single mails, bulk mails after them but before reminders',
    App\Outbox::KINDS['cancelled'] === App\Outbox::KINDS['booked'] && App\Outbox::BULK > App\Outbox::KINDS['changed']
    && App\Outbox::BULK <= App\Outbox::KINDS['summary_evening'] && App\Outbox::BULK < App\Outbox::KINDS['reminder']);

// Mail texts of the cancellation by the teacher: in every parent language, translated, the same placeholders
$cancelKeys = ['mail.cancelled_teacher.subject', 'mail.cancelled_teacher.body', 'mail.cancelled_teacher.rebook', 'mail.ics.cancel_hint'];
$placeholders = static fn (string $s): array => preg_match_all('/\{\w+\}/', $s, $m) ? $m[0] : [];
$badTexts = [];
foreach ($cancelKeys as $key) {
    foreach (App\I18n::languages() as $l) {
        $text = App\I18n::textIn($l, $key);
        if ($text === $key || $placeholders($text) !== $placeholders(App\I18n::textIn('de', $key)) || ($l !== 'de' && $text === App\I18n::textIn('de', $key))) {
            $badTexts[] = "$l:$key";
        }
    }
}
check('cancellation by the teacher: mail texts in every parent language, translated, same placeholders' . ($badTexts ? ' (' . implode(', ', $badTexts) . ')' : ''), !$badTexts);
check('cancellation mail: no link of the booking (it is gone), the name of the booking, the teacher',
    !str_contains(App\I18n::textIn('de', 'mail.cancelled_teacher.body'), '{link}') && str_contains(App\I18n::textIn('de', 'mail.cancelled_teacher.body'), 'Name: {name}')
    && str_contains(App\I18n::textIn('en', 'mail.cancelled_teacher.body'), 'the teacher has cancelled your appointment'));

echo "Summaries without names, return after signing in (expert review P1-4)\n";
$sumRow = static fn (int $id, string $name, ?string $email, string $start, string $end, array $more = []): array => $more + [
    'id' => $id, 'name' => $name, 'email' => $email, 'source' => 'parent', 'mail_failed_at' => null, 'is_conflict' => 0,
    'day_date' => '2026-08-17', 'start_time' => $start, 'end_time' => $end, 'title' => 'Elternsprechtag 3b', 'round_id' => 4,
];
$sumRows = [
    $sumRow(1, 'Anna Muster', 'anna@example.org', '14:00:00', '14:15:00'),
    $sumRow(2, ' anna  MUSTER', 'Anna@Example.org ', '14:15:00', '14:30:00', ['mail_failed_at' => '2026-08-10 10:00:00']),
    $sumRow(3, 'Ben Beispiel', null, '14:45:00', '15:00:00', ['is_conflict' => 1]),
    $sumRow(4, 'Eintrag der Lehrkraft', '', '15:00:00', '15:15:00', ['source' => 'admin']),
    $sumRow(5, 'Cem Test', 'cem@example.org', '16:00:00', '16:10:00', ['title' => 'Sprechstunde', 'round_id' => 9]),
];
check('summary: what stands out – the same name or address (case and spaces aside), parents without an address, undelivered mails, conflicts',
    App\AdminI18n::with('de', static fn () => App\Summaries::notes(array_slice($sumRows, 0, 4)))
        === ['2× gleicher Name', '2× gleiche E-Mail-Adresse', '1× ohne E-Mail-Adresse', '1× E-Mail nicht zugestellt', '1× passt nicht mehr zum Zeitplan']
    && App\Summaries::notes([$sumRows[4]]) === [] && App\Summaries::notes([]) === []
    && App\AdminI18n::with('en', static fn () => App\Summaries::notes([$sumRows[0], $sumRows[0]])) === ['2× the same name', '2× the same email address']);
$sumText = static fn (bool $names, array $rows, string $lang = 'de'): string => App\AdminI18n::with($lang,
    static fn () => App\Summaries::text(['display_name' => 'Frau Berger'], 'Ihre Termine für morgen:', $rows, $names));
$plain = $sumText(false, $sumRows);
check('summary without names: per round and day the number and times, what stands out and the link; no name, no address',
    str_contains($plain, "Hallo Frau Berger,\n\nIhre Termine für morgen:\n\nElternsprechtag 3b – Montag, 17.08.2026\n  4 Termine zwischen 14:00 und 15:15\n  Auffällig: 2× gleicher Name,")
    && str_contains($plain, "  Übersicht und Druckansicht: " . abs_url('/admin/round/4', ['lang' => null]) . "\n\nSprechstunde – Montag, 17.08.2026\n  1 Termin um 16:00\n  Übersicht und Druckansicht: " . abs_url('/admin/round/9', ['lang' => null]) . "\n\n--\n")
    && str_ends_with($plain, 'Diese Zusammenfassung enthält keine Namen: Die Terminliste sehen Sie nach der Anmeldung über den Link. Abbestellen können Sie sie im Profil.')
    && !preg_match('/Anna|Ben|Cem|Eintrag|@example\.org/i', $plain));
$named = $sumText(true, $sumRows);
check('summary with names: time and name of each appointment, deleting by the end of the school year; still no address',
    str_contains($named, "Elternsprechtag 3b – Montag, 17.08.2026\n  14:00–14:15  Anna Muster\n  14:15–14:30   anna  MUSTER\n")
    && str_contains($named, "  16:00–16:10  Cem Test\n") && str_contains($named, 'spätestens zum Ende des Schuljahres')
    && !str_contains($named, '15 Monate') && !str_contains($named, 'example.org '));
check('summary in English, and without bookings',
    str_contains($sumText(false, $sumRows, 'en'), "  4 appointments between 14:00 and 15:15\n  Worth a look: 2× the same name,")
    && str_contains($sumText(false, [], 'en'), "\n\n(no bookings)\n\n--\n") && str_contains($sumText(false, []), "Ihre Termine für morgen:\n\n(keine Buchungen)\n"));
// The calendar file of the evening summary has every round of the teacher that day: each appointment is
// titled with its own round (a round's title often names the class or room), never with a name
$sumIcs = str_replace("\r\n ", '', App\IcsExport::forRound(['title' => 'Elternsprechtag 3b, Sprechstunde'], [$sumRows[0], $sumRows[4]]));
preg_match_all('/^SUMMARY:(.*)\r$/m', $sumIcs, $sumIcsTitles);
check('calendar file of a summary: each appointment with the title of its own round, no name',
    $sumIcsTitles[1] === ['Elternsprechtag 3b', 'Sprechstunde'] && !preg_match('/Anna|Cem|example\.org/', $sumIcs)
    && $prop($sumIcs, 'X-WR-CALNAME') === ':Elternsprechtag 3b\, Sprechstunde');

// Reply-To of the parents' mails: the reply address, else the account's address, each only where allowed
$replyTo = static fn (?string $replyAddress, ?string $email): ?string => App\Notifier::replyTo(['teacher_reply_to' => $replyAddress, 'teacher_email' => $email]);
$replyOpen = [$replyTo('antwort@gmx.example', 'konto@schule.example.org'), $replyTo(null, 'konto@schule.example.org'), $replyTo('', 'kein at'), $replyTo('kein at', 'konto@gmx.example')];
App\Config::load(['allowed_account_domains' => ['schule.example.org']] + $cfg);
$replyClosed = [$replyTo('antwort@gmx.example', 'konto@schule.example.org'), $replyTo('antwort@schule.example.org', 'konto@gmx.example'), $replyTo('antwort@gmx.example', 'konto@gmx.example'),
    App\Notifier::replyTo(['teacher_email' => 'konto@schule.example.org']), App\Notifier::replyTo([])];
App\Config::load($cfg);
check('Reply-To: the reply address for parents, else the account\'s address; one outside allowed_account_domains falls back to an allowed one or none',
    $replyOpen === ['antwort@gmx.example', 'konto@schule.example.org', null, 'konto@gmx.example']
    && $replyClosed === ['konto@schule.example.org', 'antwort@schule.example.org', null, 'konto@schule.example.org', null]);

App\Config::load(['summary_names' => false] + $cfg);
$namesOff = !App\Summaries::withNames(['summary_names' => 1]) && !App\Summaries::withNames(['summary_names' => null]);
$noticeOff = static fn (bool $admin): ?string => App\AdminI18n::with('de', static fn () => App\Summaries::notice(['summary_names' => null, 'summary_evening' => 0, 'summary_deadline' => 1], $admin));
[$teacherOff, $adminOff] = [$noticeOff(false), $noticeOff(true)];
App\Config::load(['summary_names' => true] + $cfg);
$sumNotice = static fn (array $user): ?string => App\AdminI18n::with('de', static fn () => App\Summaries::notice($user + ['summary_evening' => 1, 'summary_deadline' => 1], false));
check('names only where the school allows them, then as the account chose (new accounts 0, accounts from before NULL = as before)',
    $namesOff && App\Summaries::withNames(['summary_names' => 1]) && App\Summaries::withNames(['summary_names' => '1']) && App\Summaries::withNames(['summary_names' => null])
    && !App\Summaries::withNames(['summary_names' => 0]) && !App\Summaries::withNames(['summary_names' => '0']) && !App\Summaries::withNames([]));
check('dashboard notice: only for accounts from before (NULL) with summaries, saying what changed for them',
    str_starts_with((string) $teacherOff, 'Neu: Ihre Zusammenfassungen per E-Mail enthalten keine Namen und keine Kalenderdatei mehr') && str_ends_with((string) $teacherOff, 'Namen kann nur die Schule freigeben.')
    && str_contains((string) $adminOff, '„summary_names“ in config/config.php')
    && str_starts_with((string) $sumNotice(['summary_names' => null]), 'Neu: Die Kalenderdatei Ihrer Zusammenfassungen enthält keine Namen mehr.')
    && $sumNotice(['summary_names' => 0]) === null && $sumNotice(['summary_names' => 1]) === null && $sumNotice([]) === null
    && App\Summaries::notice(['summary_names' => null, 'summary_evening' => 0, 'summary_deadline' => 0], false) === null);

// Profile: the choice only where the school allows names; the reply address for parents; the allowed domains
$_SESSION = ['csrf' => 'test'];
$profileUser = ['display_name' => 'Frau Berger', 'username' => 'berger', 'role' => 'teacher', 'email' => 'berger@schule.example.org', 'reply_to' => 'sekretariat@schule.example.org',
    'lang' => 'de', 'summary_evening' => 1, 'summary_deadline' => 1, 'summary_names' => null];
$profile = static fn (array $change = []): string => App\AdminI18n::with('de', static fn () => App\View::capture('admin/profile',
    ['user' => $change + $profileUser, 'data' => $change + $profileUser, 'errors' => []]));
$namesOn = $profile();
$namesNo = $profile(['summary_names' => 0]);
App\Config::load(['allowed_account_domains' => ['schule.example.org']] + $cfg);
$namesHidden = $profile();
App\Config::load($cfg);
check('profile: names to choose only where the school allows them (as before for NULL), the reply address for parents, the allowed domains',
    str_contains($namesOn, '<input type="checkbox" id="summary_names" name="summary_names" value="1" checked>') && str_contains($namesNo, 'name="summary_names" value="1">')
    && !str_contains($namesHidden, 'summary_names') && str_contains($namesHidden, 'Die Schule erlaubt nur Adressen bei schule.example.org.')
    && !str_contains($namesOn, 'Die Schule erlaubt nur')
    && str_contains($namesOn, '<input type="email" id="reply_to" name="reply_to" maxlength="254" value="sekretariat@schule.example.org" aria-describedby="rt-hint">'));
$sumDash = static fn (array $vars): string => App\AdminI18n::with('de', static fn () => App\View::capture('admin/dashboard', $vars + ['title' => 'x', 'rounds' => [], 'showOwner' => false,
    'configExposed' => [], 'profileHints' => [], 'operatorMissing' => false, 'legalHints' => [], 'mailsLate' => null, 'unusedAccounts' => 0]));
$withNotice = $sumDash(['summaryNotice' => 'Neu: <keine Namen>', 'foreignAddresses' => 2]);
check('dashboard: the notice with the link to the profile and a button to confirm it; accounts outside the allowed domains',
    str_contains($withNotice, '<div class="alert alert-info" role="status" data-summary-notice>' . "\n    <p>Neu: &lt;keine Namen&gt; <a href=\"/admin/profile\">Zum Profil</a></p>")
    && str_contains($withNotice, '<form method="post" action="/admin/profile/summaries"><input type="hidden" name="csrf" value="test"><button type="submit" class="button button-small">Verstanden</button></form>')
    && str_contains($withNotice, 'außerhalb von „allowed_account_domains“: 2.')
    && !str_contains($sumDash([]), 'data-summary-notice') && !str_contains($sumDash([]), 'data-foreign-addresses'));
unset($_SESSION);

check('return after signing in: only pages of the admin area (path and query of the app), nothing else',
    App\Auth::returnPath('/admin/round/5') === '/admin/round/5' && App\Auth::returnPath('/admin/round/5/print?day=3&x=%20') === '/admin/round/5/print?day=3&x=%20'
    && App\Auth::returnPath('/admin/users/3/edit') === '/admin/users/3/edit'
    && !array_filter(['', '/admin', '/admin/', '/admin?x=1', '/admin/login', '/admin/login?next=/admin/round/1', '/admin/logout', '//evil.example', '/admin//evil.example',
        'https://evil.example/admin/round/1', '/adminx', '/admin/../datenschutz', '/admin/./round', '/admin\\evil', '/admin/round/1#top', '/admin/round/<b>', "/admin/round/1\n",
        '/admin/round/1?next=//evil.example', '/b/abc', 'admin/round/1'], static fn (string $next): bool => App\Auth::returnPath($next) !== null));
$crossSite = static function (string $method, ?string $site): bool {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_SEC_FETCH_SITE'] = $site;
    if ($site === null) {
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    }
    return App\Http::isCrossSiteGet();
};
check('a page opened from another site: GET with Sec-Fetch-Site cross-site only',
    $crossSite('GET', 'cross-site') && $crossSite('GET', ' Cross-Site') && !$crossSite('GET', 'same-origin') && !$crossSite('GET', 'same-site')
    && !$crossSite('GET', 'none') && !$crossSite('GET', null) && !$crossSite('POST', 'cross-site'));
unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_SEC_FETCH_SITE']);
$loginPage = static fn (string $next): string => App\AdminI18n::with('de', static fn () => App\View::capture('admin/login', ['error' => '', 'username' => '', 'csrf' => 'x', 'next' => $next]));
$hop = App\AdminI18n::with('de', static fn () => App\View::capture('admin/layout', ['title' => 'Weiterleitung', 'flash' => [], 'refresh' => '/admin/round/5?day=1&x=2',
    'content' => App\View::capture('admin/message', ['title' => 'Weiterleitung', 'text' => 'Gleich.', 'link' => ['/admin/round/5?day=1&x=2', 'Weiter']])]));
check('login form carries the page to go back to; the interim page opens it again from this site (meta refresh, link as fallback)',
    str_contains($loginPage('/admin/round/5'), '<input type="hidden" name="next" value="/admin/round/5">') && !str_contains($loginPage(''), 'name="next"')
    && str_contains($hop, '<meta http-equiv="refresh" content="0; url=/admin/round/5?day=1&amp;x=2">') && str_contains($hop, '<p><a href="/admin/round/5?day=1&amp;x=2">Weiter</a></p>')
    && !str_contains(App\View::capture('admin/layout', ['content' => '', 'title' => 'x', 'flash' => []]), 'http-equiv="refresh"')
    && str_contains(App\AdminI18n::with('de', static fn () => App\View::capture('admin/message', ['title' => 'x', 'text' => 'y'])), '<a href="/admin">Zur Übersicht</a>'));

// allowed_account_domains: work addresses only, where the school's deletion rules apply
App\Config::load(['allowed_account_domains' => [' Schule.Example.org', '@xn--mnchen-3ya.de', 'kaputt', 7, 'schule.example.org']] + $cfg);
$domainError = App\AdminI18n::with('de', static fn () => App\AccountDomains::error('a@gmx.de'));
$installErrors = App\AdminI18n::with('de', static fn () => [App\Installer::validate('admin1', 'Kiesel-Turm-Wolke-42', 'Kiesel-Turm-Wolke-42', 'a@gmx.de'),
    App\Installer::validate('admin1', 'Kiesel-Turm-Wolke-42', 'Kiesel-Turm-Wolke-42', 'a@schule.example.org')]);
check('allowed domains: normalised (case, spaces, "@", duplicates), invalid entries left out',
    App\AccountDomains::allowed() === ['schule.example.org', 'xn--mnchen-3ya.de']);
check('allowed domains: exactly the domain, regardless of case; the message names the domains, none for an empty address',
    App\AccountDomains::permits('A.Muster@SCHULE.example.org') && App\AccountDomains::permits('x@xn--mnchen-3ya.de')
    && !App\AccountDomains::permits('a@lehrer.schule.example.org') && !App\AccountDomains::permits('a@example.org') && !App\AccountDomains::permits('a@schule.example.org.evil.example')
    && !App\AccountDomains::permits('schule.example.org') && !App\AccountDomains::permits('')
    && $domainError === 'Bitte eine Adresse bei schule.example.org, xn--mnchen-3ya.de angeben: Die Schule erlaubt für Konten nur ihre dienstlichen Adressen.'
    && App\AccountDomains::error('') === null && App\AccountDomains::error('a@schule.example.org') === null);
check('installer: the first admin account as well', in_array($domainError, $installErrors[0], true) && $installErrors[1] === []);
App\Config::load($cfg);
check('without allowed domains: any address',
    App\AccountDomains::allowed() === [] && App\AccountDomains::permits('a@gmx.de') && App\AccountDomains::error('a@gmx.de') === null);
$devConfig = (static fn (): array => require dirname(__DIR__, 2) . '/dev/config.dev.php')();
$domainWarnings = static fn (mixed $value): array => App\AdminI18n::with('de', static fn () => App\ConfigSchema::warnings(['allowed_account_domains' => $value] + $devConfig));
check('configuration: allowed_account_domains a list of domain names, otherwise a warning; summary_names true or false',
    App\AccountDomains::valid('schule.example.org') && App\AccountDomains::valid('@schule.de') && !App\AccountDomains::valid('schule') && !App\AccountDomains::valid('-x.de')
    && !App\AccountDomains::valid('a..de') && !App\AccountDomains::valid('a@b.de') && !App\AccountDomains::valid('*.schule.de')
    && $domainWarnings([]) === [] && $domainWarnings(['schule.example.org', '@xn--mnchen-3ya.de']) === []
    && $domainWarnings(['schule.example.org', 'kaputt']) === ['„allowed_account_domains“ hat keinen gültigen Wert, erwartet wird eine Liste von Domains, z. B. [\'schule.example.org\'].']
    && count($domainWarnings('schule.example.org')) === 1 && count($domainWarnings(['a' => 'schule.example.org'])) === 1
    && App\AdminI18n::with('de', static fn () => App\ConfigSchema::warnings(['summary_names' => 'ja'] + $devConfig)) === ['„summary_names“ hat keinen gültigen Wert, erwartet wird true oder false (ohne Anführungszeichen).']);

// Migration 22: existing accounts NULL (as before), new ones 0, and a repeated run changes nothing
$step22 = implode("\n", $steps[22] ?? []);
check('migration 22 and schema.sql: summary_names (existing accounts NULL, new ones 0) and reply_to',
    str_contains($step22, 'ADD COLUMN IF NOT EXISTS summary_names TINYINT(1) NULL AFTER summary_deadline') && !str_contains(strstr($step22, 'summary_names') ?: '', 'NULL DEFAULT')
    && str_contains($step22, 'ADD COLUMN IF NOT EXISTS reply_to VARCHAR(254) NULL AFTER email') && str_contains($step22, 'ALTER COLUMN summary_names SET DEFAULT 0')
    && str_contains($schema, "  summary_names TINYINT(1) NULL DEFAULT 0,\n") && str_contains($schema, "  email VARCHAR(254) NULL,\n  reply_to VARCHAR(254) NULL,\n")
    && App\Migrations::LATEST >= 22);
// Deployment with GitHub Actions: both settings from repository variables
$p14File = tempnam(sys_get_temp_dir(), 'cfg');
$p14Env = ['BASE_URL' => 'https://t.example/', 'APP_SECRET' => str_repeat('s', 40), 'DB_USER' => 'u', 'DB_PASS' => 'p'];
[$rc] = $run(['bin/build-config.php', $p14File], $p14Env);
$p14Default = (static fn ($f) => require $f)($p14File);
[$rc2] = $run(['bin/build-config.php', $p14File], ['SUMMARY_NAMES' => '1', 'ALLOWED_ACCOUNT_DOMAINS' => ' Schule.Example.org, @xn--mnchen-3ya.de,,schule.example.org'] + $p14Env);
$p14Set = (static fn ($f) => require $f)($p14File);
@unlink($p14File);
[$rc3, , $err3] = $run(['bin/build-config.php', $p14File], ['ALLOWED_ACCOUNT_DOMAINS' => 'schule.example.org, gmx'] + $p14Env);
check('build-config: SUMMARY_NAMES and ALLOWED_ACCOUNT_DOMAINS (off and none by default, a value that is no domain stops it)',
    $rc === 0 && $p14Default['summary_names'] === false && $p14Default['allowed_account_domains'] === []
    && $rc2 === 0 && $p14Set['summary_names'] === true && $p14Set['allowed_account_domains'] === ['schule.example.org', 'xn--mnchen-3ya.de']
    && $rc3 === 1 && str_contains($err3, 'ALLOWED_ACCOUNT_DOMAINS: "gmx" is not a domain') && !is_file($p14File));
