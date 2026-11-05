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
        // The web cron and the health check: requests that send their key header. Without it the address is
        // an ordinary round address, so a round that got /cron or /health before Slug::RESERVED listed them
        // keeps its link. Both migrate only after checking their key and report a failed migration instead
        // of swallowing it; neither needs the clean-up fallback (the cron runs it in full).
        $endpoint = match (true) {
            $path === '/cron' && isset($_SERVER['HTTP_X_CRON_TOKEN']) => 'cron',
            $path === '/health' && isset($_SERVER['HTTP_X_HEALTH_TOKEN']) => 'health',
            default => '',
        };
        try {
            if ($endpoint === '' && Installer::isInstalled()) {
                Migrations::run();
                Cleanup::maybeRun();
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
        if ($endpoint === 'cron') {
            self::webCron();
            return;
        }
        if ($endpoint === 'health') {
            self::health();
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
        if (!self::keyGiven((string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''), self::cronToken())) {
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

    /**
     * Health check for the deployment (bin/deploy.sh): POST /health with header "X-Health-Token" (App\Health).
     * Response: only a status word, 200 if the version works with the database, otherwise 503.
     */
    private static function health(): void
    {
        if (!self::keyGiven((string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''), Health::token())) {
            return;
        }
        [$code, $status] = Health::check();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($status === 'schema_outdated') {
            header('Retry-After: 30'); // another request is still migrating
        }
        echo json_encode(['status' => $status]);
    }

    /** POST with the right key; otherwise a plain 404 page (the endpoint does not reveal itself). */
    private static function keyGiven(#[\SensitiveParameter] string $given, #[\SensitiveParameter] string $expected): bool
    {
        if (Http::method() === 'POST' && strlen((string) Config::get('secret', '')) >= 32 && hash_equals($expected, $given)) {
            return true;
        }
        http_response_code(404);
        I18n::init(null);
        View::render('public/notfound', ['title' => t('notfound.title'), 'text' => t('notfound.text')]);
        return false;
    }
}
