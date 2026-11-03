<?php
declare(strict_types=1);

namespace App;

/**
 * Operator of the platform = the (only) admin account.
 *
 * Under data protection law the admin is the controller for all rounds; their details
 * ("Angaben für Impressum und Datenschutz" in the profile, column users.controller_contact)
 * replace the placeholder [[VERANTWORTLICHE]] in the legal notice and privacy policy.
 * Without these details no rounds are publicly reachable.
 */
final class Operator
{
    public const PLACEHOLDER = '[[VERANTWORTLICHE]]';

    /** SQL condition for public queries: the same check as ready() (the same account). */
    public static function readySql(): string
    {
        return self::ready() ? '1 = 1' : '1 = 0';
    }

    private static ?array $admin = null;
    private static bool $loaded = false;

    /** The admin account (if there are several from older versions: the oldest active one). */
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
        $admin = self::admin();
        return $admin && $admin['is_active'] ? trim((string) ($admin['controller_contact'] ?? '')) : '';
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

    /** Reload after changes to the admin account within the same request. */
    public static function forget(): void
    {
        self::$admin = null;
        self::$loaded = false;
    }
}
