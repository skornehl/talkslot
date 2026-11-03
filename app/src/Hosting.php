<?php
declare(strict_types=1);

namespace App;

/**
 * Hosting provider details for the privacy policy: they replace the placeholder [[HOSTER]] there.
 * They live in the configuration (config.php → 'hosting', per language; on deployment the
 * variables HOSTING_DE and HOSTING_EN), because they belong to the server, not to the school.
 * If they are missing, a visible placeholder appears.
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

    /** Text for one language (the language of the page text, not necessarily that of the visitor). */
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
