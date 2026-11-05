<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Booking, rebooking and cancelling.
 *
 * Two-stage protection against double bookings:
 * 1. Transaction with SELECT … FOR UPDATE on the slot (serializes parallel bookings)
 * 2. UNIQUE(slot_id) in bookings as a hard guarantee; error 1062 → "already taken"
 *
 * Multiple bookings (round setting, booking_limit): "round" = one appointment per person in
 * the round, "day" = one per person and day, "none" = notice only. The same person means
 * the same name and, if both bookings have one, the same email address (samePerson()).
 * Applies to parents; entries made by the teacher are not limited. With an active limit the round is
 * locked (FOR UPDATE) so that two simultaneous bookings do not both get through.
 */
final class BookingService
{
    public const TAKEN = 'taken';
    public const UNAVAILABLE = 'unavailable';
    public const DEADLINE = 'deadline';
    public const LIMIT_ROUND = 'limit_round';
    public const LIMIT_DAY = 'limit_day';
    /** Values of rounds.booking_limit */
    public const LIMITS = ['none', 'round', 'day'];

    /** SQL skeleton: booking with slot, day and round (also used by ScheduleService). */
    public const DETAIL_SQL = 'SELECT b.*, s.start_time, s.end_time, s.is_conflict, d.day_date, d.id AS day_id,
                                       r.id AS round_id, r.title, r.description, r.source_lang,
                                       COALESCE(NULLIF(TRIM(d.location), \'\'), r.location) AS location, r.slug, r.is_active, r.cancel_deadline_hours,
                                       r.booking_deadline_hours, r.user_id, r.remind_parents,
                                       u.display_name AS teacher_name, u.email AS teacher_email, u.is_active AS teacher_active
                                  FROM bookings b
                                  JOIN slots s ON s.id = b.slot_id
                                  JOIN round_days d ON d.id = s.day_id
                                  JOIN rounds r ON r.id = d.round_id
                                  JOIN users u ON u.id = r.user_id';

    /**
     * Key of the parents' notice on multiple bookings (app/lang/*.php): the same sentence on the round page
     * and in the invitation text, so that both name the rule that the round really applies.
     */
    public static function limitNotice(array $round): string
    {
        return ['round' => 'round.limit_round', 'day' => 'round.limit_day'][$round['booking_limit'] ?? 'none'] ?? 'round.one_only';
    }

    public static function slotStart(array $row): DateTimeImmutable
    {
        return new DateTimeImmutable($row['day_date'] . ' ' . $row['start_time']);
    }

    /** Free, publicly bookable slots of a round (excluding $exceptSlotId). */
    public static function freeSlots(array $round, ?int $exceptSlotId = null): array
    {
        $rows = Db::all(
            'SELECT s.id, s.start_time, s.end_time, d.day_date, d.id AS day_id, d.location
               FROM slots s
               JOIN round_days d ON d.id = s.day_id
               LEFT JOIN bookings b ON b.slot_id = s.id
              WHERE d.round_id = ? AND s.is_blocked = 0 AND s.is_conflict = 0
                AND b.id IS NULL AND d.day_date >= ? AND s.id <> ?
              ORDER BY d.day_date, s.start_time',
            [$round['id'], Time::today(), $exceptSlotId ?? 0]
        );
        $limit = self::deadline((int) $round['booking_deadline_hours']);
        return array_values(array_filter($rows, static fn (array $r) => self::slotStart($r) > $limit));
    }

    /** Groups slots by date: [['date' => Y-m-d, 'slots' => [...]], ...] */
    public static function groupByDay(array $slots): array
    {
        $days = [];
        foreach ($slots as $s) {
            $days[$s['day_date']]['date'] = $s['day_date'];
            $days[$s['day_date']]['location'] = (string) ($s['location'] ?? '');
            $days[$s['day_date']]['slots'][] = $s;
        }
        return array_values($days);
    }

    /**
     * Booking by parents.
     * @return array{0:int,1:string} [booking_id, token_plaintext]
     * @throws BookingException
     */
    public static function book(array $round, int $slotId, string $name, ?string $email, string $lang): array
    {
        return self::create($round, $slotId, $name, $email, $lang, 'parent');
    }

    /** Entry by the teacher: ignores blocks and the booking deadline. */
    public static function adminBook(array $round, int $slotId, string $name, ?string $email, string $lang): array
    {
        return self::create($round, $slotId, $name, $email, $lang, 'admin');
    }

    private static function create(array $round, int $slotId, string $name, ?string $email, string $lang, string $source): array
    {
        [$plain, $hash] = Token::generate();
        // Parents may get the reminder if the round said so when they booked (the form tells them); entries
        // by the teacher only once the parents got a confirmation or change notice (see mailResult())
        $remindOk = $source === 'parent' && !empty($round['remind_parents']) ? 1 : 0;
        try {
            $id = self::retryOnDeadlock(static fn (): int => Db::transaction(static function () use ($round, $slotId, $name, $email, $lang, $source, $hash, $remindOk): int {
                $limited = $source !== 'admin' && self::lockForLimit($round);
                $slot = self::lockSlot($slotId, (int) $round['id']);
                self::assertFree($slot, $round, $source === 'admin');
                if ($limited) {
                    self::assertWithinLimit($round, (string) $slot['day_date'], $name, $email);
                }
                $now = Time::nowSql();
                return Db::insert(
                    'INSERT INTO bookings (slot_id, name, email, token_hash, lang, source, remind_ok, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$slotId, $name, $email, $hash, $lang, $source, $remindOk, $now, $now]
                );
            }));
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw new BookingException(self::TAKEN);
            }
            throw $e;
        }
        return [$id, $plain];
    }

    /**
     * Moves a booking to another slot of the same round.
     * @throws BookingException
     */
    public static function move(array $booking, int $newSlotId, bool $byAdmin): void
    {
        $round = Db::one('SELECT * FROM rounds WHERE id = ?', [$booking['round_id']]);
        // Parents: only as long as the teacher's account is active and the admin's details are present
        // (as for the public round page); cancelling always remains possible
        if (!$round || (!$byAdmin && (!Db::value('SELECT is_active FROM users WHERE id = ?', [$round['user_id']]) || !Operator::ready()))) {
            throw new BookingException(self::UNAVAILABLE);
        }
        try {
            self::retryOnDeadlock(static fn () => Db::transaction(static function () use ($booking, $newSlotId, $byAdmin, $round): void {
                // Rebooking does not change the number of appointments; only with "per day" does another day count
                $limited = !$byAdmin && ($round['booking_limit'] ?? 'none') === 'day' && self::lockForLimit($round);
                $slot = self::lockSlot($newSlotId, (int) $round['id']);
                self::assertFree($slot, $round, $byAdmin);
                if ($limited && (string) $slot['day_date'] !== (string) $booking['day_date']) {
                    self::assertWithinLimit($round, (string) $slot['day_date'], (string) $booking['name'], $booking['email'], (int) $booking['id']);
                }
                // New date: remind again (unless the move happens on the day of the reminder, see Reminders)
                Db::run('UPDATE bookings SET slot_id = ?, reminded_at = NULL, updated_at = ? WHERE id = ?', [$newSlotId, Time::nowSql(), $booking['id']]);
            }));
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw new BookingException(self::TAKEN);
            }
            throw $e;
        }
        self::removeOrphanedConflict((int) $booking['slot_id']);
    }

