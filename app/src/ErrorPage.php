<?php
declare(strict_types=1);

namespace App;

/** Error log without personal data, and a neutral error page. */
final class ErrorPage
{
    /** Size at which the error log is rotated (one older file .1 is kept, anything older is dropped) */
    public const LOG_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Removes values in quotes and brackets as well as email addresses from error messages,
     * so that e.g. SQL errors do not write entered data to the log. Everything from the first to the
     * last quote is replaced (also for names like O'Brien), a single one up to the end.
     */
    public static function sanitize(string $message): string
    {
        $message = (string) preg_replace('/[^\s@]+@[^\s@]+/u', '[email]', $message);
        foreach (["'", '"'] as $q) {
            $message = (string) preg_replace('/' . $q . '.*' . $q . '/su', $q . '…' . $q, $message);
            if (substr_count($message, $q) === 1) { // single quote: the rest counts as a value
                $message = substr($message, 0, (int) strpos($message, $q)) . $q . '…';
            }
        }
        $message = (string) preg_replace('/\([^()]*\)/u', '(…)', $message);
        return mb_substr($message, 0, 500);
    }

    /**
     * Writes an (already sanitized) line to the error log. If it grows beyond LOG_MAX_BYTES, it is
     * moved to .1 (the previous .1 is dropped) so it does not fill up the disk.
     */
    public static function writeLog(string $line): void
    {
        $file = (string) ini_get('error_log');
        if ($file !== '' && is_file($file) && @filesize($file) > self::LOG_MAX_BYTES) {
            @rename($file, $file . '.1');
        }
        error_log($line);
    }

    /**
     * A file of the app relative to the project root (/app/src/Db.php), for the log and error reports; other
     * paths unchanged. Only the leading root is removed: with the project in /app (Docker), str_replace() would
     * also cut the app/ directory out of /app/app/src/Db.php, and tests/php-errors.sh would miss the line.
     */
    public static function relativePath(string $file): string
    {
        return str_starts_with($file, APP_ROOT . '/') ? substr($file, strlen(APP_ROOT)) : $file;
    }

    public static function log(\Throwable $e): void
    {
        self::writeLog(sprintf(
            '[%s] %s (%s:%d)',
            get_class($e),
            self::sanitize($e->getMessage()),
            self::relativePath($e->getFile()),
            $e->getLine()
        ));
    }

    public static function show(?\Throwable $e = null): void
    {
        // Output already under way (a template that failed, a fatal error while rendering) must not end up in
        // front of the error page: the browser would get half a page followed by a second document
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        if (!headers_sent()) {
            // Also when the error happens before the front controller has sent them
            Http::sendSecurityHeaders();
            // Database gone: temporarily unavailable (503), otherwise internal error (500)
            http_response_code(Db::isUnavailable() ? 503 : 500);
            if (Db::isUnavailable()) {
                header('Retry-After: 300');
            }
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo self::page($e);
    }

    /**
     * The error page in the language of the request, with a way back and whom to contact. Before a language
     * was set (e.g. the database failed while signing in to the admin area) in the default language, never
     * as raw text keys.
     */
    public static function page(?\Throwable $e = null): string
    {
        $lang = I18n::raw('error.title') !== null ? I18n::lang() : I18n::defaultLanguage();
        $text = static fn (string $key): string => I18n::textIn($lang, $key);
        $detail = ($e && Config::get('debug', false)) ? $e->getMessage() . "\n" . $e->getTraceAsString() : '';
        $admin = Http::path() === '/admin' || str_starts_with(Http::path(), '/admin/');
        // The legal notice names the operator; it needs the database, so not while that is the problem
        $contact = Db::isUnavailable() ? '' : '<p>' . str_replace('{imprint}',
            '<a href="' . e(url('/impressum')) . '">' . e($text('footer.imprint')) . '</a>', e($text('error.contact'))) . '</p>';
        // No inline styles (the CSP only allows our own stylesheet)
        $css = defined('PUBLIC_ROOT') ? '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">' : '';
        return '<!doctype html><html lang="' . e($lang) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($text('error.title')) . '</title>' . $css
            . '<main class="wrap error-page">'
            . '<h1>' . e($text('error.title')) . '</h1><p>' . e($text('error.text')) . '</p>' . $contact
            . '<p><a href="' . e(url($admin ? '/admin' : '/')) . '">' . e($text('error.home')) . '</a></p>'
            . ($detail !== '' ? '<pre class="error-detail">' . e($detail) . '</pre>' : '')
            . '</main>';
    }
}
