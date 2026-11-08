<?php
declare(strict_types=1);

namespace App;

/**
 * Schema changes for databases that are already installed.
 * schema.sql always describes the latest state; new installations get
 * LATEST directly. Existing ones are upgraded step by step on the next request.
 */
final class Migrations
{
    public const LATEST = 23;

    /** Version => SQL statements */
    private const STEPS = [
        2 => ['ALTER TABLE rounds ADD COLUMN IF NOT EXISTS access_pin VARCHAR(12) NULL AFTER description'],
        // Multiple teachers: admin_users → users with roles, rounds belong to one person
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
        // Invitation/password links instead of initial passwords by email
        4 => [
            'ALTER TABLE users
                ADD COLUMN IF NOT EXISTS access_token_hash CHAR(64) NULL AFTER must_change_password,
                ADD COLUMN IF NOT EXISTS access_token_expires_at DATETIME NULL AFTER access_token_hash,
                ADD UNIQUE KEY IF NOT EXISTS uq_users_access_token (access_token_hash)',
        ],
        // 5: PHP step only (refreshPages) – calendar export removed from the privacy texts
        5 => [],
        // 6: DeepL translations of title/description; privacy texts mention DeepL (refreshPages)
        6 => [
            "ALTER TABLE rounds ADD COLUMN IF NOT EXISTS source_lang VARCHAR(5) NOT NULL DEFAULT 'de' AFTER description",
            "CREATE TABLE IF NOT EXISTS round_translations (
                round_id INT UNSIGNED NOT NULL, lang VARCHAR(5) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NULL,
                source_hash CHAR(64) NOT NULL, is_manual TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL,
                PRIMARY KEY (round_id, lang),
                CONSTRAINT fk_translations_round FOREIGN KEY (round_id) REFERENCES rounds (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // 7: round code for entry on the start page; existing rounds get one (RoundCode::backfill)
        7 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS code CHAR(8) NULL AFTER slug,
                ADD UNIQUE KEY IF NOT EXISTS uq_rounds_code (code)',
        ],
        // 8: translation in the browser instead of DeepL (privacy texts are replaced by the template sync in run())
        8 => [],
        // 9 (historical, before multiple admins): exactly one admin (the oldest active admin account stays, others become teachers);
        //    they are the controller for all rounds, legal notice/privacy policy name them (refreshPages)
        9 => [
            "UPDATE users SET role = 'teacher' WHERE role = 'admin' AND id <> (
                SELECT keep_id FROM (SELECT id AS keep_id FROM users WHERE role = 'admin' ORDER BY is_active DESC, id LIMIT 1) AS keep_admin)",
            // Teachers' contact details are no longer needed (data minimization)
            "UPDATE users SET controller_contact = NULL WHERE role <> 'admin'",
        ],
        // 10: the round code protects the round ("Nur mit Rundencode") instead of a separate access code
        //     (old PINs are no longer valid; protected rounds stay protected) – see pinToCode().
        //     The access_pin column remains in this version (transition during the upload)
        //     and can be removed in a later version.
        10 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS code_required TINYINT(1) NOT NULL DEFAULT 0 AFTER code',
        ],
        // 11: limit multiple bookings (none | round | day); existing rounds keep working without a limit
        11 => [
            "ALTER TABLE rounds ADD COLUMN IF NOT EXISTS booking_limit VARCHAR(5) NOT NULL DEFAULT 'none' AFTER code_required",
        ],
        // 12: sessions end on password change/reset (counter per account, remembered in the session)
        12 => [
            'ALTER TABLE users ADD COLUMN IF NOT EXISTS session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_logins',
        ],
        // 13: several admins – the details for legal notice/privacy policy belong to the platform and move
        // from the admin's profile into the settings (the column stays, see Operator::save())
        13 => [
            "INSERT IGNORE INTO settings (name, value) SELECT 'controller_contact', controller_contact FROM users
               WHERE role = 'admin' AND is_active = 1 AND COALESCE(controller_contact, '') <> '' ORDER BY id LIMIT 1",
        ],
        // 14: location of the meetings (e.g. a room) per round, optionally different per day
        14 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS location VARCHAR(200) NULL AFTER description',
            'ALTER TABLE round_days ADD COLUMN IF NOT EXISTS location VARCHAR(200) NULL AFTER slot_minutes',
        ],
        // 15: reminder mail to parents the evening before (per round, on by default)
        15 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS remind_parents TINYINT(1) NOT NULL DEFAULT 1 AFTER booking_limit',
            'ALTER TABLE bookings ADD COLUMN IF NOT EXISTS reminded_at DATETIME NULL AFTER source,
                ADD COLUMN IF NOT EXISTS reminder_token_hash CHAR(64) NULL AFTER reminded_at,
                ADD UNIQUE KEY IF NOT EXISTS uq_bookings_reminder_token (reminder_token_hash)',
        ],
        // 16: log of the changes made by admins
        16 => [
            "CREATE TABLE IF NOT EXISTS audit_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, user_id INT UNSIGNED NULL,
                actor VARCHAR(254) NOT NULL, message VARCHAR(1000) NOT NULL,
                KEY idx_audit_created (created_at),
                CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // 17: templates for new rounds
        17 => [
            "CREATE TABLE IF NOT EXISTS round_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, data TEXT NOT NULL,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_templates_name (name)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // 18: admin area in German or English, per account (NULL = admin_language)
        18 => [
            'ALTER TABLE users ADD COLUMN IF NOT EXISTS lang VARCHAR(5) NULL AFTER email',
        ],
        // 19: only rounds that were in use expire automatically (activated at least once or with an entry
        //     by the teacher); a draft such as a copy that keeps the old dates stays. Existing rounds count
        //     as used if they are active, already expired or booked. Additive: the version before ignores it.
        19 => [
            'ALTER TABLE rounds ADD COLUMN IF NOT EXISTS was_used TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
            'UPDATE rounds r SET r.was_used = 1
              WHERE r.is_active = 1 OR r.expired_at IS NOT NULL
                 OR EXISTS (SELECT 1 FROM round_days d JOIN slots s ON s.day_id = d.id JOIN bookings b ON b.slot_id = s.id WHERE d.round_id = r.id)',
        ],
        // 20: mail queue (outbox, references only: the text and a new link are made when sending) and per
        //     booking: may it be reminded (remind_ok), the last confirmation or change notice (notified_at,
        //     the reminder skips that day instead of looking at updated_at), a failed mail (mail_failed_at)
        //     and the link of a confirmation sent later from the outbox (mail_token_hash). Existing bookings
        //     of parents remind as their round does; entries by the teacher only after a new confirmation or
        //     change notice, as nobody knows whether the parents got one (they never saw the booking form).
        20 => [
            'ALTER TABLE bookings
                ADD COLUMN IF NOT EXISTS remind_ok TINYINT(1) NOT NULL DEFAULT 0 AFTER source,
                ADD COLUMN IF NOT EXISTS notified_at DATETIME NULL AFTER remind_ok,
                ADD COLUMN IF NOT EXISTS mail_failed_at DATETIME NULL AFTER notified_at,
                ADD COLUMN IF NOT EXISTS mail_token_hash CHAR(64) NULL AFTER reminder_token_hash,
                ADD UNIQUE KEY IF NOT EXISTS uq_bookings_mail_token (mail_token_hash)',
            "UPDATE bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id JOIN rounds r ON r.id = d.round_id
                SET b.remind_ok = r.remind_parents WHERE b.source = 'parent'",
            "UPDATE bookings SET notified_at = updated_at WHERE notified_at IS NULL AND COALESCE(email, '') <> ''",
            "CREATE TABLE IF NOT EXISTS mail_outbox (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(20) NOT NULL, booking_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL, ref VARCHAR(40) NULL, priority TINYINT UNSIGNED NOT NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL, created_at DATETIME NOT NULL,
                UNIQUE KEY uq_outbox_booking (booking_id, kind), UNIQUE KEY uq_outbox_account (user_id, kind, ref),
                KEY idx_outbox_due (next_attempt_at),
                CONSTRAINT fk_outbox_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
                CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        // 21: cancellations by the teacher (booking, day, round or account deleted) wait in the outbox with
        //     what the mail needs, as the booking is gone (additive: older code drops such rows unsent)
        21 => [
            'ALTER TABLE mail_outbox ADD COLUMN IF NOT EXISTS payload TEXT NULL AFTER ref',
        ],
        // 22: summaries without names unless the school allows them (config summary_names). Existing accounts
        //     get NULL ("as before": names, if allowed, until they confirm the notice on the dashboard), new
        //     ones 0. Added without a default first, so that a repeated run leaves the values as they are.
        //     reply_to: the account's own address for the replies of parents instead of its e-mail address
        22 => [
            'ALTER TABLE users ADD COLUMN IF NOT EXISTS summary_names TINYINT(1) NULL AFTER summary_deadline,
                ADD COLUMN IF NOT EXISTS reply_to VARCHAR(254) NULL AFTER email',
            'ALTER TABLE users ALTER COLUMN summary_names SET DEFAULT 0',
        ],
        // 23: parent_contact: how parents can reach the account with questions (shown on the parents' pages,
        //     ParentContact); NULL = the contact the admins set for all (setting parent_contact) or none
        23 => [
            'ALTER TABLE users ADD COLUMN IF NOT EXISTS parent_contact VARCHAR(300) NULL AFTER reply_to',
        ],
    ];

    /**
     * SHA-256 of the current templates app/templates/pages/*.md (checked by tests/unit.php). Each page has one
     * template per operator type (LegalTexts::VARIANTS); the installer and LegalTexts::apply() store the one of
     * the chosen type.
     */
    public const CURRENT_PAGE_HASHES = [
        'imprint.de' => '557dbb64b5ab373144a29e23a36056355fe02bdc7ff70a43d690d6acb8a74672',
        'imprint.en' => '0692f22c0bb7dd3c6f76c96c51f3dc78e25af54f97b4c556fb7268b8e372fa3f',
        // Unchanged since v1.5.0: the legal notice of a "private, non-commercial service"
        'imprint-private.de' => '036e210b629357dfbf768868e3a5f249c888d3052f301170703b196336058f43',
        'imprint-private.en' => 'f15521ceaa18e97c98190e448cc95eb6130c4cf571164b09819bb40dd7af22c8',
        'privacy.de' => 'e7928743e27c7350503e2a1355ca9470734455f2aeaa9e0c729c96ebf2c12c5d',
        'privacy.en' => 'a438a0928308672f6808e7b6947baba2c228697d84688f8d6eada1a4f5da90b2',
        'privacy-private.de' => '1bb552694fa8214a0d84763856268adc5c2b9f61699cdb90ab9f0800f3d12a9e',
        'privacy-private.en' => 'be51145bd45b19d97dd1a290dcf9e11d30196c1d3ae89850370202233cd3a3d5',
    ];

    /**
     * SHA-256 of earlier templates of legal notice/privacy policy, by the operator type they were written for.
     * A stored text that still matches one exactly (never edited) counts as an unedited template: it is
     * replaced by the current template of the chosen operator type (LegalTexts::apply()), and up to v1.6 the
     * templates described a privately run service (LegalTexts::detectType()).
     */
    public const OLD_PAGE_HASHES = [
        'private' => [
            // Without the limit of the mails parents trigger per address (up to schema version 22)
            'f6a1a0488c648b7d524f41d6406718938fad59cb934a5c0a6d8b1631d9700d34',
            '859517c15d6f539cf9d8506404abb6171bd7e8f46eb727e5b43826e738dcddca',
            // Summary mails with time and name, the recommendation to delete them after 15 months (up to schema
            // version 21, before the summaries without names)
            '41e56d82edcfea69af044fbef9639ab5f3e0667d831450fa1f593bce0b6623a1',
            'a2305054d982c4c4aba8a97698209e7146a247e86f7875fdc0c79b51acd6bdab',
            // Privacy policy de/en up to v1.6.0: "private service" without a data protection officer, legal
            // bases (b) and (f), "cancel at any time" (the legal notice of that time is the current
            // imprint-private template)
            '3885e997cd3bbf330158fdccc730f7fe1b93044379cc2df060ea5e0e7b6396ec',
            'ffbcc88fbd8b760efc7c4f173bb4e8850517392f88f9ffc0100da3aad921253f',
            // The same before the calendar download and the later confirmation mail (interim state on main
            // before v1.7.0)
            '8e63fee7c9e729b8faf94be1e0ed98291a59170a51b768b4c8ac9a75b2226693',
            '91d703acbd4ef5aa44d3c0527bffac75ccb3bf5376643e4b0605c1bef1a5cc47',
            // v1.7.0: privacy policy de/en for private operators with a required placeholder for the data
            // protection officer, teachers acting under the operator's instructions
            'de4289fa6a99be078922c0d4f2a945805de5fe1398b6e682f256f99cf9a8dabd',
            '05bcdc8ab770ab9e17046b5b89e2d9bc9bbbe7a39a66e4fa6c777950c345995b',
            // The same with the mail queue (interim state on main after v1.7.0, schema version 20)
            '4214ed9b61089be04b820467e61ac06319cd5152a1c9fab2b8c4069f9cdac21c',
            '0d7cd119cc674671238f4aad67cef56baa057647a71d0e8655b475ba35513fdf',
            // The same with the cancellation by the teacher (interim state on main, schema version 21)
            '47c6e2a09756b3fd45840c2eca28ccab265ce3f1913bca70e8a6972e60324bbe',
            '02f09a8774e42c1b7c17ac6818f92a79879865e114456f36d0974f86f55d3b4b',
            // Operator type (talkslot PR #38): the platform as controller "for all appointments booked through
            // it", summary mails kept for 15 months as a record of the meetings
            'a91e6d899e2ca6d66eae227adc0fcc634c5fd9f5f88aa3f8dc4161637cd64cd4',
            'c3bbc77e4142176554ae4ebec892c1341dd39b0572962a85aa69e187a25dc59c',
            // Operator-type rework before the cancellation by the teacher (test installations only)
            '4a7a2d0b0c1bfb9c518d6aa6b69b079763df0210c2448262e788e8d8aeaf4780',
            '9073bf4cc5bc431f2e98298c4a1c4c680c0fcc1116d96892e5e427b7607cc809',
            // Privacy policy de/en without the reminder mail (up to version 14)
            '0cda1f6b2ca1ad90d1135a27b0087542862a700e0fbcefce7d448895f07a702a',
            '7ab09658494d5af363ab22f52a13140952a72e83656280c26ba54e029b2a0d51',
            // Privacy policy with hosting placeholder in square brackets (before [[HOSTER]])
            'ad6f6857bd256028a0bb977342079a12de3896553f38b6988bc90513dd38b3db',
            '144970362b39b145e5d4253ce311bfa38fa4c85a23ed090d14cb9f40340d28df',
            // Privacy policy with hard-coded hosting provider
            'de6d0d5db54705cdfaebeb229b708e0bb408f52963664220cfcbda4e8e0d2022',
            '709ca1125fecd1230d4dc8d4fdfd92b52a195d1bfdc0d5a705b7cdd536e29120',
            // Privacy policy without the section on appearance (light/dark)
            'bfc745eea89fdf42486366ec3ce736b038c858382f2cb4ff233670e465e06440',
            '8fdf11cfe5fbe26ba706f449dd0471bb73bb06d5c07b584d7fd30f7bfab57fc1',
            // Versions with template notes and notes on the school (up to version 10)
            '905c83e66c81ce4a3179192145ed7801260dc23a9bc30f42e6c76c39b7d74f8a',
            '64dae24f77aa5056ea551f8429a4a33b2a7916e2b2366d2fed9ab622452a6551',
            'dd0843a163d086b559d734e4d6dc573960a05c7651b2db4866dd34035c443f5d',
            'ee6655d8d71c55f0700c91600e0da896187bb8f61f3bbeda3064f65e0aeeb480',
            // Privacy policy de/en with "built-in translation feature" (interim state, test installations only)
            'e7e7f5e201d3cb8f646da9476aeeb18042b2afbb7e6a493ecdb17095c7b20082',
            'afecb073e5a316485b4105b37b41f0626417fcea78c4f5add76460891afac1e2',
            // Privacy policy de/en with teachers as controllers (Translator API, up to version 8), legal notice en with placeholders
            '14875459955518adc09879c03f020f06c5eaa9a19494837f99237b0d4c9932ab',
            '71d92e80a981bf20cd7a6409f6500015799e08cd801815cf303977b3004b09fd',
            'c503bcd0596bc7bc45098fedc8f2b910f452909701ba9e28fc247dd9854fcc94',
            // Privacy policy de/en with admin as controller and access code (up to version 9)
            'f3ce5609499373779aa2de56e2275a6c55cd32ffffd1cd95cca15462ae390264',
            '460240e538d932258d9aa50a2ec1d127e0b290a8d881f54ce8121e2c2caf7e17',
            // Interim state of the single-admin rework (test installations only)
            '8c8973faa47678a960e027205d31604c914cfe515a76d5801c4511d341df4cf2',
            'a20f6373f8ed2438a452e4226e3a216820e09da2a8c29b46c090a90bdb6c7fc2',
            'ee6625e4708a310c37db76031304d33ed26f685c92e27b9d4da9a95fc2590ce3',
            'd9b8e0f952fd2b3d0837841005b466241c492f4f06bc648254d2938c83a0624c',
            // Privacy policy de/en with DeepL (up to version 7)
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
            'd71943ef603c979ebf3bda10357c80b6395568f07d1690798db8b52dfd016a5c',
            'f1ac0f64ece1e1ce11b4322831f8d121b6d5a3ea450183d6a277cf26d9c0f3b1',
        ],
        'school' => [
            // Without the limit of the mails parents trigger per address (up to schema version 22)
            '6d3917ebd6668be5ef53fd3d9215db22ffaf526a7e2db0aaad2c195c3bb873ef',
            '89197fcf4e398d4e5de638b5efcbf0c3624f6dbf7d8b0a9fafa0a1803997c2c6',
            // Summary mails with time and name, kept as a record of the meetings for 15 months (up to schema
            // version 21, before the summaries without names)
            '6a3e56b92970cd83352b6d4fbff9ff64fbb6765bf8d622c9c66cd5bb8812f374',
            '5c1350ac669db8120b946879389dd3cfb6bb1720a86aa523d6df95520c2b7878',
            // Privacy policy de/en for public schools without the calendar download, the later confirmation
            // mail and the expiry of the remember-me entry (interim state on main after v1.5.0)
            'ac8cf4d882a13a6e127476ab13de02524316d343a746b4b8ff055c3aa0d09347',
            '320c437da7fbf0e1ab24328e555348c160b1ac8bc551dfcb7229ae0593de2683',
            // Privacy policy de/en for public schools without the mail queue (outbox; v1.7.0, up to schema version 19)
            '12d249130ce60c94e7cd8f430e6cea2809d121351ab59090db0e0cc014eb4afe',
            '53be37d7c9c6697144d9400fe8a4536dee767f9ee22fe2636224d2484d04390a',
            // The same with the mail queue, without the cancellation by the teacher (interim state on main
            // after v1.7.0, schema version 20)
            'a45b94275b4e42f8635ab7a601e6c52a8e3562c155865e349edf2a3502cedb8d',
            'b1186539d1140945417e257cc4e04f764be96db65c95e67c6b22e27b44be5e45',
        ],
    ];

    public static function run(): void
    {
        // Only in a database installed by this app (own version marker)
        $version = Settings::get('schema_version');
        if ($version === null) {
            return;
        }
        $current = (int) $version;
        // Only one request migrates (parallel page requests, e.g. during the upload); the others
        // wait briefly and then find the new state
        if ($current < self::LATEST) {
            // The steps use MariaDB syntax (ADD COLUMN IF NOT EXISTS …): on MySQL or an older MariaDB they would
            // fail half-way. Reported by alert mail with the reason instead (App::run, Health::check()).
            if (($problem = Health::serverProblem()) !== null) {
                throw new \RuntimeException($problem);
            }
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
        // Templates for legal notice/privacy policy: independent of the schema version, as soon as the
        // files on the server match the code (possibly only later during an upload)
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
     * Rounds with an access code become "Nur mit Rundencode" (repeatable). The PIN column stays
     * for now so that old code keeps protecting protected rounds during the upload.
     */
    private static function pinToCode(): void
    {
        if (Db::one("SHOW COLUMNS FROM rounds LIKE 'access_pin'") === null) {
            return;
        }
        Db::execRaw("UPDATE rounds SET code_required = 1 WHERE COALESCE(access_pin, '') <> ''");
    }

    /**
     * Identifier of the current templates (changes with CURRENT_PAGE_HASHES). ",base": since the version markers
     * of the stored texts (LegalTexts::base()), so that an installation from before them runs refreshPages()
     * once, which records them for the texts that have the wording of the current templates.
     */
    public static function pagesVersion(): string
    {
        return substr(hash('sha256', implode(',', self::CURRENT_PAGE_HASHES) . ',base'), 0, 16);
    }

    /**
     * Brings the stored legal notice and privacy policy up to date with the templates of the operator type:
     * texts that are missing or still exactly match a template (current or earlier, i.e. never edited) get the
     * current template of that type; edited texts stay, their version markers tell the admins what the new
     * template changed (LegalTexts::apply(), LegalTexts::outdated()). Installations from before the
     * operator type was asked for get "private" if all their texts are unedited templates of a private
     * service, as up to v1.6 (LegalTexts::detectType()); otherwise the texts stay as they are until an admin
     * chooses the type.
     * @return bool false = templates on the server do not match the code (yet), nothing done
     */
    public static function refreshPages(): bool
    {
        foreach (self::CURRENT_PAGE_HASHES as $name => $hash) {
            $file = APP_ROOT . "/app/templates/pages/$name.md";
            if (!is_file($file) || hash_file('sha256', $file) !== $hash) {
                return false; // e.g. code already new, templates still old (upload in progress)
            }
        }
        // Both are logged (accountability, Art. 5(2) GDPR): who is the controller, and which texts changed
        $type = Operator::type();
        if ($type === null && ($type = LegalTexts::detectType(LegalTexts::stored())) !== null) {
            Operator::saveType($type, true);
            Audit::system(static fn (): string => __('Betriebsart beim Update übernommen: {type} (alle Texte waren unveränderte Vorlagen dieser Betriebsart; gilt, bis ein Admin sie unter „Texte“ speichert)', ['type' => Operator::typeLabel($type)]));
        }
        if ($type !== null && ($replaced = LegalTexts::apply($type)) > 0) {
            Audit::system(static fn (): string => __('{count} unveränderte Vorlagentexte beim Update durch die aktuellen Vorlagen ersetzt', ['count' => $replaced]));
        }
        return true;
    }
}
