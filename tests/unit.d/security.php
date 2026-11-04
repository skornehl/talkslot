<?php
// Account security (expert review P1-5). Mails, the lock on the admin rows and ending sessions need the
// database: tests/smoke.d/security.sh
echo "Account security\n";

// #[\SensitiveParameter] on every password and token parameter: PHP then leaves the value out of stack
// traces (the error page shows them with debug on, and zend.exception_ignore_args is off without php.ini)
$params = [];
$missing = [];
foreach (array_merge(glob(APP_ROOT . '/app/src/*.php') ?: [], glob(APP_ROOT . '/app/src/Controller/*.php') ?: []) as $file) {
    $class = 'App\\' . (str_contains($file, '/Controller/') ? 'Controller\\' : '') . basename($file, '.php');
    foreach ((new ReflectionClass($class))->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        foreach ($method->getParameters() as $p) {
            if (preg_match('/password|token|secret|^plain$/i', $p->getName())) {
                $params[] = $p;
                if (!$p->getAttributes(SensitiveParameter::class)) {
                    $missing[] = basename(str_replace('\\', '/', $class)) . '::' . $method->getName() . '($' . $p->getName() . ')';
                }
            }
        }
    }
}
check('#[\SensitiveParameter] on every password and token parameter (' . count($params) . ' found'
    . ($missing ? '; missing: ' . implode(', ', $missing) : '') . ')', count($params) >= 25 && !$missing);
// Beyond the names: mail texts carry invitation, password and booking links, the input of a round code
$marked = static fn (string $class, string $method, int $i): bool
    => (bool) (new ReflectionMethod($class, $method))->getParameters()[$i]->getAttributes(SensitiveParameter::class);
check('#[\SensitiveParameter] also on the mail text and the entered round code',
    $marked(App\Mailer::class, 'send', 2) && $marked(App\RoundAccess::class, 'checkCode', 1));
