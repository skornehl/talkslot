<?php
declare(strict_types=1);

namespace App;

/**
 * Admin login with a secure session.
 * - Session cookie only for /admin, HttpOnly, Secure, SameSite=Strict
 * - Inactivity and absolute timeout
 * - Roles: admin (one or more: all rounds, accounts, texts) and teacher (own rounds only)
 * - Brute-force protection (see attempt()): failed logins are limited per truncated IP and per
 *   account. The per-account limit slows down guessing from many addresses, but it is a lockout:
 *   once strangers have used it up, unknown devices cannot sign in to the account until the window
 *   ends. The owner gets an e-mail then, and devices that signed in to the account before
 *   (TrustedDevice) are exempt, so the owner can still sign in there.
 * - Passwords: 12 characters up to 72 bytes, not common, no patterns, nothing personal (passwordPolicy()).
 * - Sessions end when the password of the account changes (users.session_epoch) or when an admin ends
 *   all sessions of all accounts (setting global_session_epoch, see endAllSessions()).
 * - Security-relevant changes ask for the password again (confirmPassword()); wrong entries there and
 *   when changing the own password are limited per sign-in (verifyOwnPassword()).
 * Password and token parameters carry #[\SensitiveParameter]: they never appear in stack traces.
 */
final class Auth
{
    /** Allowed usernames (creating, editing, installing, signing in): 3–254 characters, ASCII only. */
    public static function validUsername(string $username): bool
    {
        return preg_match('/^[A-Za-z0-9._@+-]{3,254}\z/', $username) === 1;
    }

