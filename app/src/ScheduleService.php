<?php
declare(strict_types=1);

namespace App;

/**
 * Changes by the teacher that concern booked parents:
 * - deleteBooking(), deleteDay(), cancelRound(), deleteAccount(): the bookings are deleted, their parents get a
 *   cancellation (Notifier::teacherCancelled(), without a link: the booking is gone);
 * - changeDay() (date, times, location; the slots are recalculated), resyncDay() (after a break was added or
 *   removed) and changeRound() (location, teacher): parents whose appointment changed get the current details
 *   (Notifier::parentChanged()). A booking that no longer fits the schedule becomes a conflict; its parents are
 *   told when the teacher moves or deletes it.
 *
 * Each method finds the bookings concerned before it deletes or changes anything, in the same transaction, and
 * with $notify queues the mails in the outbox in the language of each booking. Several mails at once are bulk
 * mails (they respect the hourly limit, see Outbox); the cancellation of a single booking is sent right away.
 * The foreign keys still delete bookings with their day, round or account (ON DELETE CASCADE); these methods
 * make sure that nobody is left out.
 *
 * Mails go to parents who know the booking from this system: they booked themselves, or the teacher's entry got
 * a confirmation or change notice (mailable(), the rule of the reminder). Everybody else, and everybody when the
 * teacher unticks the box, is listed in the result so the teacher can tell them in person. Appointments that are
 * over concern nobody.
 */
final class ScheduleService
{
    /** Bookings of a day, a round or an account: condition on the joins of BookingService::DETAIL_SQL. */
    private const SCOPES = ['day' => 'd.id = ?', 'round' => 'd.round_id = ?', 'account' => 'r.user_id = ?'];
    /**
     * Locking reads per scope (see concerned()): the days, then the bookings with their slots, the order in which
     * a deletion or SlotGenerator::sync() locks them. The rows of the rounds stay unlocked (a booking with a limit
     * locks its round first), except for an account, whose rounds are deleted anyway.
     */
    private const LOCKS = [
        'day' => [
            'SELECT id FROM round_days WHERE id = ? FOR UPDATE',
            'SELECT b.id FROM bookings b JOIN slots s ON s.id = b.slot_id WHERE s.day_id = ? FOR UPDATE',
        ],
        'round' => [
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
        BookingService::delete($booking);
        if ($data === null) {
            return null;
        }
        if (Notifier::teacherCancelled($data)) {
            return Notifier::SENT;
        }
        Outbox::queueCancellation($data, true);
        return Notifier::QUEUED;
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
     * New date, times, slot length or location of a day ($in: date, start, end, minutes, location as from the
     * day form); the slots are recalculated.
     */
    public static function changeDay(int $dayId, array $in, bool $notify): ScheduleChange
    {
        return self::change('day', $dayId, $notify, static function () use ($dayId, $in): int {
            Db::run(
                'UPDATE round_days SET day_date = ?, start_time = ?, end_time = ?, slot_minutes = ?, location = ? WHERE id = ?',
                [$in['date'], $in['start'], $in['end'], $in['minutes'], $in['location'] !== '' ? $in['location'] : null, $dayId]
            );
            return SlotGenerator::sync($dayId);
        });
    }

    /** Recalculates the slots of a day, e.g. after a break was added or removed. */
    public static function resyncDay(int $dayId, bool $notify): ScheduleChange
    {
        return self::change('day', $dayId, $notify, static fn (): int => SlotGenerator::sync($dayId));
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

    /** Queues the cancellations of the upcoming bookings in $scope, then runs $delete (same transaction). */
    private static function delete(string $scope, int $id, bool $notify, \Closure $delete): ScheduleChange
    {
        return Db::transaction(static function () use ($scope, $id, $notify, $delete): ScheduleChange {
            // The rows have no foreign key to the booking: they outlive the deletion
            $change = self::tell(self::concerned($scope, $id, true), $notify,
                static fn (array $b) => Outbox::queueCancellation(Notifier::cancellationData($b), false, true));
            $delete();
            return $change;
        });
    }

    /**
     * Runs $change (returns the number of conflicts or nothing) and queues a change notice for each upcoming
     * booking in $scope whose details changed, unless it became a conflict.
     */
    private static function change(string $scope, int $id, bool $notify, \Closure $change): ScheduleChange
    {
        return Db::transaction(static function () use ($scope, $id, $notify, $change): ScheduleChange {
            $old = array_column(self::concerned($scope, $id, true), null, 'id');
            $conflicts = $change();
            // The same bookings afterwards (a change keeps them in the day, round or account)
            $where = self::SCOPES[$scope];
            $changed = array_filter(
                $old ? Db::all(BookingService::DETAIL_SQL . " WHERE $where ORDER BY d.day_date, s.start_time, b.id", [$id]) : [],
                static fn (array $b): bool => isset($old[$b['id']]) && !$b['is_conflict'] && self::upcoming($b) && self::details($b) !== self::details($old[$b['id']])
            );
            return self::tell(array_values($changed), $notify,
                static fn (array $b) => Outbox::queueBooking((int) $b['id'], 'changed', false, true), is_int($conflicts) ? $conflicts : 0);
        });
    }

    /** With $notify, $mail queues the mail to the parents of each booking who can be mailed; the others are listed. */
    private static function tell(array $bookings, bool $notify, \Closure $mail, int $conflicts = 0): ScheduleChange
    {
        $inPerson = [];
        foreach ($bookings as $b) {
            if ($notify && self::mailable($b)) {
                $mail($b);
            } else {
                $inPerson[] = self::person($b);
            }
        }
        return new ScheduleChange(count($bookings), count($bookings) - count($inPerson), $inPerson, $notify, $conflicts);
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
