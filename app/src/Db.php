<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin PDO wrapper. All queries go through prepared statements.
 *
 * Table prefix (config db.prefix, e.g. "tp_"): the application writes SQL with the
 * plain table names; prefix() puts the prefix in front of every known table name.
 * This way the app can share a database with other applications.
 */
final class Db
{
    /** All tables of the application (including historical ones for migrations) */
    public const TABLES = ['users', 'rounds', 'round_days', 'day_breaks', 'slots', 'bookings', 'pages', 'rate_limits', 'settings', 'sent_summaries', 'admin_users', 'round_translations'];

    private static ?PDO $pdo = null;
    /** Connection error of this request: don't wait for the timeout again on every query. */
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

    /** Full table name including prefix */
    public static function table(string $name): string
    {
        return self::tablePrefix() . $name;
    }

    /**
     * Replaces table names in the SQL with the prefixed names. Matches whole words only,
     * i.e. "rounds", but not "r.round_id" or "fk_rounds_user".
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

    /** Execute SQL without parameters (DDL in installer and migrations) */
    public static function execRaw(string $sql): void
    {
        self::pdo()->exec(self::prefix($sql));
    }

    /** true if no connection to the database could be established in this request. */
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
                    PDO::ATTR_TIMEOUT => 5, // don't hang for minutes if the database goes down
                ]);
            } catch (PDOException $e) {
                self::$connectError = $e;
                throw $e;
            }
            // Strict SQL mode: no silent truncation
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

    /** Executes a modification and returns the number of affected rows. */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Runs $fn in a transaction; rollback on exception. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn(); // nested: runs as part of the outer transaction
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

    /** Violation of a UNIQUE constraint (MariaDB error 1062)? */
    public static function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
