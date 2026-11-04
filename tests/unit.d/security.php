<?php
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
