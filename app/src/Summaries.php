<?php
declare(strict_types=1);

namespace App;

/**
 * Zusammenfassungs-Mails an Lehrkräfte (pro Person im Profil abschaltbar):
 * - Tagesübersicht am Vorabend: ab config summary_evening_hour (Standard 18 Uhr) für den Folgetag
 * - Übersicht nach Buchungsschluss: sobald für einen Tag der letzte Slot nicht mehr buchbar ist
 * Inhalt: nur Uhrzeit und Name (keine E-Mail-Adressen der Eltern), dazu eine .ics-Datei.
 * sent_summaries verhindert Doppelversand; es wird vor dem Versand eingetragen.
 */
final class Summaries
{
    /** @return array<string,int> */
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
            "SELECT DISTINCT u.* FROM users u
               JOIN rounds r ON r.user_id = u.id
               JOIN round_days d ON d.round_id = r.id AND d.day_date = ?
               JOIN slots s ON s.day_id = d.id
               JOIN bookings b ON b.slot_id = s.id
              WHERE u.is_active = 1 AND u.summary_evening = 1 AND COALESCE(u.email, '') <> ''",
            [$tomorrow]
        );
        $sent = 0;
        foreach ($users as $u) {
            if (!self::claim((int) $u['id'], 'evening', $tomorrow)) {
                continue;
            }
            $rows = self::bookings('d.day_date = ? AND r.user_id = ?', [$tomorrow, $u['id']]);
            $subject = 'Morgen: ' . count($rows) . ' Termin' . (count($rows) === 1 ? '' : 'e') . ' (' . I18n::with('de', static fn () => fmt_date($tomorrow)) . ')';
            $sent += (int) self::send($u, $subject, 'Ihre Termine für morgen:', $rows, 'termine-' . $tomorrow . '.ics');
        }
        return $sent;
    }

    private static function deadline(): int
    {
        // Tage, deren letzter Slot nicht mehr buchbar ist (Start − Buchungsfrist ≤ jetzt)
        $days = Db::all(
            "SELECT d.id, d.day_date, r.id AS round_id, r.title, r.user_id,
                    TIMESTAMP(d.day_date, MAX(s.start_time)) - INTERVAL r.booking_deadline_hours HOUR AS closes_at
               FROM round_days d
               JOIN rounds r ON r.id = d.round_id
               JOIN users u ON u.id = r.user_id
               JOIN slots s ON s.day_id = d.id
              WHERE d.day_date >= ? AND u.is_active = 1 AND u.summary_deadline = 1 AND COALESCE(u.email, '') <> ''
                AND (r.is_active = 1 OR EXISTS (SELECT 1 FROM slots s2 JOIN bookings b2 ON b2.slot_id = s2.id WHERE s2.day_id = d.id))
              GROUP BY d.id, d.day_date, r.id, r.title, r.user_id, r.booking_deadline_hours
             HAVING closes_at <= ?",
            [Time::today(), Time::nowSql()]
        );
        $sent = 0;
        foreach ($days as $day) {
            if (!self::claim((int) $day['user_id'], 'deadline', 'day-' . $day['id'])) {
                continue;
            }
            $user = Db::one('SELECT * FROM users WHERE id = ?', [$day['user_id']]);
            $rows = self::bookings('d.id = ?', [$day['id']]);
            $free = (int) Db::value(
                'SELECT COUNT(*) FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id WHERE s.day_id = ? AND b.id IS NULL AND s.is_blocked = 0',
                [$day['id']]
            );
            $date = I18n::with('de', static fn () => fmt_date($day['day_date']));
            $subject = 'Buchungsschluss: ' . $day['title'] . ' – ' . $date;
            $intro = 'Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste'
                . ($free > 0 ? " ($free Termin" . ($free === 1 ? '' : 'e') . ' blieb' . ($free === 1 ? '' : 'en') . ' frei)' : '') . ':';
            $sent += (int) self::send($user, $subject, $intro, $rows, 'termine-' . $day['day_date'] . '.ics');
        }
        return $sent;
    }

    /** Trägt den Versand ein; false, wenn er bereits erfolgt ist. */
    private static function claim(int $userId, string $kind, string $ref): bool
    {
        return Db::exec(
            'INSERT IGNORE INTO sent_summaries (user_id, kind, ref, sent_at) VALUES (?, ?, ?, ?)',
            [$userId, $kind, $ref, Time::nowSql()]
        ) === 1;
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

    private static function send(array $user, string $subject, string $intro, array $rows, string $icsName): bool
    {
        return (bool) I18n::with('de', static function () use ($user, $subject, $intro, $rows, $icsName): bool {
            $lines = ['Hallo ' . $user['display_name'] . ',', '', $intro, ''];
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
                $lines[] = '(keine Buchungen)';
            }
            $roundIds = array_unique(array_column($rows, 'round_id'));
            $lines[] = '';
            foreach ($roundIds as $rid) {
                $lines[] = 'Übersicht und Druckansicht: ' . abs_url('/admin/round/' . (int) $rid, ['lang' => null]);
            }
            $lines[] = '';
            $lines[] = '--';
            $lines[] = 'Diese Zusammenfassung können Sie im Profil abbestellen. Bitte löschen Sie sie spätestens 15 Monate nach dem Termin (siehe Datenschutzhinweise).';

            $attachments = $rows ? [[
                'name' => $icsName,
                'type' => 'text/calendar; charset=utf-8; method=PUBLISH',
                'content' => IcsExport::forRound(['title' => $rows[0]['title']], $rows),
            ]] : [];
            return Mailer::send((string) $user['email'], $subject, implode("\n", $lines), $attachments);
        });
    }
}