[$rc, $trace] = $run(['-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=100', '-r', 'require "app/bootstrap.php";
    App\Config::load(["db" => ["host" => "127.0.0.1", "port" => 1, "name" => "x", "user" => "x", "pass" => "x"]] + (require "config/config.php"));
    try { App\Auth::attempt("sabrina", "Geheim-Passwort-42"); } catch (Throwable $e) { echo $e->getTraceAsString(); }']);
check('stack trace of a failed login without the password', str_contains($trace, 'App\Auth::attempt(\'sabrina\', Object(SensitiveParameterValue))')
    && !str_contains($trace, 'Geheim-Passwort'));

// Which changes of the account form need the password of the acting admin
$teacher = ['role' => 'teacher', 'is_active' => 1, 'email' => 'Anna@example.org'];
$admin = ['role' => 'admin', 'is_active' => 1, 'email' => 'chef@example.org'];
$needs = App\AccountSecurity::needsPassword(...);
check('password needed: new admin, role changed, admin (de)activated, e-mail address changed',
    $needs(null, ['role' => 'admin', 'is_active' => 1, 'email' => 'x@example.org'])
    && $needs($teacher, ['role' => 'admin'] + $teacher) && $needs($admin, ['role' => 'teacher'] + $admin)
    && $needs($admin, ['is_active' => 0] + $admin) && $needs(['is_active' => 0] + $admin, $admin)
    && $needs($teacher, ['email' => 'neu@example.org'] + $teacher));
check('no password needed: new teacher, teacher (de)activated, name or username changed, e-mail only in other case',
    !$needs(null, ['role' => 'teacher', 'is_active' => 1, 'email' => 'x@example.org'])
    && !$needs($teacher, ['is_active' => 0] + $teacher) && !$needs($teacher, ['email' => 'anna@example.org', 'display_name' => 'Neu'] + $teacher)
    && !$needs($admin, ['username' => 'chef2'] + $admin));
$event = App\AccountSecurity::adminEvent(...);
check('admin events: granted, taken away, deactivated, reactivated; none for teachers or other changes',
    $event($teacher, ['role' => 'admin'] + $teacher) === App\AccountSecurity::GRANTED
    && $event($admin, ['role' => 'teacher', 'is_active' => 0] + $admin) === App\AccountSecurity::REVOKED
    && $event($admin, ['is_active' => 0] + $admin) === App\AccountSecurity::DEACTIVATED
    && $event(['is_active' => 0] + $admin, $admin) === App\AccountSecurity::REACTIVATED
    && $event($teacher, ['is_active' => 0] + $teacher) === null && $event($admin, ['email' => 'neu@example.org'] + $admin) === null);

// Users list: sign-ins only in coarse steps, no login times of colleagues
$row = ['id' => 2, 'username' => 'anna', 'display_name' => 'Anna', 'email' => 'anna@example.org', 'role' => 'teacher', 'is_active' => 1,
    'must_change_password' => 0, 'access_token_expires_at' => null, 'never_signed_in' => 0, 'long_inactive' => 0, 'round_count' => 1];
$page = App\AdminI18n::with('de', static fn () => App\View::capture('admin/users', ['me' => 1, 'users' => [
    $row, ['id' => 3, 'long_inactive' => 1] + $row, ['id' => 4, 'never_signed_in' => 1, 'long_inactive' => null] + $row,
]]));
check('users list: "in den letzten 12 Monaten", "vor über 12 Monaten", "nie" instead of a time',
    str_contains($page, '<td class="small">in den letzten 12 Monaten</td>') && str_contains($page, '<td class="small"><strong>vor über 12 Monaten</strong></td>')
    && str_contains($page, '<td class="small"><span class="muted">nie</span></td>') && !preg_match('/\d\d:\d\d/', strip_tags($page)));
check('users list: "Alle Sitzungen beenden" with the password', str_contains($page, 'action="/admin/users/end-sessions"')
    && str_contains($page, 'name="current_password" required autocomplete="current-password"'));
$dash = static fn (int $n): string => App\View::capture('admin/dashboard', ['rounds' => [], 'title' => 'x', 'showOwner' => true,
    'profileHints' => [], 'legalHints' => [], 'operatorMissing' => false, 'unusedAccounts' => $n]);
check('overview: number of accounts unused for over 12 months, with a link to the users', str_contains($dash(3), 'nicht genutzt wurden: 3.')
    && str_contains($dash(3), 'href="/admin/users"') && !str_contains($dash(0), 'nicht genutzt'));
$form = static fn (string $role): string => App\View::capture('admin/user_form', ['title' => 'x', 'errors' => [], 'isSelf' => false,
    'user' => ['id' => 5, 'role' => $role, 'username' => 'anna', 'display_name' => 'Anna', 'email' => 'anna@example.org', 'last_login_at' => null],
    'data' => ['role' => $role, 'username' => 'anna', 'display_name' => 'Anna', 'email' => 'anna@example.org', 'is_active' => 1, 'lang' => 'de']]);
check('account form: password field; deleting an admin needs it too, deleting a teacher does not',
    substr_count($form('admin'), 'name="current_password"') === 2 && substr_count($form('teacher'), 'name="current_password"') === 1
    && str_contains($form('teacher'), 'id="username" name="username" required maxlength="254" autocapitalize="none" spellcheck="false" autocomplete="off"'));

// Account security and security invariants (see README.md in this directory)
echo "Security: permissions of config.php\n";
// Expert review P2-11: config.php holds the database and mail passwords and the secret. Admins are warned
// on the dashboard when every user of the server may read it; owner and group only (600, 640) is fine.
$cfgFile = (string) tempnam(sys_get_temp_dir(), 'tp-config');
$cfgMode = static function (int $perms) use ($cfgFile): ?string {
    chmod($cfgFile, $perms);
    return App\Config::exposedPermissions($cfgFile);
};
check('config.php readable for every user (644, 604, 666): its permissions are reported',
    $cfgMode(0644) === '0644' && $cfgMode(0604) === '0604' && $cfgMode(0666) === '0666');
check('config.php only for the owner or its group (600, 640, 660, 400): no warning',
    $cfgMode(0600) === null && $cfgMode(0640) === null && $cfgMode(0660) === null && $cfgMode(0400) === null);
unlink($cfgFile);
check('missing file: no warning', App\Config::exposedPermissions($cfgFile) === null);

// Installation layout: config/config.php directly (manual installation) or one copy per version in
// releases/<name>/config/ (atomic deployment, bin/deploy.sh). Every copy holds the same secrets.
$cfgRoot = sys_get_temp_dir() . '/tp-config-' . bin2hex(random_bytes(4));
$cfgPut = static function (string $path, int $perms) use ($cfgRoot): void {
    @mkdir(dirname("$cfgRoot/$path"), 0700, true);
    file_put_contents("$cfgRoot/$path", '<?php return [];');
    chmod("$cfgRoot/$path", $perms);
};
check('nothing there: no warning', App\Config::exposedFiles($cfgRoot) === []);
$cfgPut('config/config.php', 0640);
check('manual installation, config.php 640: no warning', App\Config::exposedFiles($cfgRoot) === []);
chmod("$cfgRoot/config/config.php", 0644);
check('manual installation, config.php 644: reported with its path', App\Config::exposedFiles($cfgRoot) === ['config/config.php' => '0644']);
unlink("$cfgRoot/config/config.php");
$cfgPut('releases/20261102-100000-v1.9.0/config/config.php', 0644);
$cfgPut('releases/20261102-110000-v1.9.1/config/config.php', 0600);
$cfgPut('releases/20261102-120000-v1.9.2/config/config.php', 0666);
check('atomic deployment: every version is checked, not only the active one',
    App\Config::exposedFiles($cfgRoot) === ['releases/20261102-100000-v1.9.0/config/config.php' => '0644', 'releases/20261102-120000-v1.9.2/config/config.php' => '0666']);
exec('rm -rf ' . escapeshellarg($cfgRoot));
check('without an argument: the config.php of this installation',
    App\Config::exposedFiles() === App\Config::exposedFiles(DATA_ROOT)
    && array_key_exists('config/config.php', App\Config::exposedFiles()) === (App\Config::exposedPermissions(APP_ROOT . '/config/config.php') !== null));

$dashboard = static fn (array $exposed): string => App\View::capture('admin/dashboard', [
    'title' => 'Alle Terminrunden', 'rounds' => [], 'showOwner' => true, 'configExposed' => $exposed,
    'profileHints' => [], 'operatorMissing' => false, 'legalHints' => [],
]);
$cfgTwo = ['releases/a/config/config.php' => '0644', 'releases/b/config/config.php' => '0666'];
check('dashboard: warning with the paths and permissions, nothing without exposure',
    str_contains($dashboard(['config/config.php' => '0644']), 'Die Konfiguration ist für alle Benutzer des Servers lesbar: config/config.php (0644).')
    && str_contains($dashboard($cfgTwo), 'lesbar: releases/a/config/config.php (0644), releases/b/config/config.php (0666).')
    && !str_contains($dashboard([]), 'config.php'));
check('dashboard in English: warning translated',
    str_contains(App\AdminI18n::with('en', static fn () => $dashboard($cfgTwo)), 'The configuration can be read by every user of the server: releases/a/config/config.php (0644), releases/b/config/config.php (0666).'));

// Security invariants (see README.md in this directory)
echo "Security invariants\n";
// Expert review P2-8: invariants that extensions must not break unnoticed. The smoke test
// (tests/smoke.d/security.sh) checks the behaviour behind them against the running application.
$root = dirname(__DIR__, 2);

// The role is part of every admin route; nothing outside the table decides who may use a route
$routes = (new ReflectionClassConstant(App\Controller\AdminController::class, 'ROUTES'))->getValue();
$badRoutes = [];
foreach ($routes as $i => $r) {
    if (!(count($r) === 4 && in_array($r[0], ['GET', 'POST'], true) && is_string($r[1]) && @preg_match($r[1], '') !== false
        && method_exists(App\Controller\AdminController::class, (string) $r[2]) && in_array($r[3], ['teacher', 'admin'], true))) {
        $badRoutes[] = is_string($r[1] ?? null) ? $r[1] : "#$i";
    }
}
check('admin routes: every route has method, pattern, action and a role (teacher or admin)'
    . ($badRoutes ? ' (' . implode(', ', $badRoutes) . ')' : ''), count($routes) > 40 && !$badRoutes);
$keys = array_map(static fn (array $r): string => $r[0] . ' ' . $r[1], $routes);
check('admin routes: no route listed twice', count($keys) === count(array_unique($keys)));
$opened = array_filter($routes, static fn (array $r): bool => preg_match('~^#\^/(users|texts|page|log|templates)(/|\$)~', $r[1]) === 1 && ($r[3] ?? null) !== 'admin');
check('accounts, texts, legal pages, log and templates stay admin-only' . ($opened ? ' (' . implode(', ', array_column($opened, 1)) . ')' : ''), !$opened);
$allows = (new ReflectionMethod(App\Controller\AdminController::class, 'allows'))->getClosure(null);
check('role check fails closed: teachers only on teacher routes, a missing or unknown role means admins only',
    $allows(['GET', '#x#', 'a', 'teacher'], false) && !$allows(['GET', '#x#', 'a', 'admin'], false)
    && !$allows(['GET', '#x#', 'a'], false) && !$allows(['GET', '#x#', 'a', 'Teacher'], false)
    && $allows(['GET', '#x#', 'a'], true) && $allows(['GET', '#x#', 'a', 'admin'], true) && $allows(['GET', '#x#', 'a', 'teacher'], true));

// Form posts from other sites: only Sec-Fetch-Site counts (Origin is "null" on same-origin posts as well)
$crossSite = static function (string $method, ?string $site, ?string $origin = null): bool {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_ORIGIN'] = $origin;
    if ($site === null) {
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    } else {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = $site;
    }
    return App\Http::isCrossSitePost();
};
check('cross-site: form posts the browser marks as cross-site are refused',
    $crossSite('POST', 'cross-site') && $crossSite('post', ' Cross-Site ') && $crossSite('POST', 'cross-site', 'https://schule.example'));
check('cross-site: same-origin, same-site, user-initiated and unmarked posts (Origin "null" or missing) pass, GET always',
    !$crossSite('POST', 'same-origin', 'null') && !$crossSite('POST', 'same-site') && !$crossSite('POST', 'none')
    && !$crossSite('POST', null, 'https://evil.example') && !$crossSite('POST', null) && !$crossSite('GET', 'cross-site'));
unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);

// Every booking path locks the day (smoke test). Here: bookings are created or moved nowhere else
$writers = [];
foreach (array_merge(glob("$root/app/src/*.php"), glob("$root/app/src/Controller/*.php"), glob("$root/bin/*.php"), glob("$root/public/*.php")) as $f) {
    $tk = token_get_all((string) file_get_contents($f));
    $function = '';
    foreach ($tk as $i => $tok) {
        if (!is_array($tok)) {
            continue;
        }
        if ($tok[0] === T_FUNCTION) { // named functions only, a closure belongs to the function around it
            for ($j = $i + 1; is_array($tk[$j] ?? null) && $tk[$j][0] === T_WHITESPACE; $j++);
            if (is_array($tk[$j] ?? null) && $tk[$j][0] === T_STRING) {
                $function = $tk[$j][1];
            }
        }
        if (in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/\b(INSERT|REPLACE)\s+INTO\s+bookings\b|\bUPDATE\s+bookings\b.*\bslot_id\s*=/is', $tok[1])) {
            $writers[basename($f, '.php') . '::' . $function] = true;
        }
    }
}
$writers = array_keys($writers);
sort($writers);
check('bookings are only created or moved in BookingService::create() and ::move() (' . implode(', ', $writers) . ')',
    $writers === ['BookingService::create', 'BookingService::move']);

// Security headers: .htaccess sends the values of Http::sendSecurityHeaders() on every response, including
// the error pages Apache creates itself; the copy from PHP is removed first, so every header appears once
preg_match_all('/header\(([\'"])([A-Za-z-]+): (.*?)\1\)/', (string) file_get_contents("$root/app/src/Http.php"), $m, PREG_SET_ORDER);
$php = array_column(array_map(static fn (array $x): array => [$x[2], $x[3]], $m), 1, 0);
$htaccess = (string) file_get_contents("$root/public/.htaccess");
$headerNames = ['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy'];
$apacheDiffers = array_filter($headerNames, static fn (string $h): bool => !isset($php[$h])
    || !str_contains($htaccess, "\n  Header always set $h \"" . $php[$h] . "\"\n") || !str_contains($htaccess, "\n  Header unset $h\n"));
check('.htaccess: security headers with "always" (Apache error pages too), same values as PHP' . ($apacheDiffers ? ' (' . implode(', ', $apacheDiffers) . ')' : ''),
    !$apacheDiffers && str_contains((string) ($php['Content-Security-Policy'] ?? ''), "frame-ancestors 'none'"));

// Error pages carry the security headers, even when the error happens before the front controller has
// sent them (PHP's built-in web server: in the command line PHP sends no headers)
$tmp = sys_get_temp_dir() . '/tp-errorpage-' . getmypid();
@mkdir($tmp, 0700, true);
file_put_contents("$tmp/router.php", '<?php chdir(' . var_export($root, true) . '); require "app/bootstrap.php";
    App\Alert::$stateFile = ' . var_export("$tmp/alerts.json", true) . '; App\Alert::$transport = static fn (): bool => true;
    ini_set("error_log", ' . var_export("$tmp/error.log", true) . ');
    throw new RuntimeException("before the front controller");');
$response = '';
for ($attempt = 0; $attempt < 5 && $response === ''; $attempt++) {
    $port = random_int(20000, 60000);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$tmp/router.php"], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($wait = 0; $wait < 50 && $response === ''; $wait++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($socket) {
            fwrite($socket, "GET /irgendwas HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
            $response = (string) stream_get_contents($socket);
            fclose($socket);
        } else {
            usleep(100000);
        }
    }
    proc_terminate($server);
    proc_close($server);
}
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
$head = explode("\r\n\r\n", $response, 2)[0] . "\r\n";
check('error page (500): security headers and no-store even before the front controller ran',
    preg_match('#^HTTP/1\.\d 500#', $head) === 1 && str_contains($head, "\r\nContent-Security-Policy: " . ($php['Content-Security-Policy'] ?? '?') . "\r\n")
    && str_contains($head, "\r\nX-Frame-Options: DENY\r\n") && str_contains($head, "\r\nX-Content-Type-Options: nosniff\r\n")
    && str_contains($head, "\r\nReferrer-Policy: no-referrer\r\n") && str_contains($head, "\r\nCache-Control: no-store\r\n")
    && str_contains($response, 'error-page'));
