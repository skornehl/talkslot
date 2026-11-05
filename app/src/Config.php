<?php
declare(strict_types=1);

namespace App;

/** Access to the values from config/config.php (dot notation, e.g. "db.host"). */
final class Config
{
    private static array $values = [];

    public static function load(array $values): void
    {
        self::$values = $values;
    }

    /** All values as loaded (for App\ConfigSchema). */
    public static function all(): array
    {
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /**
     * Configuration files that every user of the server may read, as path relative to the installation
     * (e.g. "config/config.php") => permissions (e.g. "0644"). config.php holds the database and mail
     * passwords and the secret: on shared hosting other customers' scripts could read it. With the atomic
     * deployment every version in <root>/releases/ has its own copy with the same secrets, so all of them
     * count. Admins see a warning (dashboard); bin/deploy.sh sets 600.
     */
    public static function exposedFiles(string $root = DATA_ROOT): array
    {
        $exposed = [];
        foreach ([$root . '/config/config.php', ...(glob($root . '/releases/*/config/config.php') ?: [])] as $file) {
            $mode = self::exposedPermissions($file);
            if ($mode !== null) {
                $exposed[substr($file, strlen($root) + 1)] = $mode;
            }
        }
        return $exposed;
    }

    /** Permissions of a file (e.g. "0644") if every user of the server may read it, otherwise null. */
    public static function exposedPermissions(string $file): ?string
    {
        // false if the file is missing; Windows has no such permissions (fileperms() reports 0666 there)
        $perms = @fileperms($file);
        return $perms !== false && ($perms & 0004) && PHP_OS_FAMILY !== 'Windows' ? sprintf('%04o', $perms & 07777) : null;
    }
}
