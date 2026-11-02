<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Dünne PDO-Hülle. Alle Abfragen laufen über Prepared Statements.
 *
 * Tabellen-Präfix (config db.prefix, z. B. „tp_“): Die Anwendung schreibt SQL mit den
 * einfachen Tabellennamen; prefix() setzt das Präfix vor jeden bekannten Tabellennamen.
 * So kann die App eine Datenbank mit anderen Anwendungen teilen.
 */
final class Db
{
    /** Alle Tabellen der Anwendung (auch historische für Migrationen) */
    public const TABLES = ['users', 'rounds', 'round_days', 'day_breaks', 'slots', 'bookings', 'pages', 'rate_limits', 'settings', 'sent_summaries', 'admin_users', 'round_translations'];

    private static ?PDO $pdo = null;
    /** Verbindungsfehler dieses Requests: nicht bei jeder Abfrage erneut auf den Timeout warten. */
    private static ?PDOException $connectError = null;
    private static ?string $tableRegex = null;

    public static function tablePrefix(): string
    {
        $prefix = (string) Config::get('db.prefix', '');
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,20}$/', $prefix)) {
            throw new \RuntimeException('config: db.prefix darf nur Buchstaben, Ziffern und _ enthalten');
        }
        return $prefix;
    }

    /** Vollständiger Tabellenname inkl. Präfix */
    public static function table(string $name): string
    {
        return self::tablePrefix() . $name;
    }

    /**
     * Ersetzt Tabellennamen im SQL durch die Namen mit Präfix. Erfasst nur ganze Wörter,
     * also z. B. „rounds“, aber nicht „r.round_id“ oder „fk_rounds_user“.
     */
    public static function prefix(string $sql): string
    {
        $prefix = self::tablePrefix();
        if ($prefix === '') {
            return $sql;
        }
        self::$tableRegex ??= '/(?<![\w.`\'"])(' . implode('|', self::TABLES) . ')(?![\w`\'"])/';
        return (string) preg_replace(self::$tableRegex, $prefix . '$1', $sql);
    }

    /** SQL ohne Parameter ausführen (DDL in Installer und Migrationen) */
    public static function execRaw(string $sql): void
    {
        self::pdo()->exec(self::prefix($sql));
    }

    /** true, wenn in diesem Request keine Verbindung zur Datenbank zustande kam. */
    public static function isUnavailable(): bool
    {
        return self::$connectError !== null;
    }

    public static function pdo(): PDO
    {
        if (self::$connectError !== null) {
            throw self::$connectError;
        }
        if (self::$pdo === null) {
            $c = (array) Config::get('db', []);
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $c['host'] ?? 'localhost',
                (int) ($c['port'] ?? 3306),
                $c['name'] ?? ''
            );
            try {
                self::$pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_TIMEOUT => 5, // nicht minutenlang hängen, wenn die Datenbank ausfällt
                ]);
            } catch (PDOException $e) {
                self::$connectError = $e;
                throw $e;
            }
            // Strikter SQL-Modus: keine stillen Abschneidungen
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare(self::prefix($sql));
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Führt eine Änderung aus und liefert die Anzahl betroffener Zeilen. */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Führt $fn in einer Transaktion aus; bei Ausnahme Rollback. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn(); // verschachtelt: läuft in der äußeren Transaktion mit
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Verletzung eines UNIQUE-Constraints (MariaDB-Fehler 1062)? */
    public static function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
