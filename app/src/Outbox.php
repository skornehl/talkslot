<?php
declare(strict_types=1);

namespace App;

/**
 * Mail queue (outbox) for mails that do not go out within the page request: reminders, summaries for
 * teachers, and confirmations or change notices whose immediate sending failed (Notifier::sendOrQueue()).
 * The cron job sends them (bin/cron.php, web cron); on PHP-FPM or LiteSpeed a page request also sends a few
 * after its response has gone out (afterResponse()), for installations whose cron job runs rarely.
 *
 * Data minimisation: a row holds references only (booking or account, kind, for summaries the day),
 * never an address, a text or a link. Text and address come from the booking or account when sending, and
 * a mail with a link gets a new token then (as the reminder does), so the outbox holds no secrets. Rows are
 * deleted once sent, together with their booking or account (foreign keys), and after at most MAX_AGE_HOURS
 * if sending keeps failing; such a booking is marked "mail not delivered" (bookings.mail_failed_at).
 *
 * Order: priority (single mails to parents before summaries before reminders), then due time. A failed
 * attempt is retried after a growing pause (BACKOFF). Each run is limited in mails and seconds; summaries
 * and reminders also respect an optional hourly limit (mail_queue.hourly_limit, e.g. the host's quota),
 * single mails do not, but they count against it.
 */
final class Outbox
{
    /** Kind of mail => priority (lower goes first). */
    public const KINDS = ['booked' => 1, 'changed' => 1, 'summary_deadline' => 2, 'summary_evening' => 2, 'reminder' => 3];
    /** Mails not sent after this long are given up (the booking shows "mail not delivered"). */
    public const MAX_AGE_HOURS = 48;
    /** Mails waiting longer than this are shown to admins (cron job missing or mail server failing). */
    public const LATE_HOURS = 2;
    /** Pause in minutes before the next attempt after the 1st, 2nd, … failed one (then the last value). */
    public const BACKOFF = [5, 15, 30, 60, 120, 240];
    /** After this many failures in a row a run stops: the mail server is probably down. */
    private const MAX_FAILURES_IN_A_ROW = 3;
    /** Fallback after the response of a page request: at most this many mails and seconds. */
    private const REQUEST_MAILS = 10;
    private const REQUEST_SECONDS = 10;

    /**
     * Queues a mail about a booking (once per booking and kind). $failedOnce: sending it right away has
     * just failed, so the first retry waits. A change notice is not queued while the confirmation still
     * waits: that one is created when sending and already has the current details.
     */
    public static function queueBooking(int $bookingId, string $kind, bool $failedOnce = false): void
    {
        if ($kind === 'changed' && Db::value("SELECT id FROM mail_outbox WHERE booking_id = ? AND kind = 'booked'", [$bookingId]) !== null) {
            return;
        }
        Db::run(
            'INSERT IGNORE INTO mail_outbox (kind, booking_id, priority, attempts, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$kind, $bookingId, self::KINDS[$kind], $failedOnce ? 1 : 0, self::retryAt($failedOnce ? 1 : 0), Time::nowSql()]
        );
    }

    /** Queues a mail to an account (summaries; $ref: the day it is about). */
    public static function queueAccount(int $userId, string $kind, string $ref): void
    {
        Db::run('INSERT IGNORE INTO mail_outbox (kind, user_id, ref, priority, next_attempt_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$kind, $userId, $ref, self::KINDS[$kind], Time::nowSql(), Time::nowSql()]);
    }

    /** When the next attempt is due after $failures failed ones. */
    public static function retryAt(int $failures): string
    {
        $minutes = $failures > 0 ? self::BACKOFF[min($failures, count(self::BACKOFF)) - 1] : 0;
        return Time::now()->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
    }

    /**
     * Sends due mails: at most $limit, within about $seconds (a started mail is finished). Only one run at a
     * time (cron job and page requests); a second one returns at once.
     * @return array{mails_sent:int,mails_failed:int,mails_dropped:int} failed = will be retried,
     *         dropped = no longer needed (booking gone or moved, day over, summary turned off)
     */
    public static function process(?int $limit = null, ?float $seconds = null): array
    {
        $stats = ['mails_sent' => 0, 'mails_failed' => 0, 'mails_dropped' => 0];
        $limit ??= max(1, (int) Config::get('mail_queue.batch', 100));
        $seconds ??= max(1.0, (float) Config::get('mail_queue.seconds', 20));
        if ((int) Db::value('SELECT GET_LOCK(?, 0)', [Db::table('outbox')]) !== 1) {
            return $stats;
        }
        try {
            $end = microtime(true) + $seconds;
            // Older ones are given up by the clean-up (expire())
            $rows = Db::all(
                'SELECT * FROM mail_outbox WHERE next_attempt_at <= ? AND created_at >= ?
                  ORDER BY priority, next_attempt_at, id LIMIT 1000',
                [Time::nowSql(), self::maxAge()]
            );
            [$hour, $count] = self::hourCount();
            $hourly = max(0, (int) Config::get('mail_queue.hourly_limit', 0));
            Mailer::batch(static function () use ($rows, $limit, $end, $hourly, &$count, &$stats): void {
                $failures = 0;
                foreach ($rows as $row) {
                    if ($stats['mails_sent'] + $stats['mails_failed'] >= $limit || microtime(true) >= $end || $failures >= self::MAX_FAILURES_IN_A_ROW) {
                        break;
                    }
                    if ($hourly > 0 && $count >= $hourly && (int) $row['priority'] > 1) {
                        continue; // reminders and summaries wait for the next hour; single mails still go out
                    }
                    try {
                        $sent = self::deliver($row);
                    } catch (\Throwable $e) {
                        Alert::exception($e, 'Mailversand aus der Warteschlange'); // retried like a failed mail, does not block the others
                        $sent = false;
                    }
                    if ($sent === false) {
                        $failures++;
                        $stats['mails_failed']++;
                        Db::run('UPDATE mail_outbox SET attempts = attempts + 1, next_attempt_at = ? WHERE id = ?', [self::retryAt((int) $row['attempts'] + 1), $row['id']]);
                        continue;
                    }
                    Db::run('DELETE FROM mail_outbox WHERE id = ?', [$row['id']]);
                    $failures = 0;
                    $stats[$sent ? 'mails_sent' : 'mails_dropped']++;
                    $count += $sent ? 1 : 0;
                }
            });
            Settings::set('mail_hour', $hour . '|' . $count);
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [Db::table('outbox')]);
        }
        return $stats;
    }

