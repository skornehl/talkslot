<?php
declare(strict_types=1);

namespace App;

/**
 * Legal notice and privacy policy: the stored texts as parents see them, their open placeholders and the
 * templates in app/templates/pages.
 *
 * Templates: "<key>.<lang>.md" is the default and is installed. For the privacy policy it is written for a
 * public school (Art. 6(1)(e) GDPR in conjunction with state law); "privacy-private.<lang>.md" is the variant
 * for a private operator (Art. 6(1)(b) and (f)), which admins load into the text form themselves.
 *
 * Details only the school can provide (data protection officer, state law, log retention, supervisory
 * authority) are placeholders in square brackets in upper case, e.g. [ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE].
 * As long as a text still contains one, no round can be activated (rounds already active stay online).
 */
final class LegalTexts
{
    public const KEYS = ['imprint', 'privacy'];

    /** Template variants per page: variant => file name without ".<lang>.md" (the first one is installed) */
    public const VARIANTS = [
        'imprint' => ['default' => 'imprint'],
        'privacy' => ['public' => 'privacy', 'private' => 'privacy-private'],
    ];

    /**
     * An open placeholder: square brackets around at least four characters without lower-case letters,
     * starting with an upper-case one (also [[UNKNOWN]]). Not a Markdown link ([PDF](https://…)).
     * With /u: the texts are UTF-8 (Ä, Cyrillic, …).
     */
    private const PLACEHOLDER = '/\[{1,2}\p{Lu}[^\[\]\p{Ll}\n]{3,}\]{1,2}(?!\()/u';

    /** @return list<string> open placeholders in a text, each once, in order of appearance */
    public static function placeholders(string $text): array
    {
        return preg_match_all(self::PLACEHOLDER, $text, $m) ? array_values(array_unique($m[0])) : [];
    }

    /** A text as parents see it: details of the operator ($fallback if missing) and the web host filled in. */
    public static function render(string $content, string $lang, string $fallback): string
    {
        return Hosting::fill(Operator::fill($content, $fallback), $lang);
    }

    /**
     * Open placeholders in the stored texts of all offered languages, as parents see them (an unconfigured
     * web host shows up as [HOSTER MIT ANSCHRIFT] here as well).
     * @return list<array{key: string, lang: string, placeholders: list<string>}> privacy policy first
     */
    public static function open(): array
    {
        $open = [];
        $rows = Db::all("SELECT page_key, lang, content FROM pages WHERE page_key IN ('imprint', 'privacy') ORDER BY page_key = 'imprint', lang");
        foreach ($rows as $row) {
            // Only offered languages: parents do not see the others, and admins cannot edit them
            $found = in_array($row['lang'], I18n::languages(), true)
                ? self::placeholders(self::render((string) $row['content'], (string) $row['lang'], '')) : [];
            if ($found) {
                $open[] = ['key' => (string) $row['page_key'], 'lang' => (string) $row['lang'], 'placeholders' => $found];
            }
        }
        return $open;
    }

    /** Template of a page in one variant and language (null if there is none). */
    public static function template(string $key, string $variant, string $lang): ?string
    {
        $name = self::VARIANTS[$key][$variant] ?? null;
        $file = APP_ROOT . "/app/templates/pages/$name.$lang.md";
        return $name !== null && preg_match('/^[a-z]{2}$/', $lang) && is_file($file) ? (string) file_get_contents($file) : null;
    }
}
