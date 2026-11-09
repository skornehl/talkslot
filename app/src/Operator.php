<?php
declare(strict_types=1);

namespace App;

/**
 * Operator of the platform, represented by the admin accounts.
 *
 * Under data protection law the operator is the controller for all rounds. Its details
 * ("Angaben für Impressum und Datenschutz", maintained by any admin under "Texte")
 * replace the placeholder [[VERANTWORTLICHE]] in the legal notice and privacy policy.
 * Without these details no rounds are publicly reachable.
 *
 * The operator type says who that is: the school itself as a public body ("school") or a private
 * person, association or independent provider whose service teachers use ("private"). It decides the
 * templates of legal notice and privacy policy and the notes on them (LegalTexts).
 */
final class Operator
{
    public const PLACEHOLDER = '[[VERANTWORTLICHE]]';
    /** Operator types, in the order offered */
    public const TYPES = ['school', 'private'];
    private const SETTING = 'controller_contact';
    private const TYPE_SETTING = 'operator_type';
    /** '1' while the type was only taken over on an update (Migrations::refreshPages) and no admin has saved it */
    private const TYPE_AUTO_SETTING = 'operator_type_auto';

    /** SQL condition for public queries: the same check as ready(). */
    public static function readySql(): string
    {
        return self::ready() ? '1 = 1' : '1 = 0';
    }

    private static ?array $admin = null;
    private static bool $loaded = false;
    private static ?string $contact = null;
    private static ?string $type = null;
    private static bool $typeLoaded = false;

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
                // Not migrated yet (migration 13 runs on the next request): still in the admin's profile. Goes
                // with 2.0, which requires v1.3.0 or newer (upgrade path, docs/guide/operations.md)
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

    /** Operator type ("school" or "private"); null = not chosen yet (installations from before the choice). */
    public static function type(): ?string
    {
        if (!self::$typeLoaded) {
            $type = Settings::get(self::TYPE_SETTING);
            self::$type = in_array($type, self::TYPES, true) ? $type : null;
            self::$typeLoaded = true;
        }
        return self::$type;
    }

    /** Stores the operator type; $auto: taken over on an update, not chosen by an admin (yet). */
    public static function saveType(string $type, bool $auto = false): void
    {
        Settings::set(self::TYPE_SETTING, $type);
        Settings::set(self::TYPE_AUTO_SETTING, $auto ? '1' : null);
        self::forget();
    }

    /** true while the operator type was only taken over on an update and no admin has confirmed it. */
    public static function typeAuto(): bool
    {
        return self::type() !== null && Settings::get(self::TYPE_AUTO_SETTING) === '1';
    }

    /** Name of an operator type as admins choose it (installer, "Texte"). */
    public static function typeLabel(string $type): string
    {
        return $type === 'private'
            ? __('Wird privat betrieben (Privatperson, Verein, freier Träger)')
            : __('Wird von der Schule betrieben (öffentliche Stelle)');
    }

    /** Reload after changes within the same request. */
    public static function forget(): void
    {
        self::$admin = null;
        self::$loaded = false;
        self::$contact = null;
        self::$type = null;
        self::$typeLoaded = false;
    }
}
