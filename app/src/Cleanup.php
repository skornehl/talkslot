<?php
declare(strict_types=1);

namespace App;

/**
 * Automatic data deletion.
 * Runs via cron job (bin/cron.php) and additionally as a fallback on page requests.
 * Mails are not sent here: summaries and reminders are only queued (see Outbox).
 */
final class Cleanup
{
    /**
     * Fallback: run at most every N minutes on a page request. Database steps only (deleting, queueing
     * summaries and reminders); the visitor never waits for the mail server.
     */
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
        // Atomically "reserve" so that parallel requests do not clean up twice
        $claimed = Db::exec('UPDATE settings SET value = ? WHERE name = ? AND value = ?', [(string) time(), 'last_cleanup', (string) $last]);
        if ($claimed === 0) {
            if (Settings::get('last_cleanup') !== null) {
                return;
            }
            Settings::set('last_cleanup', (string) time());
        }
        self::run();
        Summaries::run();
        Reminders::run();
    }

    /** @return array<string,int> number of deleted/changed records per step */
    public static function run(): array
    {
        $today = Time::today();
        $now = Time::nowSql();
        $stats = [];

        // 1. Delete bookings of past days (from 00:00 of the following day)
        $stats['bookings_deleted'] = Db::exec(
            'DELETE b FROM bookings b
               JOIN slots s ON s.id = b.slot_id
               JOIN round_days d ON d.id = s.day_id
              WHERE d.day_date < ?',
            [$today]
        );

        // 2. Remove conflict slots without a booking
        $stats['conflict_slots_deleted'] = Db::exec(
            'DELETE s FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
              WHERE s.is_conflict = 1 AND b.id IS NULL'
        );

        // 3. Deactivate rounds whose last day is over and mark them as expired
        $stats['rounds_expired'] = Db::exec(
            'UPDATE rounds r SET r.is_active = 0, r.expired_at = ?
              WHERE r.expired_at IS NULL
                AND EXISTS (SELECT 1 FROM round_days d WHERE d.round_id = r.id)
                AND NOT EXISTS (SELECT 1 FROM round_days d WHERE d.round_id = r.id AND d.day_date >= ?)',
            [$now, $today]
        );

        // 4. Delete expired rounds after the configured period (NULL = never)
        $stats['rounds_deleted'] = Db::exec(
            'DELETE FROM rounds
              WHERE expired_at IS NOT NULL AND delete_after_hours IS NOT NULL
                AND expired_at <= DATE_SUB(?, INTERVAL delete_after_hours HOUR)
                AND NOT EXISTS (SELECT 1 FROM round_days d WHERE d.round_id = rounds.id AND d.day_date >= ?)',
            [$now, $today]
        );

        // 5. Delete rate limit entries after at most 24 hours
        $stats['rate_limits_deleted'] = Db::exec('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);

        // 6. Delete the summary delivery log (contains no content) after 60 days
        $stats['summary_log_deleted'] = Db::exec('DELETE FROM sent_summaries WHERE sent_at < ?', [Time::now()->modify('-60 days')->format('Y-m-d H:i:s')]);

        // 7. Delete deactivated teacher accounts without rounds after 15 months without login
        //    (name, email, last login are no longer needed then)
        $stats['accounts_deleted'] = Db::exec(
            "DELETE FROM users WHERE role <> 'admin' AND is_active = 0
                AND COALESCE(last_login_at, created_at) < ?
                AND NOT EXISTS (SELECT 1 FROM rounds r WHERE r.user_id = users.id)",
            [Time::now()->modify('-15 months')->format('Y-m-d H:i:s')]
        );

        // 8. Delete entries of the admin log after audit_log_days (default 90)
        $stats['audit_log_deleted'] = Db::exec('DELETE FROM audit_log WHERE created_at < ?', [Time::now()->modify('-' . Audit::retentionDays() . ' days')->format('Y-m-d H:i:s')]);

        // 9. Give up mails in the outbox that could not be sent within 48 hours
        $stats['mails_expired'] = Outbox::expire();

        Settings::set('last_cleanup', (string) time());
        return $stats;
    }
}
