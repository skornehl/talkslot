<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Buchen, Umbuchen und Stornieren.
 *
 * Schutz gegen Doppelbuchung in zwei Stufen:
 * 1. Transaktion mit SELECT … FOR UPDATE auf den Slot (serialisiert parallele Buchungen)
 * 2. UNIQUE(slot_id) in bookings als harte Garantie; Fehler 1062 → „bereits vergeben“
 *
 * Mehrfachbuchungen (Einstellung der Runde, booking_limit): „round“ = ein Termin pro Person in
 * der Runde, „day“ = einer pro Person und Tag, „none“ = nur der Hinweis. Dieselbe Person ist
 * derselbe Name und, wenn beide Buchungen eine haben, dieselbe E-Mail-Adresse (samePerson()).
 * Gilt für Eltern; Einträge der Lehrkraft sind nicht begrenzt. Bei aktiver Sperre wird die Runde
 * gesperrt (FOR UPDATE), damit zwei gleichzeitige Buchungen nicht beide durchkommen.
 */
final class BookingService
{
    public const TAKEN = 'taken';
    public const UNAVAILABLE = 'unavailable';
    public const DEADLINE = 'deadline';
    public const LIMIT_ROUND = 'limit_round';
    public const LIMIT_DAY = 'limit_day';
    /** Werte von rounds.booking_limit */
    public const LIMITS = ['none', 'round', 'day'];

    /** SQL-Grundgerüst: Buchung mit Slot, Tag und Runde. */
    private const DETAIL_SQL = 'SELECT b.*, s.start_time, s.end_time, s.is_conflict, d.day_date, d.id AS day_id,
                                       r.id AS round_id, r.title, r.description, r.source_lang, r.slug, r.is_active, r.cancel_deadline_hours,
                                       r.booking_deadline_hours, r.user_id,
                                       u.display_name AS teacher_name, u.email AS teacher_email, u.is_active AS teacher_active
                                  FROM bookings b
                                  JOIN slots s ON s.id = b.slot_id
                                  JOIN round_days d ON d.id = s.day_id
                                  JOIN rounds r ON r.id = d.round_id
                                  JOIN users u ON u.id = r.user_id';

    public static function slotStart(array $row): DateTimeImmutable
    {
        return new DateTimeImmutable($row['day_date'] . ' ' . $row['start_time']);
    }

