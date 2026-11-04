<?php
declare(strict_types=1);

namespace App;

/**
 * Reminder mail to parents the evening before the appointment (from config summary_evening_hour,
 * default 6 pm, like the summaries for teachers). Only for rounds with remind_parents and only to
 * parents who gave an email address. Bookings made or moved on the day of the reminder are skipped:
 * the confirmation has just been sent.
 *
 * The confirmation link cannot be repeated (only its hash is stored), so the reminder gets a second
 * token of its own (reminder_token_hash). reminded_at prevents duplicate sends; it is set before
 * sending and reset when the booking is moved to another slot.
 */
final class Reminders
{
    /** @return array<string,int> */
    public static function run(): array
    {
        $now = Time::now();
        if ((int) $now->format('G') < (int) Config::get('summary_evening_hour', 18)) {
            return ['reminders_sent' => 0];
        }
        $ids = Db::all(
            "SELECT b.id FROM bookings b
               JOIN slots s ON s.id = b.slot_id
               JOIN round_days d ON d.id = s.day_id
               JOIN rounds r ON r.id = d.round_id
              WHERE d.day_date = ? AND r.remind_parents = 1 AND b.reminded_at IS NULL
                AND COALESCE(b.email, '') <> '' AND b.updated_at < ?",
            [$now->modify('+1 day')->format('Y-m-d'), Time::today() . ' 00:00:00']
        );
        $sent = 0;
        foreach ($ids as $row) {
            [$plain, $hash] = Token::generate();
            $claimed = Db::exec(
                'UPDATE bookings SET reminded_at = ?, reminder_token_hash = ? WHERE id = ? AND reminded_at IS NULL',
                [Time::nowSql(), $hash, $row['id']]
            );
            $booking = $claimed === 1 ? BookingService::findById((int) $row['id']) : null;
            if ($booking) {
                $sent += (int) Notifier::parentReminder($booking, $plain);
            }
        }
        return ['reminders_sent' => $sent];
    }
}
