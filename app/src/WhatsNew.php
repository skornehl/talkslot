<?php
declare(strict_types=1);

namespace App;

/**
 * "Was ist neu" (expert review P2-13): after an update, admins and teachers see on their overview what changed in
 * how they work, so that a changed default does not look like an error. Each account confirms the notes once.
 *
 * A note is added here with a key of its date and a letter (keys sort as text, newest last) and the German text
 * in __() (English in app/lang/admin/en/). Only changes that admins or teachers notice belong here, not every fix.
 * An account sees the notes newer than the one it confirmed last (setting "news_seen:<id>"), but none from before
 * the day it was created: for a new account nothing has changed. No migration needed.
 */
final class WhatsNew
{
    /** @return list<array{key: string, admin: bool, text: string}> oldest first; admin = only for admins */
    public static function entries(): array
    {
        return [
            ['key' => '2026-11-05a', 'admin' => true, 'text' => __('Impressum und Datenschutzerklärung: Ändert ein Update eine Vorlage, zeigt „Texte“ beim jeweiligen Text, welche Abschnitte sich geändert haben, und Sie können sie übernehmen. Bearbeitete Texte werden weiterhin nie automatisch ersetzt.')],
            ['key' => '2026-11-05b', 'admin' => false, 'text' => __('Unter „Mein Profil“ können Sie eine eigene Antwortadresse für Eltern angeben, z. B. Ihre dienstliche Adresse. Antworten der Eltern auf E-Mails zu ihrem Termin gehen dann dorthin statt an Ihre E-Mail-Adresse.')],
            ['key' => '2026-11-05c', 'admin' => true, 'text' => __('„Bereit für den Start?“ zeigt auf einer Seite, ob Rechtstexte, E-Mail-Versand, Cronjob und Server bereit sind; Sie finden die Seite unter „Texte“.')],
        ];
    }

    public static function latest(): string
    {
        $keys = array_column(self::entries(), 'key');
        return $keys ? max($keys) : '';
    }

    /**
     * The notes an account has not confirmed yet, without those from before it was created.
     * @param array{id: int|string, role?: string, created_at?: string} $user
     * @return list<string>
     */
    public static function unseen(array $user): array
    {
        $seen = (string) Settings::get(self::setting((int) $user['id']), '');
        $created = substr((string) ($user['created_at'] ?? ''), 0, 10);
        $admin = ($user['role'] ?? '') === 'admin';
        return array_values(array_column(array_filter(self::entries(), static fn (array $e): bool => $e['key'] > $seen
            && substr($e['key'], 0, 10) >= $created && ($admin || !$e['admin'])), 'text'));
    }

    public static function markSeen(int $userId): void
    {
        Settings::set(self::setting($userId), self::latest());
    }

    /** When an account is deleted. */
    public static function forget(int $userId): void
    {
        Db::run('DELETE FROM settings WHERE name = ?', [self::setting($userId)]);
    }

    private static function setting(int $userId): string
    {
        return 'news_seen:' . $userId;
    }
}
