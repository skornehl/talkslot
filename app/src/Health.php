<?php
declare(strict_types=1);

namespace App;

/**
 * Health check as the condition for keeping a deployed version (bin/deploy.sh, POST /health in App).
 * The login and start pages work without the database, so on their own they would pass a version with a
 * wrong database password or a failed migration. This check needs the database: reachable, MariaDB in
 * the minimum version, every migration of this code applied (it runs pending ones and reports a failure
 * instead of swallowing it). The response carries only a status word; details go to the log and the
 * alert mail.
 */
final class Health
{
    /** Minimum MariaDB version (README, installation guide); the migrations use MariaDB syntax. */
    public const MIN_MARIADB = '10.6';

    /** Key for the health check, derived from the app secret like the web cron's (no separate secret). */
    public static function token(): string
    {
        return hash_hmac('sha256', 'health', (string) Config::get('secret', ''));
    }

    /**
     * @return array{int, string} HTTP status (200 healthy, otherwise 503) and status word: ok, not_installed
     *   (empty database before the installer, fine for the first deployment), db_unreachable,
     *   db_unsupported, migration_failed, schema_outdated (e.g. another request is still migrating)
     */
    public static function check(): array
    {
        try {
            $server = (string) Db::value('SELECT VERSION()');
        } catch (\PDOException $e) {
            Alert::exception($e, 'Health-Check');
            return [503, 'db_unreachable'];
        }
        $status = match (true) {
            !self::supportedServer($server) => 'db_unsupported',
            !Installer::isInstalled() => 'not_installed',
            !self::migrate() => 'migration_failed',
            (int) Settings::get('schema_version') < Migrations::LATEST => 'schema_outdated',
            default => 'ok',
        };
        return [in_array($status, ['ok', 'not_installed'], true) ? 200 : 503, $status];
    }

    /**
     * MariaDB from MIN_MARIADB on. VERSION() e.g. "10.11.6-MariaDB-0+deb12u1-log", via some proxies
     * "5.5.5-10.6.12-MariaDB", Enterprise Server with a build number "10.6.17-12-MariaDB-enterprise-log";
     * MySQL ("8.0.36") has no "-MariaDB".
     */
    public static function supportedServer(string $version): bool
    {
        return preg_match('/(\d+\.\d+)(?:\.\d+)?(?:-\d+)?-MariaDB/i', $version, $m) === 1
            && version_compare($m[1], self::MIN_MARIADB, '>=');
    }

    /** Pending migrations; false (reported) if one fails. */
    private static function migrate(): bool
    {
        try {
            Migrations::run();
            return true;
        } catch (\Throwable $e) {
            Alert::exception($e, 'Health-Check: Migration');
            return false;
        }
    }
}
