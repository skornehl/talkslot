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
 *
 * Besides the text an entry can carry what it is about in a structured form (expert review P2-10, ARCH-14): the
 * kind of change (action), the round and a few values (params). Only the keys in PARAM_KEYS are stored, so
 * that no name or address of parents can get in there (a unit test checks the list).
 */
final class Audit
{
    /** What the params of an entry may hold: numbers and dates of the change, nothing about parents. */
    public const PARAM_KEYS = ['count', 'date', 'reached'];

    public static function log(string $message, ?string $action = null, ?int $roundId = null, array $params = []): void
    {
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') {
            return;
        }
        Db::run(
            'INSERT INTO audit_log (created_at, user_id, actor, message, action, round_id, params) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Time::nowSql(), (int) $user['id'], $user['display_name'] !== '' ? $user['display_name'] : $user['username'], mb_substr($message, 0, 1000),
             $action, $roundId, self::params($params)]
        );
    }

    /** The params as stored: only the allowed keys with plain values, as JSON (null: none). */
    public static function params(array $params): ?string
    {
        $kept = array_filter(array_intersect_key($params, array_flip(self::PARAM_KEYS)), 'is_scalar');
        return $kept ? json_encode($kept, JSON_THROW_ON_ERROR) : null;
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
