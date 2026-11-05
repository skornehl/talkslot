<?php
declare(strict_types=1);

namespace App;

/**
 * "Was ist neu" (expert review P2-13): after an update, admins and teachers see on their overview what changed in
 * how they work, so that a changed default does not look like an error. Each account confirms the notes once
 * (users.news_seen: the key of the newest note it has seen).
 *
 * A note is added here with a key of its date and a letter (keys sort as text, newest last) and the German
 * text in __() (English in app/lang/admin/en/). Only changes that admins or teachers notice belong here, not
 * every fix. Accounts from before the notes (news_seen NULL) see them all; new accounts ('') none of the older
 * ones: their first overview marks them as seen.
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
     * The notes an account has not seen yet. A new account ('') sees none: the call marks them all as seen.
     * @return list<string>
     */
    public static function unseen(array $user): array
    {
        $seen = $user['news_seen'] ?? null;
        if ($seen === '') {
            self::markSeen((int) $user['id']);
            return [];
        }
        $admin = ($user['role'] ?? '') === 'admin';
        return array_values(array_column(array_filter(self::entries(),
            static fn (array $e): bool => $e['key'] > (string) $seen && ($admin || !$e['admin'])), 'text'));
    }

    public static function markSeen(int $userId): void
    {
        Db::run('UPDATE users SET news_seen = ? WHERE id = ?', [self::latest(), $userId]);
    }
}
