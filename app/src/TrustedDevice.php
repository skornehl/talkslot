<?php
declare(strict_types=1);

namespace App;

/**
 * "Known device" cookie (OWASP device cookie) against targeted lockout.
 *
 * After a successful login, or after setting a password through an e-mailed link, the browser gets a
 * signed cookie for this account: a random number, the time of issue and an HMAC over both and the
 * account ID (app secret). Login attempts that bring a valid cookie for the account are limited per
 * IP and per device, but not by the per-account limit, so strangers who keep entering wrong passwords
 * cannot lock the owner out on devices the owner signed in with before.
 *
 * One cookie per account (name derived from the account ID), only sent to /admin/login, HttpOnly,
 * SameSite=Strict, Secure under HTTPS, valid for DAYS days and renewed with every login. Nothing about
 * it is stored on the server; failed attempts with it are counted like other rate limits (HMAC hash,
 * deleted after 24 hours at the latest). Strictly necessary for the login the user asked for, so no
 * consent is needed (§ 25 (2) no. 2 TDDDG).
 */
final class TrustedDevice
{
    public const DAYS = 365;

    public static function cookieName(int $userId): string
    {
        return 'tp_device_' . substr(hash_hmac('sha256', 'device-name|' . $userId, self::secret()), 0, 16);
    }

    /** Sets (or renews) the cookie for the account in this browser. */
    public static function remember(int $userId): void
    {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        setcookie(self::cookieName($userId), self::sign($userId, $nonce, time()), [
            'expires' => time() + self::DAYS * 86400,
            'path' => Http::basePath() . '/admin/login',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** Cookie value: nonce.issued.hmac */
    public static function sign(int $userId, string $nonce, int $issued): string
    {
        return $nonce . '.' . $issued . '.' . hash_hmac('sha256', 'device|' . $userId . '|' . $nonce . '|' . $issued, self::secret());
    }

    /** Rate limit subject of a valid, unexpired cookie for this account in the request, otherwise null. */
    public static function subject(int $userId): ?string
    {
        $value = $_COOKIE[self::cookieName($userId)] ?? null;
        if (!is_string($value) || !preg_match('/^([A-Za-z0-9_-]{22})\.(\d{1,12})\.[0-9a-f]{64}\z/', $value, $m)) {
            return null;
        }
        $issued = (int) $m[2];
        if ($issued < time() - self::DAYS * 86400 || $issued > time() + 300) {
            return null;
        }
        return hash_equals(self::sign($userId, $m[1], $issued), $value) ? 'device:' . $m[1] : null;
    }

    /** The bootstrap refuses to start without a secret of at least 32 characters. */
    private static function secret(): string
    {
        return (string) Config::get('secret', '');
    }
}
