<?php
declare(strict_types=1);

namespace App;

/**
 * Changes by the teacher that concern booked parents:
 * - deleteBooking(), deleteBookings(), deleteDay(), cancelRound(), deleteAccount(): the bookings are deleted, their
 *   parents get a cancellation (Notifier::teacherCancelled(), without a link: the booking is gone);
 * - changeDay() (date, times, location; the slots are recalculated), resyncDay() (after a break was added or
 *   removed) and changeRound() (location, teacher): parents whose appointment changed get the current details
 *   (Notifier::parentChanged()). A booking that no longer fits the schedule becomes a conflict; its parents are
 *   told when the teacher moves or deletes it, or when a later change makes it fit again and its details
 *   changed since their last mail. Each change of the details counts as a change of the booking (updated_at),
 *   so that the calendar file of the next mail updates the appointment (IcsExport::forParent(), SEQUENCE).
 *
 * Each method finds the bookings concerned before it deletes or changes anything, in the same transaction, and
 * with $notify queues the mails in the outbox in the language of each booking. Several mails at once are bulk
 * mails (they respect the hourly limit, see Outbox); the cancellation of a single booking is sent right away.
 * The foreign keys still delete bookings with their day, round or account (ON DELETE CASCADE); these methods
 * make sure that nobody is left out.
 *
 * Mails go to parents who know the booking from this system: they booked themselves, or the teacher's entry got
 * a confirmation or change notice (mailable(), the rule of the reminder; an address the teacher changes counts
 * as unknown until a mail from the entry reached it, see BookingService::update()). Everybody else, and
 * everybody when the teacher unticks the box, is listed in the result so the teacher can tell them in person.
 * Appointments that are over concern nobody.
 *
 * Change notices count against a limit per account and hour (rate limit "teacher_bulk", BULK_LIMIT): the
 * teacher chooses the details of the mail and may repeat a change at will, so without it an account could
 * send any number of mails, beyond the limit of the mails from single entries ("teacher_mail"). Parents
 * above the limit are listed as well. Cancellations need no such limit: a booking is cancelled only once.
 */
final class ScheduleService
{
    /** Change notices per account and hour by default (rate_limits.teacher_bulk). */
    public const BULK_LIMIT = 200;
    /**
     * Cancellations of appointments within this many hours are queued like single mails, not held back by the
     * hourly limit (a lost one leaves no trace: there is no booking left to mark "mail not delivered").
     */
    public const SOON_HOURS = 24;
    /** Bookings of a day, a round or an account: condition on the joins of BookingService::DETAIL_SQL. */
    private const SCOPES = ['day' => 'd.id = ?', 'round' => 'd.round_id = ?', 'account' => 'r.user_id = ?'];
    /**
     * Locking reads per scope (see concerned()): the days, then the bookings with their slots, the order in which
     * a deletion or SlotGenerator::sync() locks them. A round is locked first, as a booking with a limit of the
     * round does (BookingService::lockForLimit(), then the slot with its day): both lock in the same order, and a
     * change or deletion of the round does not end in a deadlock with such a booking. A day leaves its round
     * unlocked (it does not change it); an account locks its rounds with its days.
     */
    private const LOCKS = [
        'day' => [
            'SELECT id FROM round_days WHERE id = ? FOR UPDATE',
            'SELECT b.id FROM bookings b JOIN slots s ON s.id = b.slot_id WHERE s.day_id = ? FOR UPDATE',
        ],
        'round' => [
            'SELECT id FROM rounds WHERE id = ? FOR UPDATE',
            'SELECT id FROM round_days WHERE round_id = ? FOR UPDATE',
            'SELECT b.id FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id WHERE d.round_id = ? FOR UPDATE',
        ],
        'account' => [
            'SELECT d.id FROM round_days d JOIN rounds r ON r.id = d.round_id WHERE r.user_id = ? FOR UPDATE',
            'SELECT b.id FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id JOIN rounds r ON r.id = d.round_id WHERE r.user_id = ? FOR UPDATE',
        ],
    ];
    /** What a change notice tells: a change of one of these is mailed (not, e.g., a new title). */
    private const DETAILS = ['day_date', 'start_time', 'end_time', 'location', 'user_id'];

