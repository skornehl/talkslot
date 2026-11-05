<?php
/**
 * Shared entry point for web (public/index.php) and CLI (bin/*.php).
 * Loads configuration, autoloader and error handling.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__)); // Project root containing app/, bin/, config/
// Atomic deployment (bin/deploy.sh): code and configuration live per version in
// <base>/releases/<name>/, while mutable data (app/var: sessions, logs, lock file,
// alert counters) stays permanently in <base>/app/var. Locally both are the same directory.
define('RELEASE', basename(dirname(APP_ROOT)) === 'releases' ? basename(APP_ROOT) : '');
define('DATA_ROOT', RELEASE !== '' ? dirname(APP_ROOT, 2) : APP_ROOT);

// Until App\Platform has checked the PHP version, only syntax and functions of PHP 7.1 (no str_starts_with)
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// An old PHP version or a missing extension: a plain explanation instead of a blank 500 page. The entry points
// that use the database (public/*.php, bin/cron.php, bin/install.php) check for its driver as well; the unit
// tests run without it.
App\Platform::enforce(false);

require __DIR__ . '/helpers.php';

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    App\Platform::stop([[
        'en' => 'Configuration missing: create config/config.php (template: config/config.sample.php).',
        'de' => 'Die Konfiguration fehlt: Bitte config/config.php anlegen (Vorlage: config/config.sample.php).',
    ]]);
}
App\Config::load(require $configFile);
// Mistakes the app cannot run with (App\ConfigSchema::errors()): without a sufficiently long secret, CSRF,
// access and cron tokens as well as IP hashes would be insecure; a misspelt time zone would shift every
// deadline. Other mistakes are listed for admins on the dashboard (ConfigSchema::warnings()).
if ($configErrors = App\ConfigSchema::errors(App\Config::all())) {
    App\Platform::stop($configErrors);
}

date_default_timezone_set((string) App\Config::get('timezone', 'Europe/Berlin'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', App\Config::get('debug', false) ? '1' : '0');
// PHP itself does not log (its messages may contain input); the handlers below
// write sanitized entries via ErrorPage::writeLog() (with size limit).
ini_set('log_errors', '0');
if (is_dir(DATA_ROOT . '/app/var') && is_writable(DATA_ROOT . '/app/var')) {
    ini_set('error_log', DATA_ROOT . '/app/var/php-error.log');
}
// Unhandled errors: log internally (without personal data), report by mail to the
// operator (App\Alert, throttled) and only show a neutral error page to the outside.
set_exception_handler(static function (Throwable $e): void {
    App\Alert::exception($e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . App\ErrorPage::sanitize($e->getMessage()) . "\n");
        exit(1);
    }
    App\ErrorPage::show($e);
});

// Log warnings, notices and deprecations sanitized, additionally report warnings (usually bugs, e.g. a missing
// array key). Not for places suppressed with @. false: display as
// configured (only with debug). The tests fail on every such line (tests/php-errors.sh).
set_error_handler(static function (int $type, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $type)) {
        return false;
    }
    $kind = match (true) {
        in_array($type, [E_WARNING, E_USER_WARNING], true) => 'Warning',
        in_array($type, [E_DEPRECATED, E_USER_DEPRECATED], true) => 'Deprecated',
        in_array($type, [E_USER_ERROR, E_RECOVERABLE_ERROR], true) => 'Error',
        default => 'Notice',
    };
    App\ErrorPage::writeLog(sprintf('PHP %s: %s (%s:%d)', $kind, App\ErrorPage::sanitize($message), App\ErrorPage::relativePath($file), $line));
    if (in_array($type, [E_WARNING, E_USER_WARNING], true)) {
        App\Alert::phpError($type, $message, $file, $line);
    }
    return false;
});

// Fatal errors (memory, time limit, syntax) never reach the exception handler: report them on
// shutdown. Some memory is kept in reserve so this also works on "memory exhausted".
$GLOBALS['__alert_reserve'] = str_repeat(' ', 64 * 1024);
register_shutdown_function(static function (): void {
    unset($GLOBALS['__alert_reserve']);
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    App\ErrorPage::writeLog(sprintf('PHP Fatal error: %s (%s:%d)', App\ErrorPage::sanitize($err['message']), App\ErrorPage::relativePath($err['file']), $err['line']));
    App\Alert::phpError($err['type'], $err['message'], $err['file'], $err['line']);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        App\ErrorPage::show();
    }
});
