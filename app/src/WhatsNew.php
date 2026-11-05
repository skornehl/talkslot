<?php
declare(strict_types=1);

namespace App;

/**
 * "Was ist neu" (expert review P2-13): after an update, admins and teachers see on their overview what changed in
 * how they work, so that a changed default does not look like an error. Each account confirms the notes once.
 *
 * A note is added here with a key of its date and a letter (keys sort as text, newest last), the German text in
 * __() (English in app/lang/admin/en/) and, if it helps, the page it is about. Only changes that admins or teachers
 * notice belong here, not every fix. No migration needed: everything is in the settings table.
 * - "news_arrived": when each note reached this installation (the first overview after the update). A new
 *   installation marks the notes it comes with as arrived at 0 (Installer), so nobody sees them.
 * - "news_seen:<account id>": the newest note the account has confirmed.
 * An account sees the notes that arrived after it was created and that it has not confirmed.
 */
final class WhatsNew
{
    private const ARRIVED = 'news_arrived';

    /** @return list<array{key: string, admin: bool, text: string, link: ?string}> oldest first; admin = only for admins */
    public static function entries(): array
    {
        return [
            ['key' => '2026-11-05a', 'admin' => true, 'link' => '/admin/texts',
                'text' => __('Ändert ein Update die Vorlage für Impressum oder Datenschutzerklärung, steht unter „Texte“ beim Text „Vorlage geändert“. Beim Bearbeiten sehen Sie die geänderten Abschnitte und können sie mit „Abschnitte aus der Vorlage übernehmen“ einfügen. Ihre eigenen Änderungen werden nie automatisch überschrieben.')],
            ['key' => '2026-11-05b', 'admin' => false, 'link' => '/admin/profile',
                'text' => __('Unter „Mein Profil“ können Sie eine eigene Antwortadresse für Eltern angeben, z. B. Ihre dienstliche Adresse. Antworten der Eltern auf E-Mails zu ihrem Termin gehen dann dorthin statt an Ihre E-Mail-Adresse.')],
            ['key' => '2026-11-05c', 'admin' => true, 'link' => '/admin/start',
                'text' => __('„Bereit für den Start?“ zeigt auf einer Seite, ob Impressum und Datenschutzerklärung, E-Mail-Versand, Cronjob und Server bereit sind.')],
        ];
    }

    public static function latest(): string
    {
        $keys = array_column(self::entries(), 'key');
        return $keys ? max($keys) : '';
    }

    /**
     * The notes an account has not confirmed yet and that arrived after it was created.
     * @param array{id: int|string, role?: string, created_at?: string} $user
     * @return list<array{key: string, admin: bool, text: string, link: ?string}>
     */
    public static function unseen(array $user): array
    {
        if (!isset($user['id'])) {
            return [];
        }
        return self::visible(self::entries(), (string) Settings::get(self::seenSetting((int) $user['id']), ''), self::arrivals(),
            strtotime((string) ($user['created_at'] ?? '')) ?: 0, ($user['role'] ?? '') === 'admin');
    }

    /**
     * Which notes to show (no database, unit-tested): newer than the confirmed one, arrived after the account was
     * created, for admins all, for teachers only theirs.
     * @param list<array{key: string, admin: bool, text: string, link: ?string}> $entries
     * @param array<string, int> $arrived note key => when it reached this installation
     * @return list<array{key: string, admin: bool, text: string, link: ?string}>
     */
    public static function visible(array $entries, string $seen, array $arrived, int $created, bool $admin): array
    {
        return array_values(array_filter($entries, static fn (array $e): bool => $e['key'] > $seen
            && ($arrived[$e['key']] ?? PHP_INT_MAX) > $created && ($admin || !$e['admin'])));
    }

    /** When each note arrived; notes new to this installation arrive now. */
    private static function arrivals(): array
    {
        $arrived = json_decode((string) Settings::get(self::ARRIVED, '{}'), true);
        $arrived = is_array($arrived) ? array_map('intval', $arrived) : [];
        $new = array_diff(array_column(self::entries(), 'key'), array_keys($arrived));
        if ($new) {
            $arrived += array_fill_keys($new, time());
            Settings::set(self::ARRIVED, (string) json_encode($arrived));
        }
        return $arrived;
    }

    /** New installation: the notes it comes with are nothing new for anybody. */
    public static function installed(): void
    {
        Settings::set(self::ARRIVED, (string) json_encode(array_fill_keys(array_column(self::entries(), 'key'), 0)));
    }

    /** The account has read the notes; the confirmations of accounts deleted meanwhile (also by the clean-up) go. */
    public static function markSeen(int $userId): void
    {
        Settings::set(self::seenSetting($userId), self::latest());
        Db::run("DELETE FROM settings WHERE name LIKE 'news_seen:%' AND CAST(SUBSTRING(name, 11) AS UNSIGNED) NOT IN (SELECT id FROM users)");
    }

    /** When an account is deleted. */
    public static function forget(int $userId): void
    {
        Db::run('DELETE FROM settings WHERE name = ?', [self::seenSetting($userId)]);
    }

    private static function seenSetting(int $userId): string
    {
        return 'news_seen:' . $userId;
    }
}
