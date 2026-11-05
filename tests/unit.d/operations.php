<?php
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
