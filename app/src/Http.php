<?php
declare(strict_types=1);

namespace App;

/** Anfrage-/Antwort-Hilfen: Pfad, Weiterleitungen, HTTPS und Sicherheits-Header. */
final class Http
{
    /** Pfadanteil der base_url ohne abschließenden Schrägstrich ('' bei eigener Subdomain). */
    public static function basePath(): string
    {
        $path = (string) (parse_url((string) Config::get('base_url', ''), PHP_URL_PATH) ?? '');
        return rtrim($path, '/');
    }

    /** Schema + Host aus der base_url (nie aus dem Host-Header, gegen Header-Injection). */
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
        // Seiten enthalten ggf. personenbezogene Daten: nicht zwischenspeichern
        header('Cache-Control: no-store, max-age=0');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    /** Angefragter Pfad relativ zur Anwendung, z. B. "/admin/round/3". */
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

    /** POST-Wert als getrimmter String (Arrays und ungültiges UTF-8 werden ignoriert). */
    public static function post(string $key, string $default = ''): string
    {
        $v = self::raw($_POST[$key] ?? null);
        return $v !== null ? trim($v) : $default;
    }

    /** POST-Wert unverändert (z. B. Passwörter, Freitext mit Leerzeichen am Rand); '' bei Array/ungültigem UTF-8. */
    public static function postRaw(string $key): string
    {
        return self::raw($_POST[$key] ?? null) ?? '';
    }

    /** GET-Parameter als String; '' bei Array/ungültigem UTF-8. */
    public static function query(string $key): string
    {
        return trim(self::raw($_GET[$key] ?? null) ?? '');
    }

    private static function raw(mixed $v): ?string
    {
        return is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : null;
    }

    /** Weiterleitung (303 See Other – nach POST immer GET). */
    public static function redirect(string $path, array $query = [], string $fragment = ''): never
    {
        header('Location: ' . url($path, $query) . ($fragment !== '' ? '#' . rawurlencode($fragment) : ''), true, 303);
        exit;
    }
}
