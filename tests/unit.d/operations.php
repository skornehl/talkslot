<?php
// Operations: the health check as the condition for keeping a deployed version (expert review P0-5).
// Endpoint, migrations and the clean-up fallback need the database: tests/smoke.d/operations.sh;
// the switch-back on a wrong database password or a failing migration: tests/deploy.sh
echo "Operations\n";

check('health check: MariaDB from 10.6 (also 10.11, 11.x, with a distribution suffix or a proxy prefix)',
    App\Health::supportedServer('10.6.0-MariaDB') && App\Health::supportedServer('10.11.6-MariaDB-0+deb12u1-log')
    && App\Health::supportedServer('11.8.2-MariaDB-ubu2404') && App\Health::supportedServer('5.5.5-10.6.12-MariaDB')
    && App\Health::supportedServer('12.0.1-mariadb'));
check('health check: older MariaDB, MySQL and unknown servers are not supported',
    !App\Health::supportedServer('10.5.22-MariaDB') && !App\Health::supportedServer('10.4.32-MariaDB-log')
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
