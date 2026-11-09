<?php
declare(strict_types=1);

namespace App;

/**
 * Trash for rounds without bookings (expert review P3-9): protection against a wrong click. Deleting such a round
 * keeps a copy of it for KEEP_DAYS days, from which it can be restored with its address and round code.
 *
 * Only rounds without bookings: a round with bookings is deleted at once, as the promises to parents about
 * deletion leave no room for a soft delete. The copy lives in its own table as JSON, so that no query on rounds
 * has to skip deleted ones and the previous version of the app simply ignores it (additive migration 24). It
 * holds what the teacher entered (no data of parents: a round in here has no bookings), goes with the account
 * (foreign key) and is deleted by Cleanup after KEEP_DAYS.
 */
final class RoundTrash
{
    public const KEEP_DAYS = 14;

    /** Everything of rounds except the id; a unit test compares this with schema.sql. */
    public const ROUND_COLUMNS = [
        'user_id', 'title', 'slug', 'code', 'code_required', 'booking_limit', 'remind_parents', 'description', 'location', 'source_lang',
        'is_active', 'was_used', 'booking_deadline_hours', 'cancel_deadline_hours', 'delete_after_hours', 'expired_at', 'created_at', 'updated_at',
    ];

    /**
     * Moves a round without bookings to the trash. Locks in the order of ScheduleService::LOCKS (round, days,
     * bookings), so that a booking arriving now either is seen here or comes too late.
     * @return ?int id of the entry; null: the round is gone or has a booking by now, nothing was changed
     */
    public static function put(int $roundId): ?int
    {
        return Db::transaction(static function () use ($roundId): ?int {
            $round = Db::one('SELECT * FROM rounds WHERE id = ? FOR UPDATE', [$roundId]);
            if ($round === null) {
                return null;
            }
            $days = Db::all('SELECT * FROM round_days WHERE round_id = ? ORDER BY day_date, id FOR UPDATE', [$roundId]);
            if (Db::value('SELECT b.id FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id WHERE d.round_id = ? LIMIT 1 FOR UPDATE', [$roundId]) !== null) {
                return null;
            }
            foreach ($days as &$day) {
                $day['breaks'] = Db::all('SELECT start_time, end_time, label FROM day_breaks WHERE day_id = ? ORDER BY start_time', [$day['id']]);
                $day['slots'] = Db::all('SELECT start_time, end_time, is_blocked FROM slots WHERE day_id = ? AND is_conflict = 0 ORDER BY start_time', [$day['id']]);
            }
            unset($day);
            $translations = Db::all('SELECT lang, title, description, source_hash, is_manual, updated_at FROM round_translations WHERE round_id = ?', [$roundId]);
            $id = Db::insert(
                'INSERT INTO round_trash (user_id, title, data, deleted_at) VALUES (?, ?, ?, ?)',
                [(int) $round['user_id'], (string) $round['title'], json_encode(['round' => $round, 'days' => $days, 'translations' => $translations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), Time::nowSql()]
            );
            Db::run('DELETE FROM rounds WHERE id = ?', [$roundId]); // days, breaks, slots, translations follow (foreign keys)
            return $id;
        });
    }

    /**
     * Puts a round back: inactive rounds stay inactive, active ones are active again unless $mayBeActive is false
     * (what the activation requires is missing by now, AdminController::publishBlocker()). The address and the
     * round code stay if nobody has taken them meanwhile. expired_at is cleared: Cleanup would otherwise delete
     * a round that expired before it went to the trash at its next run, for good; it expires again from now on.
     * @return ?array{id: int, same_address: bool, deactivated: bool} null: the entry is gone (restored or deleted in
     *   another tab)
     */
    public static function restore(int $id, bool $mayBeActive = true): ?array
    {
        return Db::transaction(static function () use ($id, $mayBeActive): ?array {
            $entry = Db::one('SELECT data FROM round_trash WHERE id = ? FOR UPDATE', [$id]);
            if ($entry === null) {
                return null;
            }
            $data = json_decode((string) $entry['data'], true, 512, JSON_THROW_ON_ERROR);
            $round = $data['round'];
            $original = $round;
            $round['slug'] = Slug::unique((string) $round['slug']);
            if ((string) ($round['code'] ?? '') === '' || Db::value('SELECT id FROM rounds WHERE code = ?', [$round['code']]) !== null) {
                $round['code'] = RoundCode::unique();
            }
            $deactivated = !$mayBeActive && !empty($round['is_active']);
            if ($deactivated) {
                $round['is_active'] = 0;
            }
            $round['expired_at'] = null;
            // Only what the copy has: a column added by a later version keeps its default
            $columns = array_values(array_filter(self::ROUND_COLUMNS, static fn (string $c): bool => array_key_exists($c, $round)));
            $newId = Db::insert(
                'INSERT INTO rounds (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                array_map(static fn (string $column): mixed => $round[$column], $columns)
            );
            foreach ($data['days'] as $day) {
                $dayId = Db::insert(
                    'INSERT INTO round_days (round_id, day_date, start_time, end_time, slot_minutes, location) VALUES (?, ?, ?, ?, ?, ?)',
                    [$newId, $day['day_date'], $day['start_time'], $day['end_time'], $day['slot_minutes'], $day['location']]
                );
                foreach ($day['breaks'] as $break) {
                    Db::run('INSERT INTO day_breaks (day_id, start_time, end_time, label) VALUES (?, ?, ?, ?)', [$dayId, $break['start_time'], $break['end_time'], $break['label']]);
                }
                foreach ($day['slots'] as $slot) {
                    Db::run('INSERT INTO slots (day_id, start_time, end_time, is_blocked) VALUES (?, ?, ?, ?)', [$dayId, $slot['start_time'], $slot['end_time'], $slot['is_blocked']]);
                }
            }
            foreach ($data['translations'] as $t) {
                Db::run(
                    'INSERT INTO round_translations (round_id, lang, title, description, source_hash, is_manual, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$newId, $t['lang'], $t['title'], $t['description'], $t['source_hash'], $t['is_manual'], $t['updated_at']]
                );
            }
            Db::run('DELETE FROM round_trash WHERE id = ?', [$id]);
            return ['id' => $newId, 'same_address' => $round['slug'] === $original['slug'] && $round['code'] === $original['code'], 'deactivated' => $deactivated];
        });
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT id, user_id, title, deleted_at FROM round_trash WHERE id = ?', [$id]);
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM round_trash WHERE id = ?', [$id]);
    }

    /**
     * Entries with the number of days of the round, newest first.
     * @param ?int $userId only this account's; null: all (admins)
     * @return list<array<string, mixed>>
     */
    public static function entries(?int $userId): array
    {
        $rows = Db::all(
            "SELECT t.id, t.user_id, t.title, t.deleted_at, JSON_LENGTH(t.data, '$.days') AS day_count, COALESCE(NULLIF(u.display_name, ''), u.username) AS owner
               FROM round_trash t JOIN users u ON u.id = t.user_id
              WHERE (? = 0 OR t.user_id = ?) ORDER BY t.deleted_at DESC, t.id DESC",
            [(int) $userId, (int) $userId]
        );
        foreach ($rows as &$row) {
            $row['until'] = self::until((string) $row['deleted_at']);
        }
        return $rows;
    }

    public static function count(?int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM round_trash WHERE (? = 0 OR user_id = ?)', [(int) $userId, (int) $userId]);
    }

    /** Date from which an entry is deleted, Y-m-d. */
    public static function until(string $deletedAt): string
    {
        return (new \DateTimeImmutable($deletedAt))->modify('+' . self::KEEP_DAYS . ' days')->format('Y-m-d');
    }

    /** Deletes entries older than KEEP_DAYS (Cleanup). @return int number of entries */
    public static function purge(): int
    {
        return Db::exec('DELETE FROM round_trash WHERE deleted_at < ?', [Time::now()->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s')]);
    }
}
