<?php
declare(strict_types=1);

namespace App;

/**
 * "Known device" cookie (OWASP device cookie) against targeted lockout.
 *
 * After a successful login, or after setting a password through an e-mailed link, the browser gets a
 * signed cookie for this account: a random number, the time of issue and an HMAC over both, the account ID
 * and the account's epoch (app secret). Login attempts that bring a valid cookie for the account are limited
 * per IP and per device, but not by the per-account limit, so strangers who keep entering wrong passwords
 * cannot lock the owner out on devices the owner signed in with before.
 *
 * Revoked with the sessions: the epoch (Auth::deviceEpoch()) is the account's session counter and the one of
 * all accounts, so a new password, a reset by an admin and "Alle Sitzungen beenden" also make every cookie of
 * the account (or of all accounts) count as unknown; the browser that set the new password gets a new one.
 * A new app secret does the same for all cookies.
 *
 * One cookie per account (name derived from the account ID), only sent to /admin/login, HttpOnly,
 * SameSite=Strict, Secure under HTTPS, valid for DAYS days and renewed with every login. At most MAX_PER_BROWSER
 * per browser: on a shared PC (staff room) one per account would otherwise grow the Cookie header beyond the
 * server's limit (about 8 KB, then every request to the login page fails); the oldest go first. Nothing about
 * it is stored on the server; failed attempts with it are counted like other rate limits (HMAC hash,
 * deleted after 24 hours at the latest). Strictly necessary for the login the user asked for, so no
 * consent is needed (§ 25 (2) no. 2 TDDDG).
 */
final class TrustedDevice
{
    public const DAYS = 365;
    /** Known-device cookies kept per browser (about 130 bytes each in the Cookie header). */
    public const MAX_PER_BROWSER = 20;
    private const PREFIX = 'tp_device_';

    public static function cookieName(int $userId): string
    {
        return self::PREFIX . substr(hash_hmac('sha256', 'device-name|' . $userId, self::secret()), 0, 16);
    }

    /**
     * Sets (or renews) the cookie for the account in this browser; $epoch: Auth::deviceEpoch() of the account.
     * Removes the oldest cookies of other accounts beyond MAX_PER_BROWSER (those the request brings: the login
     * page gets them all).
     */
    public static function remember(int $userId, string $epoch): void
    {
        $name = self::cookieName($userId);
        $others = [];
        foreach ($_COOKIE as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::PREFIX) && $key !== $name) {
                $others[$key] = is_string($value) && preg_match('/^[A-Za-z0-9_-]{22}\.(\d{1,12})\./', $value, $m) ? (int) $m[1] : 0;
            }
        }
        asort($others); // oldest (and unreadable ones) first
        foreach (array_slice(array_keys($others), 0, max(0, count($others) - self::MAX_PER_BROWSER + 1)) as $old) {
            self::set($old, '', 1);
        }
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        self::set($name, self::sign($userId, $nonce, time(), $epoch), time() + self::DAYS * 86400);
    }

    /** Cookie value: nonce.issued.hmac */
    public static function sign(int $userId, string $nonce, int $issued, string $epoch): string
    {
        return $nonce . '.' . $issued . '.' . hash_hmac('sha256', 'device|' . $userId . '|' . $epoch . '|' . $nonce . '|' . $issued, self::secret());
    }

    /** Rate limit subject of a valid, unexpired cookie for this account and epoch in the request, otherwise null. */
    public static function subject(int $userId, string $epoch): ?string
    {
        $value = $_COOKIE[self::cookieName($userId)] ?? null;
        if (!is_string($value) || !preg_match('/^([A-Za-z0-9_-]{22})\.(\d{1,12})\.[0-9a-f]{64}\z/', $value, $m)) {
            return null;
        }
        $issued = (int) $m[2];
        if ($issued < time() - self::DAYS * 86400 || $issued > time() + 300) {
            return null;
        }
        return hash_equals(self::sign($userId, $m[1], $issued, $epoch), $value) ? 'device:' . $m[1] : null;
    }

    private static function set(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => Http::basePath() . '/admin/login',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** The bootstrap refuses to start without a secret of at least 32 characters. */
    private static function secret(): string
    {
        return (string) Config::get('secret', '');
    }
}