    /** May the parents be mailed about a change to this booking (BookingService::DETAIL_SQL row)? */
    public static function mailable(array $b): bool
    {
        return (string) ($b['email'] ?? '') !== '' && (($b['source'] ?? '') === 'parent' || ($b['notified_at'] ?? null) !== null);
    }

    /** Is the appointment still ahead? (Nobody needs to hear about one that is over.) */
    public static function upcoming(array $b): bool
    {
        return $b['day_date'] . ' ' . $b['start_time'] > Time::nowSql();
    }

    /**
     * Deletes a single booking. With $notify the parents get the cancellation right away, or from the outbox
     * if the mail server refuses it now. null: no mail (box unticked, not mailable(), or the appointment is over).
     */
    public static function deleteBooking(array $booking, bool $notify): ?string
    {
        $data = $notify && self::mailable($booking) && self::upcoming($booking) ? Notifier::cancellationData($booking) : null;
        // Deleted meanwhile (by the parents or in another tab): they got their mail then, not a second one now
        if (!BookingService::delete($booking) || $data === null) {
            return null;
        }
        if (Notifier::teacherCancelled($data)) {
            return Notifier::SENT;
        }
        Outbox::queueCancellation($data, true);
        return Notifier::QUEUED;
    }

    /**
     * Deletes the bookings $ids of a day at once (the teacher's bulk deletion, e.g. after slots were taken with
     * made-up names, see Abuse); ids of other days are ignored. As for deleteDay(), with $notify the parents of
     * the upcoming ones get a cancellation (bulk mails) and the others are listed.
     * @param list<int|string> $ids
     * @return array{0:int,1:ScheduleChange} bookings deleted, what it meant for the parents
     */
    public static function deleteBookings(int $dayId, array $ids, bool $notify): array
    {
        $wanted = array_flip(array_map('intval', $ids));
        return Db::retrying(static fn (): array => Db::transaction(static function () use ($dayId, $wanted, $notify): array {
            foreach (self::LOCKS['day'] as $sql) {
                Db::all($sql, [$dayId]);
            }
            $bookings = array_values(array_filter(
                Db::all(BookingService::DETAIL_SQL . ' WHERE d.id = ? ORDER BY s.start_time, b.id', [$dayId]),
                static fn (array $b): bool => isset($wanted[(int) $b['id']])
            ));
            $change = self::tell(array_values(array_filter($bookings, [self::class, 'upcoming'])), $notify,
                static fn (array $b): bool => self::queueCancellation($b, false));
            $deleted = 0;
            foreach ($bookings as $b) {
                $deleted += (int) BookingService::delete($b);
            }
            return [$deleted, $change];
        }));
    }

    public static function deleteDay(int $dayId, bool $notify): ScheduleChange
    {
        return self::delete('day', $dayId, $notify, static fn () => Db::run('DELETE FROM round_days WHERE id = ?', [$dayId]));
    }

    /** Deletes a round with its days and bookings. */
    public static function cancelRound(int $roundId, bool $notify): ScheduleChange
    {
        return self::delete('round', $roundId, $notify, static fn () => Db::run('DELETE FROM rounds WHERE id = ?', [$roundId]));
    }

    /** Deletes an account with its rounds. null: refused, it is the last active admin (AccountSecurity). */
    public static function deleteAccount(int $userId, bool $notify): ?ScheduleChange
    {
        $change = null;
        $deleted = AccountSecurity::keepingAnAdmin($userId, false, static function () use ($userId, $notify, &$change): void {
            $change = self::delete('account', $userId, $notify, static fn () => Db::run('DELETE FROM users WHERE id = ?', [$userId]));
        });
        return $deleted ? $change : null;
    }

