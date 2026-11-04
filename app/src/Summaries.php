<?php
declare(strict_types=1);

namespace App;

/**
 * Summary emails to teachers (can be turned off per person in the profile):
 * - Daily overview the evening before: from config summary_evening_hour (default 6 pm) for the next day
 * - Overview after the booking deadline: as soon as the last slot of a day can no longer be booked
 * Content: only time and name (no parents' email addresses), plus an .ics file.
 * run() queues them (Outbox); deliver() creates the mail when the outbox sends it, so the list is
 * current then. sent_summaries prevents queueing twice; the entry is made together with the queue entry.
 */
final class Summaries
{
    /** @return array<string,int> number of summaries queued */
    public static function run(): array
    {
        return ['summaries_evening' => self::evening(), 'summaries_deadline' => self::deadline()];
    }

    private static function evening(): int
    {
        $now = Time::now();
        if ((int) $now->format('G') < (int) Config::get('summary_evening_hour', 18)) {
            return 0;
        }
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $users = Db::all(
            "SELECT DISTINCT u.id FROM users u
               JOIN rounds r ON r.user_id = u.id
               JOIN round_days d ON d.round_id = r.id AND d.day_date = ?
               JOIN slots s ON s.day_id = d.id
               JOIN bookings b ON b.slot_id = s.id
              WHERE u.is_active = 1 AND u.summary_evening = 1 AND COALESCE(u.email, '') <> ''",
            [$tomorrow]
        );
        $queued = 0;
        foreach ($users as $u) {
            $queued += (int) self::queue((int) $u['id'], 'evening', $tomorrow);
        }
        return $queued;
    }

    private static function deadline(): int
    {
        // Days whose last slot can no longer be booked (start − booking deadline ≤ now)
        $days = Db::all(
            "SELECT d.id, r.user_id,
                    TIMESTAMP(d.day_date, MAX(s.start_time)) - INTERVAL r.booking_deadline_hours HOUR AS closes_at
               FROM round_days d
               JOIN rounds r ON r.id = d.round_id
               JOIN users u ON u.id = r.user_id
               JOIN slots s ON s.day_id = d.id
              WHERE d.day_date >= ? AND u.is_active = 1 AND u.summary_deadline = 1 AND COALESCE(u.email, '') <> ''
                AND (r.is_active = 1 OR EXISTS (SELECT 1 FROM slots s2 JOIN bookings b2 ON b2.slot_id = s2.id WHERE s2.day_id = d.id))
              GROUP BY d.id, d.day_date, r.user_id, r.booking_deadline_hours
             HAVING closes_at <= ?",
            [Time::today(), Time::nowSql()]
        );
        $queued = 0;
        foreach ($days as $day) {
            $queued += (int) self::queue((int) $day['user_id'], 'deadline', 'day-' . $day['id']);
        }
        return $queued;
    }

    /** Records the summary and queues it; false if that has already happened. */
    private static function queue(int $userId, string $kind, string $ref): bool
    {
        return (bool) Db::transaction(static function () use ($userId, $kind, $ref): bool {
            $new = Db::exec(
                'INSERT IGNORE INTO sent_summaries (user_id, kind, ref, sent_at) VALUES (?, ?, ?, ?)',
                [$userId, $kind, $ref, Time::nowSql()]
            ) === 1;
            if ($new) {
                Outbox::queueAccount($userId, 'summary_' . $kind, $ref);
            }
            return $new;
        });
    }

    /**
     * Sends a queued summary (from the outbox). null: no longer needed – the account is inactive or has
     * no address, the summary was turned off, the evening is over ("tomorrow" would be wrong) or the day
     * is gone or no longer this teacher's.
     */
    public static function deliver(string $kind, int $userId, string $ref): ?bool
    {
        $user = Db::one("SELECT * FROM users WHERE id = ? AND is_active = 1 AND COALESCE(email, '') <> ''", [$userId]);
        if ($kind === 'summary_evening' && $user && $user['summary_evening'] && $ref > Time::today()) {
            return self::sendEvening($user, $ref);
        }
        $day = $kind === 'summary_deadline' && $user && $user['summary_deadline'] ? Db::one(
            'SELECT d.id, d.day_date, r.title FROM round_days d JOIN rounds r ON r.id = d.round_id WHERE d.id = ? AND r.user_id = ? AND d.day_date >= ?',
            [(int) substr($ref, 4), $userId, Time::today()]
        ) : null;
        return $day ? self::sendDeadline($user, $day) : null;
    }

