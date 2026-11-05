<?php
declare(strict_types=1);

namespace App;

/**
 * Reports errors by email to the operator: unhandled exceptions, fatal PHP errors,
 * warnings, errors during migration/cleanup, unreachable database, failed
 * mail delivery.
 *
 * Recipient: config "alert_email" (deployment: variable ALERT_EMAIL), otherwise the email address
 * of the admin account. If the database is unreachable, only the address from the config is used.
 *
 * Identical errors (type + location) are reported at most once per hour, with at most
 * MAX_PER_DAY emails per day in total; suppressed repetitions are counted in the next email.
 * The counter lives in app/var (not in the database, which may be the thing that failed).
 *
 * The email contains no personal data: message sanitized as in the error log,
 * only harmless parts of the path (no tokens, no round links), no query string,
 * stack trace only with file, line and function (without arguments).
 */
final class Alert
{
    public const THROTTLE_SECONDS = 3600;
    public const MAX_PER_DAY = 30;

    /** For tests: intercepts emails instead of sending them (fn(string $to, string $subject, string $body): bool). */
    public static ?\Closure $transport = null;
    /** For tests: a different location for the counter. */
    public static ?string $stateFile = null;

    private static bool $sending = false;

    /** Log and report an exception. */
    public static function exception(\Throwable $e, string $context = ''): void
    {
        ErrorPage::log($e);
        $lines = [
            'Fehler: ' . get_class($e) . ': ' . ErrorPage::sanitize($e->getMessage()),
            'Ort: ' . self::relative($e->getFile()) . ':' . $e->getLine(),
        ];
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            $lines[] = 'Ursache: ' . get_class($p) . ': ' . ErrorPage::sanitize($p->getMessage())
                . ' (' . self::relative($p->getFile()) . ':' . $p->getLine() . ')';
        }
        $lines[] = '';
        $lines[] = 'Ablauf:';
        foreach (array_slice($e->getTrace(), 0, 12) as $i => $f) {
            $lines[] = sprintf('#%d %s%s %s%s%s()', $i,
                isset($f['file']) ? self::relative($f['file']) : '[intern]',
                isset($f['line']) ? ':' . $f['line'] : '',
                $f['class'] ?? '', $f['type'] ?? '', $f['function']);
        }
        $kind = Db::isUnavailable() ? 'Datenbank nicht erreichbar' : get_class($e);
        self::notify(
            get_class($e) . '@' . self::relative($e->getFile()) . ':' . $e->getLine(),
            $kind . ($context !== '' ? ' (' . $context . ')' : ''),
            $lines,
            Db::isUnavailable()
        );
    }

    /** Fatal PHP error (from error_get_last() on shutdown) or warning. */
    public static function phpError(int $type, string $message, string $file, int $line): void
    {
        $label = match ($type) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'Fataler Fehler',
            E_PARSE => 'Syntaxfehler',
            default => 'PHP-Warnung',
        };
        self::notify(
            'php' . $type . '@' . self::relative($file) . ':' . $line,
            $label,
            [$label . ': ' . ErrorPage::sanitize($message), 'Ort: ' . self::relative($file) . ':' . $line],
            $label !== 'PHP-Warnung'
        );
    }

    /**
     * Email to the operator, throttled per $key. Returns true if it was sent.
     * $important (database gone, fatal error): does not count against the daily limit, so that frequent
     * minor reports (e.g. deliberately triggered warnings) do not crowd out important ones.
     * @param list<string> $lines
     */
    public static function notify(string $key, string $subject, array $lines, bool $important = false): bool
    {
        if (self::$sending) {
            return false; // don't report errors while sending the report itself
        }
        self::$sending = true;
        try {
            $to = self::recipient();
            if ($to === null) {
                ErrorPage::writeLog('Alert: no recipient (config alert_email or the e-mail address of the admin account) – no alert sent');
                return false;
            }
            $suppressed = self::claim($key, $important);
            if ($suppressed === null) {
                return false;
            }
            $body = self::body($lines, $suppressed);
            $subject = '[' . self::appName() . '] ' . $subject;
            $ok = self::$transport !== null
                ? (bool) (self::$transport)($to, $subject, $body)
                : Mailer::send($to, $subject, $body);
            if (!$ok) {
                ErrorPage::writeLog('Alert: the alert could not be sent');
            }
            return $ok;
        } catch (\Throwable $e) {
            ErrorPage::writeLog('Alert: ' . get_class($e) . ': ' . ErrorPage::sanitize($e->getMessage()));
            return false;
        } finally {
            self::$sending = false;
        }
    }

    private static function recipient(): ?string
    {
        $to = trim((string) Config::get('alert_email', ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $to;
        }
        if (Db::isUnavailable()) {
            return null;
        }
        try {
            $admin = Operator::admin();
            $to = trim((string) ($admin['email'] ?? ''));
            return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Throttling: null = don't send; otherwise the number of identical errors suppressed since the
     * last email. If app/var is not writable, the counter lives in the temp directory; only
     * if that fails too, the email is sent unthrottled (better too much than nothing).
     */
    private static function claim(string $key, bool $important): ?int
    {
        $fh = @fopen(self::$stateFile ?? DATA_ROOT . '/app/var/alerts.json', 'c+')
            ?: @fopen(sys_get_temp_dir() . '/tp-alerts-' . substr(hash('sha256', DATA_ROOT), 0, 12) . '.json', 'c+');
        if ($fh === false) {
            return 0;
        }
        try {
            flock($fh, LOCK_EX);
            $state = json_decode((string) stream_get_contents($fh), true);
            $state = is_array($state) ? $state : [];
            $now = time();
            $today = date('Y-m-d', $now);
            if (($state['_day'] ?? '') !== $today) {
                $state['_day'] = $today;
                $state['_count'] = 0;
            }
            $id = hash('sha256', $key);
            $entry = $state['keys'][$id] ?? ['last' => 0, 'suppressed' => 0];
            if ($now - (int) $entry['last'] < self::THROTTLE_SECONDS || (!$important && (int) $state['_count'] >= self::MAX_PER_DAY)) {
                $entry['suppressed'] = (int) $entry['suppressed'] + 1;
                $suppressed = null;
            } else {
                $suppressed = (int) $entry['suppressed'];
                $entry = ['last' => $now, 'suppressed' => 0];
                $state['_count'] = (int) $state['_count'] + 1;
            }
            $state['keys'][$id] = $entry;
            // Remove old entries (older than one day, nothing pending)
            $state['keys'] = array_filter($state['keys'], static fn ($e) => $now - (int) $e['last'] < 86400 || (int) $e['suppressed'] > 0);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($state));
            fflush($fh);
            return $suppressed;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @param list<string> $lines */
    private static function body(array $lines, int $suppressed): string
    {
        $version = Version::info();
        $head = [
            self::appName() . ' hat einen Fehler gemeldet.',
            '',
            'Zeit: ' . date('d.m.Y H:i:s'),
            'Seite: ' . (string) Config::get('base_url', ''),
            'Version: ' . $version['version'] . ($version['commit'] !== '' ? ' (' . $version['commit'] . ')' : ''),
            'Anfrage: ' . self::request(),
        ];
        if ($suppressed > 0) {
            $head[] = "Seit der letzten Meldung $suppressed weitere Male aufgetreten.";
        }
        return implode("\n", array_merge($head, [''], $lines, [
            '',
            '--',
            'Gleiche Fehler werden höchstens einmal pro Stunde gemeldet. Details stehen im',
            'Fehlerprotokoll app/var/php-error.log. Diese Mail enthält keine personenbezogenen Daten.',
        ])) . "\n";
    }

    private static function request(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'CLI ' . basename((string) ($_SERVER['argv'][0] ?? ''));
        }
        return self::describeRequest((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /**
     * Method and path without query string; only words and numbers (e.g. /admin/round/5/edit)
     * remain, everything else (tokens, round links) is replaced by "…".
     */
    public static function describeRequest(string $method, string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $parts = array_map(
            static fn (string $s) => preg_match('/^(?:\d{1,9}|[a-z]{1,15})\z/', $s) ? $s : '…',
            array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''))
        );
        return (string) preg_replace('/[^A-Z]/', '', strtoupper($method)) . ' /' . implode('/', $parts);
    }

    private static function relative(string $file): string
    {
        return ltrim(str_replace(APP_ROOT, '', $file), '/');
    }

    /** Name from the configuration (without line breaks, it appears in the subject). */
    private static function appName(): string
    {
        $name = trim((string) preg_replace('/[\r\n]+/', ' ', (string) Config::get('app_name', 'TalkSlot')));
        return $name !== '' ? $name : 'TalkSlot';
    }
}
