<?php
declare(strict_types=1);

namespace App;

/**
 * Kurzer Rundencode (z. B. „K7MP-3QXA“), mit dem Eltern auf der Startseite zur Runde finden,
 * etwa wenn die Einladung auf Papier verteilt wird.
 *
 * Bei „Nur mit Rundencode“ ist der Code zugleich der Zugang: Über die Startseite ist man
 * damit direkt freigeschaltet, über den Link wird er abgefragt (RoundAccess). Der Zeichenvorrat
 * enthält keine verwechselbaren Zeichen (kein 0/O, 1/I/L). 8 Zeichen = 31^8 ≈ 8,5 · 10^11
 * Möglichkeiten für alle Runden zusammen: Selbst bei 500 aktiven Runden und 200 Versuchen
 * pro Sekunde über beliebig viele IPs dauert ein Treffer im Mittel über 3 Monate.
 */
final class RoundCode
{
    public const LENGTH = 8;
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    /** Kyrillische Buchstaben, die wie lateinische aussehen (russische/ukrainische Tastatur) */
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

    /** Neuer, noch nicht vergebener Code. */
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
     * Eingabe vereinheitlichen; null = kein gültiger Code. Egal sind Groß-/Kleinschreibung,
     * Leerzeichen und Striche aller Art, Vollbreite-Zeichen und kyrillische Doppelgänger.
     */
    public static function normalize(string $input): ?string
    {
        if (!mb_check_encoding($input, 'UTF-8')) {
            return null;
        }
        $input = mb_convert_kana($input, 'as', 'UTF-8'); // Vollbreite → normal: Ｋ７－ → K7- (ohne intl)
        $code = strtr(mb_strtoupper((string) preg_replace('/[\s\p{Pd}._]+/u', '', $input), 'UTF-8'), self::LOOKALIKES);
        return strlen($code) === self::LENGTH && strspn($code, self::ALPHABET) === self::LENGTH ? $code : null;
    }

    /** Anzeigeform „K7MP-3QXA“. */
    public static function format(?string $code): string
    {
        return $code === null || $code === '' ? '' : implode('-', str_split($code, intdiv(strlen($code) + 1, 2)));
    }

    /** Vergibt Codes an Runden ohne Code (bestehende Runden bei der Umstellung). */
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