    /**
     * New date, times, slot length, breaks or location of a day ($in: date, start, end, minutes, location as from
     * the day form); the slots are recalculated.
     * @param ?list<array{0: string, 1: string, 2: string}> $breaks the day's breaks as [start, end, label] (null = unchanged)
     */
    public static function changeDay(int $dayId, array $in, bool $notify, ?array $breaks = null): ScheduleChange
    {
        return self::change('day', $dayId, $notify, static function () use ($dayId, $in, $breaks): int {
            Db::run(
                'UPDATE round_days SET day_date = ?, start_time = ?, end_time = ?, slot_minutes = ?, location = ? WHERE id = ?',
                [$in['date'], $in['start'], $in['end'], $in['minutes'], $in['location'] !== '' ? $in['location'] : null, $dayId]
            );
            if ($breaks !== null) {
                Db::run('DELETE FROM day_breaks WHERE day_id = ?', [$dayId]);
                foreach ($breaks as [$start, $end, $label]) {
                    Db::run('INSERT INTO day_breaks (day_id, start_time, end_time, label) VALUES (?, ?, ?, ?)', [$dayId, $start, $end, $label !== '' ? $label : null]);
                }
            }
            return SlotGenerator::sync($dayId);
        });
    }

    /** Recalculates the slots of a day after $write (a break added or removed), in one transaction. */
    public static function resyncDay(int $dayId, bool $notify, ?\Closure $write = null): ScheduleChange
    {
        return self::change('day', $dayId, $notify, static function () use ($dayId, $write): int {
            if ($write !== null) {
                $write();
            }
            return SlotGenerator::sync($dayId);
        });
    }

    /** Runs $update on the round (e.g. a new location or teacher) and tells the parents whose appointment changed. */
    public static function changeRound(int $roundId, bool $notify, \Closure $update): ScheduleChange
    {
        return self::change('round', $roundId, $notify, $update);
    }

    /**
     * Upcoming bookings of a day, a round or an account (BookingService::DETAIL_SQL rows), e.g. for the
     * numbers in the forms. In a transaction, $lock first locks the days and bookings with a locking read:
     * nobody books into, moves out of or cancels them until it ends, and the read after it (which starts the
     * snapshot of the transaction) sees the latest state.
     */
    public static function concerned(string $scope, int $id, bool $lock = false): array
    {
        foreach ($lock ? self::LOCKS[$scope] : [] as $sql) {
            Db::all($sql, [$id]);
        }
        $where = self::SCOPES[$scope];
        return Db::all(
            BookingService::DETAIL_SQL . " WHERE $where AND TIMESTAMP(d.day_date, s.start_time) > ? ORDER BY d.day_date, s.start_time, b.id",
            [$id, Time::nowSql()]
        );
    }

    /**
     * Queues the cancellations of the upcoming bookings in $scope, then runs $delete (same transaction). The
     * address of a deleted account is not kept for the replies (Reply-To): nobody reads it any more.
     * Cancellations of appointments soon (SOON_HOURS) are not bulk mails.
     */
    private static function delete(string $scope, int $id, bool $notify, \Closure $delete): ScheduleChange
    {
        return Db::retrying(static fn (): ScheduleChange => Db::transaction(static function () use ($scope, $id, $notify, $delete): ScheduleChange {
            // The rows have no foreign key to the booking: they outlive the deletion
            $change = self::tell(self::concerned($scope, $id, true), $notify,
                static fn (array $b): bool => self::queueCancellation($b, $scope === 'account'));
            $delete();
            return $change;
        }));
    }

    /**
     * Queues the cancellation of a booking about to be deleted; one of an appointment soon (SOON_HOURS) is no
     * bulk mail. $accountGone: without the teacher's address for the replies.
     */
    private static function queueCancellation(array $b, bool $accountGone): bool
    {
        $soon = $b['day_date'] . ' ' . $b['start_time'] <= Time::now()->modify('+' . self::SOON_HOURS . ' hours')->format('Y-m-d H:i:s');
        $data = Notifier::cancellationData($b);
        Outbox::queueCancellation($accountGone ? array_replace($data, ['teacher_email' => '']) : $data, false, !$soon);
        return true;
    }

