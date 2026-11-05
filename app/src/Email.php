<?php
declare(strict_types=1);

namespace App;

/**
 * E-mail addresses: one rule for every address the app accepts, sends to or reads from its configuration.
 *
 * - normalize(): what a person typed → the address as it is stored and used (null = not usable). A domain
 *   with umlauts or other non-ASCII letters (x@müller.de) becomes its ASCII form (Punycode, IDNA:
 *   x@xn--mller-kva.de), the form mail servers and DNS use. It is stored only in that form, so that the
 *   same address is always the same text: the collation of the database would otherwise treat "müller" and
 *   "muller" as equal. The part before the @ must be ASCII (internationalized local parts need SMTPUTF8,
 *   which neither PHP's mail() nor most mail servers of schools support).
 * - isValid(): an address in that stored form.
 *
 * Built in rather than idn_to_ascii(): the intl extension is missing on many web hosts (and in the test
 * images). Labels are lower-cased, full-width letters and digits become ASCII, and where intl's Normalizer
 * exists they are brought to NFC; without it a label with combining marks (ü typed as u + ¨) is refused
 * rather than encoded into a different domain. A label with umlauts must consist of letters, digits and
 * inner hyphens (no spaces, invisible characters or emoji, which an address copied from a document may
 * carry). ß stays ß (IDNA2008, as browsers and the German registry encode it: straße.de → xn--strae-oqa.de);
 * an intl path added later would need IDNA_NONTRANSITIONAL_TO_ASCII to store the same form.
 */
final class Email
{
    /** Longest address (RFC 5321 path limit, as the database column). */
    public const MAX_LENGTH = 254;

    public static function normalize(string $input): ?string
    {
        // Also spaces and invisible characters of Unicode at both ends (no-break space, zero width space, BOM)
        $email = preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+\z/u', '', $input);
        // Before encoding: an encoded domain is never shorter (and encoding a very long one takes long)
        if ($email === null || mb_strlen($email, 'UTF-8') > self::MAX_LENGTH) {
            return null;
        }
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return null;
        }
        $domain = self::domainToAscii(substr($email, $at + 1));
        if ($domain === null) {
            return null;
        }
        $email = substr($email, 0, $at) . '@' . $domain;
        return self::isValid($email) ? $email : null;
    }

    /**
     * An address from a form: the stored form if it can be used, otherwise as typed (trimmed), so that the
     * validation that follows rejects it and the form shows it again unchanged.
     */
    public static function input(string $raw): string
    {
        return self::normalize($raw) ?? trim($raw);
    }

    /**
     * Valid address in the stored form: FILTER_VALIDATE_EMAIL (ASCII only), at most 254 characters and without
     * special forms (quotes, whitespace/control characters, angle brackets) that are formally allowed but
     * fail on sending or disrupt output.
     */
    public static function isValid(string $email): bool
    {
        return $email !== '' && strlen($email) <= self::MAX_LENGTH && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && !preg_match('/[\x00-\x20\x7F"\\\\<>()\[\],;:]/', $email);
    }

    /**
     * A domain in its ASCII form (labels with non-ASCII letters as "xn--…"); ASCII domains stay as they are.
     * null = not convertible (invalid UTF-8, an empty label, combining marks without the Normalizer, a label
     * longer than 63 characters once encoded).
     */
    public static function domainToAscii(string $domain): ?string
    {
        if (!preg_match('/[^\x00-\x7F]/', $domain)) {
            return $domain;
        }
        if (!mb_check_encoding($domain, 'UTF-8')) {
            return null;
        }
        // The ideographic and full-width full stops separate labels as well (IDNA); full-width letters and digits
        // are the ASCII ones
        $domain = str_replace(["\u{3002}", "\u{FF0E}", "\u{FF61}"], '.', mb_strtolower(mb_convert_kana($domain, 'a', 'UTF-8'), 'UTF-8'));
        if (class_exists(\Normalizer::class)) {
            $domain = (string) \Normalizer::normalize($domain, \Normalizer::FORM_C);
        } elseif (preg_match('/\p{M}/u', $domain)) {
            return null;
        }
        $labels = [];
        foreach (explode('.', $domain) as $label) {
            if ($label === '') {
                return null;
            }
            if (!preg_match('/[^\x00-\x7F]/', $label)) {
                if (strlen($label) > 63) {
                    return null;
                }
                $labels[] = $label; // checked with the whole address (FILTER_VALIDATE_EMAIL)
                continue;
            }
            // Letters and digits, inner hyphens; at most 59 characters, as "xn--" and the encoding add at least 4
            if (mb_strlen($label, 'UTF-8') > 59 || !preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{M}\p{N}-]*[\p{L}\p{M}\p{N}])?\z/u', $label)) {
                return null;
            }
            $ascii = 'xn--' . self::punycode($label);
            if (strlen($ascii) > 63) {
                return null;
            }
            $labels[] = $ascii;
        }
        return implode('.', $labels);
    }

    /** Punycode of one label (RFC 3492, section 6.3), without the "xn--" prefix. */
    private static function punycode(string $label): string
    {
        $base = 36;
        $tMin = 1;
        $tMax = 26;
        $codePoints = array_map(static fn (string $c): int => (int) mb_ord($c, 'UTF-8'), mb_str_split($label, 1, 'UTF-8'));
        $output = implode('', array_map('chr', array_filter($codePoints, static fn (int $c): bool => $c < 0x80)));
        $basic = strlen($output);
        $handled = $basic;
        if ($basic > 0) {
            $output .= '-';
        }
        $n = 0x80;
        $delta = 0;
        $bias = 72;
        $total = count($codePoints);
        $digit = static fn (int $d): string => chr($d < 26 ? $d + 97 : $d + 22); // 0–25 → a–z, 26–35 → 0–9
        while ($handled < $total) {
            $m = min(array_filter($codePoints, static fn (int $c): bool => $c >= $n));
            $delta += ($m - $n) * ($handled + 1);
            $n = $m;
            foreach ($codePoints as $c) {
                if ($c < $n) {
                    $delta++;
                } elseif ($c === $n) {
                    $q = $delta;
                    for ($k = $base; ; $k += $base) {
                        $t = $k <= $bias ? $tMin : ($k >= $bias + $tMax ? $tMax : $k - $bias);
                        if ($q < $t) {
                            break;
                        }
                        $output .= $digit($t + ($q - $t) % ($base - $t));
                        $q = intdiv($q - $t, $base - $t);
                    }
                    $output .= $digit($q);
                    $bias = self::adapt($delta, $handled + 1, $handled === $basic);
                    $delta = 0;
                    $handled++;
                }
            }
            $delta++;
            $n++;
        }
        return $output;
    }

    /** Bias adaptation (RFC 3492, section 6.1). */
    private static function adapt(int $delta, int $points, bool $first): int
    {
        $delta = $first ? intdiv($delta, 700) : intdiv($delta, 2);
        $delta += intdiv($delta, $points);
        $k = 0;
        while ($delta > 455) { // ((base - tMin) * tMax) / 2
            $delta = intdiv($delta, 35);
            $k += 36;
        }
        return $k + intdiv(36 * $delta, $delta + 38);
    }
}
