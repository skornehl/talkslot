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

    public static function log(\Throwable $e): void
    {
        self::writeLog(sprintf(
            '[%s] %s (%s:%d)',
            get_class($e),
            self::sanitize($e->getMessage()),
            str_replace(APP_ROOT, '', $e->getFile()),
            $e->getLine()
        ));
    }

    public static function show(?\Throwable $e = null): void
    {
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
        $detail = ($e && Config::get('debug', false)) ? $e->getMessage() . "\n" . $e->getTraceAsString() : '';
        $title = function_exists('t') ? t('error.title') : 'Fehler';
        $text = function_exists('t') ? t('error.text') : '';
        // No inline styles (the CSP only allows our own stylesheet)
        $css = function_exists('asset') && defined('PUBLIC_ROOT') ? '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">' : '';
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . '</title>' . $css
            . '<main class="wrap error-page">'
            . '<h1>' . e($title) . '</h1><p>' . e($text) . '</p>'
            . ($detail !== '' ? '<pre class="error-detail">' . e($detail) . '</pre>' : '')
            . '</main>';
    }
}
