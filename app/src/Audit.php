<?php
declare(strict_types=1);

namespace App;

/**
 * Log of the changes made by admin accounts: who changed what and when. With several admins this
 * makes it traceable who e.g. moved a round to another teacher or changed a role. Searches for a request for
 * access are entered as well (who, when, how many results; AccessRequest).
 *
 * Only actions of admins are recorded, not those of teachers in their own rounds (no monitoring
 * of the teaching staff), plus changes the application makes itself on an update (system()).
 * Entries contain no names or e-mail addresses of parents; a booking appears with its date only (e.g.
 * "Buchung am 12.11.2026 gelöscht"), which makes it harder to match to a person. Entries are deleted after
 * audit_log_days (default 90, see Cleanup).
 */
final class Audit
{
    public static function log(string $message): void
    {
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') {
            return;
        }
        Db::run(
            'INSERT INTO audit_log (created_at, user_id, actor, message) VALUES (?, ?, ?, ?)',
            [Time::nowSql(), (int) $user['id'], $user['display_name'] !== '' ? $user['display_name'] : $user['username'], mb_substr($message, 0, 1000)]
        );
    }

    /** Name in the log for changes the application made itself, e.g. on an update (no account). */
    public const SYSTEM = 'TalkSlot';

    /**
     * Logs a change the application made itself, not an admin, e.g. the operator type taken over on an update
     * (accountability, Art. 5(2) GDPR). $message is written in the default language of the admin area.
     */
    public static function system(\Closure $message): void
    {
        Db::run(
            'INSERT INTO audit_log (created_at, user_id, actor, message) VALUES (?, NULL, ?, ?)',
            [Time::nowSql(), self::SYSTEM, mb_substr(AdminI18n::with(AdminI18n::default(), $message), 0, 1000)]
        );
    }

    public static function retentionDays(): int
    {
        return max(1, (int) Config::get('audit_log_days', 90));
    }
}
