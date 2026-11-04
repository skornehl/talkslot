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
