<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/** Time functions; all times in the configured time zone (Europe/Berlin). */
final class Time
{
    /** Fixed clock of the tests (setNow()), null = real time */
    private static ?DateTimeImmutable $fixed = null;

    public static function now(): DateTimeImmutable
    {
        return self::$fixed ?? new DateTimeImmutable('now');
    }

    /**
     * Test hook (tests/integration.php, unit tests): Time::now(), nowSql() and today() return this moment
     * until it is reset with null, so deadlines, reminders and deletion periods can be tested without moving
     * timestamps in the database. A string is read like "2026-11-30 17:59" in the configured time zone; a moment
     * given in another zone is converted to it, as now() would be. Only tests call it (tests/unit.d/quality.php
     * checks). time() is not affected (rate limits, last_cleanup).
     */
    public static function setNow(DateTimeImmutable|string|null $now): void
    {
        $now = is_string($now) ? new DateTimeImmutable($now) : $now;
        self::$fixed = $now?->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public static function nowSql(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /** Accepts 24-h values like 14:30, 9:30, 9.30, 1430, 930, 9 or 14:30:00; returns HH:MM:00 or null. */
    public static function normalizeTime(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{1,2})[:.](\d{2})(:\d{2})?$/', $v, $m)) {
            [$h, $min] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^\d{1,4}$/', $v)) {
            $d = strlen($v) <= 2 ? $v . '00' : str_pad($v, 4, '0', STR_PAD_LEFT);
            [$h, $min] = [(int) substr($d, 0, -2), (int) substr($d, -2)];
        } else {
            return null;
        }
        return $h < 24 && $min < 60 ? sprintf('%02d:%02d:00', $h, $min) : null;
    }

    public static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    public static function fromMinutes(int $m): string
    {
        return sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    }
}
