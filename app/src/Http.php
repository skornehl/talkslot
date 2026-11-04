<?php
declare(strict_types=1);

namespace App;

/** Request/response helpers: path, redirects, HTTPS and security headers. */
final class Http
{
    /** Path part of base_url without trailing slash ('' for a dedicated subdomain). */
    public static function basePath(): string
    {
        $path = (string) (parse_url((string) Config::get('base_url', ''), PHP_URL_PATH) ?? '');
        return rtrim($path, '/');
    }

    /** Scheme + host from base_url (never from the Host header, against header injection). */
    public static function origin(): string
    {
        $p = parse_url((string) Config::get('base_url', ''));
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost');
        if (isset($p['port'])) {
            $origin .= ':' . $p['port'];
        }
        return $origin;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function enforceHttps(): void
    {
        if (Config::get('force_https', true) && !self::isHttps()) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $target = 'https://' . (string) parse_url((string) Config::get('base_url', ''), PHP_URL_HOST) . $uri;
            header('Location: ' . $target, true, 301);
            exit;
        }
    }

    public static function sendSecurityHeaders(): void
    {
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // Pages may contain personal data: do not cache
        header('Cache-Control: no-store, max-age=0');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    /** Requested path relative to the application, e.g. "/admin/round/3". */
    public static function path(): string
    {
        $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $base = self::basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        return '/' . trim(rawurldecode($uri), '/');
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /**
     * Form post that a browser sent from another site (Sec-Fetch-Site: cross-site), e.g. a foreign page
     * submitting a login form. Requests without the header (older browsers, cron jobs, command-line
     * clients) do not count. Origin is not used: with "Referrer-Policy: no-referrer" browsers send
     * "Origin: null" on same-origin form posts as well.
     */
    public static function isCrossSitePost(): bool
    {
        return self::isPost() && strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''))) === 'cross-site';
    }

    /** POST value as trimmed string (arrays and invalid UTF-8 are ignored). */
    public static function post(string $key, string $default = ''): string
    {
        $v = self::raw($_POST[$key] ?? null);
        return $v !== null ? trim($v) : $default;
    }

    /** POST value unchanged (e.g. passwords, free text with leading/trailing spaces); '' for array/invalid UTF-8. */
    public static function postRaw(string $key): string
    {
        return self::raw($_POST[$key] ?? null) ?? '';
    }

    /** GET parameter as string; '' for array/invalid UTF-8. */
    public static function query(string $key): string
    {
        return trim(self::raw($_GET[$key] ?? null) ?? '');
    }

    private static function raw(mixed $v): ?string
    {
        return is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : null;
    }

    /** Redirect (303 See Other – always GET after POST). */
    public static function redirect(string $path, array $query = [], string $fragment = ''): never
    {
        header('Location: ' . url($path, $query) . ($fragment !== '' ? '#' . rawurlencode($fragment) : ''), true, 303);
        exit;
    }
}
