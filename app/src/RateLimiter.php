<?php
declare(strict_types=1);

namespace App;

/**
 * Simple rate limiting with a fixed time window.
 *
 * Privacy: the IP address is truncated (IPv4 /24, IPv6 /48) and hashed via HMAC
 * with the app secret. Only this hash is stored; entries
 * are deleted by the cleanup run after at most 24 hours.
 *
 * Instead of the IP, a custom key can be counted ($subject, e.g. "booking:12" or
 * "user:sabrina"), likewise stored only as HMAC.
 */
final class RateLimiter
{
    public static function clientKey(): string
    {
        $bin = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        // Treat IPv4 in IPv6 notation (::ffff:a.b.c.d) like IPv4, otherwise all
        // IPv4 clients would share one /48 key
        if ($bin !== false && strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12);
        }
        if ($bin === false) {
            $truncated = 'unknown';
        } elseif (strlen($bin) === 4) {
            $truncated = (string) inet_ntop(substr($bin, 0, 3) . "\0");
        } else {
            $truncated = (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
        }
        return hash_hmac('sha256', 'ip|' . $truncated, (string) Config::get('secret', ''));
    }

    /** Returns [limit, window_seconds] from the configuration. */
    public static function limits(string $bucket, int $limit, int $window): array
    {
        $cfg = Config::get('rate_limits.' . $bucket);
        return is_array($cfg) && count($cfg) === 2 ? [(int) $cfg[0], (int) $cfg[1]] : [$limit, $window];
    }

    /** Counts an attempt. true = still within the limit. */
    private static function key(?string $subject): string
    {
        return $subject === null ? self::clientKey() : hash_hmac('sha256', 'subject|' . $subject, (string) Config::get('secret', ''));
    }

    public static function hit(string $bucket, int $limit, int $window, ?string $subject = null): bool
    {
        $now = time();
        $cut = $now - $window;
        Db::run(
            'INSERT INTO rate_limits (bucket, key_hash, window_start, hits) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start < ?, 1, hits + 1),
                                     window_start = IF(window_start < ?, ?, window_start)',
            [$bucket, self::key($subject), $now, $cut, $cut, $now]
        );
        return self::count($bucket, $window, $subject) <= $limit;
    }

    public static function count(string $bucket, int $window, ?string $subject = null): int
    {
        $row = Db::one('SELECT hits, window_start FROM rate_limits WHERE bucket = ? AND key_hash = ?', [$bucket, self::key($subject)]);
        if (!$row || (int) $row['window_start'] < time() - $window) {
            return 0;
        }
        return (int) $row['hits'];
    }

    /** Takes back a counted attempt (e.g. successful input that was reserved in advance). */
    public static function undo(string $bucket, ?string $subject = null): void
    {
        Db::run('UPDATE rate_limits SET hits = GREATEST(hits - 1, 0) WHERE bucket = ? AND key_hash = ?', [$bucket, self::key($subject)]);
    }

    public static function clear(string $bucket, ?string $subject = null): void
    {
        Db::run('DELETE FROM rate_limits WHERE bucket = ? AND key_hash = ?', [$bucket, self::key($subject)]);
    }
}
