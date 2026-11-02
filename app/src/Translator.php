<?php
declare(strict_types=1);

namespace App;

/**
 * Übersetzungen von Titel und Beschreibung einer Runde.
 *
 * Automatisch übersetzt der Browser der Lehrkraft auf dem eigenen Gerät (Translator API in
 * Chrome/Edge am Computer, public/assets/js/app.js) und schickt das Ergebnis hierher
 * (acceptAuto). Es geht kein Text an einen Übersetzungsdienst. Alternativ trägt die
 * Lehrkraft Übersetzungen von Hand ein.
 *
 * Jede Übersetzung merkt sich den Hash des Originals, aus dem sie entstanden ist. Ändert die
 * Lehrkraft Titel oder Beschreibung, gilt sie als veraltet und Eltern sehen wieder das Original,
 * bis sie neu übersetzt, von Hand angepasst oder als weiterhin passend bestätigt wurde.
 */
final class Translator
{
    /** Längen wie im Formular (Titel 200, Beschreibung 5000 Zeichen) */
    private const MAX_TITLE = 200;
    private const MAX_DESCRIPTION = 5000;

    /** Hash des Originals – ändert er sich, sind vorhandene Übersetzungen veraltet. */
    public static function sourceHash(array $round): string
    {
        return hash('sha256', $round['source_lang'] . "\0" . $round['title'] . "\0" . ($round['description'] ?? ''));
    }

    /**
     * Alle Übersetzungen einer Runde, nach Sprachcode.
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
     * Sprachen, die (neu) übersetzt werden sollten: fehlend oder veraltet. Aktuelle
     * Übersetzungen (automatisch oder von Hand) bleiben unangetastet.
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

    /** @return list<string> alle Sprachen außer der Ausgangssprache */
    public static function targetLanguages(array $round): array
    {
        return array_values(array_filter(I18n::languages(), static fn ($l) => $l !== $round['source_lang']));
    }

    /**
     * Übernimmt im Browser erstellte Übersetzungen, wenn sie zum aktuellen Original gehören.
     * Ohne $force werden nur fehlende/veraltete Sprachen gefüllt, aktuelle bleiben erhalten.
     * @param array<string, array{title:string, description:string}> $texts je Sprache
     * @return list<string>|null gespeicherte Sprachen; null = Original inzwischen geändert
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
                continue; // im Browser nicht übersetzbar (z. B. Sprachpaar nicht verfügbar)
            }
            $description = trim(str_replace("\r\n", "\n", (string) ($texts[$lang]['description'] ?? '')));
            self::save((int) $round['id'], $lang, $title, $description, $hash, false);
            $saved[] = $lang;
        }
        return $saved;
    }

    /**
     * Ersetzt Titel/Beschreibung durch die Übersetzung in $lang, aber nur, wenn sie zum
     * aktuellen Original passt; fehlt sie oder ist sie veraltet, bleibt das Original.
     * Eine leere übersetzte Beschreibung ersetzt die Original-Beschreibung nicht.
     *
     * $row ist eine Rundenzeile oder eine Buchungszeile aus BookingService (DETAIL_SQL) und
     * muss title, description und source_lang der Runde enthalten. Wurde eine automatische
     * (nicht von Hand geprüfte) Übersetzung eingesetzt, ist danach $row['auto_translated'] true.
     */
    public static function localize(array $row, int $roundId, string $lang): array
    {
        foreach (['title', 'description', 'source_lang'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \LogicException("Translator::localize(): Feld „{$key}“ der Runde fehlt.");
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
