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
$opH = 3600;
$opNow = 1_800_000_000;
check('late: no completed run for cron_alert_hours (also exactly then); a run within them is fine',
    App\CronMonitor::state($opNow - 3 * $opH, null, $opNow, 3) === 'late' && App\CronMonitor::state($opNow - 5 * $opH, $opNow - $opH, $opNow, 3) === 'late'
    && App\CronMonitor::state($opNow - 3 * $opH + 1, null, $opNow, 3) === null && App\CronMonitor::state($opNow - 2 * $opH, $opNow - 9 * $opH, $opNow, 3) === null);
check('never: no run at all, cron_alert_hours since the monitoring began; not before, and not without its start',
    App\CronMonitor::state(null, $opNow - 3 * $opH, $opNow, 3) === 'never' && App\CronMonitor::state(null, $opNow - 3 * $opH + 1, $opNow, 3) === null
    && App\CronMonitor::state(null, null, $opNow, 3) === null);
check('cron_alert_hours = 0: monitoring off', App\CronMonitor::state($opNow - 99 * $opH, $opNow - 99 * $opH, $opNow, 0) === null);
check('alert mail: due at an outage with no mail since the last run, then once a day',
    App\CronMonitor::alertDue($opNow - 3 * $opH, 0, $opNow) && App\CronMonitor::alertDue($opNow - 3 * $opH, $opNow - 30 * $opH, $opNow)
    && !App\CronMonitor::alertDue($opNow - 30 * $opH, $opNow - 23 * $opH, $opNow) && App\CronMonitor::alertDue($opNow - 30 * $opH, $opNow - 24 * $opH, $opNow));
check('alert mail: a new outage within a day of the last mail (repaired, then down again) is mailed at once',
    App\CronMonitor::alertDue($opNow - 3 * $opH, $opNow - 5 * $opH, $opNow) && App\CronMonitor::alertDue($opNow - 3 * $opH, $opNow - 3 * $opH, $opNow)
    && !App\CronMonitor::alertDue($opNow - 3 * $opH, $opNow - 3 * $opH + 1, $opNow) && !App\CronMonitor::alertDue($opNow - 5 * $opH, $opNow - 3 * $opH, $opNow));
$opHours = [];
foreach ([[], ['cron_alert_hours' => 0], ['cron_alert_hours' => -2], ['cron_alert_hours' => '6']] as $opSet) {
    App\Config::load($opSet + array_diff_key($cfg, ['cron_alert_hours' => 1]));
    $opHours[] = App\CronMonitor::alertHours();
}
App\Config::load($cfg);
check('cron_alert_hours: 12 by default (GitHub starts scheduled runs hours late), 0 (also below) switches it off (' . implode(',', $opHours) . ')', $opHours === [12, 0, 0, 6]);
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

// Operations: start and platform checks (expert review P1-10). PHP version and extensions (App\Platform), the
// configuration (App\ConfigSchema), the database server (App\Health) and the error page. With the database:
// tests/smoke.d/operations.sh; on old PHP versions: the CI workflow (tests.yml)
echo "Operations: start and platform checks\n";

$pcAll = ['Core', 'mbstring', 'iconv', 'PDO', 'pdo_mysql'];
$pcEn = static fn (array $problems): string => implode(' | ', array_column($problems, 'en'));
check('platform: PHP 8.3 with mbstring, iconv and pdo_mysql passes; PHP 8.2 is named with its version, in English and German',
    App\Platform::problems(true, 80300, $pcAll) === [] && App\Platform::problems(true, 80412, $pcAll) === []
    && $pcEn(App\Platform::problems(true, 80212, $pcAll)) === 'PHP 8.3 or newer is required; this server runs PHP 8.2. Most web hosts let you switch the PHP version in their control panel.'
    && str_starts_with(App\Platform::problems(false, 70433, $pcAll)[0]['de'], 'Es wird PHP 8.3 oder neuer benötigt, dieser Server hat PHP 7.4.'));
check('platform: each missing extension is named; pdo_mysql only where the database is used (not for the unit tests)',
    $pcEn(App\Platform::problems(true, 80300, ['Core', 'PDO'])) === 'The PHP extension "mbstring" is missing. It can usually be switched on in the PHP settings of the hosting, otherwise ask your web host. | The PHP extension "iconv" is missing. It can usually be switched on in the PHP settings of the hosting, otherwise ask your web host. | The PHP extension "pdo_mysql" is missing. It can usually be switched on in the PHP settings of the hosting, otherwise ask your web host.'
    && App\Platform::problems(false, 80300, ['MBSTRING', 'iconv']) === [] && count(App\Platform::problems(true, 80300, ['mbstring', 'iconv'])) === 1
    && App\Platform::problems(false) === []);
$pcPage = App\Platform::page([['en' => 'PHP <8.3> required', 'de' => 'PHP „8.3“ & neuer']]);
check('platform: the page is German and English, escaped, with the guide and a word for visitors',
    str_contains($pcPage, '<html lang="de">') && str_contains($pcPage, '<li>PHP „8.3“ &amp; neuer</li>') && str_contains($pcPage, '<section lang="en"><h2>TalkSlot cannot start</h2><ul><li>PHP &lt;8.3&gt; required</li>')
    && str_contains($pcPage, 'href="https://skornehl.github.io/talkslot/de/guide/installation/#requirements"') && str_contains($pcPage, 'href="https://skornehl.github.io/talkslot/guide/configuration/"')
    && str_contains($pcPage, 'Wenn Sie diese Seite als Besucher sehen') && !str_contains($pcPage, 'style='));
