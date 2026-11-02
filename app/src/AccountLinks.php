<?php
declare(strict_types=1);

namespace App;

/**
 * Einladungs- und Passwort-Links für Konten.
 * Statt eines Passworts erhält die Person einen einmaligen Link (256-Bit-Token, in der
 * Datenbank nur als SHA-256-Hash, standardmäßig 48 Stunden gültig) und legt ihr
 * Passwort selbst fest. Ein neuer Link macht den vorherigen ungültig.
 */
final class AccountLinks
{
    public const INVITE = 'invite';
    public const RESET = 'reset';

    public static function validHours(): int
    {
        return max(1, (int) Config::get('access_link_hours', 48));
    }

    /** Erzeugt einen neuen Link-Token für das Konto und liefert die vollständige URL. */
    public static function issue(int $userId): string
    {
        [$plain, $hash] = Token::generate();
        Db::run(
            'UPDATE users SET access_token_hash = ?, access_token_expires_at = ? WHERE id = ?',
            [$hash, Time::now()->modify('+' . self::validHours() . ' hours')->format('Y-m-d H:i:s'), $userId]
        );
        return abs_url('/admin/invite/' . $plain, ['lang' => null]);
    }

    /** Konto zu einem gültigen, nicht abgelaufenen Token (nur aktive Konten). */
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

    /** Setzt das Passwort und entwertet den Link. */
    public static function consume(int $userId, string $password): void
    {
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, access_token_hash = NULL, access_token_expires_at = NULL WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $userId]
        );
        Auth::endOtherSessions($userId, false);
    }

    /** Wurde für das Konto vor weniger als $minutes Minuten ein noch gültiger Link ausgestellt? */
    public static function recentlyIssued(array $user, int $minutes = 15): bool
    {
        $expires = (string) ($user['access_token_expires_at'] ?? '');
        if ($user['access_token_hash'] === null || $expires === '') {
            return false;
        }
        $issued = (new \DateTimeImmutable($expires))->modify('-' . self::validHours() . ' hours');
        return $issued > Time::now()->modify('-' . $minutes . ' minutes');
    }

    /** Ein Passwort-Hash, mit dem man sich nicht anmelden kann (für Konten vor der Einladung). */
    public static function unusablePasswordHash(): string
    {
        return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    /**
     * Verschickt den Link per Mail.
     * @return array{0:bool,1:string} [versendet, link]
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