    /** false: it was deleted already (e.g. at the same time by the parents and the teacher). */
    public static function delete(array $booking): bool
    {
        $deleted = Db::exec('DELETE FROM bookings WHERE id = ?', [$booking['id']]) > 0;
        self::removeOrphanedConflict((int) $booking['slot_id']);
        return $deleted;
    }

    /**
     * The teacher edits name, address and language of a booking. A new address counts as unknown, as for a new
     * entry by the teacher (source "admin"): it gets change notices, cancellations and reminders only once a
     * confirmation or change notice from the entry reached it (mailResult()), which counts against the limit
     * of such mails per account. Otherwise an account could replace the addresses of its bookings and send
     * the bulk mails after a change of a day or round (ScheduleService) to addresses of its choice. Mails
     * still waiting for the old address are dropped. true = new address.
     */
    public static function update(int $bookingId, string $name, ?string $email, string $lang): bool
    {
        $old = (string) Db::value('SELECT email FROM bookings WHERE id = ?', [$bookingId]);
        $newAddress = (string) $email !== '' && mb_strtolower(trim((string) $email)) !== mb_strtolower(trim($old));
        Db::run(
            'UPDATE bookings SET name = ?, email = ?, lang = ?, updated_at = ? WHERE id = ?',
            [$name, $email, $lang, Time::nowSql(), $bookingId]
        );
        if ($newAddress) {
            Db::run("UPDATE bookings SET source = 'admin', notified_at = NULL, remind_ok = 0, mail_failed_at = NULL WHERE id = ?", [$bookingId]);
            Db::run('DELETE FROM mail_outbox WHERE booking_id = ?', [$bookingId]);
        }
        return $newAddress;
    }

