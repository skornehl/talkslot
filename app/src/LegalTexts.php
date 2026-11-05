<?php
declare(strict_types=1);

namespace App;

/**
 * Legal notice and privacy policy: the stored texts as parents see them, their open placeholders and the
 * templates in app/templates/pages.
 *
 * Templates: one per page and operator type (Operator::type(), VARIANTS). "school": the school runs the
 * platform as a public body and is the controller (Art. 6(1)(e) GDPR in conjunction with the state school act,
 * the school's data protection officer, legal notice of a public body). "private": a private person,
 * association or independent provider runs it and is the controller, teachers use the service (Art. 6(1)(b)
 * and (f), legal notice of a private, non-commercial service). The installer stores the templates of the
 * chosen type; on a change of type or a template update apply() replaces the texts nobody has edited.
 *
 * Details only the operator can provide (data protection officer, state law, log retention, supervisory
 * authority) are placeholders in square brackets in upper case, e.g. [ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE].
 * As long as a text still contains one, no round can be activated (rounds already active stay online).
 */
final class LegalTexts
{
    public const KEYS = ['imprint', 'privacy'];

    /** Templates per page: operator type (Operator::TYPES) => file name without ".<lang>.md" */
    public const VARIANTS = [
        'imprint' => ['school' => 'imprint', 'private' => 'imprint-private'],
        'privacy' => ['school' => 'privacy', 'private' => 'privacy-private'],
    ];