    private static function sendEvening(array $user, string $date): bool
    {
        $rows = self::bookings('d.day_date = ? AND r.user_id = ?', [$date, $user['id']]);
        // In the teacher's language (dates as well)
        return (bool) AdminI18n::with(AdminI18n::of($user), static function () use ($user, $rows, $date): bool {
            $vars = ['count' => count($rows), 'date' => fmt_date($date)];
            $subject = count($rows) === 1 ? __('Morgen: {count} Termin ({date})', $vars) : __('Morgen: {count} Termine ({date})', $vars);
            return self::send($user, $subject, __('Ihre Termine für morgen:'), $rows, 'termine-' . $date . '.ics');
        });
    }

    private static function sendDeadline(array $user, array $day): bool
    {
        $rows = self::bookings('d.id = ?', [$day['id']]);
        $free = (int) Db::value(
            'SELECT COUNT(*) FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id WHERE s.day_id = ? AND b.id IS NULL AND s.is_blocked = 0',
            [$day['id']]
        );
        return (bool) AdminI18n::with(AdminI18n::of($user), static function () use ($user, $day, $rows, $free): bool {
            $subject = __('Buchungsschluss: {title} – {date}', ['date' => fmt_date($day['day_date']), 'title' => $day['title']]);
            $intro = match (true) {
                $free === 0 => __('Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste:'),
                $free === 1 => __('Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste ({count} Termin blieb frei):', ['count' => $free]),
                default => __('Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste ({count} Termine blieben frei):', ['count' => $free]),
            };
            return self::send($user, $subject, $intro, $rows, 'termine-' . $day['day_date'] . '.ics');
        });
    }

    private static function bookings(string $where, array $params): array
    {
        return Db::all(
            "SELECT b.id, b.name, d.day_date, s.start_time, s.end_time, r.title, r.id AS round_id
               FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id JOIN rounds r ON r.id = d.round_id
              WHERE $where ORDER BY d.day_date, s.start_time",
            $params
        );
    }

    /** Called within AdminI18n::with() in the recipient's language. */
    private static function send(array $user, string $subject, string $intro, array $rows, string $icsName): bool
    {
        $lines = [__('Hallo {name},', ['name' => $user['display_name']]), '', $intro, ''];
        $currentTitle = null;
        foreach ($rows as $r) {
            if ($r['title'] !== $currentTitle) {
                $currentTitle = $r['title'];
                $lines[] = '';
                $lines[] = $r['title'] . ' – ' . fmt_date($r['day_date']);
            }
            $lines[] = '  ' . fmt_range($r['start_time'], $r['end_time']) . '  ' . $r['name'];
        }
        if (!$rows) {
            $lines[] = __('(keine Buchungen)');
        }
        $roundIds = array_unique(array_column($rows, 'round_id'));
        $lines[] = '';
        foreach ($roundIds as $rid) {
            $lines[] = __('Übersicht und Druckansicht: {url}', ['url' => abs_url('/admin/round/' . (int) $rid, ['lang' => null])]);
        }
        $lines[] = '';
        $lines[] = '--';
        $lines[] = __('Diese Zusammenfassung können Sie im Profil abbestellen. Bitte löschen Sie sie spätestens 15 Monate nach dem Termin (siehe Datenschutzhinweise).');

        $attachments = $rows ? [[
            'name' => $icsName,
            'type' => 'text/calendar; charset=utf-8; method=PUBLISH',
            'content' => IcsExport::forRound(['title' => $rows[0]['title']], $rows),
        ]] : [];
        return Mailer::send((string) $user['email'], $subject, implode("\n", $lines), $attachments);
    }
}
