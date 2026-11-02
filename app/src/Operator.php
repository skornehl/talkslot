<?php
declare(strict_types=1);

namespace App;

/**
 * Betreiber der Plattform = das (einzige) Admin-Konto.
 *
 * Der Admin ist datenschutzrechtlich verantwortlich für alle Terminrunden; seine Angaben
 * („Angaben für Impressum und Datenschutz“ im Profil, Spalte users.controller_contact)
 * ersetzen in Impressum und Datenschutzerklärung den Platzhalter [[VERANTWORTLICHE]].
 * Ohne diese Angaben sind keine Runden öffentlich erreichbar.
 */
final class Operator
{
    public const PLACEHOLDER = '[[VERANTWORTLICHE]]';

    /** SQL-Bedingung für öffentliche Abfragen: dieselbe Prüfung wie ready() (dasselbe Konto). */
    public static function readySql(): string
    {
        return self::ready() ? '1 = 1' : '1 = 0';
    }

    private static ?array $admin = null;
    private static bool $loaded = false;

    /** Das Admin-Konto (bei mehreren ältere Stände: das älteste aktive). */
    public static function admin(): ?array
    {
        if (!self::$loaded) {
            self::$admin = Db::one("SELECT * FROM users WHERE role = 'admin' ORDER BY is_active DESC, id LIMIT 1");
            self::$loaded = true;
        }
        return self::$admin;
    }

    /** Angaben für Impressum und Datenschutz ('' = fehlen). */
    public static function contact(): string
    {
        $admin = self::admin();
        return $admin && $admin['is_active'] ? trim((string) ($admin['controller_contact'] ?? '')) : '';
    }

    public static function ready(): bool
    {
        return self::contact() !== '';
    }

    /** Name der verantwortlichen Stelle (erste Zeile der Angaben), z. B. für den Mail-Footer. */
    public static function name(): string
    {
        foreach (explode("\n", self::contact()) as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }
        return '';
    }

    /** Setzt die Angaben in einen Seitentext ein (fehlen sie: Hinweis $fallback). */
    public static function fill(string $content, string $fallback): string
    {
        $contact = self::contact();
        return str_replace(self::PLACEHOLDER, $contact !== '' ? $contact : $fallback, $content);
    }

    /** Nach Änderungen am Admin-Konto im selben Request neu laden. */
    public static function forget(): void
    {
        self::$admin = null;
        self::$loaded = false;
    }
}
