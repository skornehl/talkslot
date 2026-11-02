<?php
declare(strict_types=1);

namespace App;

/** Legt Tabellen, Admin-Konto und Standardtexte an. Danach gesperrt. */
final class Installer
{
    public static function lockFile(): string
    {
        return DATA_ROOT . '/app/var/installed.lock';
    }

    /** Installiert = Lock-Datei oder eigener Schema-Merker in settings (nicht bloß „irgendeine users-Tabelle“). */
    public static function isInstalled(): bool
    {
        if (is_file(self::lockFile())) {
            return true;
        }
        try {
            return Db::value("SELECT value FROM settings WHERE name = 'schema_version'") !== null
                || Db::value('SELECT COUNT(*) FROM users') > 0;
        } catch (\PDOException $e) {
            // Nur „Tabelle fehlt“ (leere Datenbank) heißt nicht installiert. Bei anderen Fehlern
            // (Datenbank nicht erreichbar, Rechte) bleibt der Installer gesperrt.
            return $e->getCode() !== '42S02';
        }
    }

    /**
     * Tabellen mit unseren Namen, die bereits existieren (z. B. von einer anderen Anwendung
     * in derselben Datenbank). Dann darf nicht installiert werden.
     * @return list<string>
     */
    public static function conflictingTables(): array
    {
        $existing = array_map('strtolower', Db::pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        $ours = array_map(static fn (string $t) => strtolower(Db::table($t)), Db::TABLES);
        return array_values(array_intersect($ours, $existing));
    }

    /** @return list<string> Fehlermeldungen (leer = gültig) */
    public static function validate(string $username, string $password, string $password2, string $email): array
    {
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9._@+-]{3,254}$/', $username)) {
            $errors[] = 'Benutzername: 3–254 Zeichen, nur Buchstaben, Ziffern und . _ @ + - (z. B. Ihre E-Mail-Adresse).';
        }
        if (mb_strlen($password) < 12) {
            $errors[] = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if ($password !== $password2) {
            $errors[] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($email !== '' && !is_valid_email($email)) {
            $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        }
        return $errors;
    }

    /** Legt das erste Konto als Admin an (kann zugleich eigene Runden verwalten). */
    public static function install(string $username, string $password, string $email, string $displayName = ''): void
    {
        if ($conflicts = self::conflictingTables()) {
            throw new \RuntimeException('Die Datenbank enthält bereits Tabellen mit gleichem Namen (' . implode(', ', $conflicts) . '). Bitte eine eigene, leere Datenbank verwenden.');
        }
        // Kommentarzeilen vor dem Trennen entfernen (sie dürfen dann auch „;“ enthalten)
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
