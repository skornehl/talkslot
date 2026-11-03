<?php
declare(strict_types=1);

namespace App;

/**
 * Very small, safe Markdown for legal notice/privacy policy:
 * # headings, paragraphs, - lists, > notice, **bold**, *italic*, [text](URL).
 * Everything is escaped first; links only with http(s), mailto or a relative path.
 */
final class Markdown
{
    public static function toHtml(string $text): string
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $html = [];
        $para = [];
        $list = [];
        $quote = [];

        $flush = static function () use (&$html, &$para, &$list, &$quote): void {
            if ($para) {
                $html[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
                $para = [];
            }
            if ($list) {
                $html[] = '<ul>' . implode('', array_map(static fn ($i) => '<li>' . self::inline($i) . '</li>', $list)) . '</ul>';
                $list = [];
            }
            if ($quote) {
                $html[] = '<blockquote>' . implode('<br>', array_map([self::class, 'inline'], $quote)) . '</blockquote>';
                $quote = [];
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flush();
            } elseif (preg_match('/^(#{1,3})\s+(.*)$/', $trim, $m)) {
                $flush();
                $level = strlen($m[1]) + 1; // # → h2 (h1 is the page title)
                $html[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
            } elseif (preg_match('/^[-*]\s+(.*)$/', $trim, $m)) {
                if ($para || $quote) {
                    $flush();
                }
                $list[] = $m[1];
            } elseif (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                if ($para || $list) {
                    $flush();
                }
                $quote[] = $m[1];
            } else {
                if ($list || $quote) {
                    $flush();
                }
                $para[] = $trim;
            }
        }
        $flush();
        return implode("\n", $html);
    }

    private static function inline(string $s): string
    {
        $s = e($s);
        $s = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Relative only with exactly one "/" (not //host or /\host = foreign domain)
            if (!preg_match('#^(https?://|mailto:|/(?![/\\\\]))#i', $href)) {
                return $m[0];
            }
            return '<a href="' . e($href) . '">' . $m[1] . '</a>';
        }, $s);
        $s = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $s);
        return $s;
    }
}
