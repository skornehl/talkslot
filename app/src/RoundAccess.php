<?php
declare(strict_types=1);

namespace App;

/**
 * Schutz einer Terminrunde mit ihrem Rundencode („Nur mit Rundencode“).
 *
 * Ist der Schutz an, zeigt der Link nur die Code-Abfrage; Titel, Beschreibung und Termine
 * erscheinen erst nach Eingabe des Rundencodes. Wer den Code auf der Startseite eingibt,
 * ist direkt freigeschaltet. Ohne Cookie: Nach korrekter Eingabe enthält das Buchungsformular
 * ein signiertes Zugangstoken (HMAC über Runde, Code und Zeitstempel). Ein neuer Rundencode
 * macht alle offenen Token ungültig.
 */
final class RoundAccess
{
    public static function required(array $round): bool
    {
        if (array_key_exists('code_required', $round)) {
            return !empty($round['code_required']);
        }
        // Übergang beim Upload: neuer Code, Migration 10 noch nicht gelaufen → alte PIN-Spalte
        return (string) ($round['access_pin'] ?? '') !== '';
    }

    /** Stimmt die Eingabe (Schreibweise egal, siehe RoundCode::normalize) mit dem Rundencode überein? */
    public static function checkCode(array $round, string $input): bool
    {
        $code = RoundCode::normalize($input);
        return $code !== null && (string) ($round['code'] ?? '') !== '' && hash_equals((string) $round['code'], $code);
    }

    public static function token(array $round): string
    {
        $ts = (string) time();
        return $ts . '.' . self::sign($round, $ts);
    }

    public static function validToken(array $round, mixed $token): bool
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