// enforce() and stop() end the process: in a child (script on stdin, so that a coverage run sees these lines)
[$rc, $out, $err] = $run(['--'], [], '<?php require "app/bootstrap.php"; App\Platform::enforce(true, 80300, ["mbstring", "iconv", "pdo_mysql"], true); echo "läuft|";
    App\Platform::enforce(true, 80212, ["iconv", "pdo_mysql"], true); echo "weiter";');
check('platform: on the command line a problem stops with exit code 1 and the English lines; without one nothing happens',
    $rc === 1 && $out === "läuft|PHP 8.3 or newer is required; this server runs PHP 8.2. Most web hosts let you switch the PHP version in their control panel.\nThe PHP extension \"mbstring\" is missing. It can usually be switched on in the PHP settings of the hosting, otherwise ask your web host.\n" && $err === '');
// ini_restore: as before the bootstrap set the log file, the server's log (here stderr)
[$rc, $out, $err] = $run(['--'], [], '<?php require "app/bootstrap.php"; ini_restore("error_log"); App\Platform::stop([["en" => "Configuration error: x", "de" => "Fehler: x"]], false); echo "weiter";');
check('platform: on the web the page (also in the server log), exit code 1',
    $rc === 1 && str_starts_with($out, '<!doctype html>') && str_contains($out, '<li>Fehler: x</li>') && str_ends_with($out, '</section></main>')
    && str_contains($err, 'TalkSlot cannot start: Configuration error: x'));
// The real thing: PHP without its php.ini lacks the extensions that are loaded as modules (Debian, Ubuntu, most
// web hosts). The bootstrap must explain that before mb_internal_encoding() ends in a fatal error.
[, $pcBare] = $run(['-n', '-r', 'echo implode(",", array_intersect(["mbstring", "iconv"], get_loaded_extensions()));']);
if ($pcBare !== 'mbstring,iconv') {
    [$rc, $out, $err] = $run(['-n', 'bin/cron.php']);
    check('platform: PHP without mbstring (php -n): the cron job explains it instead of a fatal error',
        $rc === 1 && str_contains($out, 'The PHP extension "' . ($pcBare === 'iconv' || $pcBare === '' ? 'mbstring' : 'iconv') . '" is missing.') && !str_contains($out . $err, 'Fatal'));
}

$pcSample = (static fn (): array => require dirname(__DIR__, 2) . '/config/config.sample.php')();
$pcOk = ['secret' => str_repeat('s', 32)] + $pcSample;
$pcErr = static fn (array $change): string => $pcEn(App\ConfigSchema::errors($change + $pcOk));
$pcTz = date_default_timezone_get();
check('configuration: the sample (with a secret) and the dev configuration have no errors',
    App\ConfigSchema::errors($pcOk) === [] && App\ConfigSchema::errors((static fn (): array => require dirname(__DIR__, 2) . '/dev/config.dev.php')()) === []);
check('configuration: a missing or short secret stops the app (as before)',
    $pcErr(['secret' => str_repeat('s', 31)]) === 'Configuration incomplete: "secret" is missing or shorter than 32 characters.'
    && $pcEn(App\ConfigSchema::errors(array_diff_key($pcOk, ['secret' => 1]))) === 'Configuration incomplete: "secret" is missing or shorter than 32 characters.');
check('configuration: a misspelt time zone stops the app instead of running on UTC; so does one PHP cannot set (+01:00)',
    $pcErr(['timezone' => 'Europe/Berllin']) === 'Configuration error: "timezone" is not a valid time zone ("Europe/Berllin"). Use a name like Europe/Berlin.'
    && $pcErr(['timezone' => '+01:00']) !== '' && $pcErr(['timezone' => '']) !== '' && str_contains($pcErr(['timezone' => ['Europe/Berlin']]), '(array)')
    && $pcErr(['timezone' => 'America/New_York']) === '' && App\ConfigSchema::errors(array_diff_key($pcOk, ['timezone' => 1])) === []
    && date_default_timezone_get() === $pcTz);
check('configuration: a language without its file stops the app (raw text keys otherwise), as does none at all',
    $pcErr(['languages' => ['de', 'fr', '../de']]) === 'Configuration error: "languages" must name at least one language, each with a file in app/lang/ (not found: "fr", "../de").'
    && $pcErr(['languages' => []]) === 'Configuration error: "languages" must name at least one language, each with a file in app/lang/ (not found: –).'
    && $pcErr(['languages' => 'de']) === '' && App\ConfigSchema::errors(array_diff_key($pcOk, ['languages' => 1])) === []);
check('configuration: an invalid table prefix stops the app; several errors are all named',
    $pcErr(['db' => ['prefix' => 'tp-'] + $pcSample['db']]) === 'Configuration error: "db.prefix" may only contain letters, digits and _ (at most 20 characters).'
    && $pcErr(['db' => ['prefix' => ''] + $pcSample['db']]) === '' && count(App\ConfigSchema::errors(['timezone' => 'x', 'languages' => [], 'db' => ['prefix' => 7]])) === 4
    && str_starts_with(App\ConfigSchema::errors(['timezone' => 'x'] + $pcOk)[0]['de'], 'Fehler in der Konfiguration: „timezone“ ist keine gültige Zeitzone ("x").'));

// The bootstrap stops before anything runs on UTC: a copy of it with its own config.php (code and languages linked)
$pcRoot = sys_get_temp_dir() . '/tp-tz-' . getmypid();
@mkdir("$pcRoot/config", 0700, true);
@mkdir("$pcRoot/app", 0700, true);
copy(dirname(__DIR__, 2) . '/app/bootstrap.php', "$pcRoot/app/bootstrap.php");
foreach (['helpers.php', 'src', 'lang'] as $pcLink) {
    symlink(dirname(__DIR__, 2) . "/app/$pcLink", "$pcRoot/app/$pcLink");
}
file_put_contents("$pcRoot/config/config.php", '<?php return ' . var_export(['timezone' => 'Europe/Berllin', 'languages' => ['de', 'xx']] + $cfg, true) . ';');
[$rc, $out] = $run(['-r', 'require ' . var_export("$pcRoot/app/bootstrap.php", true) . '; echo date_default_timezone_get();']);
exec('rm -rf ' . escapeshellarg($pcRoot));
check('bootstrap: a misspelt time zone and a language without its file stop the app with both reasons, before anything runs on UTC',
    $rc === 1 && $out === "Configuration error: \"timezone\" is not a valid time zone (\"Europe/Berllin\"). Use a name like Europe/Berlin.\n"
        . "Configuration error: \"languages\" must name at least one language, each with a file in app/lang/ (not found: \"xx\").\n");

$pcValid = ['mail' => ['from_address' => 'termine@example.org'] + $pcSample['mail']] + $pcOk;
$pcWarn = static fn (array $change): array => App\AdminI18n::with('de', static fn () => App\ConfigSchema::warnings($change + $pcValid));
check('configuration: the sample (with a sender) and the dev configuration need no hint',
    $pcWarn([]) === [] && App\ConfigSchema::warnings((static fn (): array => require dirname(__DIR__, 2) . '/dev/config.dev.php')()) === []);
check('configuration: a misspelt key is named with the setting probably meant, also inside a section',
    $pcWarn(['sumary_evening_hour' => 17]) === ['„sumary_evening_hour“ ist keine Einstellung von TalkSlot und wirkt nicht. Gemeint ist vermutlich „summary_evening_hour“.']
    && $pcWarn(['rate_limits' => ['logn' => [5, 900]]]) === ['„rate_limits.logn“ ist keine Einstellung von TalkSlot und wirkt nicht. Gemeint ist vermutlich „rate_limits.login“.']
    && $pcWarn(['mail_que' => []]) === ['„mail_que“ ist keine Einstellung von TalkSlot und wirkt nicht. Gemeint ist vermutlich „mail_queue“.']
    && $pcWarn(['wetter' => 1]) === ['„wetter“ ist keine Einstellung von TalkSlot und wirkt nicht.']
    && App\AdminI18n::with('en', static fn () => App\ConfigSchema::warnings(['sumary_evening_hour' => 17] + $pcValid)) === ['"sumary_evening_hour" is not a TalkSlot setting and has no effect. Did you mean "summary_evening_hour"?']);
check('configuration: values of the wrong type or out of range are named with what is expected',
    $pcWarn(['force_https' => 'false']) === ['„force_https“ hat keinen gültigen Wert, erwartet wird true oder false (ohne Anführungszeichen).']
    && $pcWarn(['summary_evening_hour' => 24]) === ['„summary_evening_hour“ hat keinen gültigen Wert, erwartet wird eine ganze Zahl von 0 bis 23.']
    && $pcWarn(['session_idle_minutes' => 0]) === ['„session_idle_minutes“ hat keinen gültigen Wert, erwartet wird eine ganze Zahl ab 1.']
    && $pcWarn(['mail' => ['transport' => 'SMTP'] + $pcValid['mail']]) === ['„mail.transport“ hat keinen gültigen Wert, erwartet wird einer dieser Werte: \'mail\', \'smtp\', \'none\'.']
    && $pcWarn(['rate_limits' => ['login' => [0, 900], 'code' => [30], 'booking' => ['30', 3600]]]) === array_map(static fn ($k) => "„rate_limits.{$k}“ hat keinen gültigen Wert, erwartet wird [Anzahl, Sekunden] mit ganzen Zahlen ab 1.", ['login', 'code', 'booking'])
    && $pcWarn(['mail_queue' => 'aus']) === ['„mail_queue“ hat keinen gültigen Wert, erwartet wird eine Liste von Einstellungen in eckigen Klammern wie in config.sample.php.']
    && $pcWarn(['base_url' => 'termine.example.org']) === ['„base_url“ hat keinen gültigen Wert, erwartet wird eine Adresse wie https://termine.example.org.']
    && $pcWarn(['alert_email' => 'betrieb']) === ['„alert_email“ hat keinen gültigen Wert, erwartet wird eine E-Mail-Adresse oder \'\'.']
    && $pcWarn(['hosting' => ['de' => ['x']]]) === ['„hosting“ hat keinen gültigen Wert, erwartet wird Texte je Sprache, z. B. [\'de\' => \'…\'].']
    && $pcWarn(['app_name' => 7]) === ['„app_name“ hat keinen gültigen Wert, erwartet wird ein Text in Anführungszeichen.']);
check('configuration: whole numbers may be quoted, 0 and 1 count as false and true',
    $pcWarn(['db' => ['port' => '3307'] + $pcSample['db'], 'debug' => 0, 'force_https' => 1]) === []);
check('configuration: hints never repeat a value (it may be a password)',
    !str_contains(implode(' ', $pcWarn(['db' => ['pass' => ['Geheim-123']] + $pcSample['db'], 'mail' => ['smtp' => ['pass' => 4711] + $pcSample['mail']['smtp']] + $pcValid['mail']])), 'Geheim')
    && count($pcWarn(['db' => ['pass' => ['Geheim-123']] + $pcSample['db']])) === 1);
check('configuration: a default language that is not offered, and no sender address, are named',
    $pcWarn(['default_language' => 'fr']) === ['„default_language“ ist "fr", diese Sprache steht aber nicht unter „languages“. Stattdessen gilt "de".']
    && $pcWarn(['mail' => ['from_address' => ''] + $pcSample['mail']]) === ['„mail.from_address“ fehlt: Ohne Absenderadresse verschickt TalkSlot keine E-Mails.']
    && $pcWarn(['mail' => ['from_address' => '', 'transport' => 'none'] + $pcSample['mail']]) === []);
[, $out] = $run(['-d', 'disable_functions=mail', '--'], [], '<?php require "app/bootstrap.php"; $c = require "config/config.php";
    echo json_encode([App\ConfigSchema::warnings(["mail" => ["transport" => "mail", "from_address" => "a@example.org"]] + $c), App\ConfigSchema::warnings(["mail" => ["transport" => "smtp", "from_address" => "a@example.org"]] + $c)], JSON_UNESCAPED_UNICODE);');
check('configuration: transport "mail" while the host has disabled mail() is named',
    $out === json_encode([['„mail.transport“ ist „mail“, aber die PHP-Funktion mail() ist auf diesem Server abgeschaltet: So gehen keine E-Mails hinaus. Bitte stellen Sie auf „smtp“ um.'], []], JSON_UNESCAPED_UNICODE));

// One place for every setting: config.sample.php lists exactly the keys of the schema, and every key the code
// reads is in it with the same default (Config::get('x', <default>), RateLimiter::limits('bucket', n, s))
$pcMissing = array_filter(array_keys(App\ConfigSchema::KEYS), static function (string $key) use ($pcSample): bool {
    $v = $pcSample;
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) {
            return true;
        }
        $v = $v[$part];
    }
    return false;
});
check('configuration: config.sample.php has every key of the schema' . ($pcMissing ? ' (missing: ' . implode(', ', $pcMissing) . ')' : ''), !$pcMissing);
$pcRead = $pcBad = [];
$pcFiles = array_merge(glob(dirname(__DIR__, 2) . '/app/*.php'), glob(dirname(__DIR__, 2) . '/app/src/*.php'), glob(dirname(__DIR__, 2) . '/app/src/*/*.php'),
    glob(dirname(__DIR__, 2) . '/app/templates/*.php'), glob(dirname(__DIR__, 2) . '/app/templates/*/*.php'), glob(dirname(__DIR__, 2) . '/bin/*.php'), glob(dirname(__DIR__, 2) . '/public/*.php'));
