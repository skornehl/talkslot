<?php
declare(strict_types=1);

namespace App;

/**
 * "Bei Fragen": how parents can reach someone when the booking page leaves them stuck (no free appointment,
 * deadline passed, link not found), e.g. "Sekretariat, Tel. 01234 5678 (Mo–Fr 8–12 Uhr)" (expert review P2-1).
 *
 * Each account can give its own contact in "Mein Profil" (users.parent_contact); the admins set one for all
 * under "Texte", which applies to accounts without their own and to pages that belong to no round (link not
 * found). Free text in one line, shown as it is (not translated: names, phone numbers, addresses).
 */
final class ParentContact
{
    public const MAX_LENGTH = 300;
    private const SETTING = 'parent_contact';

    /** The contact for all (set by the admins); '' = none. */
    public static function general(): string
    {
        return self::clean((string) Settings::get(self::SETTING, ''));
    }

    /** The contact for a teacher's pages: the account's own, otherwise the one for all ('' = none). */
    public static function forTeacher(?string $own): string
    {
        $own = self::clean((string) $own);
        return $own !== '' ? $own : self::general();
    }

    public static function saveGeneral(string $contact): void
    {
        Settings::set(self::SETTING, self::clean($contact));
    }

    /** One line: control characters and line breaks become spaces, runs of spaces one. */
    public static function clean(string $contact): string
    {
        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $contact));
    }
}
