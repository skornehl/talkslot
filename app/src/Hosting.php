<?php
declare(strict_types=1);

namespace App;

/**
 * Angaben zum Hoster für die Datenschutzerklärung: ersetzen dort den Platzhalter [[HOSTER]].
 * Sie stehen in der Konfiguration (config.php → 'hosting', je Sprache; beim Deployment die
 * Variablen HOSTING_DE und HOSTING_EN), weil sie zum Server gehören und nicht zur Schule.
 * Fehlen sie, erscheint ein sichtbarer Platzhalter.
 */
final class Hosting
{
    public const PLACEHOLDER = '[[HOSTER]]';

    private const MISSING = [
        'de' => '[HOSTER MIT ANSCHRIFT], als Auftragsverarbeiter nach Art. 28 DSGVO. Die Server stehen in [LAND].',
        'en' => '[WEB HOST WITH ADDRESS], as processor under Art. 28 GDPR. The servers are located in [COUNTRY].',
    ];

    public static function configured(string $lang): bool
    {
        $cfg = Config::get('hosting', []);
        return is_array($cfg) && trim((string) ($cfg[$lang] ?? '')) !== '';
    }

    /** Text für eine Sprache (die Sprache des Seitentexts, nicht unbedingt die der Besucher). */
    public static function text(string $lang): string
    {
        if (self::configured($lang)) {
            return trim((string) Config::get('hosting', [])[$lang]);
        }
        return self::MISSING[$lang] ?? self::MISSING['en'];
    }

    public static function fill(string $content, string $lang): string
    {
        return str_contains($content, self::PLACEHOLDER) ? str_replace(self::PLACEHOLDER, self::text($lang), $content) : $content;
    }
}
