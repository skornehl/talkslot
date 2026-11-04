<?php
declare(strict_types=1);

namespace App;

/**
 * Log of the changes made by admin accounts: who changed what and when. With several admins this
 * makes it traceable who e.g. moved a round to another teacher or changed a role.
 *
 * Only actions of admins are recorded, not those of teachers in their own rounds (no monitoring
 * of the teaching staff). Entries contain no data of parents (e.g. "Buchung 12.11.2026 14:00
 * gelöscht", without the name) and are deleted after audit_log_days (default 90, see Cleanup).
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

    public static function retentionDays(): int
    {
        return max(1, (int) Config::get('audit_log_days', 90));
    }
}