foreach ($pcFiles as $pcFile) {
    $src = (string) file_get_contents($pcFile);
    // Config::get('key') or Config::get('key', default); a default is a literal (a number, text, array, true …)
    preg_match_all("/Config::get\\('([a-z_.]+)'(\\)|, (\\[[^\\]]*\\]|[^,()]+)\\))/", $src, $pcM, PREG_SET_ORDER);
    foreach ($pcM as $m) {
        $pcRead[] = $m[1];
        $spec = App\ConfigSchema::KEYS[$m[1]] ?? null;
        if ($spec === null && !in_array($m[1], App\ConfigSchema::sections(), true)) {
            $pcBad[] = basename($pcFile) . ": $m[1] (not in the schema)";
        } elseif ($spec !== null && isset($m[3]) && preg_match("/^[\\w\\s'\\[\\],*-]*$/", $m[3]) && eval('return ' . $m[3] . ';') !== $spec[1]) {
            $pcBad[] = basename($pcFile) . ": $m[1] defaults to $m[3], the schema to " . json_encode($spec[1]);
        }
    }
    preg_match_all("/RateLimiter::limits\\('(\\w+)', (\\d+), (\\d+)\\)/", $src, $pcM, PREG_SET_ORDER);
    foreach ($pcM as $m) {
        if ((App\ConfigSchema::KEYS['rate_limits.' . $m[1]][1] ?? null) !== [(int) $m[2], (int) $m[3]]) {
            $pcBad[] = basename($pcFile) . ": rate limit $m[1] [$m[2], $m[3]] differs from the schema";
        }
    }
}
check('configuration: every key the code reads is in the schema, with the same default' . ($pcBad ? ' (' . implode('; ', $pcBad) . ')' : ''),
    !$pcBad && count(array_unique($pcRead)) > 30 && in_array('summary_evening_hour', $pcRead, true) && in_array('csrf_max_age_seconds', $pcRead, true));

