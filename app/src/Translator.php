<?php
declare(strict_types=1);

namespace App;

/**
 * Translations of a round's title and description.
 *
 * Automatic translation is done by the teacher's browser on their own device (Translator API in
 * Chrome/Edge on desktop, public/assets/js/app.js), which sends the result here
 * (acceptAuto). No text is sent to a translation service. Alternatively the
 * teacher enters translations by hand.
 *
 * Each translation remembers the hash of the original it was created from. If the
 * teacher changes the title or description, it counts as stale and parents see the original again
 * until it has been retranslated, adjusted by hand or confirmed as still fitting.
 */
final class Translator
{
    /** Lengths as in the form (title 200, description 5000 characters) */
    private const MAX_TITLE = 200;
    private const MAX_DESCRIPTION = 5000;

    /** Hash of the original – if it changes, existing translations are stale. */
    public static function sourceHash(array $round): string
    {
        return hash('sha256', $round['source_lang'] . "\0" . $round['title'] . "\0" . ($round['description'] ?? ''));
    }

    /**
     * All translations of a round, by language code.
     * @return array<string, array<string, mixed>>
     */
    public static function forRound(int $roundId): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM round_translations WHERE round_id = ?', [$roundId]) as $t) {
            $out[$t['lang']] = $t;
        }
        return $out;
    }

    /**
     * Languages that should be (re)translated: missing or stale. Current
     * translations (automatic or by hand) are left untouched.
     * @return list<string>
     */
    public static function pendingLanguages(array $round): array
    {
        $hash = self::sourceHash($round);
        $existing = self::forRound((int) $round['id']);
        $out = [];
        foreach (self::targetLanguages($round) as $lang) {
            $t = $existing[$lang] ?? null;
            if (!$t || $t['source_hash'] !== $hash) {
                $out[] = $lang;
            }
        }
        return $out;
    }

    /** @return list<string> all languages except the source language */
    public static function targetLanguages(array $round): array
    {
        return array_values(array_filter(I18n::languages(), static fn ($l) => $l !== $round['source_lang']));
    }

    /**
     * Accepts translations created in the browser if they belong to the current original.
     * Without $force only missing/stale languages are filled, current ones are kept.
     * @param array<string, array{title:string, description:string}> $texts per language
     * @return list<string>|null saved languages; null = original has changed in the meantime
     */
    public static function acceptAuto(array $round, string $sourceHash, array $texts, bool $force): ?array
    {
        $hash = self::sourceHash($round);
        if (!hash_equals($hash, $sourceHash)) {
            return null;
        }
        $todo = $force ? self::targetLanguages($round) : self::pendingLanguages($round);
        $saved = [];
        foreach ($todo as $lang) {
            $title = trim((string) ($texts[$lang]['title'] ?? ''));
            if ($title === '') {
                continue; // not translatable in the browser (e.g. language pair not available)
            }
            $description = trim(str_replace("\r\n", "\n", (string) ($texts[$lang]['description'] ?? '')));
            self::save((int) $round['id'], $lang, $title, $description, $hash, false);
            $saved[] = $lang;
        }
        return $saved;
    }

    /**
     * Replaces title/description with the translation into $lang, but only if it matches the
     * current original; if it is missing or stale, the original stays.
     * An empty translated description does not replace the original description.
     *
     * $row is a round row or a booking row from BookingService (DETAIL_SQL) and
     * must contain title, description and source_lang of the round. If an automatic
     * (not reviewed by hand) translation was used, $row['auto_translated'] is true afterwards.
     */
    public static function localize(array $row, int $roundId, string $lang): array
    {
        foreach (['title', 'description', 'source_lang'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \LogicException("Translator::localize(): field {$key} of the round is missing.");
            }
        }
        $row['auto_translated'] = false;
        if ($roundId <= 0 || $lang === $row['source_lang']) {
            return $row;
        }
        $t = Db::one('SELECT title, description, source_hash, is_manual FROM round_translations WHERE round_id = ? AND lang = ?', [$roundId, $lang]);
        if (!$t || $t['source_hash'] !== self::sourceHash($row) || trim((string) $t['title']) === '') {
            return $row;
        }
        $row['title'] = $t['title'];
        if (trim((string) ($t['description'] ?? '')) !== '') {
            $row['description'] = $t['description'];
        }
        $row['auto_translated'] = !$t['is_manual'];
        return $row;
    }

    public static function save(int $roundId, string $lang, string $title, string $description, string $hash, bool $manual): void
    {
        $now = Time::nowSql();
        Db::run(
            'INSERT INTO round_translations (round_id, lang, title, description, source_hash, is_manual, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),
                 source_hash = VALUES(source_hash), is_manual = VALUES(is_manual), updated_at = VALUES(updated_at)',
            [$roundId, $lang, mb_substr($title, 0, self::MAX_TITLE),
             $description !== '' ? mb_substr($description, 0, self::MAX_DESCRIPTION) : null, $hash, $manual ? 1 : 0, $now]
        );
    }
}
