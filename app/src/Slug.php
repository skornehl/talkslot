<?php
declare(strict_types=1);

namespace App;

/** Sprechende URLs für Terminrunden. */
final class Slug
{
    /** Pfade, die die Anwendung selbst belegt. */
    public const RESERVED = ['admin', 'install', 'impressum', 'datenschutz', 'imprint', 'privacy', 'b', 'assets', 'index', 'robots'];

    /** Länge der Spalte rounds.slug */
    public const MAX_LENGTH = 100;
    /** Platz für den Eindeutigkeitszusatz von unique() ("-2" … "-999") */
    public const SUFFIX_RESERVE = 4;

    private const RANDOM_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789'; // ohne l, o, 0, 1

    /** "Elternsprechtag August 2026" → "elternsprechtag-august-2026" */
    public static function fromText(string $text, int $maxLength = 80): string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'ss'];
        $s = strtr($text, $map);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $s = $t;
            }
        }
        $s = strtolower($s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > $maxLength) {
            $s = rtrim(substr($s, 0, $maxLength), '-');
        }
        return $s;
    }

    /** Hängt einen Zufallsanteil an und kürzt die Basis so, dass alles in die Spalte passt. */
    public static function withSuffix(string $base): string
    {
        $suffix = self::random();
        $max = self::MAX_LENGTH - self::SUFFIX_RESERVE - strlen($suffix) - 1;
        $base = rtrim(substr($base, 0, $max), '-');
        return ($base !== '' ? $base . '-' : '') . $suffix;
    }

    /** Nicht erratbarer Zusatz, 8 Zeichen ≈ 40 Bit. */
    public static function random(int $length = 8): string
    {
        $out = '';
        $max = strlen(self::RANDOM_ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::RANDOM_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Macht einen Slug eindeutig (ggf. "-2", "-3" …), optional ohne die Runde $exceptId. */
    public static function unique(string $base, ?int $exceptId = null): string
    {
        if ($base === '') {
            $base = 'termine';
        }
        $candidate = $base;
        $n = 2;
        while (in_array($candidate, self::RESERVED, true) || self::exists($candidate, $exceptId)) {
            $candidate = $base . '-' . $n++;
        }
        return $candidate;
    }

    private static function exists(string $slug, ?int $exceptId): bool
    {
        return Db::value('SELECT id FROM rounds WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null;
    }
}