$pcDash = App\AdminI18n::with('de', static fn () => App\View::capture('admin/dashboard', ['title' => 'x', 'rounds' => [], 'showOwner' => true,
    'configExposed' => [], 'profileHints' => [], 'operatorMissing' => false, 'legalHints' => [], 'mailsLate' => null, 'unusedAccounts' => 0,
    'setupHints' => ['„a<b>“ ist keine Einstellung von TalkSlot und wirkt nicht.', 'Der Datenbankserver wird nicht unterstützt: MySQL 8.0.36.']]));
check('dashboard: setup hints listed for admins, escaped, with where to change them',
    str_contains($pcDash, '<div class="alert alert-warning" role="status" data-setup-hints>')
    && str_contains($pcDash, 'die Einstellungen stehen in config/config.php):</strong></p>')
    && str_contains($pcDash, '<li>„a&lt;b&gt;“ ist keine Einstellung von TalkSlot und wirkt nicht.</li><li>Der Datenbankserver wird nicht unterstützt: MySQL 8.0.36.</li>'));

$pcServer = static function (string $version, string $lang = 'de'): ?string {
    App\Health::$serverVersion = $version;
    try {
        return App\AdminI18n::with($lang, static fn () => App\Health::serverProblem());
    } finally {
        App\Health::$serverVersion = null;
    }
};
check('database server: MariaDB from 10.6 is fine; MySQL and older MariaDB are named for the admin',
    $pcServer('10.11.6-MariaDB-0+deb12u1-log') === null && $pcServer('5.5.5-10.6.12-MariaDB') === null
    && $pcServer('8.0.36') === 'Der Datenbankserver wird nicht unterstützt: MySQL 8.0.36. TalkSlot braucht MariaDB 10.6 oder neuer; mit MySQL oder einer älteren MariaDB schlagen die Updates der Datenbank fehl. Bitte wählen Sie beim Webhoster eine aktuelle MariaDB-Datenbank.'
    && str_starts_with((string) $pcServer('10.5.22-MariaDB-log', 'en'), 'The database server is not supported: MariaDB 10.5.22. TalkSlot needs MariaDB 10.6 or newer;')
    && str_starts_with((string) $pcServer('5.5.5-10.5.8-4-MariaDB-enterprise'), 'Der Datenbankserver wird nicht unterstützt: MariaDB 10.5.8.')
    && str_starts_with((string) $pcServer(''), 'Der Datenbankserver wird nicht unterstützt: unbekannt.'));