    /**
     * Wording that belongs to a template of a certain kind (mismatches()): "old" the templates up to v1.6 (a
     * "private service" without a data protection officer, its legal notice, cancelling "at any time"),
     * "anytime" only the last of these, "private" the legal bases of a private operator (Art. 6(1)(b), (f)),
     * "school" the school template (Art. 6(1)(e), state school act, the school's data protection officer),
     * "scope" a private template up to talkslot PR #39: the operator named as controller "for all appointments
     * booked through it", i.e. also for the meeting and the summary mails in the teacher's mailbox. "checkbox"
     * the required checkbox in the booking form that the texts up to expert review P1-9 described
     * ("acknowledgement, not consent"); the form no longer has it. The translations into the other parent
     * languages cite the GDPR as "6(1)(e)" or, in Polish, "art. 6 ust. 1 lit. e RODO".
     */
    private const MARKERS = [
        'old' => '/für dieses private Angebot nicht bestellt|appointed for this private service|privates, nicht kommerzielles Angebot'
            . '|private, non-commercial service|jederzeit selbst über Ihren persönlichen Link absagen|cancel your appointment yourself at any time/iu',
        'anytime' => '/jederzeit selbst über Ihren persönlichen Link absagen|cancel your appointment yourself at any time/iu',
        'private' => '/Art\. 6 Abs\. 1 lit\. [bf] DSGVO|\b6\(1\)\([bf]\)|\bart\. 6 ust\. 1 lit\. [bf]\b/iu',
        'school' => '/Art\. 6 Abs\. 1 lit\. e\b|\b6\(1\)\(e\)|\bart\. 6 ust\. 1 lit\. e\b|Schulgesetz|school act|Datenschutzbeauftragte[nr]? der Schule|school\'s data protection officer/iu',
        'scope' => '/alle darüber gebuchten Termine ist:|all appointments booked through it is:/iu',
        'checkbox' => '/Pflicht-Häkchen|required box in the booking form/iu',
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

    /** Template of a page for an operator type in one language (null if there is none). */
    public static function template(string $key, string $type, string $lang): ?string
    {
        $name = self::VARIANTS[$key][$type] ?? null;
        $file = APP_ROOT . "/app/templates/pages/$name.$lang.md";
        return $name !== null && preg_match('/^[a-z]{2}$/', $lang) && is_file($file) ? (string) file_get_contents($file) : null;
    }

    /**
     * Operator type a stored text is an unedited template of: one of the current templates of this page or
     * an earlier one (Migrations::OLD_PAGE_HASHES). null = edited (or never a template).
     */
    public static function templateType(string $key, string $content): ?string
    {
        $hash = hash('sha256', $content);
        $types = array_flip(self::VARIANTS[$key] ?? []);
        foreach (Migrations::CURRENT_PAGE_HASHES as $file => $current) {
            $type = $types[(string) preg_replace('/\.[a-z]{2}$/', '', $file)] ?? null;
            if ($type !== null && $current === $hash) {
                return $type;
            }
        }
        foreach (Migrations::OLD_PAGE_HASHES as $type => $hashes) {
            if (in_array($hash, $hashes, true)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * What to store instead of a text for the operator type: its template if the text is missing or an
     * unedited template (of either type, current or earlier). null = keep it: edited, already the right
     * template, or no template in this language.
     */
    public static function replacement(string $key, string $lang, ?string $stored, string $type): ?string
    {
        $new = self::template($key, $type, $lang);
        return $new === null || $stored === $new || ($stored !== null && self::templateType($key, $stored) === null) ? null : $new;
    }

    /**
     * Operator type of an installation from before the type was asked for, from its stored texts: "private"
     * if every text in German or English that has a template is an unedited template of a private service (what
     * the templates up to v1.6 described), otherwise null – the admins have to choose. Texts in other languages
     * do not count: up to v1.6 there were no templates for them.
     * @param array<string, array<string, string>> $stored page key => language => text
     */
    public static function detectType(array $stored): ?string
    {
        $types = [];
        foreach ($stored as $key => $texts) {
            foreach ($texts as $lang => $content) {
                if (in_array((string) $lang, ['de', 'en'], true) && self::template($key, 'private', (string) $lang) !== null) {
                    $types[] = self::templateType($key, $content);
                }
            }
        }
        return array_values(array_unique($types)) === ['private'] ? 'private' : null;
    }

    /**
     * Wording in a text that does not fit the operator type (null = not chosen yet), for the notes to admins:
     * for a school "old" (the template up to v1.6) or else "private" (legal bases of a private operator); for a
     * private operator "school" (school template), "scope" (controller also for the meeting and the teacher's
     * mailbox) and "anytime" (cancelling "at any time", also without a type).
     * @return list<string>
     */
    public static function mismatches(string $content, ?string $type): array
    {
        $has = static fn (string $kind): bool => (bool) preg_match(self::MARKERS[$kind], $content);
        if ($type === 'school') {
            return $has('old') ? ['old'] : ($has('private') ? ['private'] : []);
        }
        return array_values(array_filter($type === 'private' ? ['school', 'scope', 'anytime'] : ['anytime'], $has));
    }

    /**
     * Wording that no longer fits whatever the operator type, for the notes to admins: "checkbox" (the required
     * checkbox in the booking form, which the form no longer has since expert review P1-9).
     * @return list<string>
     */
    public static function obsolete(string $content): array
    {
        return preg_match(self::MARKERS['checkbox'], $content) ? ['checkbox'] : [];
    }

    /**
     * Languages whose texts parents get when there is no complete one in their own language (the order of
     * forParents() after their own language): English and the default language. Open placeholders in these
     * texts keep rounds from being activated; in any other language they only mean that parents get one of
     * these instead until the admins have filled them in.
     * @return list<string>
     */
    public static function fallbackLanguages(): array
    {
        return array_values(array_unique(['en', I18n::defaultLanguage()]));
    }

    /**
     * open() split into the texts that keep rounds from being activated (a fallback language) and the others,
     * e.g. a translated privacy policy whose placeholders are still open.
     * @param list<array{key: string, lang: string, placeholders: list<string>}> $open
     * @return array{0: list<array{key: string, lang: string, placeholders: list<string>}>, 1: list<array{key: string, lang: string, placeholders: list<string>}>}
     */
    public static function splitOpen(array $open): array
    {
        $fallback = self::fallbackLanguages();
        $blocking = static fn (array $t): bool => in_array($t['lang'], $fallback, true);
        return [array_values(array_filter($open, $blocking)), array_values(array_filter($open, static fn (array $t): bool => !$blocking($t)))];
    }

    /**
     * Which of the stored texts of a page parents get, as they see them ($texts: language => text with the
     * operator's details and the web host filled in): the first in $order (their language, then the fallback
     * languages) that is not empty and either complete (no open placeholders) or in a fallback language. A
     * translation with open placeholders is thus skipped; an incomplete text in English or the default language
     * is shown as before (it keeps rounds from being activated, see splitOpen()). If only incomplete
     * translations are there, the first of them; null if there is no text. An emptied text counts as missing, so
     * admins who do not want to keep a translation can empty it.
     * @param array<string, string> $texts
     * @param list<string> $order
     */
    public static function pick(array $texts, array $order): ?string
    {
        $present = array_values(array_filter(array_unique($order), static fn (string $l): bool => trim($texts[$l] ?? '') !== ''));
        foreach ($present as $lang) {
            if (in_array($lang, self::fallbackLanguages(), true) || self::placeholders($texts[$lang]) === []) {
                return $lang;
            }
        }
        return $present[0] ?? null;
    }

    /**
     * The text of a page that parents in $lang get (pick()), as they see it: [text, its language], or null if
     * there is none ($fallback: see render()).
     * @return array{0: string, 1: string}|null
     */
    public static function forParents(string $key, string $lang, string $fallback): ?array
    {
        $order = array_values(array_unique([$lang, ...self::fallbackLanguages()]));
        $texts = [];
        foreach (Db::all('SELECT lang, content FROM pages WHERE page_key = ?', [$key]) as $row) {
            if (in_array($row['lang'], $order, true)) {
                $texts[(string) $row['lang']] = self::render((string) $row['content'], (string) $row['lang'], $fallback);
            }
        }
        $chosen = self::pick($texts, $order);
        return $chosen !== null ? [$texts[$chosen], $chosen] : null;
    }

    /** @return array<string, array<string, string>> stored texts of the offered languages: page key => language => text */
    public static function stored(): array
    {
        $stored = [];
        foreach (Db::all("SELECT page_key, lang, content FROM pages WHERE page_key IN ('imprint', 'privacy') ORDER BY page_key, lang") as $row) {
            if (in_array($row['lang'], I18n::languages(), true)) {
                $stored[(string) $row['page_key']][(string) $row['lang']] = (string) $row['content'];
            }
        }
        return $stored;
    }

    /**
     * Stores the templates of the operator type wherever a text of an offered language is missing or an
     * unedited template (replacement()). Edited texts are never touched.
     * @return int number of texts written
     */
    public static function apply(string $type): int
    {
        $stored = self::stored();
        $written = 0;
        foreach (self::KEYS as $key) {
            foreach (I18n::languages() as $lang) {
                $new = self::replacement($key, $lang, $stored[$key][$lang] ?? null, $type);
                if ($new !== null) {
                    Db::run(
                        'INSERT INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = VALUES(updated_at)',
                        [$key, $lang, $new, Time::nowSql()]
                    );
                    $written++;
                }
            }
        }
        return $written;
    }
}
