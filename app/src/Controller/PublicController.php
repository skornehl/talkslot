<?php
declare(strict_types=1);

namespace App\Controller;

use App\BookingException;
use App\BookingService;
use App\Csrf;
use App\Config;
use App\Db;
use App\Http;
use App\I18n;
use App\Markdown;
use App\Notifier;
use App\Operator;
use App\RateLimiter;
use App\RoundAccess;
use App\RoundCode;
use App\Translator;
use App\View;

/** Öffentliche Seiten für Eltern – ohne Login, ohne Cookies. */
final class PublicController
{
    public function dispatch(string $path, string $method): void
    {
        $post = $method === 'POST';
        $m = [];
        match (true) {
            $path === '/' => $this->home(),
            in_array($path, ['/impressum', '/imprint'], true) => $this->page('imprint'),
            in_array($path, ['/datenschutz', '/privacy'], true) => $this->page('privacy'),
            (bool) preg_match('#^/b/([A-Za-z0-9_-]{43})$#', $path, $m) => $post ? $this->managePost($m[1]) : $this->manage($m[1]),
            (bool) preg_match('#^/([a-z0-9][a-z0-9-]{0,99})$#', $path, $m) => $post ? $this->bookPost($m[1]) : $this->round($m[1]),
            default => $this->notFound(),
        };
    }