App\Health::$serverVersion = '8.4.3-0ubuntu0.24.04.1';
$pcStatus = App\Health::serverStatus();
$pcEnv = App\AdminI18n::with('de', static fn () => App\Installer::environmentProblems(sys_get_temp_dir()));
App\Health::$serverVersion = '11.8.2-MariaDB';
$pcEnv2 = [App\Installer::environmentProblems(sys_get_temp_dir()), App\AdminI18n::with('de', static fn () => App\Installer::environmentProblems(sys_get_temp_dir() . '/gibt-es-nicht-' . getmypid())), App\Health::serverStatus()];
App\Health::$serverVersion = null;
check('installer: refuses MySQL and a missing or read-only app/var before anything is created; cron reports the server',
    $pcStatus === 'unsupported' && count($pcEnv) === 1 && str_contains($pcEnv[0], 'MySQL 8.4.3')
    && $pcEnv2[0] === [] && $pcEnv2[2] === 'ok'
    && $pcEnv2[1] === ['Das Verzeichnis app/var fehlt oder ist für PHP nicht beschreibbar. Dort liegen Sitzungen, Sperrdatei, Fehlerprotokoll und der Zähler für Fehlermeldungen. Bitte legen Sie es an bzw. erlauben Sie PHP das Schreiben (z. B. Rechte 750 für den PHP-Benutzer) und versuchen Sie es erneut.']);