    public static function findByToken(#[\SensitiveParameter] string $plain): ?array
    {
        if (!Token::isWellFormed($plain)) {
            return null;
        }
        $hash = Token::hash($plain);
        // The links in the reminder and in a confirmation sent later from the outbox have their own tokens
        // (the original one is only stored as a hash)
        return Db::one(self::DETAIL_SQL . ' WHERE b.token_hash = ?', [$hash])
            ?? Db::one(self::DETAIL_SQL . ' WHERE b.reminder_token_hash = ?', [$hash])
            ?? Db::one(self::DETAIL_SQL . ' WHERE b.mail_token_hash = ?', [$hash]);
    }

    /**
     * New link for a confirmation sent later from the outbox (only its hash is stored, like the reminder's).
     * The link of the page after booking stays valid; a later attempt replaces this one.
     */
    public static function newMailToken(int $bookingId): string
    {
        [$plain, $hash] = Token::generate();
        Db::run('UPDATE bookings SET mail_token_hash = ? WHERE id = ?', [$hash, $bookingId]);
        return $plain;
    }

    /**
     * Records a mail to the booking's address ($type: booked, changed, reminder). Sent: a confirmation or
     * change notice sets notified_at (no reminder on that day) and makes a waiting change notice obsolete;
     * an entry by the teacher may be reminded from now on if the round reminds, as the parents now know
     * about the booking and the privacy notice. Not sent: mail_failed_at, shown in the booking list until a
     * mail gets through.
     */
    public static function mailResult(array $b, string $type, bool $sent): void
    {
        if (!$sent) {
            Db::run('UPDATE bookings SET mail_failed_at = ? WHERE id = ?', [Time::nowSql(), $b['id']]);
            return;
        }
        if ($type === 'reminder') {
            Db::run('UPDATE bookings SET mail_failed_at = NULL WHERE id = ?', [$b['id']]);
            return;
        }
        $remindOk = (int) $b['remind_ok'] === 1 || ($b['source'] === 'admin' && (int) $b['remind_parents'] === 1) ? 1 : 0;
        Db::run('UPDATE bookings SET notified_at = ?, mail_failed_at = NULL, remind_ok = ? WHERE id = ?', [Time::nowSql(), $remindOk, $b['id']]);
        Db::run("DELETE FROM mail_outbox WHERE booking_id = ? AND kind = 'changed'", [$b['id']]);
    }

    public static function findById(int $id): ?array
    {
        return Db::one(self::DETAIL_SQL . ' WHERE b.id = ?', [$id]);
    }

    /**
     * May parents still cancel this booking? Independent of the round's active status,
     * so that a deactivated (e.g. full) round does not prevent cancellations.
     * Rebooking is additionally only possible for an active round (see assertFree).
     */
    public static function canChange(array $booking): bool
    {
        return Time::now() < self::changeDeadline($booking);
    }

    /** Until when parents can change or cancel a booking online: its start minus the round's deadline for changes. */
    public static function changeDeadline(array $booking): DateTimeImmutable
    {
        return self::slotStart($booking)->modify('-' . (int) $booking['cancel_deadline_hours'] . ' hours');
    }

    /**
     * The deadline for changes in the parents' language, for the appointment page and the mails about the
     * appointment: until when (date and time) while it is still open, otherwise that it has passed.
     */
    public static function changeNotice(array $booking): string
    {
        return self::canChange($booking)
            ? t('manage.deadline', ['when' => fmt_datetime(self::changeDeadline($booking))])
            : t('manage.locked', ['hours' => fmt_hours((int) $booking['cancel_deadline_hours'])]);
    }

    private static function deadline(int $hours): DateTimeImmutable
    {
        return Time::now()->modify('+' . $hours . ' hours');
    }

    /**
     * The same person? Same name (case and whitespace irrelevant) and, if both
     * have an email address, the same one. If one lacks it, only the name counts (otherwise
     * the limit could be bypassed by omitting the address). Siblings with their own name are different.
     */
    public static function samePerson(string $name1, ?string $email1, string $name2, ?string $email2): bool
    {
        $norm = static fn (string $s): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($s)), 'UTF-8');
        if ($norm($name1) !== $norm($name2)) {
            return false;
        }
        $e1 = $norm((string) $email1);
        $e2 = $norm((string) $email2);
        return $e1 === '' || $e2 === '' || $e1 === $e2;
    }

    /**
     * The teacher edits a day at the same time (slots are recalculated): InnoDB may then
     * abort one of the transactions as a deadlock or after a lock wait. Retry once, then
     * treat it like "just taken" instead of a server error.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function retryOnDeadlock(callable $fn): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                if (!in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $e;
                }
                if ($attempt >= 2) {
                    throw new BookingException(self::TAKEN);
                }
                usleep(50000);
            }
        }
    }

    /** Locks the round for the check if it limits multiple bookings. true = limited. */
    private static function lockForLimit(array $round): bool
    {
        if (!in_array($round['booking_limit'] ?? 'none', ['round', 'day'], true)) {
            return false;
        }
        Db::one('SELECT id FROM rounds WHERE id = ? FOR UPDATE', [$round['id']]);
        return true;
    }

    /** @throws BookingException if the same person already has an appointment in the round or on that day */
    private static function assertWithinLimit(array $round, string $dayDate, string $name, ?string $email, int $exceptId = 0): void
    {
        $perDay = ($round['booking_limit'] ?? 'none') === 'day';
        $rows = Db::all(
            'SELECT b.id, b.name, b.email FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id
              WHERE d.round_id = ? AND b.id <> ?' . ($perDay ? ' AND d.day_date = ?' : ''),
            $perDay ? [$round['id'], $exceptId, $dayDate] : [$round['id'], $exceptId]
        );
        foreach ($rows as $r) {
            if (self::samePerson($name, $email, (string) $r['name'], $r['email'])) {
                throw new BookingException($perDay ? self::LIMIT_DAY : self::LIMIT_ROUND);
            }
        }
    }

    /**
     * Locks the slot and, through the join, its day row. SlotGenerator::sync holds that day row while it
     * rebuilds the slots of the day, so a booking and a sync of the same day do not interleave (where
     * they lock in opposite order, InnoDB ends one as a deadlock; bookings retry, see retryOnDeadlock).
     * Keep the join when changing this; sync's DELETE also checks for a booking itself.
     */
    private static function lockSlot(int $slotId, int $roundId): array
    {
        $slot = Db::one(
            'SELECT s.*, d.day_date FROM slots s JOIN round_days d ON d.id = s.day_id
              WHERE s.id = ? AND d.round_id = ? FOR UPDATE',
            [$slotId, $roundId]
        );
        if (!$slot) {
            throw new BookingException(self::UNAVAILABLE);
        }
        return $slot;
    }

    private static function assertFree(array $slot, array $round, bool $byAdmin): void
    {
        if (!$byAdmin) {
            if (!$round['is_active'] || $slot['is_blocked'] || $slot['is_conflict']) {
                throw new BookingException(self::UNAVAILABLE);
            }
            if (self::slotStart($slot) <= self::deadline((int) $round['booking_deadline_hours'])) {
                throw new BookingException(self::DEADLINE);
            }
        }
        if (Db::value('SELECT id FROM bookings WHERE slot_id = ? FOR UPDATE', [$slot['id']]) !== null) {
            throw new BookingException(self::TAKEN);
        }
    }

    /** A conflict slot without a booking no longer serves a purpose. */
    private static function removeOrphanedConflict(int $slotId): void
    {
        Db::run(
            'DELETE s FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
              WHERE s.id = ? AND s.is_conflict = 1 AND b.id IS NULL',
            [$slotId]
        );
    }
}
