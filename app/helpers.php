<?php
/**
 * Kleine globale Hilfsfunktionen für Templates.
 */
declare(strict_types=1);

use App\Config;
use App\Http;
use App\I18n;

/** HTML-Escaping für jede Ausgabe in Templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Übersetzung, siehe app/lang/*.php */
function t(string $key, array $vars = []): string
{
    return I18n::t($key, $vars);
}

/**
 * Relative URL innerhalb der Anwendung. Hängt die Sprache an, wenn sie
 * ausdrücklich gewählt wurde (öffentliche Seiten setzen keine Cookies).
 */
function url(string $path = '/', array $query = []): string
{
    if (!array_key_exists('lang', $query) && I18n::explicit()) {
        $query['lang'] = I18n::lang();
    }
    $query = array_filter($query, static fn ($v) => $v !== null);
    $qs = $query ? '?' . http_build_query($query) : '';
    return Http::basePath() . $path . $qs;
}

/** Absolute URL (für E-Mails und kopierbare Links). */
function abs_url(string $path = '/', array $query = []): string
{
    return Http::origin() . url($path, $query);
}

/**
 * Dateipfad einer statischen Datei. Nach dem atomaren Deployment liegen CSS/JS/Bilder je Version
 * unter /v/<Version>/ im Webverzeichnis (passend zum Code, der sie ausliefert), lokal unter /assets/.
 */
function asset_file(string $path): string
{
    if (!defined('PUBLIC_ROOT')) {
        return '';
    }
    return PUBLIC_ROOT . (defined('RELEASE') && RELEASE !== '' ? '/v/' . RELEASE . '/' : '/assets/') . $path;
}

/** URL zu einer statischen Datei; der Versionspfad bzw. ?v= (lokal) sorgt für frische Caches. */
function asset(string $path): string
{
    if (defined('RELEASE') && RELEASE !== '') {
        return Http::basePath() . '/v/' . RELEASE . '/' . $path;
    }
    $file = asset_file($path);
    $v = $file !== '' && is_file($file) ? (string) filemtime($file) : '1';
    return Http::basePath() . '/assets/' . $path . '?v=' . $v;
}

/** Datum (Y-m-d) lokalisiert, z. B. „Montag, 17.08.2026“. */
function fmt_date(string $ymd): string
{
    $d = new DateTimeImmutable($ymd);
    $weekdays = I18n::raw('weekdays');
    $months = I18n::raw('months');
    return strtr((string) I18n::raw('date_format'), [
        '{weekday}' => $weekdays[(int) $d->format('N') - 1] ?? '',
        '{month}' => $months[(int) $d->format('n') - 1] ?? '',
        '{d}' => $d->format('d'),
        '{j}' => $d->format('j'),
        '{m}' => $d->format('m'),
        '{Y}' => $d->format('Y'),
    ]);
}

/** Uhrzeit HH:MM aus einem TIME-Wert. */
function fmt_time(string $time): string
{
    return substr($time, 0, 5);
}

/** „14:00–14:15“ */
function fmt_range(string $start, string $end): string
{
    return fmt_time($start) . '–' . fmt_time($end);
}

/** Vollständige Terminbezeichnung, z. B. für Mails und den Browser-Merker. */
function fmt_slot(string $date, string $start, string $end): string
{
    return fmt_date($date) . ', ' . fmt_range($start, $end) . t('time_suffix');
}

function app_name(): string
{
    return (string) Config::get('app_name', 'TalkSlot');
}

/**
 * Gültige E-Mail-Adresse für Eingaben: FILTER_VALIDATE_EMAIL, höchstens 254 Zeichen und ohne
 * Sonderformen (Anführungszeichen, Leer-/Steuerzeichen, spitze Klammern), die zwar formal erlaubt
 * sind, beim Versand aber scheitern oder in Ausgaben stören.
 */
function is_valid_email(string $email): bool
{
    return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && !preg_match('/[\x00-\x20\x7F"\\\\<>()\[\],;:]/', $email);
}
