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

        $path = Http::path();
        try {
            if (Installer::isInstalled()) {
                Migrations::run();
                if ($path !== '/cron') { // the web cron runs all steps itself
                    Cleanup::maybeRun();
                    register_shutdown_function(self::afterResponse(...));
                }
            }
        } catch (\Throwable $e) {
            Alert::exception($e, 'Migration/Bereinigung'); // must not block the page
        }

        $admin = $path === '/admin' || str_starts_with($path, '/admin/');
        // Form posts from other sites are refused before anything is processed (login CSRF; a second
        // layer next to the CSRF tokens)
        if (Http::isCrossSitePost()) {
            self::refuseCrossSite($admin);
            return;
        }
        if ($path === '/cron') {
            self::webCron();
            return;
        }
        if ($admin) {
            (new AdminController())->dispatch(substr($path, 6) ?: '/', Http::method());
            return;
        }

        self::initPublicLanguage();
        header('Vary: Accept-Language'); // the browser language decides whether a link to it is offered
        (new PublicController())->dispatch($path, Http::method());
    }

    private static function initPublicLanguage(): void
    {
        // switch_lang: language button that submits the booking form including its input
        $lang = $_POST['switch_lang'] ?? $_GET['lang'] ?? $_POST['lang'] ?? null;
        I18n::init(is_string($lang) ? $lang : null);
    }

    /** 403 for a cross-site form post, in the language of the area; no session is started. */
    private static function refuseCrossSite(bool $admin): void
    {
        http_response_code(403);
        if ($admin) {
            header('X-Robots-Tag: noindex, nofollow');
            AdminI18n::set(AdminI18n::forRequest(null));
            View::render('admin/message', [
                'title' => __('Anfrage abgelehnt'),
                'text' => __('Das Formular wurde von einer anderen Website aus abgeschickt und deshalb nicht verarbeitet. Bitte öffnen Sie die Seite direkt und versuchen Sie es dort erneut.'),
            ], 'admin/layout');
            return;
        }
        self::initPublicLanguage();
        View::render('public/notfound', ['title' => t('crosssite.title'), 'text' => t('crosssite.text')]);
    }

    /** At the end of a page request: send due mails once the response is out (see Outbox::afterResponse()). */
    private static function afterResponse(): void
    {
        try {
            Outbox::afterResponse(Http::finishResponse(...));
        } catch (\Throwable $e) {
            Alert::exception($e, 'Mailversand nach der Antwort');
        }
    }

    /** Key for the web cron, derived from the app secret (no separate secret needed). */
    public static function cronToken(): string
    {
        return hash_hmac('sha256', 'cron', (string) Config::get('secret', ''));
    }

    /**
     * Web cron as a replacement for a cron job on the server: POST /cron with header
     * "X-Cron-Token". The key never appears in the URL (no entry in access logs).
     * Response: counts only, no personal data. Sends the due mails of the outbox (limited in number and time).
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
        set_time_limit((int) Config::get('mail_queue.seconds', 20) + 60);
        echo json_encode(['status' => 'ok'] + Cleanup::run() + Summaries::run() + Reminders::run() + Outbox::process());
    }
}
