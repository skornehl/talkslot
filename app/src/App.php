<?php
declare(strict_types=1);

namespace App;

use App\Controller\AdminController;
use App\Controller\PublicController;

/** Front-Controller: verteilt Anfragen an öffentlichen Bereich bzw. Admin. */
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
            Alert::exception($e, 'Migration/Bereinigung'); // darf die Seite nicht blockieren
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

        // switch_lang: Sprachknopf, der das Buchungsformular samt Eingaben absendet
        $lang = $_POST['switch_lang'] ?? $_GET['lang'] ?? $_POST['lang'] ?? null;
        I18n::init(is_string($lang) ? $lang : null);
        (new PublicController())->dispatch($path, Http::method());
    }

    /** Schlüssel für den Web-Cron, abgeleitet aus dem App-Secret (kein eigenes Secret nötig). */
    public static function cronToken(): string
    {
        return hash_hmac('sha256', 'cron', (string) Config::get('secret', ''));
    }

    /**
     * Web-Cron als Ersatz für einen Cronjob auf dem Server: POST /cron mit Header
     * „X-Cron-Token“. Der Schlüssel steht nie in der URL (kein Eintrag in Zugriffslogs).
     * Antwort: nur Anzahlen, keine personenbezogenen Daten.
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
        echo json_encode(['status' => 'ok'] + Cleanup::run() + Summaries::run());
    }
}
