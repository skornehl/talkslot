<?php
/**
 * Gemeinsamer Einstiegspunkt für Web (public/index.php) und CLI (bin/*.php).
 * Lädt Konfiguration, Autoloader und Fehlerbehandlung.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__)); // Projektwurzel mit app/, bin/, config/
// Atomares Deployment (bin/deploy.sh): Code und Konfiguration liegen je Version in
// <Basis>/releases/<Name>/, die veränderlichen Daten (app/var: Sessions, Logs, Sperrdatei,
// Meldungszähler) bleiben dauerhaft in <Basis>/app/var. Lokal ist beides dasselbe Verzeichnis.
define('RELEASE', basename(dirname(APP_ROOT)) === 'releases' ? basename(APP_ROOT) : '');
define('DATA_ROOT', RELEASE !== '' ? dirname(APP_ROOT, 2) : APP_ROOT);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/helpers.php';

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo "Konfiguration fehlt: config/config.php anlegen (Vorlage: config/config.sample.php).\n";
    exit(1);
}
App\Config::load(require $configFile);

date_default_timezone_set((string) App\Config::get('timezone', 'Europe/Berlin'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', App\Config::get('debug', false) ? '1' : '0');
// PHP selbst protokolliert nicht (seine Meldungen enthalten ggf. Eingaben); die Handler unten
// schreiben bereinigt über ErrorPage::writeLog() (mit Größenbegrenzung).
ini_set('log_errors', '0');
if (is_dir(DATA_ROOT . '/app/var') && is_writable(DATA_ROOT . '/app/var')) {
    ini_set('error_log', DATA_ROOT . '/app/var/php-error.log');
}
// Ohne ausreichend langes Secret wären CSRF-, Zugangs- und Cron-Token sowie die IP-Hashes unsicher
if (strlen((string) App\Config::get('secret', '')) < 32) {
    http_response_code(500);
    echo "Konfiguration unvollständig: \"secret\" fehlt oder ist kürzer als 32 Zeichen.\n";
    exit(1);
}

// Unbehandelte Fehler: intern protokollieren (ohne personenbezogene Daten), per Mail an den
// Betreiber melden (App\Alert, gedrosselt) und nach außen nur eine neutrale Fehlerseite zeigen.
set_exception_handler(static function (Throwable $e): void {
    App\Alert::exception($e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Fehler: ' . App\ErrorPage::sanitize($e->getMessage()) . "\n");
        exit(1);
    }
    App\ErrorPage::show($e);
});

// Warnungen und Hinweise bereinigt protokollieren, Warnungen (meist Programmfehler, z. B. fehlender
// Array-Schlüssel) zusätzlich melden. Mit @ unterdrückte Stellen nicht. false: Anzeige wie
// konfiguriert (nur mit debug).
set_error_handler(static function (int $type, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $type)) {
        return false;
    }
    App\ErrorPage::writeLog(sprintf('PHP %s: %s (%s:%d)', in_array($type, [E_WARNING, E_USER_WARNING], true) ? 'Warning' : 'Notice',
        App\ErrorPage::sanitize($message), str_replace(APP_ROOT, '', $file), $line));
    if (in_array($type, [E_WARNING, E_USER_WARNING], true)) {
        App\Alert::phpError($type, $message, $file, $line);
    }
    return false;
});

// Fatale Fehler (Speicher, Zeitlimit, Syntax) erreichen den Exception-Handler nicht: im Shutdown
// melden. Etwas Speicher wird freigehalten, damit das auch bei „memory exhausted“ klappt.
$GLOBALS['__alert_reserve'] = str_repeat(' ', 64 * 1024);
register_shutdown_function(static function (): void {
    unset($GLOBALS['__alert_reserve']);
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    App\ErrorPage::writeLog(sprintf('PHP Fatal error: %s (%s:%d)', App\ErrorPage::sanitize($err['message']), str_replace(APP_ROOT, '', $err['file']), $err['line']));
    App\Alert::phpError($err['type'], $err['message'], $err['file'], $err['line']);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        App\ErrorPage::show();
    }
});
