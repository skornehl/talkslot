<?php
declare(strict_types=1);

namespace App;

/**
 * Short round code (e.g. "K7MP-3QXA") that lets parents find the round on the start page,
 * e.g. when the invitation is distributed on paper.
 *
 * With "Nur mit Rundencode" the code is also the access key: via the start page it unlocks
 * the round directly, via the link it is prompted for (RoundAccess). The character set
 * contains no confusable characters (no 0/O, 1/I/L). 8 characters = 31^8 ≈ 8.5 · 10^11
 * possibilities for all rounds together: even with 500 active rounds and 200 attempts
 * per second across any number of IPs, a hit takes more than 3 months on average.
 */
final class RoundCode
{
    public const LENGTH = 8;
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    /** Cyrillic letters that look like Latin ones (Russian/Ukrainian keyboard) */
    private const LOOKALIKES = ['А' => 'A', 'В' => 'B', 'Е' => 'E', 'З' => '3', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X'];

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $code;
    }

    /** New code that is not yet assigned. */
    public static function unique(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = self::generate();
            if (Db::value('SELECT id FROM rounds WHERE code = ?', [$code]) === null) {
                return $code;
            }
        }
        throw new \RuntimeException('Kein freier Rundencode gefunden.');
    }

    /**
     * Normalize input; null = not a valid code. Case, spaces and dashes of all kinds,
     * full-width characters and Cyrillic look-alikes do not matter.
     */
    public static function normalize(string $input): ?string
    {
        if (!mb_check_encoding($input, 'UTF-8')) {
            return null;
        }
        $input = mb_convert_kana($input, 'as', 'UTF-8'); // full-width → normal: Ｋ７－ → K7- (without intl)
        $code = strtr(mb_strtoupper((string) preg_replace('/[\s\p{Pd}._]+/u', '', $input), 'UTF-8'), self::LOOKALIKES);
        return strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH ? $code : null;
    }

    /** Display form "K7MP-3QXA". */
    public static function format(?string $code): string
    {
        return $code === null || $code === '' ? '' : implode('-', str_split($code, intdiv(strlen($code) + 1, 2)));
    }

    /** Assigns codes to rounds without a code (existing rounds during the changeover). */
    public static function backfill(): int
    {
        $n = 0;
        foreach (Db::all('SELECT id FROM rounds WHERE code IS NULL') as $r) {
            Db::run('UPDATE rounds SET code = ? WHERE id = ? AND code IS NULL', [self::unique(), $r['id']]);
            $n++;
        }
        return $n;
    }
}
