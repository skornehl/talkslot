<?php
/**
 * Web installer: creates the tables and the admin account.
 * Protected by the install_key from config.php; locks itself after a successful
 * run (lock file + existing admin account) and tries to delete itself.
 */
declare(strict_types=1);

define('PUBLIC_ROOT', __DIR__);
$appRoot = is_file(__DIR__ . '/app_root.php') ? require __DIR__ . '/app_root.php' : dirname(__DIR__);
require $appRoot . '/app/bootstrap.php';

use App\Config;
use App\Http;
use App\I18n;
use App\Installer;
use App\RateLimiter;
use App\View;

Http::enforceHttps();
Http::sendSecurityHeaders();
header('Content-Type: text/html; charset=utf-8');
I18n::set('de');

if (Installer::isInstalled()) {
    http_response_code(404);
    View::render('public/notfound', ['title' => 'Nicht gefunden', 'text' => 'Die Installation ist bereits abgeschlossen.']);
    exit;
}

$errors = [];
$old = ['username' => Http::post('username'), 'display_name' => Http::post('display_name'), 'notify_email' => Http::post('notify_email')];
$done = false;

if (Http::isPost()) {
    $key = (string) Config::get('install_key', '');
    $given = Http::postRaw('install_key');
    if (strlen($key) < 16) {
        $errors[] = 'In config.php ist kein install_key (mind. 16 Zeichen) gesetzt.';
    } elseif (!hash_equals($key, $given)) {
        sleep(2); // slow down guessing
        $errors[] = 'Der Installationsschlüssel ist falsch.';
    }
    if (!$errors && ($conflicts = Installer::conflictingTables())) {
        $errors[] = 'Die Datenbank enthält bereits Tabellen mit gleichem Namen (' . implode(', ', $conflicts) . '), vermutlich von einer anderen Anwendung. Bitte legen Sie für TalkSlot eine eigene, leere Datenbank an und tragen Sie deren Zugangsdaten ein. Es wurde nichts verändert.';
    }
    if (!$errors) {
        $errors = Installer::validate($old['username'], Http::postRaw('password'), Http::postRaw('password2'), $old['notify_email']);
    }
    // Two concurrent installations: only one creates, the other then sees "installed"
    if (!$errors && ((int) App\Db::value('SELECT GET_LOCK(?, 10)', [App\Db::table('install')]) !== 1 || Installer::isInstalled())) {
        $errors[] = 'Die Installation läuft bereits oder ist abgeschlossen.';
    }
    if (!$errors) {
        Installer::install($old['username'], Http::postRaw('password'), $old['notify_email'], mb_substr($old['display_name'], 0, 200));
        $done = true;
        @unlink(__FILE__);
    }
}

View::render('install', ['title' => 'Installation', 'errors' => $errors, 'old' => $old, 'done' => $done]);