    /**
     * Startseite mit Eingabe des Rundencodes (Formular per POST, damit der Code nicht in
     * Adresse, Verlauf und Server-Logs landet; ?code=… in Links geht weiterhin). Ohne Schutz
     * leitet ein gültiger Code zur Runde weiter; bei „Nur mit Rundencode“ ist der Code der
     * Zugang und die Runde wird direkt freigeschaltet angezeigt. Nur Fehlversuche mit einem
     * formal gültigen Code zählen gegen das Limit (je gekürzter IP, gemeinsam mit der
     * Code-Abfrage auf der Rundenseite), damit sich Eltern hinter einer gemeinsamen IP
     * (Schul-WLAN, Mobilfunk) nicht durch erfolgreiche Eingaben oder Tippfehler gegenseitig
     * aussperren. Gezählt wird vor der Suche, bei Erfolg zurückgenommen.
     */
    private function home(): void
    {
        $raw = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['code'] ?? null) : ($_GET['code'] ?? null);
        $input = is_string($raw) ? trim($raw) : '';
        $errors = [];
        if ($input !== '') {
            [$limit, $window] = RateLimiter::limits('code', 30, 900);
            $code = RoundCode::normalize($input);
            if ($code === null ? RateLimiter::count('code', $window) >= $limit : !RateLimiter::hit('code', $limit, $window)) {
                http_response_code(429);
                $errors[] = t('err.code_rate');
            } else {
                $found = $code !== null ? Db::one('SELECT r.slug, r.code_required' . self::activeRoundSql() . ' AND r.code = ?', [$code]) : null;
                if ($found) {
                    RateLimiter::undo('code');
                    if (!$found['code_required']) {
                        Http::redirect('/' . $found['slug']);
                    }
                    // geschützte Runde: der Code ist der Zugang – direkt freigeschaltet anzeigen
                    $this->round((string) $found['slug'], [], [], true);
                    return;
                }
                http_response_code(422);
                // Nur Ziffern (z. B. eine alte PIN): Hinweis auf das Format des Codes
                $errors[] = t(preg_match('/^\d{4,12}$/', (string) preg_replace('/\s+/', '', $input)) ? 'err.code_pin' : 'err.code');
            }
        }
        View::render('public/home', ['title' => t('home.title'), 'errors' => $errors, 'code' => mb_substr($input, 0, 20)]);
    }

    private function page(string $key): void
    {
        // Ohne eigene Fassung: Englisch (verständlicher als Deutsch für die meisten), dann Standardsprache
        $content = null;
        foreach (array_unique([I18n::lang(), 'en', I18n::defaultLanguage()]) as $l) {
            $content ??= Db::value('SELECT content FROM pages WHERE page_key = ? AND lang = ?', [$key, $l]);
        }
        $slug = is_string($_GET['r'] ?? null) ? (string) $_GET['r'] : '';
        if ($content !== null) {
            // Verantwortlich für alle Runden (und im Impressum genannt) ist der Admin
            $content = Operator::fill((string) $content, t('privacy.controller.generic'));
        }
        View::render('public/page', [
            'title' => t('footer.' . $key),
            'html' => $content !== null ? Markdown::toHtml((string) $content) : '',
            'privacyRound' => $slug !== '' && preg_match('/^[a-z0-9-]{1,100}$/', $slug) ? $slug : null,
        ]);
    }

    private function notFound(string $text = '', int $status = 404): void
    {
        http_response_code($status);
        View::render('public/notfound', ['title' => t('notfound.title'), 'text' => $text !== '' ? $text : t('notfound.text')]);
    }

    /** Nur aktive Runden aktiver Konten, und nur wenn der Admin die Angaben für Impressum/Datenschutz hinterlegt hat */
    private static function activeRoundSql(): string
    {
        return ' FROM rounds r JOIN users u ON u.id = r.user_id
              WHERE r.is_active = 1 AND u.is_active = 1 AND ' . Operator::readySql();
    }

    private function findRound(string $slug): ?array
    {
        return Db::one('SELECT r.*, u.display_name AS teacher_name' . self::activeRoundSql() . ' AND r.slug = ?', [$slug]);
    }

    /**
     * Rundenseite. Bei PIN-geschützten Runden ohne gültigen Zugang ($granted) wird
     * stattdessen die Abfrage des Zugangscodes angezeigt.
     */
    private function round(string $slug, array $errors = [], array $old = [], bool $granted = false): void
    {
        $round = $this->findRound($slug);
        if (!$round) {
            $this->notFound();
            return;
        }
        $round = Translator::localize($round, (int) $round['id'], I18n::lang()); // Titel/Beschreibung in der Sprache der Eltern
        if ($errors) {
            http_response_code(http_response_code() === 429 ? 429 : 422);
        }
        $protected = RoundAccess::required($round);
        if ($protected && !$granted) {
            View::render('public/pin', [
                'title' => t('pin.title'),
                'privacyRound' => $round['slug'],
                'round' => $round,
                'csrf' => Csrf::publicToken('pin:' . $round['id']),
                'errors' => $errors,
            ]);
            return;
        }
        // Zugangstoken: ein mitgeschicktes gültiges behalten (sonst verlängerte jeder Sprachwechsel
        // es ohne Code beliebig), nur nach Eingabe des Codes ein neues ausstellen
        $sentAccess = is_string($_POST['access'] ?? null) ? $_POST['access'] : '';
        $access = $protected ? (RoundAccess::validToken($round, $sentAccess) ? $sentAccess : RoundAccess::token($round)) : '';
        $days = BookingService::groupByDay(BookingService::freeSlots($round));
        View::render('public/round', [
            'title' => $round['title'],
            'privacyRound' => $round['slug'],
            'round' => $round,
            'days' => $days,
            'csrf' => Csrf::publicToken('book:' . $round['id']),
            'access' => $access,
            // Sprachwechsel auf freigeschalteter Seite per POST, damit der Zugang erhalten bleibt
            'langSwitchAccess' => $protected ? ['action' => url('/' . $round['slug']), 'access' => $access] : null,
            // Gibt es ein Buchungsformular, senden die Sprachknöpfe es samt Eingaben ab
            'langSwitchForm' => $days ? 'booking-form' : null,
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    /**
     * Rundencode auf der Seite einer geschützten Runde (Link). Fehlversuche teilen sich das
     * Limit mit der Startseite ('code'): gezählt wird vor der Prüfung, Erfolg wird zurückgenommen;
     * formal ungültige Eingaben (z. B. eine alte Ziffern-PIN) zählen nicht.
     */
    private function unlock(array $round, string $slug): void
    {
        [$limit, $window] = RateLimiter::limits('code', 30, 900);
        $csrf = Csrf::checkPublic('pin:' . $round['id'], $_POST['csrf'] ?? null);
        if ($csrf !== Csrf::OK) {
            $this->round($slug, [t($csrf === Csrf::TOO_FAST ? 'err.too_fast' : 'err.csrf')]);
            return;
        }
        $input = Http::post('code');
        if (RoundCode::normalize($input) === null) {
            if (RateLimiter::count('code', $window) >= $limit) {
                http_response_code(429);
                $this->round($slug, [t('err.code_rate')]);
                return;
            }
            $this->round($slug, [t(preg_match('/^\d{4,12}$/', (string) preg_replace('/\s+/', '', $input)) ? 'err.code_pin' : 'err.pin')]);
            return;
        }
        if (!RateLimiter::hit('code', $limit, $window)) {
            http_response_code(429);
            $this->round($slug, [t('err.code_rate')]);
            return;
        }
        if (!RoundAccess::checkCode($round, $input)) {
            $this->round($slug, [t('err.pin')]);
            return;
        }
        RateLimiter::undo('code');
        $this->round($slug, [], [], true);
    }

    private function bookPost(string $slug): void
    {
        $round = $this->findRound($slug);
        if (!$round) {
            $this->notFound();
            return;
        }
        if (RoundAccess::required($round)) {
            if (Http::post('step') === 'unlock') {
                $this->unlock($round, $slug);
                return;
            }
            if (Http::post('step') === 'switch') {
                // Sprachwechsel: Zugangstoken prüfen, Seite in neuer Sprache zeigen
                RoundAccess::validToken($round, $_POST['access'] ?? null)
                    ? $this->round($slug, [], [], true)
                    : $this->round($slug, [t('err.pin_expired')]);
                return;
            }
            if (!RoundAccess::validToken($round, $_POST['access'] ?? null)) {
                $this->round($slug, [t('err.pin_expired')]);
                return;
            }
        }
        $old = [
            'slot_id' => Http::post('slot_id'),
            'name' => Http::post('name'),
            'email' => Http::post('email'),
            'remember' => Http::post('remember') === '1',
            'privacy' => Http::post('privacy') === '1',
        ];

        // Sprachwechsel: Formular mit allen Eingaben in der neuen Sprache erneut zeigen, nichts buchen
        if (Http::post('switch_lang') !== '') {
            $this->round($slug, [], $old, true);
            return;
        }

        // Missbrauchsschutz: Rate-Limit, CSRF/Mindestzeit, Honeypot
        // Zwei Limits je gekürzter IP: alle Versuche großzügig (Bots), erfolgreiche Buchungen
        // enger. Da sich viele Eltern eine gekürzte IP teilen können (Mobilfunk-CGNAT,
        // Schul-WLAN), zählen Tippfehler und abgelehnte Versuche nicht gegen das Buchungslimit.
        [$attemptLimit, $attemptWindow] = RateLimiter::limits('attempt', 150, 3600);
        [$limit, $window] = RateLimiter::limits('booking', 30, 3600);
        if (!RateLimiter::hit('attempt', $attemptLimit, $attemptWindow) || RateLimiter::count('booking', $window) >= $limit) {
            http_response_code(429);
            $this->round($slug, [t('err.rate')], $old, true);
            return;
        }
        $csrf = Csrf::checkPublic('book:' . $round['id'], $_POST['csrf'] ?? null);
        if ($csrf !== Csrf::OK) {
            $this->round($slug, [t($csrf === Csrf::TOO_FAST ? 'err.too_fast' : 'err.csrf')], $old, true);
            return;
        }
        if (Http::post('website') !== '') {
            $this->round($slug, [t('err.spam')], [], true);
            return;
        }

        $errors = self::validate($old, true);
        if (Http::post('privacy') !== '1') {
            $errors[] = t('err.privacy');
        }
        if ($errors) {
            $this->round($slug, $errors, $old, true);
            return;
        }

        try {
            [$id, $token] = BookingService::book($round, (int) $old['slot_id'], self::cleanName($old['name']), $old['email'] ?: null, I18n::lang());
        } catch (BookingException $e) {
            // Treffer der Sperre für Mehrfachbuchungen zählen wie Buchungen: bremst das Ausprobieren,
            // ob es für einen Namen schon einen Termin gibt
            if (in_array($e->getMessage(), [BookingService::LIMIT_ROUND, BookingService::LIMIT_DAY], true)) {
                RateLimiter::hit('booking', $limit, $window);
            }
            $this->round($slug, [t('err.' . $e->getMessage())], ['slot_id' => ''] + $old, true);
            return;
        }
        RateLimiter::hit('booking', $limit, $window);

        $booking = BookingService::findById($id);
        $mailed = $booking && Notifier::parentBooked($booking, $token);
        Http::redirect('/b/' . $token, ['done' => 'booked', 'mail' => $mailed ? '1' : null, 'remember' => $old['remember'] ? self::rememberSignature($token) : null]);
    }

    private function manage(string $token, array $errors = []): void
    {
        [$limit, $window] = RateLimiter::limits('manage', 300, 3600);
        if (!RateLimiter::hit('manage', $limit, $window)) {
            $this->notFound(t('err.rate'), 429);
            return;
        }
        $booking = BookingService::findByToken($token);
        if (!$booking) {
            $this->notFound(t('manage.notfound'));
            return;
        }
        $canChange = BookingService::canChange($booking);
        $canMove = $canChange && (bool) $booking['is_active'] && (bool) $booking['teacher_active'] && Operator::ready();
        $round = Db::one('SELECT * FROM rounds WHERE id = ?', [$booking['round_id']]);
        View::render('public/manage', [
            'title' => t('manage.title'),
            'privacyRound' => $booking['slug'],
            'booking' => Translator::localize($booking, (int) $booking['round_id'], I18n::lang()),
            'token' => $token,
            'canChange' => $canChange,
            'canMove' => $canMove,
            'days' => $canMove ? BookingService::groupByDay(BookingService::freeSlots($round, (int) $booking['slot_id'])) : [],
            'csrf' => Csrf::publicToken('manage:' . $booking['id']),
            'done' => Http::query('done'),
            'mailed' => Http::query('mail') === '1',
            'remember' => self::rememberValid($token, Http::query('remember')),
            'errors' => $errors,
        ]);
    }

    private function managePost(string $token): void
    {
        [$limit, $window] = RateLimiter::limits('manage', 300, 3600);
        if (!RateLimiter::hit('manage', $limit, $window)) {
            $this->notFound(t('err.rate'), 429);
            return;
        }
        $booking = BookingService::findByToken($token);
        if (!$booking) {
            $this->notFound(t('manage.notfound'));
            return;
        }
        $csrf = Csrf::checkPublic('manage:' . $booking['id'], $_POST['csrf'] ?? null);
        if ($csrf !== Csrf::OK) {
            $this->manage($token, [t($csrf === Csrf::TOO_FAST ? 'err.too_fast' : 'err.csrf')]);
            return;
        }
        if (!BookingService::canChange($booking)) {
            $this->manage($token, [t('manage.locked', ['hours' => (int) $booking['cancel_deadline_hours']])]);
            return;
        }

        $action = Http::post('action');
        if ($action === 'cancel') {
            if (Http::post('confirm') !== '1') {
                $this->manage($token, [t('manage.cancel.confirm_required')]);
                return;
            }
            BookingService::delete($booking);
            Notifier::parentCancelled($booking);
            View::render('public/cancelled', ['title' => t('cancelled.title'), 'booking' => Translator::localize($booking, (int) $booking['round_id'], I18n::lang()), 'privacyRound' => $booking['slug']]);
            return;
        }

        if ($action === 'move') {
            $slotId = (int) Http::post('slot_id');
            if ($slotId <= 0) {
                $this->manage($token, [t('err.slot')]);
                return;
            }
            // Jede Umbuchung verschickt eine Mail: je Buchung höchstens 10 pro Stunde (gegen Mailfluten)
            [$moveLimit, $moveWindow] = RateLimiter::limits('move', 10, 3600);
            if (!RateLimiter::hit('move', $moveLimit, $moveWindow, 'booking:' . $booking['id'])) {
                http_response_code(429);
                $this->manage($token, [t('err.rate')]);
                return;
            }
            try {
                BookingService::move($booking, $slotId, false);
            } catch (BookingException $e) {
                $this->manage($token, [t('err.' . $e->getMessage())]);
                return;
            }
            $updated = BookingService::findById((int) $booking['id']);
            $mailed = $updated && Notifier::parentChanged($updated, $token);
            Http::redirect('/b/' . $token, ['done' => 'changed', 'mail' => $mailed ? '1' : null]);
        }

        $this->manage($token);
    }

    /** Gemeinsame Validierung für Name/E-Mail/Slot (auch vom Admin genutzt). */
    public static function validate(array $in, bool $requireSlot): array
    {
        $errors = [];
        if ($requireSlot && (int) ($in['slot_id'] ?? 0) <= 0) {
            $errors[] = t('err.slot');
        }
        $name = self::cleanName((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
            $errors[] = t('err.name');
        } elseif (preg_match('~(://|www\.|https?:)~i', $name)) {
            // Der Name steht in Mails: keine Links (sonst Phishing über den Absender der Schule)
            $errors[] = t('err.name_link');
        }
        $email = (string) ($in['email'] ?? '');
        if ($email !== '' && !is_valid_email($email)) {
            $errors[] = t('err.email');
        }
        return $errors;
    }

    /**
     * „Termin auf diesem Gerät merken“: Der Parameter nach der Buchung ist signiert und 10 Minuten
     * gültig, damit ein fremder Link (/b/…?remember=1) keinen Merker im Browser anderer setzen kann.
     */
    private static function rememberSignature(string $token): string
    {
        $ts = (string) time();
        return $ts . '.' . substr(hash_hmac('sha256', 'remember|' . $token . '|' . $ts, (string) Config::get('secret', '')), 0, 32);
    }

    private static function rememberValid(string $token, string $value): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{32})$/', $value, $m) || time() - (int) $m[1] > 600 || (int) $m[1] > time() + 60) {
            return false;
        }
        return hash_equals(substr(hash_hmac('sha256', 'remember|' . $token . '|' . $m[1], (string) Config::get('secret', '')), 0, 32), $m[2]);
    }

    /** Steuerzeichen entfernen, Leerraum zusammenfassen. */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\p{C}]+/u', ' ', $name);
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
