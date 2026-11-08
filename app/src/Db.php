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
    public const TABLES = ['users', 'rounds', 'round_days', 'day_breaks', 'slots', 'bookings', 'pages', 'rate_limits', 'settings', 'sent_summaries', 'admin_users', 'round_translations', 'audit_log', 'round_templates', 'mail_outbox', 'security_events'];

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
                self::$pdo = new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), self::options($c));
            } catch (PDOException $e) {
                self::$connectError = self::explain($e, $c);
                throw self::$connectError;
            }
            // Strict SQL mode: no silent truncation
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    /**
     * Options of the connection. With db.ssl_ca (absolute path of the CA certificate of the database server, a
     * PEM file) the connection is encrypted with TLS, and the server must show a certificate signed by that CA
     * for the host name in db.host; otherwise it fails. Without it the connection is not encrypted, which is
     * fine when the database runs on the same server or in the hoster's internal network.
     * The file is not checked here: PHP's file functions may not reach it under open_basedir, while the MySQL
     * driver hands it to OpenSSL directly. A missing or wrong file fails like any other connection error
     * (503, alert mail, health check "db_unreachable"), with explain()'s hint.
     * @param array<string, mixed> $db the db section of the configuration
     * @return array<int, mixed>
     * @throws PDOException for a relative path (it would depend on the working directory: web and cron differ)
     */
    public static function options(array $db): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5, // don't hang for minutes if the database goes down
        ];
        $ca = is_string($db['ssl_ca'] ?? null) ? trim($db['ssl_ca']) : ''; // another type: a warning on the dashboard
        if ($ca !== '' && !str_starts_with($ca, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $ca)) {
            throw new PDOException('db.ssl_ca must be an absolute path, the path of the CA certificate of the database server');
        }
        if ($ca !== '') {
            $options[self::mysqlAttribute('SSL_CA')] = $ca;
            $options[self::mysqlAttribute('SSL_VERIFY_SERVER_CERT')] = true;
        }
        return $options;
    }

    /** Pdo\Mysql::ATTR_<name> from PHP 8.4 on; PHP 8.3 only has PDO::MYSQL_ATTR_<name> (deprecated since 8.5). */
    private static function mysqlAttribute(string $name): int
    {
        return (int) constant(class_exists(\Pdo\Mysql::class, false) ? \Pdo\Mysql::class . '::ATTR_' . $name : PDO::class . '::MYSQL_ATTR_' . $name);
    }

    /**
     * A failed connection with db.ssl_ca: the driver only says "Cannot connect to MySQL using SSL" (wrong or
     * unreadable CA file, a certificate for another name) or "MySQL server has gone away" (a server without
     * TLS). The alert mail then names the setting to check. Without quotes or brackets: ErrorPage::sanitize()
     * would cut them out of the alert mail and the log.
     */
    private static function explain(PDOException $e, array $db): PDOException
    {
        $tls = in_array((int) ($e->errorInfo[1] ?? 0), [2002, 2006], true) || preg_match('/\[(2002|2006)\]/', $e->getMessage()) === 1;
        if (!is_string($db['ssl_ca'] ?? null) || trim($db['ssl_ca']) === '' || !$tls) {
            return $e; // e.g. a wrong password: the hint would point the wrong way
        }
        return new PDOException($e->getMessage() . ' – db.ssl_ca is set: check that PHP can read the file, that it holds the CA that'
            . ' signed the certificate of the database server, that db.host is the name in that certificate and that the server'
            . ' offers TLS', 0, $e);
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
