<?php
declare(strict_types=1);

namespace App;

/**
 * Erzeugt Slots aus Tageszeiten, Slot-Dauer und Pausen und gleicht sie mit der
 * Datenbank ab. Gebuchte Slots werden dabei nie gelöscht.
 */
final class SlotGenerator
{
    /**
     * Berechnet die Slots eines Tages. Fällt ein Slot in eine Pause (oder in einen
     * belegten Zeitraum), geht es direkt nach deren Ende weiter.
     *
     * @param list<array{start_time:string,end_time:string}> $blocked Pausen bzw. belegte Zeiträume
     * @return list<array{start:string,end:string}> Zeiten im Format HH:MM:00
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
     * Gleicht die Slots eines Tages mit dem Zeitplan ab.
     * - fehlende Slots werden angelegt (Sperren bestehender Slots bleiben erhalten)
     * - ungebuchte, nicht mehr passende Slots werden gelöscht
     * - gebuchte, nicht mehr passende Slots werden als Konflikt markiert
     *
     * @return int Anzahl der Konflikte
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

            // Gebuchte Slots, die nicht mehr passen, blockieren ihren Zeitraum, damit keine
            // überlappenden neuen Slots entstehen. Gebuchte Slots, die weiterhin passen, bleiben
            // fest stehen; nur die freien Lücken um beide herum werden neu aufgeteilt. (Ohne
            // diese Fixierung könnte die Neuberechnung passende Buchungen verschieben und
            // fälschlich als Konflikt markieren.)
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
                } elseif ($s['booking_id'] !== null) {
                    Db::run('UPDATE slots SET is_conflict = 1 WHERE id = ?', [$s['id']]);
                    $conflicts++;
                } else {
                    Db::run('DELETE FROM slots WHERE id = ?', [$s['id']]);
                }
            }
            foreach ($desiredStarts as $start => $end) {
                Db::run('INSERT INTO slots (day_id, start_time, end_time) VALUES (?, ?, ?)', [$dayId, $start, $end]);
            }
            return $conflicts;
        });
    }
}
