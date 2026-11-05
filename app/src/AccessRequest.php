<?php
declare(strict_types=1);

namespace App;

/**
 * Requests for access (Art. 15 GDPR) about one e-mail address (expert review P3-7): what TalkSlot holds about the
 * parents behind it, for the admin who answers such a request.
 *
 * Narrow on purpose: one exact address (the collation ignores case), no partial matches and no lists, and every
 * search is logged (AdminController::accessRequestSearch()). It shows bookings of all teachers in one place, which
 * admins can see round by round anyway; the log keeps that traceable.
 *
 * Covered: the bookings with that address (round, teacher, appointment, the name as booked, language, when booked,
 * who entered it) and cancellation mails still waiting in the mail queue, which keep the address for at most
 * 48 hours (Outbox). Not covered: mails that were sent (mailboxes, the mail server), backups and the web host's
 * logs; the page says so.
 */
final class AccessRequest
{
    /** @return array{bookings: list<array<string, mixed>>, cancellations: list<array<string, mixed>>} */
    public static function find(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return ['bookings' => [], 'cancellations' => []];
        }
        $bookings = Db::all(BookingService::DETAIL_SQL . ' WHERE b.email = ? ORDER BY d.day_date, s.start_time, b.id', [$email]);
        // The cancellation of a booking the teacher deleted keeps the address in its payload until it is sent
        $cancellations = [];
        foreach (Db::all("SELECT payload, created_at FROM mail_outbox WHERE kind = 'cancelled' AND payload IS NOT NULL ORDER BY id") as $row) {
            $data = json_decode((string) $row['payload'], true);
            if (is_array($data) && mb_strtolower((string) ($data['email'] ?? '')) === mb_strtolower($email)) {
                $cancellations[] = $data + ['queued_at' => (string) $row['created_at']];
            }
        }
        return ['bookings' => $bookings, 'cancellations' => $cancellations];
    }
}
