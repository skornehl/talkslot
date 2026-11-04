<?php
declare(strict_types=1);

namespace App;

/**
 * Admin login with a secure session.
 * - Session cookie only for /admin, HttpOnly, Secure, SameSite=Strict
 * - Inactivity and absolute timeout
 * - Roles: admin (one or more: all rounds, accounts, texts) and teacher (own rounds only)
 * - Brute-force protection: limit on failed logins per (truncated, hashed) IP.
 *   Deliberately no account lockout: otherwise anyone could lock the teacher out permanently
 *   with a few failed attempts. The minimum length of 12 characters protects against distributed guessing.
 * - Sessions end when the password of the account changes (users.session_epoch) or when an admin ends
 *   all sessions of all accounts (setting global_session_epoch, see endAllSessions()).
 * - Security-relevant changes ask for the password again (confirmPassword()); wrong entries there and
 *   when changing the own password are limited per sign-in (verifyOwnPassword()).
 * Password and token parameters carry #[\SensitiveParameter]: they never appear in stack traces.
 */
final class Auth
{
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
     * Login. Failed attempts are limited per truncated IP (as before) and additionally per username
     * (more generously, so that a single IP cannot lock an account, while distributed guessing is
     * still slowed down). The attempt is counted before the check (no parallel attempts past the limit)
     * and only taken back on success – a successful login with a different account does not reset
     * the counter.
     * @return true|string true on success, otherwise an error message
     */
    public static function attempt(string $username, #[\SensitiveParameter] string $password): bool|string
    {
        [$limit, $window] = RateLimiter::limits('login', 5, 900);
        [$userLimit, $userWindow] = RateLimiter::limits('login_user', 20, 3600);
        $generic = __('Anmeldung fehlgeschlagen. Bitte prüfen Sie Ihre Eingaben oder versuchen Sie es später erneut.');
        $subject = 'login:' . mb_strtolower($username);
        // IP first: if it is blocked, nothing is stored for (possibly made-up) usernames
        if (!RateLimiter::hit('login', $limit, $window)) {
            return $generic;
        }
        if (!RateLimiter::hit('login_user', $userLimit, $userWindow, $subject)) {
            RateLimiter::undo('login');
            return $generic;
        }

        $user = Db::one('SELECT * FROM users WHERE username = ?', [$username]);
        // Check a hash even without a user (uniform response time, same cost as real hashes)
        $hash = $user['password_hash'] ?? self::dummyHash();
        $valid = password_verify($password, $hash);

        if (!$user || !$valid || !$user['is_active']) {
            if ($user) {
                // Counter only (visible in the DB), no lockout
                Db::run('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?', [$user['id']]);
            }
            return $generic;
        }

        Db::run('UPDATE users SET failed_logins = 0, last_login_at = ? WHERE id = ?', [Time::nowSql(), $user['id']]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        RateLimiter::undo('login');
        RateLimiter::undo('login_user', $subject);
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
        if (($error = self::passwordPolicy($newPassword)) !== null) {
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
        AccountSecurity::passwordChanged($user);
        return true;
    }

    /** At least 12 characters; at most 72 bytes, because bcrypt ignores everything beyond that. */
    public static function passwordPolicy(#[\SensitiveParameter] string $password): ?string
    {
        if (mb_strlen($password) < 12) {
            return __('Das Passwort muss mindestens 12 Zeichen lang sein.');
        }
        return strlen($password) > 72 ? __('Das Passwort darf höchstens 72 Byte lang sein (etwa 72 Zeichen, Umlaute zählen doppelt).') : null;
    }
}
