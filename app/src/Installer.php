<?php
declare(strict_types=1);

namespace App;

/** Creates tables, admin account and default texts. Locked afterwards. */
final class Installer
{
    /** The schema the installation creates; tests point it to a file of their own */
    public static string $schemaFile = APP_ROOT . '/app/schema.sql';

    public static function lockFile(): string
    {
        return DATA_ROOT . '/app/var/installed.lock';
    }

    /** Installed = lock file or our own schema marker in settings (not merely "some users table"). */
    public static function isInstalled(): bool
    {
        if (is_file(self::lockFile())) {
            return true;
        }
        try {
            return Db::value("SELECT value FROM settings WHERE name = 'schema_version'") !== null
                || Db::value('SELECT COUNT(*) FROM users') > 0;
        } catch (\PDOException $e) {
            // Only "table missing" (empty database) means not installed. On other errors
            // (database unreachable, permissions) the installer stays locked.
            return $e->getCode() !== '42S02';
        }
    }

    /**
     * Tables with our names that already exist (e.g. from another application
     * in the same database). Installation is not allowed then.
     * @return list<string>
     */
    public static function conflictingTables(): array
    {
        $existing = array_map('strtolower', Db::pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        $ours = array_map(static fn (string $t) => strtolower(Db::table($t)), Db::TABLES);
        return array_values(array_intersect($ours, $existing));
    }

    /**
     * Foreign keys of the schema whose names are already taken in this database (expert review P2-7). Their names
     * are unique per database, not per table: an installation from before the prefixed names (fk_…) blocks a
     * second one without a prefix. Installation is not allowed then either.
     * @return list<string>
     */
    public static function conflictingConstraints(): array
    {
        preg_match_all('/\bCONSTRAINT\s+(\w+)/i', implode("\n", array_map(Db::prefix(...), self::statements())), $m);
        $taken = array_map(static fn (mixed $n): string => strtolower((string) $n), array_column(Db::all(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        ), 'CONSTRAINT_NAME'));
        return array_values(array_filter($m[1], static fn (string $n): bool => in_array(strtolower($n), $taken, true)));
    }

    /**
     * The statements of the schema (comment lines removed before splitting: they may contain ";").
     * @return list<string>
     */
    private static function statements(): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(self::$schemaFile));
        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }

    /**
     * What the server must offer before installing (both installers check this first): MariaDB from
     * Health::MIN_MARIADB on and a writable app/var. PHP and its extensions are checked on every request
     * (App\Platform).
     * @return list<string> error messages (empty = fine)
     */
    public static function environmentProblems(?string $var = null): array
    {
        $var ??= DATA_ROOT . '/app/var';
        return array_values(array_filter([
            Health::serverProblem(),
            // Sessions, the lock file, the error log and the alert counter live there; without it the admin
            // sessions would end up in the server's shared temporary directory
            is_dir($var) && is_writable($var) ? null
                : __('Das Verzeichnis {dir} fehlt oder ist für PHP nicht beschreibbar. Dort liegen Sitzungen, Sperrdatei, Fehlerprotokoll und der Zähler für Fehlermeldungen. Bitte legen Sie es an bzw. erlauben Sie PHP das Schreiben (z. B. Rechte 750 für den PHP-Benutzer) und versuchen Sie es erneut.', ['dir' => 'app/var']),
        ]));
    }

    /** @return list<string> error messages (empty = valid) */
    public static function validate(string $username, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $password2, string $email): array
    {
        $errors = [];
        if (!Auth::validUsername($username)) {
            $errors[] = __('Benutzername: 3–254 Zeichen, nur Buchstaben, Ziffern und . _ @ + - (z. B. Ihre E-Mail-Adresse).');
        }
        // The same rules as for every other password (length up to the bcrypt limit, common passwords, …)
        if (($error = Auth::passwordPolicy($password, $username, $email)) !== null) {
            $errors[] = $error;
        }
        if ($password !== $password2) {
            $errors[] = __('Die Passwörter stimmen nicht überein.');
        }
        if ($email !== '' && !is_valid_email($email)) {
            $errors[] = __('Die E-Mail-Adresse ist ungültig.');
        } elseif (($error = AccountDomains::error($email)) !== null) {
            $errors[] = $error;
        }
        return $errors;
    }

    /** Error message if the operator type is not one of Operator::TYPES (the installer asks for it), or null. */
    public static function validateType(string $type): ?string
    {
        return in_array($type, Operator::TYPES, true) ? null : __('Bitte wählen Sie die Betriebsart: Wird die Terminvergabe von der Schule oder privat betrieben?');
    }

    /**
     * The legal notice and privacy policy the installer stores for an operator type: the templates of that type
     * in the given languages (a language without a template is left out; its pages fall back to English).
     * @param list<string> $languages
     * @return array<string, array<string, string>> page key => language => text
     */
    public static function pages(string $operatorType, array $languages): array
    {
        $pages = [];
        foreach (LegalTexts::KEYS as $key) {
            foreach ($languages as $lang) {
                if (($content = LegalTexts::template($key, $operatorType, $lang)) !== null) {
                    $pages[$key][$lang] = $content;
                }
            }
        }
        return $pages;
    }

    /**
     * Stores the templates of the operator type as legal notice and privacy policy where a text is missing,
     * each with its template as version marker (LegalTexts::base()).
     */
    public static function storePages(string $operatorType): void
    {
        foreach (self::pages($operatorType, I18n::languages()) as $key => $texts) {
            foreach ($texts as $lang => $content) {
                if (Db::run('INSERT IGNORE INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)', [$key, $lang, $content, Time::nowSql()])->rowCount() > 0) {
                    LegalTexts::setBase($key, $lang, $operatorType);
                }
            }
        }
    }

    /**
     * Creates the first account as admin (which can also manage its own rounds) and stores the legal notice
     * and privacy policy templates of the operator type (with them as their version markers).
     */
    public static function install(string $username, #[\SensitiveParameter] string $password, string $email, string $displayName, string $operatorType): void
    {
        if (($error = self::validateType($operatorType)) !== null) {
            throw new \InvalidArgumentException($error);
        }
        if ($conflicts = self::conflictingTables()) {
            throw new \RuntimeException('Die Datenbank enthält bereits Tabellen mit gleichem Namen (' . implode(', ', $conflicts) . '). Bitte eine eigene, leere Datenbank verwenden.');
        }
        if ($conflicts = self::conflictingConstraints()) {
            throw new \RuntimeException('Die Datenbank enthält bereits Fremdschlüssel mit gleichem Namen (' . implode(', ', $conflicts) . '). Bitte ein anderes Tabellenpräfix (db.prefix) oder eine eigene Datenbank verwenden.');
        }
        // DDL cannot be rolled back: if anything fails, the tables of this run are dropped again (none of them
        // existed before, see above), so the installation can simply be repeated (expert review P2-7)
        $now = Time::nowSql();
        try {
            foreach (self::statements() as $stmt) {
                Db::execRaw($stmt);
            }
            Db::run(
                "INSERT INTO users (username, password_hash, role, display_name, email, created_at) VALUES (?, ?, 'admin', ?, ?, ?)",
                [$username, password_hash($password, PASSWORD_DEFAULT), $displayName !== '' ? $displayName : $username, $email !== '' ? $email : null, $now]
            );
            self::storePages($operatorType);
            Operator::saveType($operatorType);
            Settings::set('last_cleanup', '0');
            Settings::set('schema_version', (string) Migrations::LATEST);
            Settings::set('pages_version', Migrations::pagesVersion());
        } catch (\Throwable $e) {
            self::dropOurTables();
            throw $e;
        }
        @file_put_contents(self::lockFile(), 'installed ' . $now . "\n");
    }

    /** Drops the tables of this installation that exist (after a failed installation, see install()). */
    private static function dropOurTables(): void
    {
        $ours = array_map(static fn (string $t): string => strtolower(Db::table($t)), Db::TABLES);
        $existing = array_values(array_intersect(array_map('strtolower', Db::pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN)), $ours));
        if ($existing) {
            Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                Db::pdo()->exec('DROP TABLE IF EXISTS `' . implode('`, `', $existing) . '`');
            } finally {
                Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }
}
