<?php
declare(strict_types=1);

namespace App;

/**
 * Checks that make a password easy to guess, beyond its length (see Auth::passwordPolicy):
 * - common passwords from a local list (app/data/common-passwords.txt, no external service), also a
 *   listed word with digits or symbols before or after it ("Sommer2024!!!!", "P@ssw0rd-2024");
 * - repetitions and runs ("aaaa…", "passwortpasswort", "123456789012", "qwertzuiop12", "1q2w3e4r5t6z");
 * - personal values: username, e-mail address (and the words of its local part) and the app name.
 * All comparisons ignore case.
 */
final class PasswordRules
{
    public const LIST_FILE = APP_ROOT . '/app/data/common-passwords.txt';

    /** Alphabet, digits, keyboard rows (US, German, French) and columns, shifted digit rows. Also checked reversed. */
    private const SEQUENCES = [
        'abcdefghijklmnopqrstuvwxyzabc',
        '01234567890123456789',
        'qwertyuiopasdfghjklzxcvbnm',
        'qwertzuiopüasdfghjklöäyxcvbnm',
        'azertyuiopqsdfghjklmwxcvbn',
        '1qaz2wsx3edc4rfv5tgb6yhn7ujm8ik9ol0p',
        '1qay2wsx3edc4rfv5tgb6zhn7ujm8ik9ol0p',
        'qazwsxedcrfvtgbyhnujmikolp',
        'qaywsxedcrfvtgbzhnujmikolp',
        '1q2w3e4r5t6y7u8i9o0p',
        '1q2w3e4r5t6z7u8i9o0p',
        'q1w2e3r4t5y6u7i8o9p0',
        'q1w2e3r4t5z6u7i8o9p0',
        '!@#$%^&*()_+',
        '!"§$%&/()=?',
    ];

    /** On the list of common passwords, or a listed word with only digits/symbols around it? */
    public static function isCommon(#[\SensitiveParameter] string $password): bool
    {
        $lower = mb_strtolower($password);
        $list = self::commonList();
        if (isset($list[$lower])) {
            return true;
        }
        // "Sommer2024!!!!" → "sommer"; "P@ssw0rd-2024" → "p@ssw0rd" → "password"
        $core = (string) preg_replace('/^[^\p{L}]+|[^\p{L}]+\z/u', '', $lower);
        $plain = strtr($core, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i']);
        return mb_strlen($core) >= 4 && (isset($list[$core]) || isset($list[$plain]));
    }

    /** Few different characters, a repeated part, or only runs of the alphabet, digits or the keyboard? */
    public static function isPattern(#[\SensitiveParameter] string $password): bool
    {
        $lower = mb_strtolower($password);
        if (count(array_unique(mb_str_split($lower))) < 5 || preg_match('/^(.+?)\1+\z/su', $lower)) {
            return true;
        }
        $stripped = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $lower);
        return self::onlyRuns($lower) || ($stripped !== '' && self::onlyRuns($stripped));
    }

    /**
     * Contains one of the values, or a word from it? For e-mail addresses only the words before the @
     * count individually ("anna.schmidt@schule.example" → "anna", "schmidt"). Parts of 5 or more
     * characters count anywhere in the password, parts of 4 only as a word of their own ("Anna-2024…",
     * but not "Hannah…"), shorter ones not at all.
     * @param list<string> $values
     */
    public static function containsPersonal(#[\SensitiveParameter] string $password, array $values): bool
    {
        $lower = mb_strtolower($password);
        $words = preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($values as $value) {
            $value = mb_strtolower(trim($value));
            $at = strrpos($value, '@');
            $parts = preg_split('/[^\p{L}\p{N}]+/u', $at === false ? $value : substr($value, 0, $at), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_merge([$value], $parts) as $part) {
                $length = mb_strlen($part);
                if ($length >= 5 ? str_contains($lower, $part) : ($length === 4 && in_array($part, $words, true))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Can the text be split into pieces of at least 3 characters that each occur in a sequence? */
    private static function onlyRuns(string $text): bool
    {
        static $sequences = null;
        $sequences ??= array_merge(self::SEQUENCES, array_map(static fn (string $s) => implode('', array_reverse(mb_str_split($s))), self::SEQUENCES));
        $chars = mb_str_split($text);
        $n = count($chars);
        for ($i = 0; $i < $n; $i += $best) {
            $best = 0;
            foreach ($sequences as $sequence) {
                // longest piece from position $i that occurs in this sequence
                for ($len = $n - $i; $len > max($best, 2); $len--) {
                    if (str_contains($sequence, implode('', array_slice($chars, $i, $len)))) {
                        $best = $len;
                        break;
                    }
                }
            }
            if ($best < 3) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, true> */
    private static function commonList(): array
    {
        static $list = null;
        if ($list === null) {
            $lines = file(self::LIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $list = array_fill_keys(array_filter($lines, static fn (string $l) => $l[0] !== '#'), true);
        }
        return $list;
    }
}
