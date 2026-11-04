<?php
declare(strict_types=1);

namespace App;

use App\Controller\AdminController;
use App\Controller\PublicController;

/** Front controller: dispatches requests to the public area or admin. */
final class App
{
    public static function run(): void
    {
        Http::enforceHttps();
        Http::sendSecurityHeaders();
        header('Content-Type: text/html; charset=utf-8');

        try {
            if (Installer::isInstalled()) {
                Migrations::run();
                Cleanup::maybeRun();
            }
        } catch (\Throwable $e) {
            Alert::exception($e, 'Migration/Bereinigung'); // must not block the page
        }

        $path = Http::path();
        if ($path === '/cron') {
            self::webCron();
            return;
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            I18n::set('de');
            (new AdminController())->dispatch(substr($path, 6) ?: '/', Http::method());
            return;
        }

        // switch_lang: language button that submits the booking form including its input
        $lang = $_POST['switch_lang'] ?? $_GET['lang'] ?? $_POST['lang'] ?? null;
        I18n::init(is_string($lang) ? $lang : null);
        (new PublicController())->dispatch($path, Http::method());
    }

    /** Key for the web cron, derived from the app secret (no separate secret needed). */
    public static function cronToken(): string
    {
        return hash_hmac('sha256', 'cron', (string) Config::get('secret', ''));
    }

    /**
     * Web cron as a replacement for a cron job on the server: POST /cron with header
     * "X-Cron-Token". The key never appears in the URL (no entry in access logs).
     * Response: counts only, no personal data.
     */
    private static function webCron(): void
    {
        $given = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
        if (Http::method() !== 'POST' || strlen((string) Config::get('secret', '')) < 32 || !hash_equals(self::cronToken(), $given)) {
            http_response_code(404);
            I18n::init(null);
            View::render('public/notfound', ['title' => t('notfound.title'), 'text' => t('notfound.text')]);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        if (!Installer::isInstalled()) {
            echo json_encode(['status' => 'not_installed']);
            return;
        }
        Migrations::run();
        echo json_encode(['status' => 'ok'] + Cleanup::run() + Summaries::run() + Reminders::run());
    }
}
