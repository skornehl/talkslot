<?php
declare(strict_types=1);

namespace App;

/**
 * Mehrsprachigkeit über Sprachdateien in app/lang/<code>.php.
 * Eine neue Sprache = neue Datei + Eintrag in config "languages".
 * Die Sprache wird ohne Cookie bestimmt: ?lang=…, sonst immer die Standardsprache (Deutsch),
 * unabhängig von der Browsersprache.
 */
final class I18n
{
    private static string $lang = 'de';
    private static bool $explicit = false;
    private static array $strings = [];
    private static array $fallback = [];
    /** @var array<string, string> Sprachnamen je Code (der Sprachumschalter fragt alle Sprachen ab) */
    private static array $names = [];

    /** @return list<string> */
    public static function languages(): array
    {
        return array_values((array) Config::get('languages', ['de']));
    }

    public static function defaultLanguage(): string
    {
        return (string) Config::get('default_language', self::languages()[0] ?? 'de');
    }

    public static function init(?string $requested): void
    {
        $langs = self::languages();
        if ($requested !== null && in_array($requested, $langs, true)) {
            self::$explicit = true;
            self::set($requested);
            return;
        }
        self::set(self::defaultLanguage());
    }

    public static function set(string $lang): void
    {
        if (!in_array($lang, self::languages(), true)) {
            $lang = self::defaultLanguage();
        }
        self::$lang = $lang;
        self::$strings = self::load($lang);
        self::$fallback = $lang === self::defaultLanguage() ? [] : self::load(self::defaultLanguage());
    }

    /** Führt $fn in einer anderen Sprache aus (z. B. für Mails an Eltern). */
    public static function with(string $lang, callable $fn): mixed
    {
        $prev = [self::$lang, self::$strings, self::$fallback];
        self::set($lang);
        try {
            return $fn();
        } finally {
            [self::$lang, self::$strings, self::$fallback] = $prev;
        }
    }

    /** Name einer Sprache in dieser Sprache selbst (z. B. „Polski“). */
    public static function name(string $code): string
    {
        return self::$names[$code] ??= (string) (self::load($code)['lang.name'] ?? strtoupper($code));
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function explicit(): bool
    {
        return self::$explicit;
    }

    public static function t(string $key, array $vars = []): string
    {
        $s = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        if (!is_string($s)) {
            return $key;
        }
        // In einem Durchgang ersetzen: Werte (z. B. ein Name mit „{link}“) werden nicht erneut ersetzt
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return $map ? strtr($s, $map) : $s;
    }

    public static function raw(string $key): mixed
    {
        return self::$strings[$key] ?? self::$fallback[$key] ?? null;
    }

    private static function load(string $lang): array
    {
        $file = APP_ROOT . '/app/lang/' . basename($lang) . '.php';
        return is_file($file) ? (array) require $file : [];
    }
}
