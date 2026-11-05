<?php
// Operations: no silent data loss (expert review P0-6). The clean-up itself, deleting a round and the
// defensive slot sync need the database: tests/smoke.d/operations.sh
echo "Operations: deletion date of a round\n";

$at = static function (array $round, ?string $lastDay): ?string {
    $d = App\Cleanup::deletionAt($round + ['is_active' => 0, 'was_used' => 0, 'expired_at' => null, 'delete_after_hours' => 24], $lastDay);
    return $d?->format('Y-m-d H:i');
};
check('active round: 0:00 after the last day plus the deletion period',
    $at(['is_active' => 1], '2026-11-10') === '2026-11-12 00:00' && $at(['is_active' => 1, 'delete_after_hours' => 36], '2026-11-10') === '2026-11-12 12:00'
    && $at(['is_active' => 1, 'delete_after_hours' => 0], '2026-11-10') === '2026-11-11 00:00');
check('inactive round that was in use (deactivated by hand, or with an entry): deleted the same way',
    $at(['was_used' => 1], '2026-11-10') === '2026-11-12 00:00');
check('draft that was never used (e.g. a copy with the old dates): not deleted automatically',
    $at([], '2020-01-01') === null && $at([], '2099-01-01') === null);
check('expired: the period counts from the expiry',
    $at(['expired_at' => '2026-11-11 00:15:00'], '2026-11-10') === '2026-11-12 00:15'
    && $at(['expired_at' => '2026-11-11 00:15:00', 'was_used' => 1, 'delete_after_hours' => 0], '2026-11-10') === '2026-11-11 00:15');
check('expired, but a day is still ahead: not before that day is over',
    $at(['expired_at' => '2026-11-11 00:15:00', 'was_used' => 1], '2026-12-01') === '2026-12-02 00:00');
check('never: no deletion period, or no days',
    $at(['is_active' => 1, 'delete_after_hours' => null], '2026-11-10') === null && $at(['expired_at' => '2026-11-11 00:15:00', 'delete_after_hours' => null], '2026-11-10') === null
    && $at(['is_active' => 1], null) === null);

// Operations: the health check as the condition for keeping a deployed version (expert review P0-5).
// Endpoint, migrations and the clean-up fallback need the database: tests/smoke.d/operations.sh;
// the switch-back on a wrong database password or a failing migration: tests/deploy.sh
echo "Operations\n";

check('health check: MariaDB from 10.6 (also 10.11, 11.x, with a distribution suffix, a proxy prefix or an Enterprise build number)',
    App\Health::supportedServer('10.6.0-MariaDB') && App\Health::supportedServer('10.11.6-MariaDB-0+deb12u1-log')
    && App\Health::supportedServer('11.8.2-MariaDB-ubu2404') && App\Health::supportedServer('5.5.5-10.6.12-MariaDB')
    && App\Health::supportedServer('12.0.1-mariadb') && App\Health::supportedServer('10.6.17-12-MariaDB-enterprise-log')
    && App\Health::supportedServer('11.4.5-3-MariaDB-enterprise'));
check('health check: older MariaDB (also Enterprise), MySQL and unknown servers are not supported',
    !App\Health::supportedServer('10.5.22-MariaDB') && !App\Health::supportedServer('10.4.32-MariaDB-log')
    && !App\Health::supportedServer('10.5.27-21-MariaDB-enterprise-log') && !App\Health::supportedServer('5.5.5-10.5.8-4-MariaDB-enterprise')
    && !App\Health::supportedServer('8.0.36') && !App\Health::supportedServer('8.4.3-0ubuntu0.24.04.1')
    && !App\Health::supportedServer('5.7.44-log') && !App\Health::supportedServer(''));
check('health check key: HMAC-SHA256 of "health" with the secret (like bin/deploy.sh), not the cron key',
    App\Health::token() === hash_hmac('sha256', 'health', (string) App\Config::get('secret')) && App\Health::token() !== App\App::cronToken()
    && preg_match('/^[0-9a-f]{64}$/', App\Health::token()) === 1);
