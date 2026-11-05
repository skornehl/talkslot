<?php
declare(strict_types=1);

namespace App;

/**
 * Reminder mail to parents the evening before the appointment (from config summary_evening_hour,
 * default 6 pm, like the summaries for teachers). Only for rounds with remind_parents, only for bookings
 * that may be reminded (remind_ok: parents booked while the round reminded; entries by the teacher once the
 * parents got a confirmation or change notice) and only to parents who gave an email address. Bookings whose
 * parents got a confirmation or change notice on the day of the reminder (notified_at) are skipped.
 *
 * run() only queues the reminders (Outbox); deliver() creates the mail when the outbox sends it. The
 * confirmation link cannot be repeated (only its hash is stored), so the reminder gets a token of its own
 * then (reminder_token_hash). reminded_at prevents queueing twice; it is reset when the booking is moved
 * to another slot.
 */
final class Reminders
{
    /** @return array<string,int> */
    public static function run(): array
    {
        $now = Time::now();
        if ((int) $now->format('G') < (int) Config::get('summary_evening_hour', 18)) {
            return ['reminders_queued' => 0];
        }
        $ids = Db::all(
            "SELECT b.id FROM bookings b
               JOIN slots s ON s.id = b.slot_id
               JOIN round_days d ON d.id = s.day_id
               JOIN rounds r ON r.id = d.round_id
              WHERE d.day_date = ? AND r.remind_parents = 1 AND b.remind_ok = 1 AND b.reminded_at IS NULL
                AND COALESCE(b.email, '') <> '' AND (b.notified_at IS NULL OR b.notified_at < ?)",
            [$now->modify('+1 day')->format('Y-m-d'), Time::today() . ' 00:00:00']
        );
        $queued = 0;
        foreach ($ids as $row) {
            // Claimed and queued together: a run in parallel (cron job and page request) skips it
            $queued += (int) Db::transaction(static function () use ($row): bool {
                $claimed = Db::exec('UPDATE bookings SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL', [Time::nowSql(), $row['id']]) === 1;
                if ($claimed) {
                    Outbox::queueBooking((int) $row['id'], 'reminder');
                }
                return $claimed;
            });
        }
        return ['reminders_queued' => $queued];
    }

    /**
     * Sends a queued reminder (from the outbox) with a new link. null: no longer due – the booking was moved
     * to another day, the round no longer reminds, or a confirmation or change notice has just gone out (e.g.
     * one that waited in the outbox and was sent first in the same run).
     */
    public static function deliver(array $b): ?bool
    {
        if ($b['day_date'] !== Time::now()->modify('+1 day')->format('Y-m-d') || (int) $b['remind_parents'] !== 1 || (int) $b['remind_ok'] !== 1
            || (string) $b['notified_at'] >= Time::today() . ' 00:00:00') {
            return null;
        }
        [$plain, $hash] = Token::generate();
        Db::run('UPDATE bookings SET reminder_token_hash = ? WHERE id = ?', [$hash, $b['id']]);
        return Notifier::parentReminder($b, $plain);
    }
}
