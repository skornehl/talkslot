<?php
declare(strict_types=1);

namespace App;

/**
 * Secret change/cancellation tokens.
 * The plain text (256 bits, base64url, 43 characters) appears only in the link; the database
 * stores only the SHA-256 hash.
 */
final class Token
{
    /** @return array{0:string,1:string} [plaintext, hash] */
    public static function generate(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$plain, self::hash($plain)];
    }

    public static function hash(#[\SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function isWellFormed(#[\SensitiveParameter] string $plain): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}\z/', $plain);
    }
}
