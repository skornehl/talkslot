<?php
declare(strict_types=1);

namespace App;

/**
 * Schema-Änderungen für bereits installierte Datenbanken.
 * schema.sql beschreibt immer den neuesten Stand; neue Installationen bekommen
 * direkt LATEST. Bestehende werden beim nächsten Aufruf schrittweise angehoben.
 */
final class Migrations
{
    public const LATEST = 12;

    /** Version => SQL-Anweisungen */
    private const STEPS = [
        2 => ['ALTER TABLE rounds ADD COLUMN IF NOT EXISTS access_pin VARCHAR(12) NULL AFTER description'],
        // Mehrere Lehrkräfte: admin_users → users mit Rollen, Runden gehören einer Person
        3 => [
            'RENAME TABLE admin_users TO users',
            "ALTER TABLE users
                MODIFY username VARCHAR(254) NOT NULL,
                ADD COLUMN role ENUM('admin','teacher') NOT NULL DEFAULT 'teacher' AFTER password_hash,
                ADD COLUMN display_name VARCHAR(200) NOT NULL DEFAULT '' AFTER role,
                ADD COLUMN email VARCHAR(254) NULL AFTER display_name,
                ADD COLUMN controller_contact TEXT NULL AFTER email,
                ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER controller_contact,
                ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
                ADD COLUMN summary_evening TINYINT(1) NOT NULL DEFAULT 1 AFTER must_change_password,
                ADD COLUMN summary_deadline TINYINT(1) NOT NULL DEFAULT 1 AFTER summary_evening,
                DROP COLUMN locked_until",
            "UPDATE users SET role = 'admin', display_name = username",
            "UPDATE users SET email = (SELECT value FROM settings WHERE name = 'notify_email')",
            'ALTER TABLE rounds ADD COLUMN user_id INT UNSIGNED NULL AFTER id',
            'UPDATE rounds SET user_id = (SELECT MIN(id) FROM users)',
            'ALTER TABLE rounds MODIFY user_id INT UNSIGNED NOT NULL, DROP COLUMN notify_teacher,
                ADD KEY idx_rounds_user (user_id),
                ADD CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
            "CREATE TABLE IF NOT EXISTS sent_summaries (
                user_id INT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL, ref VARCHAR(40) NOT NULL, sent_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, kind, ref),
                CONSTRAINT fk_summaries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "DELETE FROM settings WHERE name = 'notify_email'",
        ],
        // Einladungs-/Passwort-Links statt Startpasswörter per Mail
        4 => [
            'ALTER TABLE users
                ADD COLUMN IF NOT EXISTS access_token_hash CHAR(64) NULL AFTER must_change_password,
                ADD COLUMN IF NOT EXISTS access_token_expires_at DATETIME NULL AFTER access_token_hash,
                ADD UNIQUE KEY IF NOT EXISTS uq_users_access_token (access_token_hash)',
        ],
        // 5: nur PHP-Schritt (refreshPages) – Kalenderexport aus den Datenschutztexten entfernt
        5 => [],
        // 6: DeepL-Übersetzungen von Titel/Beschreibung; Datenschutztexte nennen DeepL (refreshPages)
        6 => [
            "ALTER TABLE rounds ADD COLUMN IF NOT EXISTS source_lang VARCHAR(5) NOT NULL DEFAULT 'de' AFTER description",
            "CREATE TABLE IF NOT EXISTS round_translations (
                round_id INT UNSIGNED NOT NULL, lang VARCHAR(5) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NULL,
                source_hash CHAR(64) NOT NULL, is_manual TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL,
                PRIMARY KEY (round_id, lang),
                CONSTRAINT fk_translations_round FOREIGN KEY (round_id) REFERENCES rounds (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // 7: Rundencode für die Eingabe auf der Startseite; bestehende Runden bekommen einen (RoundCode::backfill)
        7 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS code CHAR(8) NULL AFTER slug,
                ADD UNIQUE KEY IF NOT EXISTS uq_rounds_code (code)',
        ],
        // 8: Übersetzung im Browser statt DeepL (Datenschutztexte ersetzt der Vorlagen-Abgleich in run())
        8 => [],
        // 9: Genau ein Admin (das älteste aktive Admin-Konto bleibt, weitere werden Lehrkräfte);
        //    er ist für alle Runden verantwortlich, Impressum/Datenschutz nennen ihn (refreshPages)
        9 => [
            "UPDATE users SET role = 'teacher' WHERE role = 'admin' AND id <> (
                SELECT keep_id FROM (SELECT id AS keep_id FROM users WHERE role = 'admin' ORDER BY is_active DESC, id LIMIT 1) AS keep_admin)",
            // Kontaktdaten der Lehrkräfte werden nicht mehr gebraucht (Datensparsamkeit)
            "UPDATE users SET controller_contact = NULL WHERE role <> 'admin'",
        ],
        // 10: Rundencode schützt die Runde („Nur mit Rundencode“) statt eines eigenen Zugangscodes
        //     (alte PINs gelten nicht mehr; geschützte Runden bleiben geschützt) – siehe pinToCode().
        //     Die Spalte access_pin bleibt in dieser Version stehen (Übergang während des Uploads)
        //     und kann in einer späteren Version entfernt werden.
        10 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS code_required TINYINT(1) NOT NULL DEFAULT 0 AFTER code',
        ],
        // 11: Mehrfachbuchungen begrenzen (none | round | day); bestehende Runden wie bisher ohne Sperre
        11 => [
            "ALTER TABLE rounds ADD COLUMN IF NOT EXISTS booking_limit VARCHAR(5) NOT NULL DEFAULT 'none' AFTER code_required",
        ],
        // 12: Sitzungen enden bei Passwortänderung/-reset (Zähler je Konto, in der Sitzung gemerkt)
        12 => [
            'ALTER TABLE users ADD COLUMN IF NOT EXISTS session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_logins',
        ],
    ];

    /** SHA-256 aller früher ausgelieferten Vorlagen von Impressum/Datenschutz (unbearbeitet). */
    /** SHA-256 der aktuellen Vorlagen app/templates/pages/*.md (tests/unit.php prüft das) */
    public const CURRENT_PAGE_HASHES = [
        'imprint.de' => '036e210b629357dfbf768868e3a5f249c888d3052f301170703b196336058f43',
        'imprint.en' => 'f15521ceaa18e97c98190e448cc95eb6130c4cf571164b09819bb40dd7af22c8',
        'privacy.de' => '0cda1f6b2ca1ad90d1135a27b0087542862a700e0fbcefce7d448895f07a702a',
        'privacy.en' => '7ab09658494d5af363ab22f52a13140952a72e83656280c26ba54e029b2a0d51',
    ];

    private const OLD_PAGE_HASHES = [
        // Datenschutz mit Hoster-Platzhalter in eckigen Klammern (vor [[HOSTER]])
        'ad6f6857bd256028a0bb977342079a12de3896553f38b6988bc90513dd38b3db',
        '144970362b39b145e5d4253ce311bfa38fa4c85a23ed090d14cb9f40340d28df',
        // Datenschutz mit fest eingetragenem Hoster
        'de6d0d5db54705cdfaebeb229b708e0bb408f52963664220cfcbda4e8e0d2022',
        '709ca1125fecd1230d4dc8d4fdfd92b52a195d1bfdc0d5a705b7cdd536e29120',
        // Datenschutz ohne Abschnitt zur Darstellung (hell/dunkel)
        'bfc745eea89fdf42486366ec3ce736b038c858382f2cb4ff233670e465e06440',
        '8fdf11cfe5fbe26ba706f449dd0471bb73bb06d5c07b584d7fd30f7bfab57fc1',
        // Fassungen mit Vorlagenhinweisen und Hinweisen zur Schule (bis Version 10)
        '905c83e66c81ce4a3179192145ed7801260dc23a9bc30f42e6c76c39b7d74f8a',
        '64dae24f77aa5056ea551f8429a4a33b2a7916e2b2366d2fed9ab622452a6551',
        'dd0843a163d086b559d734e4d6dc573960a05c7651b2db4866dd34035c443f5d',
        'ee6655d8d71c55f0700c91600e0da896187bb8f61f3bbeda3064f65e0aeeb480',
        // Datenschutz de/en mit „eingebauter Übersetzungsfunktion“ (Zwischenstand, nur Testinstallationen)
        'e7e7f5e201d3cb8f646da9476aeeb18042b2afbb7e6a493ecdb17095c7b20082',
        'afecb073e5a316485b4105b37b41f0626417fcea78c4f5add76460891afac1e2',
        // Datenschutz de/en mit Lehrkräften als Verantwortlichen (Translator API, bis Version 8), Impressum en mit Platzhaltern
        '14875459955518adc09879c03f020f06c5eaa9a19494837f99237b0d4c9932ab',
        '71d92e80a981bf20cd7a6409f6500015799e08cd801815cf303977b3004b09fd',
        'c503bcd0596bc7bc45098fedc8f2b910f452909701ba9e28fc247dd9854fcc94',
        // Datenschutz de/en mit Admin als Verantwortlichem und Zugangscode (bis Version 9)
        'f3ce5609499373779aa2de56e2275a6c55cd32ffffd1cd95cca15462ae390264',
        '460240e538d932258d9aa50a2ec1d127e0b290a8d881f54ce8121e2c2caf7e17',
        // Zwischenstand des Ein-Admin-Umbaus (nur Testinstallationen)
        '8c8973faa47678a960e027205d31604c914cfe515a76d5801c4511d341df4cf2',
        'a20f6373f8ed2438a452e4226e3a216820e09da2a8c29b46c090a90bdb6c7fc2',
        'ee6625e4708a310c37db76031304d33ed26f685c92e27b9d4da9a95fc2590ce3',
        'd9b8e0f952fd2b3d0837841005b466241c492f4f06bc648254d2938c83a0624c',
        // Datenschutz de/en mit DeepL (bis Version 7)
        '713b85160d48c940afd47de09bd18ad8b1e9b069284992f9b571fc030ed6f25f',
        '4b3e0a3adc7ec55a14c2435835f375581a6c4b300482cc8fd9cd04f3c5a12b23',
        '05b82314637b712949e3b7c8e588f71de71d0c852c4c4dd6e7a4b3a2939c185f',
        '07ececfa877f336ee76f2ac33e9ee6660899b189ed02fdd29235ed6aa0ae8dc4',
        '0b2e7d11e71ffa5772cc523b688740a1eb721c08a4226347c73bf39e2d723574',
        '28a3268258ac7f9d1a6f8a4ea2655c1f0c2a981e65f78a38babef960fb9b15c7',
        '31c90a01945dae9e433a57c019060c982884ac6d461ec6dac6790d48e82c6f0f',
        '410c752a7363eb5692a96afc71c3fd4f7f607e8d55c6661b1035f7fcedfae9ce',
        '6f9ca87a86200e7e47a5e48a22d29a7c0c509f9e03893b7c966c55c0d2d489b4',
        '754f8e5c20a09572710cd9bcaaaf3580b2d8ffe84c7ff82f02d09f04efcb61e4',
        '7d77bdda1a65f5251366acd41e984b3ec2a3faaca6839c5fe3e5a23cae2f6dc6',
        '83fd8153dd3a29ca51e31d0ad19d7e9a2cfd83837517d8eca63d17d7a6f82177',
        '88b1f6ea37cc81e3723fd150ca7c755331beabfc47af864d6887438fc7e5f80b',
        '8e5b26520d3369d652fb2addb98c9a75708dee853c88847157892c443ea625eb',
        '98f8990bdb164f0a01ab54ce25e61b8e930fac7d66a1834bb10526ce86106c1a',
        'a6b368a3a7312f1f052f593e9076e5e26300de5d01839904e002ebe0b100041b',
        'aa35ca887f189a6991e91d669f0d9ccd82be164a34663c722ffbad4de0c4f343',
        'c503bcd0596bc7bc45098fedc8f2b910f452909701ba9e28fc247dd9854fcc94',
        'd71943ef603c979ebf3bda10357c80b6395568f07d1690798db8b52dfd016a5c',
        'f1ac0f64ece1e1ce11b4322831f8d121b6d5a3ea450183d6a277cf26d9c0f3b1',
    ];

    public static function run(): void
    {
        // Nur in einer von dieser App installierten Datenbank (eigener Versionsmerker)
        $version = Settings::get('schema_version');
        if ($version === null) {
            return;
        }
        $current = (int) $version;
        // Nur ein Aufruf migriert (parallele Seitenaufrufe, z. B. während des Uploads); die anderen
        // warten kurz und finden dann den neuen Stand vor
        if ($current < self::LATEST) {
            if ((int) Db::value("SELECT GET_LOCK(?, 10)", [Db::table('migrate')]) !== 1) {
                return;
            }
            try {
                $current = (int) Settings::get('schema_version');
                self::steps($current);
            } finally {
                Db::value('SELECT RELEASE_LOCK(?)', [Db::table('migrate')]);
            }
        }
        // Vorlagen für Impressum/Datenschutz: unabhängig von der Schema-Version, sobald die
        // Dateien auf dem Server zum Code passen (während eines Uploads ggf. erst später)
        if (Settings::get('pages_version') !== self::pagesVersion() && self::refreshPages()) {
            Settings::set('pages_version', self::pagesVersion());
        }
    }

    private static function steps(int $current): void
    {
        foreach (self::STEPS as $version => $statements) {
            if ($version > $current) {
                foreach ($statements as $sql) {
                    Db::execRaw($sql);
                }
                if ($version === 7) {
                    RoundCode::backfill();
                }
                if ($version === 10) {
                    self::pinToCode();
                }
                Settings::set('schema_version', (string) $version);
            }
        }
    }

    /**
     * Runden mit Zugangscode werden „Nur mit Rundencode“ (wiederholbar). Die PIN-Spalte bleibt
     * vorerst, damit alter Code während des Uploads geschützte Runden weiter schützt.
     */
    private static function pinToCode(): void
    {
        if (Db::one("SHOW COLUMNS FROM rounds LIKE 'access_pin'") === null) {
            return;
        }
        Db::execRaw("UPDATE rounds SET code_required = 1 WHERE COALESCE(access_pin, '') <> ''");
    }

    /** Kennung der aktuellen Vorlagen (ändert sich mit CURRENT_PAGE_HASHES). */
    public static function pagesVersion(): string
    {
        return substr(hash('sha256', implode(',', self::CURRENT_PAGE_HASHES)), 0, 16);
    }

    /**
     * Ersetzt gespeicherte Seiten durch die aktuelle Vorlage – aber nur, wenn sie noch
     * exakt einer früheren Vorlage entsprechen (also nicht bearbeitet wurden).
     * Fehlende Sprachen werden aus vorhandenen Vorlagen ergänzt.
     * @return bool false = Vorlagen auf dem Server passen (noch) nicht zum Code, nichts getan
     */
    public static function refreshPages(): bool
    {
        foreach (self::CURRENT_PAGE_HASHES as $name => $hash) {
            $file = APP_ROOT . "/app/templates/pages/$name.md";
            if (!is_file($file) || hash_file('sha256', $file) !== $hash) {
                return false; // z. B. Code schon neu, Vorlagen noch alt (Upload läuft)
            }
        }
        foreach (['imprint', 'privacy'] as $key) {
            foreach (I18n::languages() as $lang) {
                $file = APP_ROOT . "/app/templates/pages/$key.$lang.md";
                if (!is_file($file)) {
                    continue;
                }
                $new = (string) file_get_contents($file);
                $current = Db::value('SELECT content FROM pages WHERE page_key = ? AND lang = ?', [$key, $lang]);
                if ($current === null || in_array(hash('sha256', (string) $current), self::OLD_PAGE_HASHES, true)) {
                    Db::run(
                        'INSERT INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = VALUES(updated_at)',
                        [$key, $lang, $new, Time::nowSql()]
                    );
                }
            }
        }
        return true;
    }
}
