<?php
declare(strict_types=1);

namespace App;

/**
 * Requirements of the server, checked first thing by app/bootstrap.php: otherwise an old PHP version or a
 * missing extension ends in a blank 500 page (e.g. mb_internal_encoding() without mbstring) with nothing
 * in app/var that says why. Problems found before the app can run (also a missing or broken configuration,
 * App\ConfigSchema::errors()) stop the request with a plain explanation (stop()).
 *
 * This file is loaded on any PHP version, so it uses only syntax that PHP 7.1 understands (no typed
 * constants, no match, no arrow functions, no trailing commas in parameter lists).
 */
final class Platform
{
    /** Minimum PHP version (composer.json, README, installation guide) */
    public const MIN_PHP = '8.3';
    public const MIN_PHP_ID = 80300;
    /** Extensions every run needs */
    public const EXTENSIONS = ['mbstring', 'iconv'];
    /** Needed wherever the database is used: every web request, not the unit tests */
    public const DATABASE_EXTENSION = 'pdo_mysql';

    public const GUIDE_EN = 'https://skornehl.github.io/talkslot/guide/';
    public const GUIDE_DE = 'https://skornehl.github.io/talkslot/de/guide/';

    /**
     * What this server lacks, in German and English (empty = fine). $versionId and $loaded only for tests.
     * @param ?list<string> $loaded loaded extensions (default: get_loaded_extensions())
     * @return list<array{en: string, de: string}>
     */
    public static function problems(bool $database, int $versionId = PHP_VERSION_ID, ?array $loaded = null): array
    {
        $loaded = array_map('strtolower', $loaded === null ? get_loaded_extensions() : $loaded);
        $problems = [];
        if ($versionId < self::MIN_PHP_ID) {
            $version = intdiv($versionId, 10000) . '.' . intdiv($versionId % 10000, 100);
            $problems[] = [
                'en' => sprintf('PHP %s or newer is required; this server runs PHP %s. Most web hosts let you switch the PHP version in their control panel.', self::MIN_PHP, $version),
                'de' => sprintf('Es wird PHP %s oder neuer benötigt, dieser Server hat PHP %s. Bei den meisten Webhostern lässt sich die PHP-Version in der Verwaltung umstellen.', self::MIN_PHP, $version),
            ];
        }
        $needed = $database ? array_merge(self::EXTENSIONS, [self::DATABASE_EXTENSION]) : self::EXTENSIONS;
        foreach ($needed as $extension) {
            if (!in_array($extension, $loaded, true)) {
                $problems[] = [
                    'en' => sprintf('The PHP extension "%s" is missing. It can usually be switched on in the PHP settings of the hosting, otherwise ask your web host.', $extension),
                    'de' => sprintf('Die PHP-Erweiterung „%s“ fehlt. Sie lässt sich meist in den PHP-Einstellungen des Hostings einschalten, sonst hilft der Webhoster.', $extension),
                ];
            }
        }
        return $problems;
    }

    /**
     * Stops the run if the server lacks something (see problems()). $versionId, $loaded and $cli only for
     * tests.
     */
    public static function enforce(bool $database, int $versionId = PHP_VERSION_ID, ?array $loaded = null, ?bool $cli = null): void
    {
        $problems = self::problems($database, $versionId, $loaded);
        if ($problems) {
            self::stop($problems, $cli);
        }
    }

    /**
     * Ends the run with exit code 1 and the problems: on the command line in English, on the web as a page
     * in German and English (status 500, also in the server's error log). Nothing of the app is used: the
     * reason may be that it cannot run.
     * @param list<array{en: string, de: string}> $problems
     */
    public static function stop(array $problems, ?bool $cli = null): void
    {
        if ($cli === null ? PHP_SAPI === 'cli' : $cli) {
            echo implode("\n", array_column($problems, 'en')) . "\n";
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        error_log('TalkSlot cannot start: ' . implode(' ', array_column($problems, 'en')));
        echo self::page($problems);
        exit(1);
    }

    /** @param list<array{en: string, de: string}> $problems */
    public static function page(array $problems): string
    {
        $html = function ($s): string {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $list = function (string $lang) use ($problems, $html): string {
            $items = '';
            foreach ($problems as $p) {
                $items .= '<li>' . $html($p[$lang]) . '</li>';
            }
            return '<ul>' . $items . '</ul>';
        };
        // No styles: the content security policy only allows the app's own stylesheet, which may be the
        // thing that cannot be served
        return '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>TalkSlot kann nicht starten · cannot start</title>'
            . '<main><h1>TalkSlot kann nicht starten</h1>' . $list('de')
            . '<p>Für die technische Betreuung: <a href="' . $html(self::GUIDE_DE . 'installation/#requirements') . '">Voraussetzungen</a>, '
            . '<a href="' . $html(self::GUIDE_DE . 'configuration/') . '">Konfiguration</a>. '
            . 'Wenn Sie diese Seite als Besucher sehen: Der Betreiber muss die Einrichtung noch abschließen, bitte versuchen Sie es später erneut.</p>'
            . '<section lang="en"><h2>TalkSlot cannot start</h2>' . $list('en')
            . '<p>For whoever runs the server: <a href="' . $html(self::GUIDE_EN . 'installation/#requirements') . '">requirements</a>, '
            . '<a href="' . $html(self::GUIDE_EN . 'configuration/') . '">configuration</a>. '
            . 'If you are a visitor: the operator still has to finish the setup, please try again later.</p></section></main>';
    }
}