// The error page: half-rendered output is discarded (View::capture() for a template that fails,
// ErrorPage::show() for everything still buffered, e.g. after a fatal error while rendering)
$pcLevel = ob_get_level();
ob_start();
try {
    App\View::capture('public/notfound', ['title' => new stdClass(), 'text' => 'x']); // <h1> is out, then the title fails
    $pcThrown = false;
} catch (Error) {
    $pcThrown = true;
}
$pcLeak = (string) ob_get_clean();
check('template error: its half-written output is discarded and the error goes on', $pcThrown && $pcLeak === '' && ob_get_level() === $pcLevel);
[, $out] = $run(['--'], [], '<?php require "app/bootstrap.php"; $_SERVER["REQUEST_URI"] = "/admin/round/7"; App\I18n::set("en");
    ob_start(); echo "<p>half a page"; ob_start(); echo "template"; App\ErrorPage::show(); echo "|", ob_get_level();');
check('error page: everything still buffered is discarded, the page is the whole response (lang, back to /admin, legal notice)',
    str_starts_with($out, '<!doctype html><html lang="en">') && !str_contains($out, 'half a page') && !str_contains($out, 'template') && str_ends_with($out, '</main>|0')
    && str_contains($out, '<p>If the error persists, please contact the operator of this site (see <a href="/impressum">Legal notice</a>).</p><p><a href="/admin">Go to the start page</a></p>'));
[, $out] = $run(['--'], [], '<?php require "app/bootstrap.php"; $c = require "config/config.php"; $c["db"]["host"] = "127.0.0.1"; $c["db"]["port"] = 1;
    App\Config::load(["alert_email" => "", "default_language" => "en", "languages" => ["de", "en"]] + $c);
    try { App\Db::one("SELECT 1"); } catch (PDOException $e) { echo App\ErrorPage::page($e); }');
check('error page before a language was chosen: the default language, never raw text keys; no legal notice link while the database is down',
    str_contains($out, '<html lang="en">') && str_contains($out, '<h1>Something went wrong</h1>') && !str_contains($out, 'error.') && !str_contains($out, 'impressum')
    && str_contains($out, '<a href="/">Go to the start page</a>'));

echo "Operations: table prefix, foreign keys and locks (expert review P2-7)\n";
App\Config::load(['db' => ['prefix' => 'tp2_'] + $cfg['db']] + $cfg);
// Only names of tables outside quotes and comments: values, LIKE patterns and quoted identifiers stay as written
check('prefix: nothing inside quotes or comments changes, also not with spaces around the word; tables and foreign keys get it',
    App\Db::prefix("SELECT * FROM rounds WHERE title LIKE '%the users and rounds%' AND note = \"slots, bookings\" -- users\nAND `pages` = 1 /* settings */")
        === "SELECT * FROM tp2_rounds WHERE title LIKE '%the users and rounds%' AND note = \"slots, bookings\" -- users\nAND `pages` = 1 /* settings */"
    && App\Db::prefix("SELECT 'it''s users' FROM users WHERE x = 'a\\' rounds'") === "SELECT 'it''s users' FROM tp2_users WHERE x = 'a\\' rounds'"
    && App\Db::prefix("CREATE TABLE IF NOT EXISTS mail_outbox (id INT,\n  constraint  fk_outbox_user FOREIGN KEY (id) REFERENCES users (id))")
        === "CREATE TABLE IF NOT EXISTS tp2_mail_outbox (id INT,\n  constraint  tp2_fk_outbox_user FOREIGN KEY (id) REFERENCES tp2_users (id))"
    && App\Db::prefix('SELECT fk_rounds_user, r.rounds_x, rounds.id FROM rounds') === 'SELECT fk_rounds_user, r.rounds_x, tp2_rounds.id FROM tp2_rounds'
    && App\Db::prefix('ALTER TABLE rounds DROP CONSTRAINT fk_rounds_user') === 'ALTER TABLE tp2_rounds DROP CONSTRAINT fk_rounds_user'
    && App\Db::prefix("SELECT 1 # users' note\nFROM users") === "SELECT 1 # users' note\nFROM tp2_users"
    && App\Db::prefix('SELECT 1--1 FROM users -- users') === 'SELECT 1--1 FROM tp2_users -- users'
    && App\Db::prefix('SELECT /*! users */ /*M! rounds */ /* slots */ 1') === 'SELECT /*! tp2_users */ /*M! tp2_rounds */ /* slots */ 1');
App\Config::load($cfg);
// Every table the app creates is in Db::TABLES (else it would run without the prefix), and every foreign key is
// called fk_… (else it would keep its name and clash with a second installation in the same database)
$p7Steps = (new ReflectionClassConstant(App\Migrations::class, 'STEPS'))->getValue();
$p7Sql = (string) file_get_contents(dirname(__DIR__, 2) . '/app/schema.sql') . "\n" . implode("\n", array_merge(...array_values($p7Steps)));
preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? (\w+)/i', $p7Sql, $p7Tables);
preg_match_all('/\bCONSTRAINT\s+(\w+)\s+FOREIGN KEY/i', $p7Sql, $p7Fks);
preg_match_all('/\bFOREIGN KEY\b/i', $p7Sql, $p7AllFks);
check('schema.sql and every migration: each table in Db::TABLES, each foreign key named fk_…',
    count($p7Tables[1]) > 10 && !array_diff($p7Tables[1], App\Db::TABLES) && count($p7Fks[1]) === count($p7AllFks[0])
    && count($p7Fks[1]) > 10 && $p7Fks[1] === array_values(array_filter($p7Fks[1], static fn (string $n): bool => str_starts_with($n, 'fk_'))));
// A foreign key is never dropped by a name written into a migration: installations from before the prefixed
// names have fk_…, newer ones <prefix>fk_… (Db::foreignKey() looks the name up)
check('migrations: no foreign key dropped by a written-down name', !preg_match('/DROP\s+(?:FOREIGN\s+KEY|CONSTRAINT)\s+(?:IF\s+EXISTS\s+)?`?fk_/i', $p7Sql));
// No alias named like a table: Db::prefix() would rewrite it ("AS slots" → "AS tp_slots") and change the keys
$p7Aliases = [];
$p7Words = implode('|', App\Db::TABLES);
foreach (array_merge(glob(dirname(__DIR__, 2) . '/app/src/*.php') ?: [], glob(dirname(__DIR__, 2) . '/app/src/*/*.php') ?: [], glob(dirname(__DIR__, 2) . '/bin/*.php') ?: [], glob(dirname(__DIR__, 2) . '/public/*.php') ?: []) as $f) {
    foreach (token_get_all((string) file_get_contents($f)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match("/\\bAS\\s+($p7Words)\\b|\\b(?:FROM|JOIN|UPDATE)\\s+\\w+\\s+($p7Words)\\b/i", $tok[1])) {
            $p7Aliases[] = basename($f) . ':' . $tok[2];
        }
    }
}
check('SQL in the code: no alias named like a table' . ($p7Aliases ? ' (' . implode(', ', $p7Aliases) . ')' : ''), !$p7Aliases);
// Locks only through Db::lock(): its name holds the database and the prefix (GET_LOCK names count server-wide)
$p7Locks = [];
foreach (array_merge(glob(dirname(__DIR__, 2) . '/app/src/*.php') ?: [], glob(dirname(__DIR__, 2) . '/app/src/*/*.php') ?: [], glob(dirname(__DIR__, 2) . '/bin/*.php') ?: [], glob(dirname(__DIR__, 2) . '/public/*.php') ?: []) as $f) {
    if (basename($f) !== 'Db.php' && preg_match('/\b(?:GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK)\s*\(/i', (string) file_get_contents($f))) {
        $p7Locks[] = basename($f);
    }
}
$p7Db = (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Db.php');
check('named locks only through Db::lock(), whose name contains DATABASE() and the prefix' . ($p7Locks ? ' (' . implode(', ', $p7Locks) . ')' : ''),
    !$p7Locks && str_contains($p7Db, "private const LOCK_NAME = \"SHA1(CONCAT(DATABASE(), '.', ?))\";") && str_contains($p7Db, '[self::table($name), max(0, $timeout)]'));

echo "Operations: migrations stay additive and repeatable (expert review P2-5)\n";
// The rules of docs/guide/development.md#migrations as a check: what a step may do so that the previous version keeps
// working on the newer schema (one-click rollback) and an interrupted step can simply run again
$p5Problems = static function (array $statements): array {
    $problems = [];
    $added = [];
    $nullable = [];
    foreach ($statements as $sql) {
        $code = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", "''", $sql); // values do not count
        if (preg_match('/\b(DROP|RENAME|MODIFY|CHANGE|TRUNCATE)\b|\bCONVERT\s+TO\b|\bON\s+DELETE\s+RESTRICT\b/i', $code, $m)) {
            $problems[] = strtoupper($m[0]);
        }
        if (preg_match_all('/\bFOREIGN\s+KEY\b/i', $code) !== preg_match_all('/\bFOREIGN\s+KEY\b[^,]*?\bON\s+DELETE\s+(?:CASCADE|SET\s+NULL)\b/is', $code)) {
            $problems[] = 'foreign key without ON DELETE CASCADE or SET NULL (the previous version could no longer delete)';
        }
        if (preg_match('/\bCREATE\s+(UNIQUE\s+)?(TABLE|INDEX)\b(?!\s+IF\s+NOT\s+EXISTS)/i', $code, $m) || preg_match('/\bCREATE\s+UNIQUE\s+INDEX\b/i', $code, $m)) {
            $problems[] = trim($m[0]) . ' without IF NOT EXISTS, or a unique index on existing columns';
        }
        // Only these kinds of statement are known to be additive; anything else (CREATE OR REPLACE, ALTER IGNORE, a
        // quoted table name) is reported rather than passed
        if (!preg_match('/^\s*(?:ALTER\s+TABLE\s+\w+\s|CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s|CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\s|UPDATE\s|INSERT\s|DELETE\s+FROM\s)/i', $code)) {
            $problems[] = 'unknown kind of statement: ' . substr(trim($code), 0, 40);
        }
        if (!preg_match('/^\s*ALTER\s+TABLE\s+(\w+)\s+(.*)$/is', $code, $alter)) {
            continue;
        }
        $clauses = [''];
        $depth = 0;
        foreach (str_split($alter[2]) as $ch) {
            $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            if ($ch === ',' && $depth === 0) {
                $clauses[] = '';
            } else {
                $clauses[array_key_last($clauses)] .= $ch;
            }
        }
        foreach (array_map('trim', $clauses) as $clause) {
            if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+(\w+)\b(.*)$/is', $clause, $k)) {
                $added[$alter[1]][] = strtolower($k[1]);
                if (!preg_match('/\bNOT\s+NULL\b|\bDEFAULT\b/i', $k[2])) {
                    $nullable[$alter[1]][] = strtolower($k[1]); // all rows NULL: a UNIQUE key on it cannot fail
                }
                if (preg_match('/\bNOT\s+NULL\b/i', $k[2]) && !preg_match('/\bDEFAULT\b/i', $k[2])) {
                    $problems[] = "column $k[1]: NOT NULL without DEFAULT";
                }
                if (preg_match('/\b(UNIQUE|PRIMARY)\b/i', $k[2])) {
                    $problems[] = "column $k[1]: UNIQUE or PRIMARY in its definition";
                }
            } elseif (preg_match('/^ADD\s+(UNIQUE\s+)?(?:KEY|INDEX)\s+IF\s+NOT\s+EXISTS\s+\w+\s*\((.*)\)\s*$/is', $clause, $k)) {
                $columns = array_map(static fn (string $c): string => strtolower(trim((string) preg_replace('/\(\d+\)/', '', $c))), explode(',', $k[2]));
                if (trim($k[1]) !== '' && array_diff($columns, $nullable[$alter[1]] ?? [])) {
                    $problems[] = 'UNIQUE on columns that are not new and empty: ' . implode(', ', $columns);
                }
            } elseif (preg_match('/^ALTER\s+COLUMN\s+(\w+)\s+SET\s+DEFAULT\b/i', $clause, $k)) {
                if (!in_array(strtolower($k[1]), $added[$alter[1]] ?? [], true)) {
                    $problems[] = "default of an existing column: $k[1]"; // what the previous version's INSERTs store
                }
            } elseif (!preg_match('/^(ALGORITHM|LOCK)\s*=\s*\w+$/i', $clause)) {
                $problems[] = 'not additive or without IF NOT EXISTS: ' . substr($clause, 0, 60);
            }
        }
    }
    return $problems;
};
$p5Steps = (new ReflectionClassConstant(App\Migrations::class, 'STEPS'))->getValue();
$p5All = [];
foreach ($p5Steps as $version => $statements) {
    if ($problems = $p5Problems($statements)) {
        $p5All[$version] = $problems;
    }
}
check('migrations: every step is additive and repeatable, except step 3 from before the rule' . (array_diff_key($p5All, [3 => 1]) ? ' (' . json_encode(array_diff_key($p5All, [3 => 1]), JSON_UNESCAPED_UNICODE) . ')' : ''),
    array_keys($p5All) === [3]);
$p5Bad = ['ALTER TABLE users DROP COLUMN lang', 'RENAME TABLE users TO people', 'ALTER TABLE users MODIFY lang VARCHAR(9) NULL', 'ALTER TABLE users CHANGE lang language VARCHAR(5) NULL',
    'ALTER TABLE users ADD COLUMN lang VARCHAR(5) NULL', 'ALTER TABLE users ADD COLUMN IF NOT EXISTS x INT NOT NULL', 'ALTER TABLE users ADD KEY idx_x (lang)',
    'ALTER TABLE users ADD UNIQUE KEY IF NOT EXISTS uq_x (lang)', 'CREATE TABLE audit (id INT)', 'CREATE UNIQUE INDEX IF NOT EXISTS uq_x ON users (lang)',
    'ALTER TABLE users ALTER COLUMN lang DROP DEFAULT', 'ALTER TABLE users ADD CONSTRAINT fk_x FOREIGN KEY (lang) REFERENCES pages (lang)', "CREATE TABLE IF NOT EXISTS x (id INT, CONSTRAINT fk_y FOREIGN KEY (id) REFERENCES users (id) ON DELETE RESTRICT)",
    'ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4', 'TRUNCATE TABLE rate_limits', 'CREATE OR REPLACE TABLE rounds (id INT)', 'ALTER TABLE users ADD COLUMN IF NOT EXISTS u INT NOT NULL DEFAULT 0, ADD UNIQUE KEY IF NOT EXISTS uq_u (u)', 'ALTER TABLE users ADD COLUMN IF NOT EXISTS u INT UNIQUE NULL', 'ALTER TABLE users ALTER COLUMN lang SET DEFAULT 0', 'CREATE TABLE IF NOT EXISTS t (id INT, CONSTRAINT fk_z FOREIGN KEY (id) REFERENCES users (id))', 'ALTER IGNORE TABLE users ADD COLUMN x INT NOT NULL', 'ALTER TABLE `users` ADD COLUMN IF NOT EXISTS x INT NOT NULL'];
$p5Good = ["ALTER TABLE users ADD COLUMN IF NOT EXISTS x VARCHAR(5) NOT NULL DEFAULT 'de' AFTER email, ADD COLUMN IF NOT EXISTS y CHAR(64) NULL, ADD UNIQUE KEY IF NOT EXISTS uq_y (y), ADD KEY IF NOT EXISTS idx_x (email(20), x)",
    'ALTER TABLE users ADD COLUMN IF NOT EXISTS x INT NULL, ALTER COLUMN x SET DEFAULT 0, ALGORITHM=INSTANT', 'CREATE TABLE IF NOT EXISTS t (id INT, UNIQUE KEY u (id), CONSTRAINT fk_t FOREIGN KEY (id) REFERENCES users (id) ON DELETE CASCADE)',
    'CREATE INDEX IF NOT EXISTS idx_z ON users (email)', "UPDATE settings SET value = 'DROP RENAME' WHERE name = 'x'", "INSERT IGNORE INTO settings (name, value) VALUES ('a', 'b')"];
$p5Missed = array_filter($p5Bad, static fn (string $sql): bool => $p5Problems([$sql]) === []);
$p5False = array_filter($p5Good, static fn (string $sql): bool => $p5Problems([$sql]) !== []);
check('the check of the migrations finds the forbidden forms and accepts the additive ones' . ($p5Missed || $p5False ? ' (' . implode(' | ', array_merge($p5Missed, $p5False)) . ')' : ''), !$p5Missed && !$p5False);
// Two branches with the same number merge without a conflict, and PHP keeps only the last of two equal array keys
preg_match_all('/^ {8}(\d+) => \[/m', (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Migrations.php'), $p5Numbers);
$p5Numbers = array_map('intval', $p5Numbers[1]);
check('migrations: numbered without gaps or duplicates up to LATEST', $p5Numbers === range(2, App\Migrations::LATEST) && array_keys($p5Steps) === $p5Numbers);
echo "Operations: rules in the database, repeating lost transactions (expert review P2-6)\n";
$p6Error = static function (int $code): PDOException {
    $e = new PDOException('x');
    $e->errorInfo = ['HY000', $code, 'x'];
    return $e;
};
$p6Run = static function (array $codes) use ($p6Error): array {
    $calls = 0;
    try {
        $out = App\Db::retrying(static function () use (&$calls, $codes, $p6Error): string {
            $code = $codes[$calls++] ?? 0;
            if ($code !== 0) {
                throw $p6Error($code);
            }
            return 'done';
        });
    } catch (PDOException $e) {
        $out = (int) $e->errorInfo[1];
    }
    return [$out, $calls];
};
check('Db::retrying(): a lost row lock (1213, 1205, 1020) is repeated once, other errors at once go on, a second loss goes on',
    $p6Run([1213]) === ['done', 2] && $p6Run([1205]) === ['done', 2] && $p6Run([1020]) === ['done', 2]
    && $p6Run([1062]) === [1062, 1] && $p6Run([1213, 1213]) === [1213, 2] && $p6Run([]) === ['done', 1]);
$p6Pdo = new ReflectionProperty(App\Db::class, 'pdo');
$p6Was = $p6Pdo->getValue();
$p6Pdo->setValue(null, new class () extends PDO {
    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        return true;
    }
});
$p6Nested = $p6Run([1213]);
$p6Pdo->setValue(null, $p6Was);
check('Db::retrying(): inside an open transaction nothing is repeated (a deadlock has rolled back all of it)', $p6Nested === [1213, 1]);
$p6Taken = new ReflectionMethod(App\Controller\AdminController::class, 'takenByAnother');
$p6Admin = (new ReflectionClass(App\Controller\AdminController::class))->newInstanceWithoutConstructor();
$p6Other = false;
try {
    $p6Taken->invoke($p6Admin, $p6Error(1062));
    $p6Taken->invoke($p6Admin, $p6Error(1205));
} catch (PDOException $e) {
    $p6Other = (int) $e->errorInfo[1] === 1205;
}
check('forms: a duplicate key is the message of the form, any other database error goes on', $p6Other);
check('Db::isRetryable(): only PDO errors with those codes', App\Db::isRetryable($p6Error(1020)) && !App\Db::isRetryable($p6Error(1062)) && !App\Db::isRetryable(new RuntimeException('x')));
$p6Schema = (string) file_get_contents(dirname(__DIR__, 2) . '/app/schema.sql');
$p6Code = (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Migrations.php');
$p6Names = ['idx_days_date', 'uq_days_round_date', 'ck_days_times', 'idx_rounds_expired', 'ck_rounds_limit', 'idx_summaries_sent'];
check('schema.sql and migration 26 name the same indexes and rules', array_filter($p6Names, static fn (string $n): bool => !str_contains($p6Schema, $n) || !str_contains($p6Code, $n)) === []);
check('no LOWER() on accounts: the collation compares without regard to case', !preg_match('/LOWER\((?:email|username)\)/', (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Controller/AdminController.php')));
