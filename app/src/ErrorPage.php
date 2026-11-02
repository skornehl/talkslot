<?php
declare(strict_types=1);

namespace App;

/** Fehlerprotokoll ohne personenbezogene Daten und neutrale Fehlerseite. */
final class ErrorPage
{
    /** Größe, ab der das Fehlerlog rotiert wird (eine ältere Datei .1 bleibt, älteres entfällt) */
    public const LOG_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Entfernt Werte in Anführungszeichen und Klammern sowie E-Mail-Adressen aus Fehlermeldungen,
     * damit z. B. SQL-Fehler keine eingegebenen Daten ins Log schreiben. Vom ersten bis zum letzten
     * Anführungszeichen wird alles ersetzt (auch bei Namen wie O'Brien), ein einzelnes bis zum Ende.
     */
    public static function sanitize(string $message): string
    {
        $message = (string) preg_replace('/[^\s@]+@[^\s@]+/u', '[email]', $message);
        foreach (["'", '"'] as $q) {
            $message = (string) preg_replace('/' . $q . '.*' . $q . '/su', $q . '…' . $q, $message);
            if (substr_count($message, $q) === 1) { // einzelnes Anführungszeichen: Rest gilt als Wert
                $message = substr($message, 0, (int) strpos($message, $q)) . $q . '…';
            }
        }
        $message = (string) preg_replace('/\([^()]*\)/u', '(…)', $message);
        return mb_substr($message, 0, 500);
    }

    /**
     * Schreibt eine (bereits bereinigte) Zeile ins Fehlerlog. Wird es größer als LOG_MAX_BYTES, wird
     * es nach .1 verschoben (die vorige .1 entfällt), damit es den Speicherplatz nicht füllt.
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
            // Datenbank weg: vorübergehend nicht verfügbar (503), sonst interner Fehler (500)
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
        // Ohne Inline-Styles (die CSP erlaubt nur das eigene Stylesheet)
        $css = function_exists('asset') && defined('PUBLIC_ROOT') ? '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">' : '';
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title) . '</title>' . $css
            . '<main class="wrap error-page">'
            . '<h1>' . e($title) . '</h1><p>' . e($text) . '</p>'
            . ($detail !== '' ? '<pre class="error-detail">' . e($detail) . '</pre>' : '')
            . '</main>';
    }
}
