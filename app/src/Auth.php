<?php
declare(strict_types=1);

namespace App;

/**
 * Admin login with a secure session.
 * - Session cookie only for /admin, HttpOnly, Secure, SameSite=Strict
 * - Inactivity and absolute timeout
 * - Roles: exactly one admin (all rounds, accounts, texts; controller) and teacher (own rounds only)
 * - Brute-force protection: limit on failed logins per (truncated, hashed) IP.
 *   Deliberately no account lockout: otherwise anyone could lock the teacher out permanently
 *   with a few failed attempts. The minimum length of 12 characters protects against distributed guessing.
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
                self::logout();
                session_start();
                $_SESSION['flash'][] = ['info', 'Die Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.'];
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
            $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$uid]);
            // Password changed/reset: older sessions are no longer valid
            $cache[$uid] = $user && (int) ($user['session_epoch'] ?? 0) === (int) ($_SESSION['epoch'] ?? 0) ? $user : null;
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
    public static function attempt(string $username, string $password): bool|string
    {
        [$limit, $window] = RateLimiter::limits('login', 5, 900);
        [$userLimit, $userWindow] = RateLimiter::limits('login_user', 20, 3600);
        $generic = 'Anmeldung fehlgeschlagen. Bitte prüfen Sie Ihre Eingaben oder versuchen Sie es später erneut.';
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

    public static function changePassword(int $userId, string $current, string $new): bool|string
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            return 'Das aktuelle Passwort ist falsch.';
        }
        if (($error = self::passwordPolicy($new)) !== null) {
            return $error;
        }
        if (password_verify($new, $user['password_hash'])) {
            return 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
        }
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, access_token_hash = NULL, access_token_expires_at = NULL WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), $userId]
        );
        session_regenerate_id(true);
        self::endOtherSessions($userId, true);
        return true;
    }

    /** At least 12 characters; at most 72 bytes, because bcrypt ignores everything beyond that. */
    public static function passwordPolicy(string $password): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }
        return strlen($password) > 72 ? 'Das Passwort darf höchstens 72 Byte lang sein (etwa 72 Zeichen, Umlaute zählen doppelt).' : null;
    }
}