// Script on stdin (not php -r), so that a coverage run sees these lines
[, $out] = $run(['--'], [], '<?php require "app/bootstrap.php"; App\Alert::$stateFile = tempnam(sys_get_temp_dir(), "alert");
    App\Alert::$transport = static function ($to, $subject) { echo "ALERT:$subject|"; return true; };
    App\Config::load(["alert_email" => "betrieb@example.org", "db" => ["host" => "127.0.0.1", "port" => 1, "name" => "x", "user" => "x", "pass" => "x"]] + (require "config/config.php"));
    echo json_encode(App\Health::check()); @unlink(App\Alert::$stateFile);');
check('health check with the database down: 503 "db_unreachable", reported as "Datenbank nicht erreichbar"',
    $out === 'ALERT:[' . App\Config::get('app_name', 'TalkSlot') . '] Datenbank nicht erreichbar (Health-Check)|[503,"db_unreachable"]');

// No round gets a fixed address of the app (a round at /health would answer like the health check before
// the key header decided): the routes of App::run and PublicController::dispatch, the files and directories
// of the web directory, and v/ with the assets of a deployment (bin/build.sh)
$opPaths = ['v'];
foreach ([[App\App::class, 'run'], [App\Controller\PublicController::class, 'dispatch']] as [$opClass, $opMethod]) {
    $opRm = new ReflectionMethod($opClass, $opMethod);
    $opSrc = implode('', array_slice(file((string) $opRm->getFileName()), $opRm->getStartLine() - 1, $opRm->getEndLine() - $opRm->getStartLine() + 1));
    preg_match_all("~['#]\\^?/([a-z0-9][a-z0-9-]*)(?=[/'])~", $opSrc, $opM); // '/cron', '/admin/', '#^/b/
    $opPaths = array_merge($opPaths, $opM[1]);
}
foreach (scandir(dirname(__DIR__, 2) . '/public') ?: [] as $opFile) {
    if ($opFile[0] !== '.') {
        $opPaths[] = strtolower(pathinfo($opFile, PATHINFO_FILENAME));
    }
}
check('round addresses: every fixed address of the app is reserved (routes incl. /cron and /health, web directory)',
    !array_diff(['admin', 'cron', 'health', 'b', 'impressum', 'privacy', 'assets', 'index', 'robots'], $opPaths)
    && !array_diff($opPaths, App\Slug::RESERVED));

// Cron monitoring (expert review P1-2). Recording the runs, the dashboard notice and the alert mail need the
// database: tests/smoke.d/operations.sh
echo "Operations: cron monitoring\n";
$h = 3600;
$now = 1_800_000_000;
check('late: no completed run for cron_alert_hours (also exactly then); a run within them is fine',
    App\CronMonitor::state($now - 3 * $h, null, $now, 3) === 'late' && App\CronMonitor::state($now - 5 * $h, $now - $h, $now, 3) === 'late'
    && App\CronMonitor::state($now - 3 * $h + 1, null, $now, 3) === null && App\CronMonitor::state($now - 2 * $h, $now - 9 * $h, $now, 3) === null);
check('never: no run at all, cron_alert_hours since the monitoring began; not before, and not without its start',
    App\CronMonitor::state(null, $now - 3 * $h, $now, 3) === 'never' && App\CronMonitor::state(null, $now - 3 * $h + 1, $now, 3) === null
    && App\CronMonitor::state(null, null, $now, 3) === null);
check('cron_alert_hours = 0: monitoring off', App\CronMonitor::state($now - 99 * $h, $now - 99 * $h, $now, 0) === null);
check('alert mail: due at a new outage (none since the last run), then once a day',
    App\CronMonitor::alertDue($now - 3 * $h, 0, $now) && App\CronMonitor::alertDue($now - 3 * $h, $now - 30 * $h, $now)
    && !App\CronMonitor::alertDue($now - 30 * $h, $now - 23 * $h, $now) && App\CronMonitor::alertDue($now - 30 * $h, $now - 24 * $h, $now));
$opHours = [];
foreach ([[], ['cron_alert_hours' => 0], ['cron_alert_hours' => -2], ['cron_alert_hours' => '6']] as $set) {
    App\Config::load($set + array_diff_key($cfg, ['cron_alert_hours' => 1]));
    $opHours[] = App\CronMonitor::alertHours();
}
App\Config::load($cfg);
check('cron_alert_hours: 3 by default, 0 (also below) switches it off (' . implode(',', $opHours) . ')', $opHours === [3, 0, 0, 6]);
// Switched off, page views do not even read the settings: any database access throws here
$opDbError = new ReflectionProperty(App\Db::class, 'connectError');
$opSaved = $opDbError->getValue();
$opDbError->setValue(null, new PDOException('no database in this test'));
$opCheck = static function (int $hours) use ($cfg): bool {
    App\Config::load(['cron_alert_hours' => $hours] + $cfg);
    try {
        App\CronMonitor::check();
        return true;
    } catch (PDOException) {
        return false;
    }
};
$opOff = $opCheck(0);
$opOn = $opCheck(3);
$opDbError->setValue(null, $opSaved);
App\Config::load($cfg);
check('cron_alert_hours = 0: page views skip the check without a database query (with 3 they read the settings)', $opOff && !$opOn);
$opMail = implode("\n", App\CronMonitor::alertLines(mktime(10, 17, 0, 11, 5, 2026), 3));
check('alert mail: since when, after how many hours, what to check (GitHub: 60 days, enable again), no address or token',
    str_contains($opMail, 'seit dem 05.11.2026 10:17 Uhr nicht mehr vollständig gelaufen (gemeldet wird nach 3 Stunden)')
    && str_contains($opMail, 'nach 60 Tagen ohne Aktivität ab') && str_contains($opMail, 'gh workflow enable cron.yml')
    && str_contains($opMail, 'einmal am Tag') && !preg_match('/@|https?:|[0-9a-f]{32}/', $opMail));
