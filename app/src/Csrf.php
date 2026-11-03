<?php
declare(strict_types=1);

namespace App;

/**
 * CSRF protection.
 *
 * Public forms: stateless, signed token (HMAC over context +
 * timestamp). This way the parents' page needs neither session nor cookie.
 * The token expires after a few hours and is only accepted after a minimum
 * time (simple bot brake).
 *
 * Admin forms: classic session token.
 */
final class Csrf
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const TOO_FAST = 'too_fast';

    public static function publicToken(string $context): string
    {
        $ts = (string) time();
        return $ts . '.' . hash_hmac('sha256', 'pub|' . $context . '|' . $ts, self::secret());
    }

    public static function checkPublic(string $context, mixed $token, ?int $minAge = null): string
    {
        if (!is_string($token) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m)) {
            return self::INVALID;
        }
        $expected = hash_hmac('sha256', 'pub|' . $context . '|' . $m[1], self::secret());
        if (!hash_equals($expected, $m[2])) {
            return self::INVALID;
        }
        $age = time() - (int) $m[1];
        if ($age > (int) Config::get('csrf_max_age_seconds', 4 * 3600) || $age < -60) {
            return self::INVALID;
        }
        if ($age < ($minAge ?? (int) Config::get('csrf_min_age_seconds', 2))) {
            return self::TOO_FAST;
        }
        return self::OK;
    }

    public static function adminToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkAdmin(mixed $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $token);
    }

    public static function adminField(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(self::adminToken()) . '">';
    }

    private static function secret(): string
    {
        $s = (string) Config::get('secret', '');
        if (strlen($s) < 32) {
            throw new \RuntimeException('config: "secret" fehlt oder ist zu kurz');
        }
        return $s;
    }
}
