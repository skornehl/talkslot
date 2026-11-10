<?php
declare(strict_types=1);

namespace App;

/**
 * Security events of an account, for the account holder only (expert review P2-9, SEC-5/DS-10): traceability
 * after an incident without a means to monitor the staff.
 *
 * - Single events only for the password (changed, set through a link, reset requested or done by an admin),
 *   the e-mail address, admin rights, and the sign-in limit reached by strangers. Sign-ins
 *   themselves are not recorded, failed ones only as the existing counter (users.failed_logins, shown to the
 *   holder after signing in as the number since the previous sign-in). No IP address, no device, and of an
 *   admin's change only that an admin made it.
 * - Only the account holder sees them ("Mein Profil"); there is no page for admins. Admins get an alarm when the
 *   sign-in limit of several accounts is reached within a short time (Auth), without names.
 * - Deleted after security_events_days (default 30, at most 90, as agreed with the staff council), see
 *   Cleanup, and with the account.
 */
final class SecurityEvents
{
    public const PASSWORD_CHANGED = 'password_changed';
    public const PASSWORD_SET = 'password_set';
    public const RESET_REQUESTED = 'reset_requested';
    public const RESET_BY_ADMIN = 'reset_by_admin';
    public const EMAIL_CHANGED = 'email_changed';
    public const ADMIN_GRANTED = 'admin_granted';
    public const ADMIN_REVOKED = 'admin_revoked';
    public const LOGIN_LIMIT = 'login_limit';

    /** $at: when it happened, if it is recorded later (from the outbox). */
    public static function record(int $userId, string $event, bool $byAdmin = false, ?string $at = null): void
    {
        Db::run(
            'INSERT INTO security_events (user_id, event, by_admin, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $event, $byAdmin ? 1 : 0, $at ?? Time::nowSql()]
        );
    }

    /**
     * A reset was requested, at most one entry a day: strangers can repeat the request, and their entries must
     * not push the ones that matter off the list.
     */
    public static function resetRequested(int $userId): void
    {
        $seen = Db::value('SELECT 1 FROM security_events WHERE user_id = ? AND event = ? AND created_at >= ? LIMIT 1',
            [$userId, self::RESET_REQUESTED, Time::today() . ' 00:00:00']);
        if ($seen === null) {
            self::record($userId, self::RESET_REQUESTED);
        }
    }

    /** The account's events within the retention period, newest first. @return list<array{event:string,by_admin:int,created_at:string}> */
    public static function of(int $userId): array
    {
        return Db::all(
            'SELECT event, by_admin, created_at FROM security_events WHERE user_id = ? AND created_at >= ? ORDER BY created_at DESC, id DESC LIMIT 100',
            [$userId, self::cutoff()]
        );
    }

    /** What happened, for the account holder (admin language). */
    public static function label(string $event, bool $byAdmin): string
    {
        $text = match ($event) {
            self::PASSWORD_CHANGED => __('Passwort geändert'),
            self::PASSWORD_SET => __('Passwort über einen Link aus einer E-Mail festgelegt'),
            self::RESET_REQUESTED => __('Link zum Zurücksetzen des Passworts angefordert („Passwort vergessen?“)'),
            self::RESET_BY_ADMIN => __('Passwort zurückgesetzt'),
            self::EMAIL_CHANGED => __('E-Mail-Adresse geändert'),
            self::ADMIN_GRANTED => __('Admin-Rechte erhalten'),
            self::ADMIN_REVOKED => __('Admin-Rechte entzogen'),
            self::LOGIN_LIMIT => __('Viele fehlgeschlagene Anmeldeversuche: Anmeldung eine Weile nur auf bekannten Geräten'),
            default => $event,
        };
        return $byAdmin ? __('{event} (durch einen Admin)', ['event' => $text]) : $text;
    }

    public static function retentionDays(): int
    {
        return min(90, max(1, (int) Config::get('security_events_days', 30)));
    }

    /** Clean-up: events older than the retention period. */
    public static function expire(): int
    {
        return Db::exec('DELETE FROM security_events WHERE created_at < ?', [self::cutoff()]);
    }

    private static function cutoff(): string
    {
        return Time::now()->modify('-' . self::retentionDays() . ' days')->format('Y-m-d H:i:s');
    }
}