    /**
     * Fallback for installations whose cron job runs rarely or not at all: called at the end of a page
     * request (shutdown). If mails are due, $finish ends the response first (PHP-FPM, LiteSpeed), then a few
     * mails are sent while the visitor no longer waits. Without such a server API ($finish returns false)
     * nothing is sent here, as the visitor would wait for the mail server; the cron job sends them.
     * @param callable(): bool $finish
     * @return int mails sent
     */
    public static function afterResponse(callable $finish): int
    {
        if (!Config::get('mail_queue.after_response', true) || !self::due()) {
            return 0;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // the admin's next page does not wait for the mail server
        }
        if (!$finish()) {
            return 0;
        }
        ignore_user_abort(true);
        set_time_limit(self::REQUEST_SECONDS + 30);
        return self::process(self::REQUEST_MAILS, self::REQUEST_SECONDS)['mails_sent'];
    }

    /** Is a mail due? (one indexed query per page request) */
    public static function due(): bool
    {
        return Db::value('SELECT 1 FROM mail_outbox WHERE next_attempt_at <= ? LIMIT 1', [Time::nowSql()]) !== null;
    }

    /**
     * Gives up mails that could not be sent within MAX_AGE_HOURS (step of the clean-up). Their bookings are
     * marked "mail not delivered", also when no attempt was made (no cron job).
     */
    public static function expire(): int
    {
        Db::run(
            'UPDATE bookings b JOIN mail_outbox o ON o.booking_id = b.id SET b.mail_failed_at = COALESCE(b.mail_failed_at, ?) WHERE o.created_at < ?',
            [Time::nowSql(), self::maxAge()]
        );
        return Db::exec('DELETE FROM mail_outbox WHERE created_at < ?', [self::maxAge()]);
    }

    /**
     * Mails waiting longer than LATE_HOURS (for the admins' overview): the cron job does not run or the
     * mail server keeps refusing. null = none.
     * @return ?array{count:int,since:string}
     */
    public static function late(): ?array
    {
        $row = Db::one(
            'SELECT COUNT(*) AS n, MIN(created_at) AS since FROM mail_outbox WHERE created_at < ?',
            [Time::now()->modify('-' . self::LATE_HOURS . ' hours')->format('Y-m-d H:i:s')]
        );
        return (int) ($row['n'] ?? 0) > 0 ? ['count' => (int) $row['n'], 'since' => (string) $row['since']] : null;
    }

    /**
     * Creates and sends the mail of a row. true = sent, false = failed (retry), null = no longer needed:
     * the booking has no address any more or its day is over (the booking itself is deleted then).
     */
    private static function deliver(array $row): ?bool
    {
        if ($row['booking_id'] === null) {
            return Summaries::deliver((string) $row['kind'], (int) $row['user_id'], (string) $row['ref']);
        }
        $b = BookingService::findById((int) $row['booking_id']);
        if (!$b || (string) $b['email'] === '' || $b['day_date'] < Time::today()) {
            return null;
        }
        return match ((string) $row['kind']) {
            // The link of the page after booking is only stored as a hash: the mail gets a new one
            'booked' => Notifier::parentBooked($b, BookingService::newMailToken((int) $b['id'])),
            'changed' => Notifier::parentChanged($b, null),
            'reminder' => Reminders::deliver($b),
            default => null,
        };
    }

    /** [current hour, mails sent from the outbox in it] (setting mail_hour, for the hourly limit). */
    private static function hourCount(): array
    {
        $hour = Time::now()->format('Y-m-d H');
        [$stored, $count] = explode('|', (string) (Settings::get('mail_hour') ?? '') . '|0');
        return [$hour, $stored === $hour ? (int) $count : 0];
    }

    private static function maxAge(): string
    {
        return Time::now()->modify('-' . self::MAX_AGE_HOURS . ' hours')->format('Y-m-d H:i:s');
    }
}
