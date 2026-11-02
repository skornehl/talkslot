<?php
declare(strict_types=1);

namespace App;

/**
 * Einfaches Rate-Limiting mit festem Zeitfenster.
 *
 * Datenschutz: Die IP-Adresse wird gekürzt (IPv4 /24, IPv6 /48) und mit dem
 * App-Secret per HMAC gehasht. Gespeichert wird nur dieser Hash; Einträge
 * werden spätestens nach 24 Stunden vom Bereinigungslauf gelöscht.
 *
 * Statt der IP kann ein eigener Schlüssel gezählt werden ($subject, z. B. „booking:12“ oder
 * „user:sabrina“), ebenfalls nur als HMAC gespeichert.
 */
final class RateLimiter
{
    public static function clientKey(): string
    {
        $bin = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        // IPv4 in IPv6-Schreibweise (::ffff:a.b.c.d) wie IPv4 behandeln, sonst teilten sich alle
        // IPv4-Clients einen /48-Schlüssel
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

    /** Liefert [limit, fenster_sekunden] aus der Konfiguration. */
    public static function limits(string $bucket, int $limit, int $window): array
    {
        $cfg = Config::get('rate_limits.' . $bucket);
        return is_array($cfg) && count($cfg) === 2 ? [(int) $cfg[0], (int) $cfg[1]] : [$limit, $window];
    }

    /** Zählt einen Versuch. true = noch innerhalb des Limits. */
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

    /** Nimmt einen gezählten Versuch zurück (z. B. erfolgreiche Eingabe, die vorab reserviert wurde). */
    public static function undo(string $bucket, ?string $subject = null): void
    {
        Db::run('UPDATE rate_limits SET hits = GREATEST(hits - 1, 0) WHERE bucket = ? AND key_hash = ?', [$bucket, self::key($subject)]);
    }

    public static function clear(string $bucket, ?string $subject = null): void
    {
        Db::run('DELETE FROM rate_limits WHERE bucket = ? AND key_hash = ?', [$bucket, self::key($subject)]);
    }
}
