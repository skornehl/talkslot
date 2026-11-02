<?php
declare(strict_types=1);

namespace App;

/**
 * Automatische Datenlöschung.
 * Läuft per Cronjob (bin/cron.php) und zusätzlich als Fallback bei Seitenaufrufen.
 */
final class Cleanup
{
    /** Fallback: höchstens alle N Minuten bei einem Seitenaufruf ausführen. */
    public static function maybeRun(): void
    {
        if (!Config::get('cleanup_on_request', true)) {
            return;
        }
        $interval = max(1, (int) Config::get('cleanup_interval_minutes', 15)) * 60;
        $last = (int) (Settings::get('last_cleanup') ?? '0');
        if (time() - $last < $interval) {
            return;
        }
        // Atomar „reservieren“, damit parallele Aufrufe nicht doppelt bereinigen
        $claimed = Db::exec('UPDATE settings SET value = ? WHERE name = ? AND value = ?', [(string) time(), 'last_cleanup', (string) $last]);
        if ($claimed === 0) {
            if (Settings::get('last_cleanup') !== null) {
                return;
            }
            Settings::set('last_cleanup', (string) time());
        }
        self::run();
        Summaries::run();
    }

    /** @return array<string,int> Anzahl gelöschter/geänderter Datensätze je Schritt */
    public static function run(): array
    {
        $today = Time::today();
        $now = Time::nowSql();
        $stats = [];

        // 1. Buchungen vergangener Tage löschen (ab 00:00 Uhr des Folgetags)
        $stats['bookings_deleted'] = Db::exec(
            'DELETE b FROM bookings b
               JOIN slots s ON s.id = b.slot_id
               JOIN round_days d ON d.id = s.day_id
              WHERE d.day_date < ?',
            [$today]
        );

        // 2. Konflikt-Slots ohne Buchung entfernen
        $stats['conflict_slots_deleted'] = Db::exec(
            'DELETE s FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
              WHERE s.is_conflict = 1 AND b.id IS NULL'
        );

        // 3. Runden, deren letzter Tag vorbei ist, deaktivieren und als abgelaufen markieren
        $stats['rounds_expired'] = Db::exec(
            'UPDATE rounds r SET r.is_active = 0, r.expired_at = ?
              WHERE r.expired_at IS NULL
                AND EXISTS (SELECT 1 FROM round_days d WHERE d.round_id = r.id)
                AND NOT EXISTS (SELECT 1 FROM round_days d WHERE d.round_id = r.id AND d.day_date >= ?)',
            [$now, $today]
        );

        // 4. Abgelaufene Runden nach der eingestellten Frist löschen (NULL = nie)
        $stats['rounds_deleted'] = Db::exec(
            'DELETE FROM rounds
              WHERE expired_at IS NOT NULL AND delete_after_hours IS NOT NULL
                AND expired_at <= DATE_SUB(?, INTERVAL delete_after_hours HOUR)
                AND NOT EXISTS (SELECT 1 FROM round_days d WHERE d.round_id = rounds.id AND d.day_date >= ?)',
            [$now, $today]
        );

        // 5. Rate-Limit-Einträge nach spätestens 24 Stunden löschen
        $stats['rate_limits_deleted'] = Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);

        // 6. Versandprotokoll der Zusammenfassungen (enthält keine Inhalte) nach 60 Tagen löschen
        $stats['summary_log_deleted'] = Db::exec('DELETE FROM sent_summaries WHERE sent_at < ?', [Time::now()->modify('-60 days')->format('Y-m-d H:i:s')]);

        // 7. Deaktivierte Lehrkraft-Konten ohne Runden nach 15 Monaten ohne Anmeldung löschen
        //    (Name, E-Mail, letzte Anmeldung werden dann nicht mehr gebraucht)
        $stats['accounts_deleted'] = Db::exec(
            "DELETE FROM users WHERE role <> 'admin' AND is_active = 0
                AND COALESCE(last_login_at, created_at) < ?
                AND NOT EXISTS (SELECT 1 FROM rounds r WHERE r.user_id = users.id)",
            [Time::now()->modify('-15 months')->format('Y-m-d H:i:s')]
        );

        Settings::set('last_cleanup', (string) time());
        return $stats;
    }
}
