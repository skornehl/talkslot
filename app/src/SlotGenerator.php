<?php
declare(strict_types=1);

namespace App;

/**
 * Generates slots from day times, slot duration and breaks and syncs them with the
 * database. Booked slots are never deleted in the process.
 */
final class SlotGenerator
{
    /**
     * Calculates the slots of a day. If a slot falls into a break (or into an
     * occupied period), it continues directly after its end.
     *
     * @param list<array{start_time:string,end_time:string}> $blocked breaks or occupied periods
     * @return list<array{start:string,end:string}> times in HH:MM:00 format
     */
    public static function compute(string $start, string $end, int $minutes, array $blocked): array
    {
        if ($minutes < 1) {
            return [];
        }
        $from = Time::minutes($start);
        $to = Time::minutes($end);
        $ranges = array_map(
            static fn (array $b) => [Time::minutes($b['start_time']), Time::minutes($b['end_time'])],
            $blocked
        );

        $slots = [];
        $t = $from;
        while ($t + $minutes <= $to) {
            $slotEnd = $t + $minutes;
            $jumpTo = null;
            foreach ($ranges as [$bs, $be]) {
                if ($t < $be && $slotEnd > $bs) {
                    $jumpTo = max($jumpTo ?? $be, $be);
                }
            }
            if ($jumpTo !== null) {
                $t = $jumpTo;
                continue;
            }
            $slots[] = ['start' => Time::fromMinutes($t), 'end' => Time::fromMinutes($slotEnd)];
            $t = $slotEnd;
        }
        return $slots;
    }

    /**
     * Syncs the slots of a day with the schedule.
     * - missing slots are created (blocks on existing slots are kept)
     * - unbooked slots that no longer fit are deleted
     * - booked slots that no longer fit are marked as conflicts
     *
     * Locks: the day row first (FOR UPDATE), then the slots it changes. A booking locks its slot
     * together with the day row (BookingService::lockSlot joins round_days), so a booking and a sync of
     * the same day are serialized; where they cross, InnoDB ends one of them as a deadlock (bookings
     * retry). The list of slots below is a plain snapshot read, though, and a deleted slot takes its
     * booking with it (ON DELETE CASCADE). So the DELETE checks for a booking itself and does not
     * rely on that lock: a slot booked in the meantime is kept as a conflict instead.
     *
     * @return int number of conflicts
     */
    public static function sync(int $dayId): int
    {
        return (int) Db::transaction(static function () use ($dayId): int {
            $day = Db::one('SELECT * FROM round_days WHERE id = ? FOR UPDATE', [$dayId]);
            if (!$day) {
                return 0;
            }
            $breaks = Db::all('SELECT start_time, end_time FROM day_breaks WHERE day_id = ?', [$dayId]);
            $existing = Db::all(
                'SELECT s.id, s.start_time, s.end_time, b.id AS booking_id
                   FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
                  WHERE s.day_id = ?',
                [$dayId]
            );

            $desired = self::compute($day['start_time'], $day['end_time'], (int) $day['slot_minutes'], $breaks);
            $desiredStarts = array_column($desired, 'end', 'start');

            // Booked slots that no longer fit block their period so that no overlapping
            // new slots are created. Booked slots that still fit stay fixed in place;
            // only the free gaps around both are split up anew. (Without
            // this pinning the recalculation could shift fitting bookings and
            // wrongly mark them as conflicts.)
            $conflictRanges = [];
            $kept = [];
            foreach ($existing as $s) {
                if ($s['booking_id'] === null) {
                    continue;
                }
                if (isset($desiredStarts[$s['start_time']])) {
                    $kept[$s['start_time']] = $desiredStarts[$s['start_time']];
                } else {
                    $conflictRanges[] = ['start_time' => $s['start_time'], 'end_time' => $s['end_time']];
                }
            }
            if ($conflictRanges) {
                $blocked = array_merge($breaks, $conflictRanges);
                foreach ($kept as $start => $end) {
                    $blocked[] = ['start_time' => $start, 'end_time' => $end];
                }
                $desired = self::compute($day['start_time'], $day['end_time'], (int) $day['slot_minutes'], $blocked);
                $desiredStarts = array_column($desired, 'end', 'start') + $kept;
            }

            $conflicts = 0;
            foreach ($existing as $s) {
                $start = $s['start_time'];
                if (isset($desiredStarts[$start])) {
                    Db::run('UPDATE slots SET end_time = ?, is_conflict = 0 WHERE id = ?', [$desiredStarts[$start], $s['id']]);
                    unset($desiredStarts[$start]);
                } elseif ($s['booking_id'] !== null || !self::deleteIfFree((int) $s['id'])) {
                    Db::run('UPDATE slots SET is_conflict = 1 WHERE id = ?', [$s['id']]);
                    $conflicts++;
                }
            }
            foreach ($desiredStarts as $start => $end) {
                Db::run('INSERT INTO slots (day_id, start_time, end_time) VALUES (?, ?, ?)', [$dayId, $start, $end]);
            }
            return $conflicts;
        });
    }

    /** Deletes a slot only if it has no booking now (the DELETE reads bookings with a lock, not the snapshot). */
    private static function deleteIfFree(int $slotId): bool
    {
        return Db::exec('DELETE s FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id WHERE s.id = ? AND b.id IS NULL', [$slotId]) > 0;
    }
}
