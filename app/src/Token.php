<?php
declare(strict_types=1);

namespace App;

/**
 * Geheime Änderungs-/Stornierungstoken.
 * Klartext (256 Bit, base64url, 43 Zeichen) steht nur im Link; die Datenbank
 * speichert ausschließlich den SHA-256-Hash.
 */
final class Token
{
    /** @return array{0:string,1:string} [klartext, hash] */
    public static function generate(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$plain, self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function isWellFormed(string $plain): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $plain);
    }
}
