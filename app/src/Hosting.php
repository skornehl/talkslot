<?php
declare(strict_types=1);

namespace App;

/**
 * Hosting provider details for the privacy policy: they replace the placeholder [[HOSTER]] there.
 * They live in the configuration (config.php → 'hosting', per language; on deployment the
 * variables HOSTING_DE and HOSTING_EN; other languages may have their own or take the English one), because
 * they belong to the server, not to the school.
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

    /**
     * Language whose configured text fills in [[HOSTER]] in a text of $lang, or null if there is none. German
     * and English need their own (the deployment sets both); the translations of the privacy policy into the
     * other parent languages take their own if there is one, otherwise the English one, then the German one.
     */
    public static function source(string $lang): ?string
    {
        foreach (in_array($lang, ['de', 'en'], true) ? [$lang] : [$lang, 'en', 'de'] as $l) {
            if (self::configured($l)) {
                return $l;
            }
        }
        return null;
    }

    /** Text for one language (the language of the page text, not necessarily that of the visitor). */
    public static function text(string $lang): string
    {
        $source = self::source($lang);
        if ($source !== null) {
            return trim((string) Config::get('hosting', [])[$source]);
        }
        return self::MISSING[$lang] ?? self::MISSING['en'];
    }

    public static function fill(string $content, string $lang): string
    {
        return str_contains($content, self::PLACEHOLDER) ? str_replace(self::PLACEHOLDER, self::text($lang), $content) : $content;
    }
}
