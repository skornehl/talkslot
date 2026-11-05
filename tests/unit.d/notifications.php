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