    /**
     * Starts the admin session. Without $force only if the browser already has a session cookie:
     * login, "forgot password" and invitation pages do not create a session file for anonymous
     * visitors (otherwise the storage could be filled up with requests).
     */
    public static function startSession(bool $force = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!$force && !isset($_COOKIE['tp_admin'])) {
            return;
        }
        $dir = DATA_ROOT . '/app/var/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '50');
        }
        $idle = (int) Config::get('session_idle_minutes', 30) * 60;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) max($idle, 1440));
        session_name('tp_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Http::basePath() . '/admin',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        $now = time();
        if (isset($_SESSION['uid'])) {
            $absolute = (int) Config::get('session_absolute_hours', 8) * 3600;
            if ($now - (int) ($_SESSION['last'] ?? 0) > $idle || $now - (int) ($_SESSION['started'] ?? 0) > $absolute) {
                // The admin language is not set yet: the message in the language of the account that was signed in
                $lang = AdminI18n::forRequest(self::user());
                self::logout();
                session_start();
                $_SESSION['flash'][] = ['info', AdminI18n::with($lang, static fn () => __('Die Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.'))];
            }
        }
        $_SESSION['last'] = $now;
    }

    /** Logged in and account still active? */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Current account (loaded once per request; deactivated accounts count as logged out). */
    public static function user(): ?array
    {
        static $cache = [];
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) {
            return null;
        }
        if (!array_key_exists($uid, $cache)) {
            $user = Db::one(
                "SELECT u.*, (SELECT value FROM settings WHERE name = 'global_session_epoch') AS global_epoch FROM users u WHERE u.id = ? AND u.is_active = 1",
                [$uid]
            );
            // Password changed/reset (account) or all sessions ended (global): older sessions are no longer valid
            $cache[$uid] = $user && (int) ($user['session_epoch'] ?? 0) === (int) ($_SESSION['epoch'] ?? 0)
                && (int) $user['global_epoch'] === (int) ($_SESSION['global_epoch'] ?? 0) ? $user : null;
        }
        return $cache[$uid];
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /**
     * Login. Failed attempts are limited
     * - per truncated IP ("login", 5 per 15 minutes),
     * - per account ("login_user", 20 per hour, more generous so that a single IP cannot use it up),
     *   counted by account ID; unknown names by the name in lower case, so that the limit does not
     *   reveal whether an account exists,
     * - instead of per account: per device, if the request brings a valid TrustedDevice cookie for
     *   the account ("login_device", 20 per hour, in case such a cookie is stolen).
     * An attempt is counted before the check (no parallel attempts past the limit) and only taken back
     * on success – a successful login with a different account does not reset the counters.
     * @return true|string true on success, otherwise an error message
     */
    public static function attempt(string $username, #[\SensitiveParameter] string $password): bool|string
    {
        [$limit, $window] = RateLimiter::limits('login', 5, 900);
        $generic = __('Anmeldung fehlgeschlagen. Bitte prüfen Sie Ihre Eingaben oder versuchen Sie es später erneut.');
        // IP first: if it is blocked, nothing is stored for (possibly made-up) usernames
        if (!RateLimiter::hit('login', $limit, $window)) {
            return $generic;
        }
        // Only names that an account can have. The database compares case- and accent-insensitively,
        // so "SÁBRİNA" or "sabri<zero-width space>na" would find "sabrina" (and sign in with the right
        // password) while counting under a key of their own. The attempt still counts for the IP.
        if (!self::validUsername($username)) {
            return $generic;
        }
        $user = Db::one('SELECT * FROM users WHERE username = ?', [$username]);

        [$bucket, $subject] = ['login_device', $user ? TrustedDevice::subject((int) $user['id']) : null];
        [$deviceLimit, $deviceWindow] = RateLimiter::limits('login_device', 20, 3600);
        if ($subject === null || !RateLimiter::hit($bucket, $deviceLimit, $deviceWindow, $subject)) {
            [$bucket, $subject] = ['login_user', $user ? 'login:#' . $user['id'] : 'login:' . strtolower($username)];
            [$userLimit, $userWindow] = RateLimiter::limits('login_user', 20, 3600);
            if (!RateLimiter::hit($bucket, $userLimit, $userWindow, $subject)) {
                RateLimiter::undo('login');
                if ($user) {
                    self::lockoutNotice($user, $userLimit, $userWindow);
                }
                return $generic;
            }
        }

        // Check a hash even without a user (uniform response time, same cost as real hashes)
        $hash = $user['password_hash'] ?? self::dummyHash();
        $valid = password_verify($password, $hash);

        if (!$user || !$valid || !$user['is_active']) {
            if ($user) {
                // Counter only (visible in the DB)
                Db::run('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$user['id']]);
            }
            return $generic;
        }

        Db::run('UPDATE users SET failed_logins = 0, last_login_at = ? WHERE id = ?', [Time::nowSql(), $user['id']]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        RateLimiter::undo('login');
        RateLimiter::undo($bucket, $subject);
        TrustedDevice::remember((int) $user['id']);
        // An open "forgot password" link is settled by logging in
        Db::run('UPDATE users SET access_token_hash = NULL, access_token_expires_at = NULL WHERE id = ?', [$user['id']]);

        self::startSession(true);
        session_regenerate_id(true);
        $_SESSION = [
            'uid' => (int) $user['id'],
            'epoch' => (int) ($user['session_epoch'] ?? 0),
            'global_epoch' => self::globalEpoch(),
            'started' => time(),
            'last' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
        return true;
    }

    /**
     * E-mail to the account holder when the per-account limit refuses an attempt, at most one per
     * account and day ("login_notice"). It is sent during the refused attempt: the longer response
     * shows up only once a day and only for an account whose name the sender knows anyway.
     */
    private static function lockoutNotice(array $user, int $limit, int $window): void
    {
        [$noticeLimit, $noticeWindow] = RateLimiter::limits('login_notice', 1, 86400);
        if (!$user['is_active'] || !is_valid_email((string) $user['email'])
            || !RateLimiter::hit('login_notice', $noticeLimit, $noticeWindow, 'notice:' . $user['id'])) {
            return;
        }
        // In the language of the account
        AdminI18n::with(AdminI18n::of($user), static function () use ($user, $limit, $window): void {
            $vars = ['app' => app_name(), 'url' => abs_url('/admin', ['lang' => null]), 'count' => $limit, 'minutes' => (int) ceil($window / 60)];
            Mailer::send((string) $user['email'], __('Viele fehlgeschlagene Anmeldeversuche bei {app}', $vars), implode("\n", [
                __('Hallo {name},', ['name' => $user['display_name'] ?: $user['username']]),
                '',
                __('für Ihr Konto bei {app} ({url}) gab es viele fehlgeschlagene Anmeldeversuche (mehr als {count}). Zum Schutz Ihres Kontos ist die Anmeldung deshalb für bis zu {minutes} Minuten nur auf Geräten möglich, auf denen Sie sich schon einmal angemeldet haben.', $vars),
                '',
                __('Auf einem neuen Gerät hilft „Passwort vergessen?“ auf der Anmeldeseite: Wenn Sie über den Link ein neues Passwort festlegen, können Sie sich in diesem Browser sofort anmelden.'),
                '',
                __('Waren Sie das nicht, versucht vermutlich jemand, Ihr Passwort zu erraten. Solange Sie es nirgends sonst verwenden, müssen Sie nichts tun. Bei Fragen wenden Sie sich an einen Admin.'),
            ]));
        });
    }

    /** Comparison hash with the current default cost (created once per request). */
    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    /** Ends all other sessions of the account (increments the counter); keeps the own one if given. */
    public static function endOtherSessions(int $userId, bool $keepCurrent): void
    {
        Db::run('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
        if ($keepCurrent && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['epoch'] = (int) Db::value('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
        }
    }

    /**
     * Ends the sessions of all accounts on all devices (e.g. after a lost device or a suspected break-in;
     * changing the app secret does not do that). Keeps the current session.
     */
    public static function endAllSessions(): void
    {
        Db::run(
            "INSERT INTO settings (name, value) VALUES ('global_session_epoch', '1')
             ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1"
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['global_epoch'] = self::globalEpoch();
        }
    }

    private static function globalEpoch(): int
    {
        return (int) Settings::get('global_session_epoch', '0');
    }

    /**
     * Asks again for the password of the signed-in account before a security-relevant change (admin
     * rights, e-mail addresses, ending all sessions), so that a session taken over without the password
     * cannot make it. Wrong entries are limited, see verifyOwnPassword().
     * @return ?string null if confirmed, otherwise the error message ($prompt for a missing or wrong password)
     */
    public static function confirmPassword(#[\SensitiveParameter] string $password, string $prompt): ?string
    {
        $user = self::user();
        return $user ? self::verifyOwnPassword($user, $password, $prompt) : $prompt;
    }

    /**
     * Checks the own password for confirmPassword() and changePassword(). Both count wrong entries in the
     * same counter ("reauth", 5 per 15 minutes), so neither is a way around the other: a taken-over
     * session cannot guess the password where it is not limited and then use it where it is asked for.
     * The counter belongs to the sign-in (account and session ID), not to the account alone: a new session
     * needs the password, so a taken-over session gets no more attempts that way, but it cannot lock the
     * account holder out of changing the password or ending all sessions either (signing in again starts
     * a new counter). An empty field does not count.
     * @return ?string null if right, otherwise $wrong or the message that there were too many wrong entries
     */
    private static function verifyOwnPassword(array $user, #[\SensitiveParameter] string $password, string $wrong): ?string
    {
        if ($password === '') {
            return $wrong;
        }
        [$limit, $window] = RateLimiter::limits('reauth', 5, 900);
        $subject = 'reauth:' . $user['id'] . ':' . session_id();
        if (!RateLimiter::hit('reauth', $limit, $window, $subject)) {
            return __('Zu viele falsche Passwörter. Bitte versuchen Sie es in {minutes} Minuten erneut oder melden Sie sich ab und wieder an.', ['minutes' => (int) ceil($window / 60)]);
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return $wrong;
        }
        RateLimiter::undo('reauth', $subject);
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => 'Strict',
            ]);
            session_destroy();
        }
    }

    /**
     * Changes the own password; ends the other sessions of the account and tells its owner by e-mail.
     * Wrong current passwords count like those in confirmPassword() (verifyOwnPassword()).
     */
    public static function changePassword(int $userId, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): bool|string
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        $wrong = __('Das aktuelle Passwort ist falsch.');
        if (($error = $user ? self::verifyOwnPassword($user, $currentPassword, $wrong) : $wrong) !== null) {
            return $error;
        }
        if (($error = self::passwordPolicy($newPassword, (string) $user['username'], (string) $user['email'])) !== null) {
            return $error;
        }
        if (password_verify($newPassword, $user['password_hash'])) {
            return __('Das neue Passwort muss sich vom bisherigen unterscheiden.');
        }
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, access_token_hash = NULL, access_token_expires_at = NULL WHERE id = ?',
            [password_hash($newPassword, PASSWORD_DEFAULT), $userId]
        );
        session_regenerate_id(true);
        self::endOtherSessions($userId, true);
        session_write_close(); // the account's other tabs do not wait for the mail server
        try {
            AccountSecurity::passwordChanged($user);
        } finally {
            self::startSession();
        }
        return true;
    }

    /**
     * At least 12 characters; at most 72 bytes, because bcrypt ignores everything beyond that; not on
     * the list of common passwords, no repetitions or runs, and none of the personal values (username,
     * e-mail address) or the app name in it (PasswordRules).
     */
    public static function passwordPolicy(#[\SensitiveParameter] string $password, string ...$personal): ?string
    {
        if (mb_strlen($password) < 12) {
            return __('Das Passwort muss mindestens 12 Zeichen lang sein.');
        }
        if (strlen($password) > 72) {
            return __('Das Passwort darf höchstens 72 Byte lang sein (etwa 72 Zeichen, Umlaute zählen doppelt).');
        }
        if (PasswordRules::containsPersonal($password, [...$personal, app_name(), 'TalkSlot'])) {
            return __('Das Passwort darf weder den Benutzernamen noch die E-Mail-Adresse oder den Namen der Anwendung enthalten.');
        }
        if (PasswordRules::isPattern($password) || PasswordRules::isCommon($password)) {
            return __('Dieses Passwort ist zu leicht zu erraten (häufig verwendet, ein Wort mit Ziffern, Wiederholungen oder Tastenfolgen). Gut geeignet sind z. B. drei oder vier zufällige Wörter.');
        }
        return null;
    }
}
