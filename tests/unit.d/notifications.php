<?php
// Mails to parents and teachers: mail queue (outbox), reminders (see README.md in this directory)
echo "Mail queue (outbox)\n";
$cfg = (static function (): array { return require dirname(__DIR__, 2) . '/config/config.php'; })();
$schema = (string) file_get_contents(APP_ROOT . '/app/schema.sql');
preg_match('/CREATE TABLE IF NOT EXISTS mail_outbox \((.*?)\n\) ENGINE/s', $schema, $outboxTable);
preg_match_all('/^  ([a-z_]+) [A-Z]/m', $outboxTable[1] ?? '', $outboxColumns);
// Data minimisation: no address, subject, text or token in the queue; it goes with the booking or account
check('outbox in schema.sql: references only, deleted with the booking or account',
    $outboxColumns[1] === ['id', 'kind', 'booking_id', 'user_id', 'ref', 'priority', 'attempts', 'next_attempt_at', 'created_at']
    && str_contains($outboxTable[1], 'FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE')
    && str_contains($outboxTable[1], 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'));
$steps = (new ReflectionClassConstant(App\Migrations::class, 'STEPS'))->getValue();
$created = implode("\n", array_filter($steps[App\Migrations::LATEST] ?? [], static fn (string $sql): bool => str_contains($sql, 'CREATE TABLE IF NOT EXISTS mail_outbox')));
$norm = static fn (string $s): string => (string) preg_replace('/\s+/', ' ', $s);
check('migration ' . App\Migrations::LATEST . ' creates the same outbox (columns, keys, cascades)', $created !== '' && !array_filter(
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

// One SMTP connection per run of the outbox (keep-alive); after an error the next mail connects again
App\Config::load(['alert_email' => 'betrieb@example.org', 'mail' => ['transport' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'user' => '', 'pass' => '']] + $cfg['mail']] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$alerts = [];
App\Alert::$transport = static function (string $to, string $subject) use (&$alerts): bool { $alerts[] = $subject; return true; };
$results = App\Mailer::batch(static fn (): array => [App\Mailer::send('a@example.org', 'S', 'B'), App\Mailer::send('b@example.org', 'S', 'B')]);
$smtp = (new ReflectionProperty(App\Mailer::class, 'smtp'))->getValue();
check('batch: SMTP unreachable → each mail fails on its own, reported once, no connection left over',
    $results === [false, false] && $alerts === ['[TalkSlot] Mailversand fehlgeschlagen'] && $smtp === null);
App\Config::load(['mail' => ['transport' => 'none'] + $cfg['mail']] + $cfg);
check('batch: returns the result of the run', App\Mailer::batch(static fn (): string => App\Mailer::send('a@example.org', 'S', 'B') ? 'sent' : 'failed') === 'sent');
@unlink(App\Alert::$stateFile);
App\Alert::$transport = null;
App\Alert::$stateFile = null;
App\Config::load($cfg);
