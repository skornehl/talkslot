<?php
declare(strict_types=1);

namespace App;

/**
 * Language of the admin area (German or English).
 *
 * The German text is the key: __('Runde gespeichert.') returns it unchanged in German and looks
 * it up in app/lang/admin/en/*.php in English (missing → German). Placeholders like {name} are
 * replaced in both languages. tests/unit.php checks that every text has an English version.
 *
 * Signed-in accounts use their own setting (users.lang, "Mein Profil"); before signing in
 * (login, forgotten password, invitation) ?lang= or the browser language counts, otherwise
 * admin_language from the configuration (default de). Mails to an account use its language.
 */
final class AdminI18n
{
    public const LANGUAGES = ['de', 'en'];

    private static string $lang = 'de';
    private static ?array $dictionary = null;

    public static function default(): string
    {
        $lang = (string) Config::get('admin_language', 'de');
        return in_array($lang, self::LANGUAGES, true) ? $lang : 'de';
    }

    /** Language of an account (falls back to the default). */
    public static function of(?array $user): string
    {
        $lang = (string) ($user['lang'] ?? '');
        return in_array($lang, self::LANGUAGES, true) ? $lang : self::default();
    }

    /** Language for the current request: account setting, then ?lang=, then browser, then default. */
    public static function forRequest(?array $user): string
    {
        if ($user && in_array((string) ($user['lang'] ?? ''), self::LANGUAGES, true)) {
            return (string) $user['lang'];
        }
        $query = $_GET['lang'] ?? null;
        if (is_string($query) && in_array($query, self::LANGUAGES, true)) {
            return $query;
        }
        if ($user) {
            return self::default();
        }
        // Accept-Language, e.g. "en-GB,en;q=0.9,de;q=0.8": the first supported language wins
        foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $code = strtolower(substr(trim($part), 0, 2));
            if (in_array($code, self::LANGUAGES, true)) {
                return $code;
            }
        }
        return self::default();
    }

    public static function set(string $lang): void
    {
        self::$lang = in_array($lang, self::LANGUAGES, true) ? $lang : self::default();
        // Dates and weekdays (fmt_date) follow, if the public pages offer that language
        I18n::set(self::$lang);
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    /** Runs $fn in another language (e.g. mails to an account, the log). */
    public static function with(string $lang, callable $fn): mixed
    {
        $prev = self::$lang;
        $prevPublic = I18n::lang();
        self::set($lang);
        try {
            return $fn();
        } finally {
            self::$lang = $prev;
            I18n::set($prevPublic);
        }
    }

    public static function translate(string $de, array $vars = []): string
    {
        $text = self::$lang === 'de' ? $de : (self::dictionary()[$de] ?? $de);
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /** German → English, from all files in app/lang/admin/en/. */
    public static function dictionary(): array
    {
        if (self::$dictionary === null) {
            self::$dictionary = [];
            foreach (glob(APP_ROOT . '/app/lang/admin/en/*.php') ?: [] as $file) {
                self::$dictionary += (array) require $file;
            }
        }
        return self::$dictionary;
    }
}
