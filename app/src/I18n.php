<?php
declare(strict_types=1);

namespace App;

/**
 * Multilingual support via language files in app/lang/<code>.php.
 * A new language = new file + entry in config "languages".
 * The language is determined without a cookie: ?lang=…, otherwise always the default language (German),
 * regardless of the browser language.
 */
final class I18n
{
    private static string $lang = 'de';
    private static bool $explicit = false;
    private static array $strings = [];
    private static array $fallback = [];
    /** @var array<string, string> Language names per code (the language switcher queries all languages) */
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

    /** Runs $fn in another language (e.g. for emails to parents). */
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

    /** Name of a language in that language itself (e.g. "Polski"). */
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
        // Replace in a single pass: values (e.g. a name containing "{link}") are not replaced again
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
