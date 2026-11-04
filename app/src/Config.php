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
     * Permissions of the configuration file (e.g. "0644") if every user of the server may read it,
     * otherwise null. config.php holds the database and mail passwords and the secret: on shared hosting
     * other customers' scripts could read it. Admins see a warning (dashboard); bin/deploy.sh sets 600.
     */
    public static function exposedPermissions(?string $file = null): ?string
    {
        // false if the file is missing; Windows has no such permissions (fileperms() reports 0666 there)
        $perms = @fileperms($file ?? APP_ROOT . '/config/config.php');
        return $perms !== false && ($perms & 0004) && PHP_OS_FAMILY !== 'Windows' ? sprintf('%04o', $perms & 07777) : null;
    }
}
