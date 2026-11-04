<?php
declare(strict_types=1);

namespace App;

/**
 * Multilingual support via language files in app/lang/<code>.php.
 * A new language = new file + entry in config "languages".
 * The language is determined without a cookie: ?lang=…, otherwise always the default language (German).
 * The browser language (Accept-Language) never switches the page by itself; it only decides whether a
 * link to the page in that language is offered at the top (suggested()).
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
        // A default that is not among the offered languages falls back to the first one
        $default = (string) Config::get('default_language', '');
        $languages = self::languages();
        return in_array($default, $languages, true) ? $default : (string) ($languages[0] ?? 'de');
    }

    public static function init(?string $requested): void
    {
        $langs = self::languages();
        self::$explicit = false;
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

    /**
     * Offered language the browser prefers (Accept-Language, highest q wins, the first one on a tie), if the
     * page is shown in another one because the visitor has not chosen yet; otherwise null. Only used for a
     * link at the top of the page: nothing is stored, and the page never switches on its own.
     */
    public static function suggested(): ?string
    {
        if (self::$explicit) {
            return null;
        }
        $best = null;
        $bestQ = 0.0;
        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $range) {
            // e.g. "tr-TR", "uk;q=0.8"; "*" and malformed ranges are skipped, q=0 means "not wanted"
            if (!preg_match('/^\s*([a-z]{2,3})(?:-[a-z0-9]{1,8})*\s*(?:;\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?))?\s*$/i', $range, $m)) {
                continue;
            }
            $code = strtolower($m[1]);
            $q = isset($m[2]) ? (float) $m[2] : 1.0;
            if ($q > $bestQ && in_array($code, self::languages(), true)) {
                [$best, $bestQ] = [$code, $q];
            }
        }
        return $best !== null && $best !== self::$lang ? $best : null;
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

    /**
     * Text of a parent page in a given language, even one not offered to parents (e.g. the admin language
     * for the invitation text); missing there → default language → key. Without placeholders.
     */
    public static function textIn(string $lang, string $key): string
    {
        $s = self::load($lang)[$key] ?? self::load(self::defaultLanguage())[$key] ?? $key;
        return is_string($s) ? $s : $key;
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