    /**
     * Runs $change (returns the number of conflicts or nothing) and queues a change notice for each upcoming
     * booking in $scope whose details changed, unless it became a conflict, and for each former conflict that
     * fits again if its details changed since the parents' last mail (theirs may be out of date: a conflict's
     * new date or location is not mailed, see ScheduleChange::$movedConflicts). Every booking whose details
     * changed counts as changed (updated_at), conflicts too.
     */
    private static function change(string $scope, int $id, bool $notify, \Closure $change): ScheduleChange
    {
        return Db::retrying(static fn (): ScheduleChange => Db::transaction(static function () use ($scope, $id, $notify, $change): ScheduleChange {
            $old = array_column(self::concerned($scope, $id, true), null, 'id');
            $conflicts = $change();
            // The same bookings afterwards (a change keeps them in the day, round or account); the ones that
            // were over before concern nobody
            $where = self::SCOPES[$scope];
            $after = $old ? array_column(Db::all(BookingService::DETAIL_SQL . " WHERE $where ORDER BY d.day_date, s.start_time, b.id", [$id]), null, 'id') : [];
            $changed = $touched = [];
            $moved = 0;
            foreach (array_intersect_key($after, $old) as $b) {
                $before = $old[$b['id']];
                $differs = self::details($b) !== self::details($before);
                if ($differs) {
                    $touched[] = (int) $b['id'];
                }
                $ahead = self::upcoming($b); // one that is over now concerns nobody
                if ($ahead && $b['is_conflict']) {
                    $moved += (int) $differs;
                } elseif ($ahead && ($differs || ($before['is_conflict'] && self::outdated($b)))) {
                    $changed[] = $b;
                }
            }
            foreach ($touched as $bookingId) {
                Db::run('UPDATE bookings SET updated_at = ? WHERE id = ?', [Time::nowSql(), $bookingId]);
            }
            [$limit, $window] = RateLimiter::limits('teacher_bulk', self::BULK_LIMIT, 3600);
            $account = 'user:' . Auth::id();
            $left = $limit - RateLimiter::count('teacher_bulk', $window, $account);
            $queued = 0;
            $result = self::tell($changed, $notify, static function (array $b) use ($left, &$queued): bool {
                // A mail about the booking still waits: it is created when sending, with the new details
                if (Outbox::waiting((int) $b['id'])) {
                    return true;
                }
                if ($queued >= $left) {
                    return false;
                }
                Outbox::queueBooking((int) $b['id'], 'changed', false, true);
                $queued++;
                return true;
            }, is_int($conflicts) ? $conflicts : 0, $moved);
            RateLimiter::add('teacher_bulk', $window, $queued, $account);
            return $result;
        }));
    }

    /**
     * With $notify, $mail queues the mail to the parents of each booking who can be mailed (false: held back by
     * the hourly limit); the others are listed.
     */
    private static function tell(array $bookings, bool $notify, \Closure $mail, int $conflicts = 0, int $moved = 0): ScheduleChange
    {
        $inPerson = $held = [];
        foreach ($bookings as $b) {
            if (!$notify || !self::mailable($b)) {
                $inPerson[] = self::person($b);
            } elseif (!$mail($b)) {
                $held[] = self::person($b);
            }
        }
        return new ScheduleChange(count($bookings), count($bookings) - count($inPerson) - count($held), $inPerson, $notify, $conflicts, $held, $moved);
    }

    /** Did the details of the booking change since its parents' last mail (confirmation or change notice)? */
    private static function outdated(array $b): bool
    {
        return (string) $b['updated_at'] > (string) ($b['notified_at'] ?? $b['created_at']);
    }

    private static function details(array $b): array
    {
        $details = array_intersect_key($b, array_flip(self::DETAILS));
        $details['location'] = trim((string) $details['location']);
        return $details;
    }

    /** What the teacher needs to tell the parents in person (no data beyond what the booking list showed). */
    private static function person(array $b): array
    {
        return ['name' => (string) $b['name'], 'email' => (string) ($b['email'] ?? ''), 'day_date' => (string) $b['day_date'], 'start_time' => (string) $b['start_time']];
    }
}
