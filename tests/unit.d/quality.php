<?php
echo "Measurability: clock, warnings, regular expressions, coverage report (expert review P1-14)\n";
$root = dirname(__DIR__, 2);

// A fixed clock for tests (Time::setNow), instead of moving timestamps in the database
App\Time::setNow('2026-11-30 17:59:30');
check('fixed clock: now(), nowSql() and today() follow Time::setNow(), in the configured time zone', App\Time::nowSql() === '2026-11-30 17:59:30'
    && App\Time::today() === '2026-11-30' && App\Time::now()->getTimezone()->getName() === date_default_timezone_get());
$deadlineCase = static function (array $booking, string $before, string $at): bool {
    App\Time::setNow($before);
    $open = App\BookingService::canChange($booking);
    App\Time::setNow(new DateTimeImmutable($at));
    return $open && !App\BookingService::canChange($booking);
};
check('cancel deadline of 24 hours: open one second before, closed from then on', $deadlineCase(
    ['day_date' => '2026-12-01', 'start_time' => '18:00:00', 'cancel_deadline_hours' => 24], '2026-11-30 17:59:59', '2026-11-30 18:00:00'));
// Summer to winter time on 25 October 2026: 24 hours before 14:00 are 14:00 on the day before (not 15:00)
check('cancel deadline across the change to winter time: the same clock time on the day before', $deadlineCase(
    ['day_date' => '2026-10-25', 'start_time' => '14:00:00', 'cancel_deadline_hours' => 24], '2026-10-24 13:59:59', '2026-10-24 14:00:00'));
App\Time::setNow(null);
check('Time::setNow(null): the real time again', abs(App\Time::now()->getTimestamp() - time()) <= 2);

// tests/unit.php turns warnings into failures; the app logs deprecations under their own name
$strict = null;
try {
    $none = [];
    $quiet = @$none['missing'];
    $loud = $none['missing'];
} catch (ErrorException $e) {
    $strict = $e;
}
check('unit tests: a warning fails the run (ErrorException), one suppressed with @ does not', $strict !== null && $strict->getSeverity() === E_WARNING
    && str_contains($strict->getMessage(), 'Undefined array key') && !isset($quiet));
