<?php
declare(strict_types=1);

namespace App;

/**
 * Invitation and password links for accounts.
 * Instead of a password, the person receives a single-use link (256-bit token, stored in the
 * database only as SHA-256 hash, valid for 48 hours by default) and sets their
 * password themselves. A new link invalidates the previous one.
 */
final class AccountLinks
{
    public const INVITE = 'invite';
    public const RESET = 'reset';

    public static function validHours(): int
    {
        return max(1, (int) Config::get('access_link_hours', 48));
    }

    /** Creates a new link token for the account and returns the full URL. */
    public static function issue(int $userId): string
    {
        [$plain, $hash] = Token::generate();
        Db::run(
            'UPDATE users SET access_token_hash = ?, access_token_expires_at = ? WHERE id = ?',
            [$hash, Time::now()->modify('+' . self::validHours() . ' hours')->format('Y-m-d H:i:s'), $userId]
        );
        return abs_url('/admin/invite/' . $plain, ['lang' => null]);
    }

    /** Account for a valid, unexpired token (active accounts only). */
    public static function findUser(string $plain): ?array
    {
        if (!Token::isWellFormed($plain)) {
            return null;
        }
        return Db::one(
            'SELECT * FROM users WHERE access_token_hash = ? AND access_token_expires_at > ? AND is_active = 1',
            [Token::hash($plain), Time::nowSql()]
        );
    }

    /** Sets the password and invalidates the link. */
    public static function consume(int $userId, string $password): void
    {
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, access_token_hash = NULL, access_token_expires_at = NULL WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId]
        );
        Auth::endOtherSessions($userId, false);
    }

    /** Was a still valid link issued for the account less than $minutes minutes ago? */
    public static function recentlyIssued(array $user, int $minutes = 15): bool
    {
        $expires = (string) ($user['access_token_expires_at'] ?? '');
        if ($user['access_token_hash'] === null || $expires === '') {
            return false;
        }
        $issued = (new \DateTimeImmutable($expires))->modify('-' . self::validHours() . ' hours');
        return $issued > Time::now()->modify('-' . $minutes . ' minutes');
    }

    /** A password hash that cannot be used to log in (for accounts before the invitation). */
    public static function unusablePasswordHash(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    /**
     * Sends the link by email.
     * @return array{0:bool,1:string} [sent, link]
     */
    public static function send(array $user, string $kind, ?array $sender = null): array
    {
        $link = self::issue((int) $user['id']);
        $until = Time::now()->modify('+' . self::validHours() . ' hours');
        $untilText = I18n::with('de', static fn () => fmt_date($until->format('Y-m-d'))) . ', ' . $until->format('H:i') . ' Uhr';
        if ($kind === self::INVITE) {
            $subject = 'Einladung zu ' . app_name();
            $lines = [
                'Hallo ' . $user['display_name'] . ',',
                '',
                'für Sie wurde ein Konto bei ' . app_name() . ' angelegt. Damit können Sie Gesprächstermine (z. B. Elternsprechtage) vergeben.',
                '',
                'Bitte legen Sie über diesen Link Ihr Passwort fest:',
                $link,
                '',
                'Der Link ist einmalig nutzbar und gültig bis ' . $untilText . '.',
                'Ihr Benutzername: ' . $user['username'],
                '',
                'Prüfen Sie danach unter „Mein Profil“ Ihren Anzeigenamen (so sehen Eltern Sie) und Ihre E-Mail-Adresse für Zusammenfassungen.',
            ];
        } else {
            $subject = 'Passwort festlegen für ' . app_name();
            $lines = [
                'Hallo ' . $user['display_name'] . ',',
                '',
                'für Ihr Konto bei ' . app_name() . ' wurde ein Link zum Festlegen eines neuen Passworts angefordert:',
                $link,
                '',
                'Der Link ist einmalig nutzbar und gültig bis ' . $untilText . '.',
                'Ihr Benutzername: ' . $user['username'],
                '',
                'Falls Sie das nicht angefordert haben, können Sie diese E-Mail ignorieren – Ihr bisheriges Passwort bleibt dann unverändert.',
            ];
        }
        $replyTo = $sender && filter_var((string) ($sender['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $sender['email'] : null;
        $sent = Mailer::send((string) $user['email'], $subject, implode("\n", $lines), [], $replyTo);
        return [$sent, $link];
    }
}
