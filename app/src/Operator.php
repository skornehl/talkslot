<?php
declare(strict_types=1);

namespace App;

/**
 * Operator of the platform (usually the school), represented by the admin accounts.
 *
 * Under data protection law the operator is the controller for all rounds. Its details
 * ("Angaben für Impressum und Datenschutz", maintained by any admin under "Texte")
 * replace the placeholder [[VERANTWORTLICHE]] in the legal notice and privacy policy.
 * Without these details no rounds are publicly reachable.
 */
final class Operator
{
    public const PLACEHOLDER = '[[VERANTWORTLICHE]]';
    private const SETTING = 'controller_contact';

    /** SQL condition for public queries: the same check as ready(). */
    public static function readySql(): string
    {
        return self::ready() ? '1 = 1' : '1 = 0';
    }

    private static ?array $admin = null;
    private static bool $loaded = false;
    private static ?string $contact = null;

    /** The oldest active admin account (recipient of error reports if no alert_email is configured). */
    public static function admin(): ?array
    {
        if (!self::$loaded) {
            self::$admin = Db::one("SELECT * FROM users WHERE role = 'admin' ORDER BY is_active DESC, id LIMIT 1");
            self::$loaded = true;
        }
        return self::$admin;
    }

    /** Details for legal notice and privacy policy ('' = missing). */
    public static function contact(): string
    {
        if (self::$contact === null) {
            $value = Settings::get(self::SETTING);
            if ($value === null) {
                // Not migrated yet (migration 13 runs on the next request): still in the admin's profile
                $admin = self::admin();
                $value = $admin && $admin['is_active'] ? (string) ($admin['controller_contact'] ?? '') : '';
            }
            self::$contact = trim($value);
        }
        return self::$contact;
    }

    /**
     * Stores the details. They are also written to the oldest active admin account, where versions
     * before multiple admins read them – so a rollback to such a version keeps the rounds online.
     */
    public static function save(string $contact): void
    {
        $contact = trim(str_replace("\r\n", "\n", $contact));
        Settings::set(self::SETTING, $contact);
        $admin = self::admin();
        if ($admin) {
            Db::run('UPDATE users SET controller_contact = ? WHERE id = ?', [$contact !== '' ? $contact : null, $admin['id']]);
        }
        self::forget();
    }

    public static function ready(): bool
    {
        return self::contact() !== '';
    }

    /** Name of the controller (first line of the details), e.g. for the mail footer. */
    public static function name(): string
    {
        foreach (explode("\n", self::contact()) as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }
        return '';
    }

    /** Inserts the details into a page text (if missing: notice $fallback). */
    public static function fill(string $content, string $fallback): string
    {
        $contact = self::contact();
        return str_replace(self::PLACEHOLDER, $contact !== '' ? $contact : $fallback, $content);
    }

    /** Reload after changes within the same request. */
    public static function forget(): void
    {
        self::$admin = null;
        self::$loaded = false;
        self::$contact = null;
    }
}