// The app's handler (app/bootstrap.php), called directly: it logs, reports warnings by mail and lets PHP go on
$qLog = tempnam(sys_get_temp_dir(), 'log');
$qOldLog = (string) ini_get('error_log');
$qAlert = [App\Alert::$transport, App\Alert::$stateFile];
ini_set('error_log', $qLog);
App\Config::load(['alert_email' => 'betrieb@example.org'] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$qSent = [];
App\Alert::$transport = static function (string $to, string $subject, string $body) use (&$qSent): bool { $qSent[] = $subject; return true; };
$qResults = [$appErrorHandler(E_USER_DEPRECATED, 'bald weg', APP_ROOT . '/app/src/Time.php', 12),
    $appErrorHandler(E_USER_NOTICE, 'nur ein Hinweis', APP_ROOT . '/app/src/Time.php', 13)];
$qDeprecations = $qSent;
$qResults[] = $appErrorHandler(E_USER_WARNING, 'kaputt mit max@example.org', APP_ROOT . '/app/src/Time.php', 14);
$qLevel = error_reporting(E_ALL & ~E_USER_NOTICE); // like a place suppressed with @
$qResults[] = $appErrorHandler(E_USER_NOTICE, 'unterdrückt', APP_ROOT . '/app/src/Time.php', 15);
error_reporting($qLevel);
$qLines = (string) file_get_contents($qLog);
ini_set('error_log', $qOldLog);
@unlink((string) App\Alert::$stateFile);
[App\Alert::$transport, App\Alert::$stateFile] = $qAlert;
App\Config::load($cfg);
check('error log: deprecations as "PHP Deprecated", notices as "PHP Notice", warnings also by mail; PHP goes on (false)',
    $qResults === [false, false, false, false] && $qDeprecations === [] && $qSent === ['[TalkSlot] PHP-Warnung']
    && str_contains($qLines, 'PHP Deprecated: bald weg (/app/src/Time.php:12)') && str_contains($qLines, 'PHP Notice: nur ein Hinweis (/app/src/Time.php:13)')
    && str_contains($qLines, 'PHP Warning: kaputt mit [email] (/app/src/Time.php:14)') && !str_contains($qLines, 'unterdrückt'));

// The place in log lines and error reports: only the leading project root goes (CI mounts the project at /app,
// where str_replace() turned /app/app/src/Db.php into /src/Db.php, which tests/php-errors.sh does not recognise)
check('error log: the place relative to the project root, a directory named like the root stays, other paths unchanged',
    App\ErrorPage::relativePath(APP_ROOT . '/app/src/Db.php') === '/app/src/Db.php'
    && App\ErrorPage::relativePath(APP_ROOT . APP_ROOT . '/x.php') === APP_ROOT . '/x.php'
    && App\ErrorPage::relativePath('/tmp/x' . APP_ROOT . '/a.php') === '/tmp/x' . APP_ROOT . '/a.php' && App\ErrorPage::relativePath(APP_ROOT . 'x/a.php') === APP_ROOT . 'x/a.php');

// tests/php-errors.sh: warnings, notices, deprecations and fatal errors of the app since a given point of the log
$phpErrors = static function (string $log, int $offset = 0): array {
    exec('bash ' . escapeshellarg(dirname(__DIR__) . '/php-errors.sh') . ' ' . escapeshellarg($log) . ' ' . $offset . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
$old = "[05-Nov-2026 12:00:00 Europe/Berlin] PHP Warning: vorher (/app/src/Alt.php:1)\n";
file_put_contents($qLog, $old
    . "[05-Nov-2026 12:00:01 Europe/Berlin] PHP Deprecated: veraltet (/app/src/Time.php:12)\n"
    . "[05-Nov-2026 12:00:02 Europe/Berlin] PHP Notice: Hinweis (/app/templates/admin/dashboard.php:3)\n"
    . "[05-Nov-2026 12:00:03 Europe/Berlin] PHP Notice: Hinweis (/app/templates/admin/dashboard.php:3)\n"
    . "[05-Nov-2026 12:00:04 Europe/Berlin] PHP Warning: Undefined array key \"…\" (Command line code:1)\n"
    . "[05-Nov-2026 12:00:05 Europe/Berlin] PHP Fatal error: Allowed memory size (…) (/tmp/exc8Hq1:1)\n"
    . "[05-Nov-2026 12:00:06 Europe/Berlin] [RuntimeException] kaputt (/app/src/Db.php:82)\n"
    . "[05-Nov-2026 12:00:07 Europe/Berlin] Mailer: Versand fehlgeschlagen\n");
[$rc, $out] = $phpErrors($qLog, strlen($old));
check('php-errors.sh: new warnings of the app grouped, exit 1; earlier lines, test code and other entries do not count', $rc === 1
    && str_contains($out, '2 PHP Notice: Hinweis (/app/templates/admin/dashboard.php:3)') && str_contains($out, '1 PHP Deprecated: veraltet (/app/src/Time.php:12)')
    && !str_contains($out, 'vorher') && !str_contains($out, 'Command line code') && !str_contains($out, '/tmp/') && !str_contains($out, 'kaputt'));
file_put_contents($qLog . '.1', $old . "[05-Nov-2026 12:00:01 Europe/Berlin] PHP Warning: kurz vor dem Rotieren (/bin/cron.php:9)\n");
file_put_contents($qLog, "[05-Nov-2026 12:00:09 Europe/Berlin] Mailer: Versand fehlgeschlagen\n");
[$rc, $out] = $phpErrors($qLog, strlen($old));
check('php-errors.sh: rotated in between → the rest of the older file counts too', $rc === 1 && str_contains($out, 'kurz vor dem Rotieren'));
file_put_contents($qLog . '.1', $old);
[$rc, $out] = $phpErrors($qLog, strlen($old));
[$rcMissing] = $phpErrors($qLog . '.fehlt');
check('php-errors.sh: nothing new, or no log at all → exit 0', $rc === 0 && $out === '' && $rcMissing === 0);
@unlink($qLog);
@unlink($qLog . '.1');

// Regular expressions on text need the u modifier wherever bytes and characters differ: a literal
// non-ASCII character, \R (at the byte 0x85, P0-1), \h, \v (bytes 0xA0, 0x85), \X, \p, \x{…} would otherwise
// split UTF-8 characters. \s, \w, "." and ASCII classes only match ASCII bytes without it and stay allowed.
$utf8Files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app/src", FilesystemIterator::SKIP_DOTS)) as $f) {
    $utf8Files[] = (string) $f;
}
$utf8Files = array_merge($utf8Files, glob("$root/app/templates/*.php") ?: [], glob("$root/app/templates/*/*.php") ?: [],
    ["$root/app/helpers.php", "$root/app/bootstrap.php"], glob("$root/bin/*.php") ?: [], glob("$root/public/*.php") ?: []);
$needsU = static function (string $pattern): bool {
    if (!preg_match('~^([/#\~@!%|+])(.*)\1([a-zA-Z]*)$~s', $pattern, $m) || str_contains($m[3], 'u')) {
        return false; // not shaped like a regular expression, or one with the u modifier
    }
    return preg_match('/[\x80-\xff]/', $m[2]) === 1 || preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\(?:[RhHvVXpP]|x\{)/', $m[2]) === 1;
};
$withoutU = [];
foreach ($utf8Files as $f) {
    foreach (token_get_all((string) file_get_contents($f)) as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && $needsU((string) eval('return ' . $tok[1] . ';'))) {
            $withoutU[] = substr($f, strlen($root) + 1) . ':' . $tok[2];
        }
    }
}
check('no regular expression without the u modifier where it matters (umlauts, \R, \h, \v, \p …)' . ($withoutU ? ' (' . implode(', ', $withoutU) . ')' : ''), !$withoutU);
check('the audit finds them: literal umlaut, \R, \h, \p{L}, \x{2028}; not with u, not \s/\w/., not an escaped backslash, not plain paths',
    $needsU('/[äöü]/i') && $needsU('/\R/') && $needsU('~\h+~') && $needsU('#\p{L}#') && $needsU('/\x{2028}/')
    && !$needsU('/[äöü]/iu') && !$needsU('/\R/u') && !$needsU('/^\s*(\w+)\.(.*)$/') && !$needsU('/a\\\\R/') && !$needsU('/admin/') && !$needsU('Grüße'));

// tests/coverage/report.php: merges the raw data of the processes (tests/coverage/prepend.php)
$cov = sys_get_temp_dir() . '/tp-cov-' . bin2hex(random_bytes(4));
mkdir("$cov/root/app/src", 0700, true);
mkdir("$cov/root/bin", 0700, true);
mkdir("$cov/raw", 0700);
file_put_contents("$cov/root/app/src/A.php", '<?php');
file_put_contents("$cov/root/app/src/B.php", '<?php');
file_put_contents("$cov/root/bin/x.php", '<?php');
file_put_contents("$cov/raw/1.json", json_encode([
    "$cov/root/app/src/A.php" => [3 => 1, 4 => -1, 5 => -1, 8 => -1], "$cov/root/bin/x.php" => [2 => 1],
    '/elsewhere/app/src/A.php' => [1 => -1, 2 => -1], "$cov/root/tests/t.php" => [1 => -1],
]));
file_put_contents("$cov/raw/2.json", json_encode(["$cov/root/app/src/A.php" => [4 => 1]]));
$report = static function (array $args, string $diff = '') use ($cov, $run): array {
    return $run(array_merge(['tests/coverage/report.php', "--root=$cov/root", "--dir=$cov/raw"], $args), [], $diff);
};
[$rc, $out, $err] = $report([]);
check('coverage report: lines merged over all processes, only files of the checkout; a file no test loads fails the report', $rc === 1
    && str_contains($out, '3 of 5 lines = 60.0 %, 2 processes') && (bool) preg_match('#50\.0 %\s+2/4\s+app/src/A\.php\s+5,8\n#', $out)
    && str_contains($out, 'Not loaded by any test: app/src/B.php') && str_contains($err, 'Not loaded by any test: app/src/B.php'));
unlink("$cov/root/app/src/B.php");
[$rcAt] = $report(['--min=60']);
[$rcBelow, , $errBelow] = $report(['--min=60.1', '--github']);
check('coverage report: --min fails below the minimum (the coverage must not go down)', $rcAt === 0 && $rcBelow === 1
    && str_contains($errBelow, 'Line coverage 60.0 % is below the minimum of 60.1 %'));
$diff = "diff --git a/app/src/A.php b/app/src/A.php\n--- a/app/src/A.php\n+++ b/app/src/A.php\n@@ -1,0 +4,2 @@\n+neu\n+++ sieht aus wie ein Kopf\n"
    . "@@ -7,2 +7,2 @@\n davor\n-alt\n\\ No newline at end of file\n+geändert\n\\ No newline at end of file\n"
    . "--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-x\n+y\n";
[$rc, $out] = $report(['--diff=-', '--github', "--markdown=$cov/summary.md"], $diff);
$md = (string) @file_get_contents("$cov/summary.md");
check('coverage report: changed lines no test executes (from the diff), as annotations and in the summary; other files ignored', $rc === 0
    && str_contains($out, "Changed lines that no test executes:\n  app/src/A.php: 5,8\n")
    && str_contains($out, '::warning file=app/src/A.php,line=5,endLine=5,title=Not covered by tests::Line 5 is not executed by any unit, smoke or browser test.')
    && str_contains($out, '::warning file=app/src/A.php,line=8,endLine=8,') && !str_contains($out, 'README')
    && str_contains($md, '### Line coverage: 60.0 %') && str_contains($md, '- `app/src/A.php`: 5,8') && str_contains($md, '| 50.0 % | 2/4 | `app/src/A.php` | 5,8 |'));
[, $out] = $report(['--diff=-'], "+++ b/bin/x.php\n@@ -1 +2 @@\n-a\n+b\n");
check('coverage report: every changed line executed → says so', str_contains($out, 'Every changed line is executed by a test.'));
exec('rm -rf ' . escapeshellarg($cov));

// Stable markers for the smoke test (marker() in tests/smoke.sh) instead of wording that may change
$qDash = static fn (array $hints): string => App\View::capture('admin/dashboard', ['title' => 'x', 'rounds' => [], 'showOwner' => false,
    'profileHints' => $hints, 'legalHints' => [], 'operatorMissing' => false, 'unusedAccounts' => 0, 'mailsLate' => null, 'configExposed' => []]);
check('marker: the hint to complete the profile carries data-profile-hints, only while there is one',
    str_contains($qDash(['Anzeigename fehlt']), '<div class="alert alert-warning" role="status" data-profile-hints>') && !str_contains($qDash([]), 'data-profile-hints'));
