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
 *
 * Edited texts are never replaced. Each stored text keeps a version marker instead (base()): the template it
 * was stored from or last reconciled with. When a newer template comes with an update, admins see which
 * sections it changed since (changes(), outdated()) and can take them over (merge()); for a text edited before
 * the markers existed, the sections in which it differs from the current template.
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
     * booked through it", i.e. also for the meeting and the summary mails in the teacher's mailbox.
     */
    private const MARKERS = [
        'old' => '/für dieses private Angebot nicht bestellt|appointed for this private service|privates, nicht kommerzielles Angebot'
            . '|private, non-commercial service|jederzeit selbst über Ihren persönlichen Link absagen|cancel your appointment yourself at any time/iu',
        'anytime' => '/jederzeit selbst über Ihren persönlichen Link absagen|cancel your appointment yourself at any time/iu',
        'private' => '/Art\. 6 Abs\. 1 lit\. [bf] DSGVO|Art\. 6\(1\)\([bf]\) GDPR/iu',
        'school' => '/Art\. 6 Abs\. 1 lit\. e\b|Art\. 6\(1\)\(e\)|Schulgesetz|school act|Datenschutzbeauftragte[nr]? der Schule|school\'s data protection officer/iu',
        'scope' => '/alle darüber gebuchten Termine ist:|all appointments booked through it is:/iu',
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

    /**
     * Whether a text is a template with its placeholders filled in: each [PLACEHOLDER] of $template replaced by
     * any text (also several lines, or nothing together with the blank in front of it), the rest the same.
     */
    public static function fills(string $text, string $template): bool
    {
        $parts = preg_split(self::PLACEHOLDER, $template) ?: [$template];
        $last = count($parts) - 1;
        $re = '';
        foreach ($parts as $i => $part) {
            $re .= $i < $last ? preg_quote(rtrim($part), '/') . '\s*.*?' : preg_quote($part, '/');
        }
        return $text === $template || ($last > 0 && preg_match('/^' . $re . '$/su', $text) === 1);
    }

    /**
     * A text in sections, one per heading of the first level ("# 5. Speicherdauer und Löschung"); the lines
     * before the first heading are the section "" (introduction). Keyed by the heading without its number, so
     * that a renumbered section is still the same one.
     * @return array<string, array{heading: ?string, lines: list<string>}> heading: the line as in the text
     */
    public static function sections(string $text): array
    {
        $sections = ['' => ['heading' => null, 'lines' => []]];
        $key = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/^#\s+(?:\d+\.\s*)?(.*?)\s*$/u', trim($line), $m)) {
                for ($key = $m[1], $n = 2; isset($sections[$key]); $n++) {
                    $key = $m[1] . " ($n)"; // the same heading twice
                }
                $sections[$key] = ['heading' => trim($line), 'lines' => []];
            } else {
                $sections[$key]['lines'][] = $line;
            }
        }
        return $sections;
    }

    /** Title of a section by its heading ("5. Speicherdauer und Löschung"), for admins; null = the introduction. */
    public static function sectionTitle(?string $heading): string
    {
        return $heading === null ? __('Einleitung') : ltrim(substr($heading, 1));
    }

    /**
     * @param array{heading: ?string, lines: list<string>}|null $section
     * @return list<string> the lines of a section that have text, trimmed (blank lines only separate paragraphs),
     *     with $heading its heading first (so that a renumbered or renamed section counts as changed)
     */
    private static function content(?array $section, bool $heading = true): array
    {
        $lines = [...($heading && isset($section['heading']) ? [$section['heading']] : []), ...($section['lines'] ?? [])];
        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Lines removed ("-") and added ("+") from $old to $new, in order (longest common subsequence). With $fill
     * a line of $old is the same as a line of $new it fills in (fills()), unless that line is nothing but a
     * placeholder: it would be filled in by any line.
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{0: string, 1: string}> [" ", the line of $old] for a line in both, else ["-"|"+", line]
     */
    public static function diff(array $old, array $new, bool $fill = false): array
    {
        $same = static fn (string $a, string $b): bool => $a === $b
            || ($fill && trim((string) preg_replace(self::PLACEHOLDER, '', $b)) !== '' && self::fills($a, $b));
        $n = count($old);
        $m = count($new);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $same($old[$i], $new[$j]) ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $diff = [];
        for ($i = $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $same($old[$i], $new[$j])) {
                $diff[] = [' ', $old[$i++]];
                $j++;
            } elseif ($j >= $m || ($i < $n && $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
                $diff[] = ['-', $old[$i++]];
            } else {
                $diff[] = ['+', $new[$j++]];
            }
        }
        return $diff;
    }

    /**
     * What a stored text is missing from the current template, per section. With its version marker ($base,
     * see base()): the sections the template changed since, with the lines removed from and added to the
     * template; own changes to sections the template did not change are the operator's business. Without one:
     * the sections in which the text differs from the template, with the lines only in the text ("-") and
     * only in the template ("+"). Sections whose text already has the wording of the current template
     * (placeholders filled in) are left out either way.
     * @return list<array{key: string, heading: ?string, diff: list<array{0: string, 1: string}>}> heading null = introduction
     */
    public static function changes(string $stored, ?string $base, string $current): array
    {
        $text = self::sections($stored);
        $from = $base !== null ? self::sections($base) : null;
        $now = self::sections($current);
        $changes = [];
        foreach ($now as $key => $section) {
            $lines = self::content($section);
            $old = $from !== null ? self::content($from[$key] ?? null) : null;
            $have = self::content($text[$key] ?? null);
            if ($old !== $lines && !self::fills(implode("\n", $have), implode("\n", $lines))) {
                $changes[] = ['key' => $key, 'heading' => $section['heading'], 'diff' => self::changed(self::diff($old ?? $have, $lines, $from === null))];
            }
        }
        // Sections the template no longer has, as long as the text still has them
        foreach ($from ?? $text as $key => $section) {
            if (!isset($now[$key]) && self::content($text[$key] ?? null) !== []) {
                $changes[] = ['key' => $key, 'heading' => $section['heading'], 'diff' => self::diff(self::content($section), [])];
            }
        }
        return $changes;
    }

    /**
     * @param list<array{0: string, 1: string}> $diff
     * @return list<array{0: string, 1: string}> only the lines removed or added
     */
    private static function changed(array $diff): array
    {
        return array_values(array_filter($diff, static fn (array $d): bool => $d[0] !== ' '));
    }

    /**
     * The stored text with sections ($keys, see changes()) taken from the current template: their wording as in
     * the template, keeping the lines the text already has with placeholders filled in; sections new in the
     * template are inserted where it has them, sections it no longer has are removed. The other sections stay
     * as they are (one blank line between sections).
     * @param list<string> $keys
     */
    public static function merge(string $stored, string $current, array $keys): string
    {
        $text = self::sections($stored);
        $now = self::sections($current);
        // A section new in the template goes after the one before it in the template that the text has
        $after = [];
        $prev = '';
        foreach ($now as $key => $section) {
            if (isset($text[$key])) {
                $prev = $key;
            } elseif (in_array($key, $keys, true)) {
                $after[$prev][] = $key;
            }
        }
        $blocks = [];
        foreach ($text as $key => $section) {
            $take = in_array($key, $keys, true);
            if (!$take || isset($now[$key])) {
                $blocks[] = $take ? self::mergeSection($section, $now[$key]) : $section;
            }
            foreach ($after[$key] ?? [] as $new) {
                $blocks[] = self::mergeSection(null, $now[$new]);
            }
        }
        $out = [];
        foreach ($blocks as $block) {
            $lines = [...($block['heading'] !== null ? [$block['heading']] : []), ...$block['lines']];
            while ($lines !== [] && trim((string) end($lines)) === '') {
                array_pop($lines);
            }
            if ($lines !== []) {
                $out[] = implode("\n", $lines);
            }
        }
        return implode("\n\n", $out) . "\n";
    }

    /**
     * A section of the template, its lines replaced by those of the text's section that fill them in.
     * @param array{heading: ?string, lines: list<string>}|null $section
     * @param array{heading: ?string, lines: list<string>} $template
     * @return array{heading: ?string, lines: list<string>}
     */
    private static function mergeSection(?array $section, array $template): array
    {
        // In the order of the template's lines: the text's line that fills one in, or the template's line
        $fill = array_values(array_filter(self::diff(self::content($section, false), self::content($template, false), true), static fn (array $d): bool => $d[0] !== '-'));
        $lines = [];
        foreach ($template['lines'] as $line) {
            $lines[] = trim($line) === '' ? $line : array_shift($fill)[1];
        }
        return ['heading' => $template['heading'], 'lines' => $lines];
    }

    /**
     * Version marker of a stored text: the template it is based on (operator type and wording of the template
     * as it was when the text was stored from or reconciled with it). null = unknown, i.e. edited before the
     * markers were recorded, or never stored from a template.
     * @return array{type: string, content: string}|null
     */
    public static function base(string $key, string $lang): ?array
    {
        $base = json_decode((string) Settings::get("legal_base.$key.$lang"), true);
        return is_array($base) && in_array($base['type'] ?? null, Operator::TYPES, true) && is_string($base['content'] ?? null)
            ? ['type' => (string) $base['type'], 'content' => $base['content']] : null;
    }

    /** Records the current template of the operator type as the version marker of a stored text (base()). */
    public static function setBase(string $key, string $lang, string $type): void
    {
        $template = self::template($key, $type, $lang);
        $json = $template !== null ? json_encode(['type' => $type, 'content' => $template], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : false;
        // settings.value is a TEXT column (64 KB): a longer template of one's own stays without a marker
        if ($json !== false && strlen($json) < 65000) {
            Settings::set("legal_base.$key.$lang", $json);
        }
    }

    /** Whether a text has the wording of the current template of the operator type (placeholders filled in). */
    public static function follows(string $key, string $lang, string $content, string $type): bool
    {
        $current = self::template($key, $type, $lang);
        return $current !== null && self::changes($content, null, $current) === [];
    }

    /**
     * What a stored text is missing from the current template of the operator type (changes()): "known" with a
     * version marker of that type, otherwise the differences to the template. null = nothing to do: up to date,
     * no type chosen yet (the admins are asked for it first) or no template in this language.
     * @return array{type: string, known: bool, changes: list<array{key: string, heading: ?string, diff: list<array{0: string, 1: string}>}>}|null
     */
    public static function updates(string $key, string $lang, string $stored, ?string $type): ?array
    {
        $current = $type !== null ? self::template($key, $type, $lang) : null;
        $base = $current !== null && $stored !== $current ? self::base($key, $lang) : null;
        if ($current === null || $stored === $current || ($base['content'] ?? null) === $current) {
            return null;
        }
        $known = ($base['type'] ?? null) === $type; // a marker of the other type: the text was not reconciled with this one
        $changes = self::changes($stored, $known ? $base['content'] : null, $current);
        return $changes !== [] ? ['type' => (string) $type, 'known' => $known, 'changes' => $changes] : null;
    }

    /**
     * The stored texts of the offered languages that are behind the current template of the operator type
     * (updates()), legal notice first.
     * @return list<array{key: string, lang: string, type: string, known: bool, changes: list<array{key: string, heading: ?string, diff: list<array{0: string, 1: string}>}>}>
     */
    public static function outdated(): array
    {
        $outdated = [];
        foreach (self::stored() as $key => $texts) {
            foreach ($texts as $lang => $content) {
                if (($update = self::updates($key, (string) $lang, $content, Operator::type())) !== null) {
                    $outdated[] = ['key' => $key, 'lang' => (string) $lang] + $update;
                }
            }
        }
        return $outdated;
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
     * unedited template (of either type, current or earlier, or exactly the template of its version marker
     * $base). null = keep it: edited, already the right template, or no template in this language.
     */
    public static function replacement(string $key, string $lang, ?string $stored, string $type, ?string $base = null): ?string
    {
        $new = self::template($key, $type, $lang);
        return $new === null || $stored === $new || ($stored !== null && $stored !== $base && self::templateType($key, $stored) === null) ? null : $new;
    }

    /**
     * Operator type of an installation from before the type was asked for, from its stored texts: "private"
     * if every text with a template is an unedited template of a private service (what the templates up to
     * v1.6 described), otherwise null – the admins have to choose.
     * @param array<string, array<string, string>> $stored page key => language => text
     */
    public static function detectType(array $stored): ?string
    {
        $types = [];
        foreach ($stored as $key => $texts) {
            foreach ($texts as $lang => $content) {
                if (self::template($key, 'private', (string) $lang) !== null) {
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
     * unedited template (replacement()), with the template as its version marker (base()). Edited texts are
     * never touched; one that has the wording of the current template (placeholders filled in) gets it as its
     * version marker, e.g. a text edited before the markers were recorded.
     * @return int number of texts written
     */
    public static function apply(string $type): int
    {
        $stored = self::stored();
        $written = 0;
        foreach (self::KEYS as $key) {
            foreach (I18n::languages() as $lang) {
                $text = $stored[$key][$lang] ?? null;
                $base = self::base($key, $lang)['content'] ?? null;
                $new = self::replacement($key, $lang, $text, $type, $base);
                if ($new !== null) {
                    Db::run(
                        'INSERT INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = VALUES(updated_at)',
                        [$key, $lang, $new, Time::nowSql()]
                    );
                    self::setBase($key, $lang, $type);
                    $written++;
                } elseif ($text !== null && $base !== self::template($key, $type, $lang) && self::follows($key, $lang, $text, $type)) {
                    self::setBase($key, $lang, $type);
                }
            }
        }
        return $written;
    }
}
