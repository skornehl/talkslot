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
