<?php
declare(strict_types=1);

namespace App;

/**
 * Protection of a round with its round code ("Nur mit Rundencode" option).
 *
 * If protection is on, the link only shows the code prompt; title, description and appointments
 * appear only after the round code has been entered. Whoever enters the code on the start page
 * is unlocked directly. Without a cookie: after correct input the booking form contains
 * a signed access token (HMAC over round, code and timestamp). A new round code
 * invalidates all outstanding tokens.
 */
final class RoundAccess
{
    public static function required(array $round): bool
    {
        if (array_key_exists('code_required', $round)) {
            return !empty($round['code_required']);
        }
        // Transition during upload: new code, migration 10 not yet run → old PIN column
        return (string) ($round['access_pin'] ?? '') !== '';
    }

    /** Does the input (case/format irrelevant, see RoundCode::normalize) match the round code? */
    public static function checkCode(array $round, #[\SensitiveParameter] string $input): bool
    {
        $code = RoundCode::normalize($input);
        return $code !== null && (string) ($round['code'] ?? '') !== '' && hash_equals((string) $round['code'], $code);
    }

    public static function token(array $round): string
    {
        $ts = (string) time();
        return $ts . '.' . self::sign($round, $ts);
    }

    public static function validToken(array $round, #[\SensitiveParameter] mixed $token): bool
    {
        if (!is_string($token) || !preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $token, $m)) {
            return false;
        }
        $age = time() - (int) $m[1];
        return $age >= -60
            && $age <= (int) Config::get('csrf_max_age_seconds', 4 * 3600)
            && hash_equals(self::sign($round, $m[1]), $m[2]);
    }

    private static function sign(array $round, string $ts): string
    {
        return hash_hmac('sha256', 'access|' . $round['id'] . '|' . $round['code'] . '|' . $ts, (string) Config::get('secret', ''));
    }
}
