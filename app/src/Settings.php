<?php
declare(strict_types=1);

namespace App;

/** Schlüssel/Wert-Einstellungen in der Tabelle settings. */
final class Settings
{
    public static function get(string $name, ?string $default = null): ?string
    {
        $v = Db::value('SELECT value FROM settings WHERE name = ?', [$name]);
        return $v === null ? $default : (string) $v;
    }

    public static function set(string $name, ?string $value): void
    {
        Db::run(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
            [$name, $value, $value]
        );
    }
}
