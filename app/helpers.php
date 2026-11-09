<?php
/**
 * Small global helper functions for templates.
 */
declare(strict_types=1);

use App\Config;
use App\Http;
use App\I18n;

/** HTML escaping for every output in templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Translation, see app/lang/*.php */
function t(string $key, array $vars = []): string
{
    return I18n::t($key, $vars);
}

/** Admin area: German text, in English if the admin language is English (see AdminI18n). */
function __(string $de, array $vars = []): string
{
    return \App\AdminI18n::translate($de, $vars);
}

/**
 * Like __(), escaped for HTML; the values of $html are inserted unescaped (e.g. a link that the
 * caller has built and escaped itself): __h('Bitte {link} prüfen.', ['link' => '<a …>…</a>']).
 */
function __h(string $de, array $html = []): string
{
    return \App\AdminI18n::fill(e(\App\AdminI18n::translate($de)), $html);
}

/**
 * Relative URL within the application. Appends the language if it
 * was chosen explicitly (public pages do not set cookies).
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

/** Absolute URL (for emails and copyable links). */
function abs_url(string $path = '/', array $query = []): string
{
    return Http::origin() . url($path, $query);
}

/**
 * File path of a static file. After atomic deployment, CSS/JS/images live per version
 * under /v/<version>/ in the web root (matching the code that serves them), locally under /assets/.
 */
function asset_file(string $path): string
{
    if (!defined('PUBLIC_ROOT')) {
        return '';
    }
    return PUBLIC_ROOT . (defined('RELEASE') && RELEASE !== '' ? '/v/' . RELEASE . '/' : '/assets/') . $path;
}

/** URL of a static file; the version path or ?v= (locally) ensures fresh caches. */
function asset(string $path): string
{
    if (defined('RELEASE') && RELEASE !== '') {
        return Http::basePath() . '/v/' . RELEASE . '/' . $path;
    }
    $file = asset_file($path);
    $v = $file !== '' && is_file($file) ? (string) filemtime($file) : '1';
    return Http::basePath() . '/assets/' . $path . '?v=' . $v;
}

/** Localized date (Y-m-d), e.g. "Montag, 17.08.2026". */
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

/** Time HH:MM from a TIME value. */
function fmt_time(string $time): string
{
    return substr($time, 0, 5);
}

/** "14:00–14:15" */
function fmt_range(string $start, string $end): string
{
    return fmt_time($start) . '–' . fmt_time($end);
}

/** Full appointment label, e.g. for emails and the browser remember-me entry. */
function fmt_slot(string $date, string $start, string $end): string
{
    return fmt_date($date) . ', ' . fmt_range($start, $end) . t('time_suffix');
}

/** Location of a day: its own, otherwise the round's ('' = none given). */
function location_of(array $day, array $round): string
{
    $own = trim((string) ($day['location'] ?? ''));
    return $own !== '' ? $own : trim((string) ($round['location'] ?? ''));
}

function app_name(): string
{
    return (string) Config::get('app_name', 'TalkSlot');
}

/** Valid e-mail address in its stored form (ASCII, a domain with umlauts as "xn--…"); see App\Email. */
function is_valid_email(string $email): bool
{
    return \App\Email::isValid($email);
}
