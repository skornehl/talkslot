<?php
declare(strict_types=1);

namespace App;

/** Creates tables, admin account and default texts. Locked afterwards. */
final class Installer
{
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
        }
        return $errors;
    }

    /** Creates the first account as admin (which can also manage its own rounds). */
    public static function install(string $username, #[\SensitiveParameter] string $password, string $email, string $displayName = ''): void
    {
        if ($conflicts = self::conflictingTables()) {
            throw new \RuntimeException('Die Datenbank enthält bereits Tabellen mit gleichem Namen (' . implode(', ', $conflicts) . '). Bitte eine eigene, leere Datenbank verwenden.');
        }
        // Remove comment lines before splitting (they may then also contain ";")
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(APP_ROOT . '/app/schema.sql'));
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                Db::execRaw($stmt);
            }
        }

        $now = Time::nowSql();
        Db::run(
            "INSERT INTO users (username, password_hash, role, display_name, email, created_at) VALUES (?, ?, 'admin', ?, ?, ?)",
            [$username, password_hash($password, PASSWORD_DEFAULT), $displayName !== '' ? $displayName : $username, $email !== '' ? $email : null, $now]
        );
        foreach (['imprint', 'privacy'] as $key) {
            foreach (I18n::languages() as $lang) {
                $file = APP_ROOT . "/app/templates/pages/$key.$lang.md";
                if (is_file($file)) {
                    Db::run(
                        'INSERT IGNORE INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)',
                        [$key, $lang, (string) file_get_contents($file), $now]
                    );
                }
            }
        }
        Settings::set('last_cleanup', '0');
        Settings::set('schema_version', (string) Migrations::LATEST);
        Settings::set('pages_version', Migrations::pagesVersion());
        @file_put_contents(self::lockFile(), 'installed ' . $now . "\n");
    }
}