    /** Freie, öffentlich buchbare Slots einer Runde (ohne $exceptSlotId). */
    public static function freeSlots(array $round, ?int $exceptSlotId = null): array
    {
        $rows = Db::all(
            'SELECT s.id, s.start_time, s.end_time, d.day_date, d.id AS day_id
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

    /** Gruppiert Slots nach Datum: [['date' => Y-m-d, 'slots' => [...]], ...] */
    public static function groupByDay(array $slots): array
    {
        $days = [];
        foreach ($slots as $s) {
            $days[$s['day_date']]['date'] = $s['day_date'];
            $days[$s['day_date']]['slots'][] = $s;
        }
        return array_values($days);
    }

    /**
     * Buchung durch Eltern.
     * @return array{0:int,1:string} [buchungs_id, token_klartext]
     * @throws BookingException
     */
    public static function book(array $round, int $slotId, string $name, ?string $email, string $lang): array
    {
        return self::create($round, $slotId, $name, $email, $lang, 'parent');
    }

    /** Eintrag durch die Lehrkraft: ignoriert Sperre und Buchungsfrist. */
    public static function adminBook(array $round, int $slotId, string $name, ?string $email, string $lang): array
    {
        return self::create($round, $slotId, $name, $email, $lang, 'admin');
    }

    private static function create(array $round, int $slotId, string $name, ?string $email, string $lang, string $source): array
    {
        [$plain, $hash] = Token::generate();
        try {
            $id = self::retryOnDeadlock(static fn (): int => Db::transaction(static function () use ($round, $slotId, $name, $email, $lang, $source, $hash): int {
                $limited = $source !== 'admin' && self::lockForLimit($round);
                $slot = self::lockSlot($slotId, (int) $round['id']);
                self::assertFree($slot, $round, $source === 'admin');
                if ($limited) {
                    self::assertWithinLimit($round, (string) $slot['day_date'], $name, $email);
                }
                $now = Time::nowSql();
                return Db::insert(
                    'INSERT INTO bookings (slot_id, name, email, token_hash, lang, source, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$slotId, $name, $email, $hash, $lang, $source, $now, $now]
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
     * Verschiebt eine Buchung auf einen anderen Slot derselben Runde.
     * @throws BookingException
     */
    public static function move(array $booking, int $newSlotId, bool $byAdmin): void
    {
        $round = Db::one('SELECT * FROM rounds WHERE id = ?', [$booking['round_id']]);
        // Eltern: nur solange das Konto der Lehrkraft aktiv ist und die Angaben des Admins vorliegen
        // (wie bei der öffentlichen Rundenseite); Absagen bleibt immer möglich
        if (!$round || (!$byAdmin && (!Db::value('SELECT is_active FROM users WHERE id = ?', [$round['user_id']]) || !Operator::ready()))) {
            throw new BookingException(self::UNAVAILABLE);
        }
        try {
            self::retryOnDeadlock(static fn () => Db::transaction(static function () use ($booking, $newSlotId, $byAdmin, $round): void {
                // Umbuchen ändert die Zahl der Termine nicht; nur bei „pro Tag“ zählt ein anderer Tag
                $limited = !$byAdmin && ($round['booking_limit'] ?? 'none') === 'day' && self::lockForLimit($round);
                $slot = self::lockSlot($newSlotId, (int) $round['id']);
                self::assertFree($slot, $round, $byAdmin);
                if ($limited && (string) $slot['day_date'] !== (string) $booking['day_date']) {
                    self::assertWithinLimit($round, (string) $slot['day_date'], (string) $booking['name'], $booking['email'], (int) $booking['id']);
                }
                Db::run('UPDATE bookings SET slot_id = ?, updated_at = ? WHERE id = ?', [$newSlotId, Time::nowSql(), $booking['id']]);
            }));
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                throw new BookingException(self::TAKEN);
            }
            throw $e;
        }
        self::removeOrphanedConflict((int) $booking['slot_id']);
    }

    public static function delete(array $booking): void
    {
        Db::run('DELETE FROM bookings WHERE id = ?', [$booking['id']]);
        self::removeOrphanedConflict((int) $booking['slot_id']);
    }

    public static function update(int $bookingId, string $name, ?string $email, string $lang): void
    {
        Db::run(
            'UPDATE bookings SET name = ?, email = ?, lang = ?, updated_at = ? WHERE id = ?',
            [$name, $email, $lang, Time::nowSql(), $bookingId]
        );
    }

    public static function findByToken(string $plain): ?array
    {
        if (!Token::isWellFormed($plain)) {
            return null;
        }
        return Db::one(self::DETAIL_SQL . ' WHERE b.token_hash = ?', [Token::hash($plain)]);
    }

    public static function findById(int $id): ?array
    {
        return Db::one(self::DETAIL_SQL . ' WHERE b.id = ?', [$id]);
    }

    /**
     * Dürfen Eltern diese Buchung noch absagen? Unabhängig vom Aktiv-Status der Runde,
     * damit eine deaktivierte (z. B. volle) Runde Absagen nicht verhindert.
     * Umbuchen ist zusätzlich nur bei aktiver Runde möglich (siehe assertFree).
     */
    public static function canChange(array $booking): bool
    {
        return self::slotStart($booking) > self::deadline((int) $booking['cancel_deadline_hours']);
    }

    private static function deadline(int $hours): DateTimeImmutable
    {
        return Time::now()->modify('+' . $hours . ' hours');
    }

    /**
     * Dieselbe Person? Gleicher Name (Groß-/Kleinschreibung und Leerzeichen egal) und, wenn beide
     * eine E-Mail-Adresse haben, dieselbe. Fehlt sie bei einer, zählt nur der Name (sonst ließe sich
     * die Sperre durch Weglassen der Adresse umgehen). Geschwister mit eigenem Namen sind verschieden.
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
     * Gleichzeitig bearbeitet die Lehrkraft einen Tag (Slots werden neu berechnet): InnoDB kann dann
     * eine der Transaktionen als Deadlock bzw. nach Wartezeit abbrechen. Einmal wiederholen, danach
     * wie „gerade vergeben“ behandeln statt eines Serverfehlers.
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

    /** Sperrt die Runde für die Prüfung, falls sie Mehrfachbuchungen begrenzt. true = begrenzt. */
    private static function lockForLimit(array $round): bool
    {
        if (!in_array($round['booking_limit'] ?? 'none', ['round', 'day'], true)) {
            return false;
        }
        Db::one('SELECT id FROM rounds WHERE id = ? FOR UPDATE', [$round['id']]);
        return true;
    }

    /** @throws BookingException wenn dieselbe Person in der Runde bzw. an dem Tag schon einen Termin hat */
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

    /** Ein Konflikt-Slot ohne Buchung hat keinen Zweck mehr. */
    private static function removeOrphanedConflict(int $slotId): void
    {
        Db::run(
            'DELETE s FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
              WHERE s.id = ? AND s.is_conflict = 1 AND b.id IS NULL',
            [$slotId]
        );
    }
}
