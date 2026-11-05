<?php
declare(strict_types=1);

namespace App\Controller;

use App\Abuse;
use App\BookingException;
use App\BookingService;
use App\Csrf;
use App\Config;
use App\Db;
use App\Http;
use App\I18n;
use App\IcsExport;
use App\LegalTexts;
use App\Markdown;
use App\Notifier;
use App\Operator;
use App\RateLimiter;
use App\RoundAccess;
use App\RoundCode;
use App\Translator;
use App\View;

/** Public pages for parents – no login, no cookies. */
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
     * Start page with round code input (form via POST so that the code does not end up in the
     * address bar, history and server logs; ?code=… in links still works). Without protection
     * a valid code redirects to the round; with "Nur mit Rundencode" the code is the
     * access key and the round is shown unlocked directly. Only failed attempts with a
     * formally valid code count against the limit (per truncated IP, shared with the
     * code prompt on the round page), so that parents behind a shared IP
     * (school Wi-Fi, mobile network) do not lock each other out through successful input or typos.
     * Counted before the lookup, taken back on success.
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
                    // protected round: the code is the access key – show it unlocked directly
                    $this->round((string) $found['slug'], [], [], true);
                    return;
                }
                http_response_code(422);
                // Digits only (e.g. an old PIN): hint about the code format
                $errors[] = t(preg_match('/^\d{4,12}$/', (string) preg_replace('/\s+/', '', $input)) ? 'err.code_pin' : 'err.code');
            }
        }
        View::render('public/home', ['title' => t('home.title'), 'errors' => $errors, 'code' => mb_substr($input, 0, 20)]);
    }

    private function page(string $key): void
    {
        // Without a dedicated version: English (more understandable than German for most), then the default language
        $content = null;
        $contentLang = I18n::lang();
        foreach (array_unique([I18n::lang(), 'en', I18n::defaultLanguage()]) as $l) {
            if ($content === null && ($content = Db::value('SELECT content FROM pages WHERE page_key = ? AND lang = ?', [$key, $l])) !== null) {
                $contentLang = $l;
            }
        }
        $slug = is_string($_GET['r'] ?? null) ? (string) $_GET['r'] : '';
        if ($content !== null) {
            // The operator is the controller for all rounds (and named in the legal notice); the web host
            // comes from the configuration
            $content = LegalTexts::render((string) $content, $contentLang, t('privacy.controller.generic'));
        }
        View::render('public/page', [
            'title' => t('footer.' . $key),
            'html' => $content !== null ? Markdown::toHtml((string) $content) : '',
            'privacyRound' => $slug !== '' && preg_match('/^[a-z0-9-]{1,100}$/', $slug) ? $slug : null,
        ]);
    }

    /** $forgetUrl: link of a booking that no longer exists – the browser drops its remember-me entry */
    private function notFound(string $text = '', int $status = 404, string $forgetUrl = ''): void
    {
        http_response_code($status);
        View::render('public/notfound', ['title' => t('notfound.title'), 'text' => $text !== '' ? $text : t('notfound.text'), 'forgetUrl' => $forgetUrl]);
    }

    /** Only active rounds of active accounts, and only if the admin has provided the details for legal notice/privacy policy */
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
     * Round page. For PIN-protected rounds without valid access ($granted), the
     * access code prompt is shown instead.
     */
    private function round(string $slug, array $errors = [], array $old = [], bool $granted = false): void
    {
        $round = $this->findRound($slug);
        if (!$round) {
            $this->notFound();
            return;
        }
        $round = Translator::localize($round, (int) $round['id'], I18n::lang()); // title/description in the parents' language
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
        // Access token: keep a valid one that was submitted (otherwise every language switch would extend
        // it indefinitely without the code); only issue a new one after the code has been entered
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
            // Language switch on an unlocked page via POST so that access is preserved
            'langSwitchAccess' => $protected ? ['action' => url('/' . $round['slug']), 'access' => $access] : null,
            // If there is a booking form, the language buttons submit it including its input
            'langSwitchForm' => $days ? 'booking-form' : null,
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    /**
     * Round code on the page of a protected round (link). Failed attempts share the
     * limit with the start page ('code'): counted before the check, taken back on success;
     * formally invalid input (e.g. an old digits-only PIN) does not count.
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
                // Language switch: check the access token, show the page in the new language
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

        // Language switch: show the form again with all input in the new language, book nothing
        if (Http::post('switch_lang') !== '') {
            $this->round($slug, [], $old, true);
            return;
        }

        // Abuse protection: rate limit, CSRF/minimum time, honeypot
        // Limits per truncated IP: all attempts generously (bots), successful bookings more strictly, and
        // successful bookings of this round (slot squatting, see Abuse). Since many parents may share a
        // truncated IP (mobile CGNAT, school Wi-Fi), typos and rejected attempts do not count against the
        // booking limits.
        [$attemptLimit, $attemptWindow] = RateLimiter::limits('attempt', 150, 3600);
        [$limit, $window] = RateLimiter::limits('booking', 30, 3600);
        if (!RateLimiter::hit('attempt', $attemptLimit, $attemptWindow) || RateLimiter::count('booking', $window) >= $limit
            || Abuse::roundLimited((int) $round['id'])) {
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

        // The booking counts before it is made, so that parallel requests cannot all pass the check above;
        // given back if nothing was booked
        $withinIp = RateLimiter::hit('booking', $limit, $window);
        $withinRound = Abuse::reserve($round);
        $giveBack = static function () use ($round): void {
            RateLimiter::undo('booking');
            Abuse::release($round);
        };
        if (!$withinIp || !$withinRound) {
            $giveBack();
            http_response_code(429);
            $this->round($slug, [t('err.rate')], $old, true);
            return;
        }
        try {
            [$id, $token] = BookingService::book($round, (int) $old['slot_id'], self::cleanName($old['name']), $old['email'] ?: null, I18n::lang());
        } catch (BookingException $e) {
            // Hits of the multiple-booking limit count like bookings: slows down probing
            // whether a name already has an appointment
            if (!in_array($e->getMessage(), [BookingService::LIMIT_ROUND, BookingService::LIMIT_DAY], true)) {
                $giveBack();
            }
            $this->round($slug, [t('err.' . $e->getMessage())], ['slot_id' => ''] + $old, true);
            return;
        }
        Abuse::booked($round);

        // Sent right away; if that fails it is queued and the page says it will follow. Held back because the
        // address got too many mails: the booking keeps no address, so no reminder or change notice goes there
        $mail = self::notify((array) BookingService::findById($id), 'booked', $token);
        if ($mail === Abuse::LIMITED) {
            BookingService::forgetEmail($id);
        }
        Http::redirect('/b/' . $token, ['done' => 'booked', 'mail' => self::mailParam($mail), 'remember' => $old['remember'] ? self::rememberSignature($token) : null]);
    }

    private function manage(#[\SensitiveParameter] string $token, array $errors = []): void
    {
        [$limit, $window] = RateLimiter::limits('manage', 300, 3600);
        if (!RateLimiter::hit('manage', $limit, $window)) {
            $this->notFound(t('err.rate'), 429);
            return;
        }
        $booking = BookingService::findByToken($token);
        if (!$booking) {
            $this->notFound(t('manage.notfound'), 404, url('/b/' . $token));
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
            'mailQueued' => Http::query('mail') === 'queued',
            'mailLimited' => Http::query('mail') === 'limited',
            'remember' => self::rememberValid($token, Http::query('remember')),
            // After an error, the section of the form that was sent stays open (move, cancel, mail)
            'open' => $errors ? Http::post('action') : '',
            'mailTo' => $errors && Http::post('action') === 'mail' ? mb_substr(Http::post('email'), 0, 254) : '',
            'errors' => $errors,
        ]);
    }

    private function managePost(#[\SensitiveParameter] string $token): void
    {
        [$limit, $window] = RateLimiter::limits('manage', 300, 3600);
        if (!RateLimiter::hit('manage', $limit, $window)) {
            $this->notFound(t('err.rate'), 429);
            return;
        }
        $booking = BookingService::findByToken($token);
        if (!$booking) {
            $this->notFound(t('manage.notfound'), 404, url('/b/' . $token));
            return;
        }
        $action = Http::post('action');
        // The calendar file changes nothing: no minimum time, so it also works right after the page has loaded
        $csrf = Csrf::checkPublic('manage:' . $booking['id'], $_POST['csrf'] ?? null, $action === 'ics' ? 0 : null);
        if ($csrf !== Csrf::OK) {
            $this->manage($token, [t($csrf === Csrf::TOO_FAST ? 'err.too_fast' : 'err.csrf')]);
            return;
        }
        // Calendar file and confirmation mail do not change the booking: also after the deadline for changes
        if ($action === 'ics') {
            $this->calendarFile($booking, $token);
            return;
        }
        if ($action === 'mail') {
            $this->mailConfirmation($booking, $token);
            return;
        }
        if (!BookingService::canChange($booking)) {
            $this->manage($token, [t('manage.locked', ['hours' => (int) $booking['cancel_deadline_hours']])]);
            return;
        }

        if ($action === 'cancel') {
            if (Http::post('confirm') !== '1') {
                $this->manage($token, [t('manage.cancel.confirm_required')]);
                return;
            }
            BookingService::delete($booking);
            if ((string) $booking['email'] !== '' && Abuse::mailAllowed((string) $booking['email'])) {
                Notifier::parentCancelled($booking);
            }
            View::render('public/cancelled', [
                'title' => t('cancelled.title'),
                'booking' => Translator::localize($booking, (int) $booking['round_id'], I18n::lang()),
                'privacyRound' => $booking['slug'],
                'forgetUrl' => url('/b/' . $token),
            ]);
            return;
        }

        if ($action === 'move') {
            $slotId = (int) Http::post('slot_id');
            if ($slotId <= 0) {
                $this->manage($token, [t('err.slot')]);
                return;
            }
            // Every rebooking sends an email: at most 10 per hour per booking and 30 per truncated IP (against
            // mail floods through many bookings); the one per IP counts only rebookings made, so that other
            // parents in the same network keep theirs
            [$moveLimit, $moveWindow] = RateLimiter::limits('move', 10, 3600);
            [$ipLimit, $ipWindow] = RateLimiter::limits('move_ip', 30, 3600);
            if (!RateLimiter::hit('move', $moveLimit, $moveWindow, 'booking:' . $booking['id'])) {
                http_response_code(429);
                $this->manage($token, [t('err.rate_move')]);
                return;
            }
            if (!RateLimiter::hit('move_ip', $ipLimit, $ipWindow)) {
                RateLimiter::undo('move_ip');
                http_response_code(429);
                $this->manage($token, [t('err.rate')]);
                return;
            }
            try {
                BookingService::move($booking, $slotId, false);
            } catch (BookingException $e) {
                RateLimiter::undo('move_ip');
                $this->manage($token, [t('err.' . $e->getMessage())]);
                return;
            }
            $mail = self::notify((array) BookingService::findById((int) $booking['id']), 'changed', $token);
            Http::redirect('/b/' . $token, ['done' => 'changed', 'mail' => self::mailParam($mail)]);
        }

        $this->manage($token);
    }

    /**
     * The appointment as a calendar file, like the attachment of the confirmation mail (with the personal
     * link in the description). Requested by a button (POST), so there is no extra address with the token
     * that browsers or calendars could fetch or store on their own.
     */
    private function calendarFile(array $booking, #[\SensitiveParameter] string $token): void
    {
        $booking = Translator::localize($booking, (int) $booking['round_id'], I18n::lang());
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="termin.ics"');
        echo IcsExport::forParent($booking, t('mail.ics.description', ['link' => abs_url('/b/' . $token, ['lang' => I18n::lang()])]));
    }

    /**
     * Sends the confirmation (again) to an address entered on the page, e.g. by parents who gave none when
     * booking. The address is only used for this mail and not stored. At most twice per booking within
     * 24 hours, so that a link cannot be used to send mails from the school's address in bulk.
     */
    private function mailConfirmation(array $booking, #[\SensitiveParameter] string $token): void
    {
        $email = Http::post('email');
        if (!is_valid_email($email)) {
            $this->manage($token, [t('err.email')]);
            return;
        }
        [$limit, $window] = RateLimiter::limits('confirm', 2, 86400);
        if (!RateLimiter::hit('confirm', $limit, $window, 'booking:' . $booking['id'])) {
            http_response_code(429);
            $this->manage($token, [t('manage.mail.limit')]);
            return;
        }
        // Also limited per address (Abuse): many bookings must not send many mails to one stranger. The attempt
        // still counts for the booking, so that probing addresses costs its budget
        if (!Abuse::mailAllowed($email)) {
            http_response_code(429);
            $this->manage($token, [t('manage.mail_limited')]);
            return;
        }
        if (Notifier::parentConfirmation($booking, $token, $email)) {
            Http::redirect('/b/' . $token, ['done' => 'sent']);
        }
        $this->manage($token, [t('manage.mail.failed')]);
    }

    /**
     * Confirmation ('booked') or change notice ('changed') after the parents booked or moved, unless their
     * address got too many such mails already (Abuse::LIMITED, nothing sent). null: the booking has no address.
     */
    private static function notify(array $b, string $type, #[\SensitiveParameter] string $token): ?string
    {
        if ((string) ($b['email'] ?? '') === '') {
            return null;
        }
        return Abuse::mailAllowed((string) $b['email']) ? Notifier::sendOrQueue($b, $type, $token) : Abuse::LIMITED;
    }

    /**
     * Notice on the appointment page after booking or moving: "mail=1" sent, "mail=queued" follows later,
     * "mail=limited" not sent (too many mails to the address).
     */
    private static function mailParam(?string $result): ?string
    {
        return match ($result) { Notifier::SENT => '1', Notifier::QUEUED => 'queued', Abuse::LIMITED => 'limited', default => null };
    }

    /** Shared validation for name/email/slot (also used by the admin). */
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
            // The name appears in emails: no links (otherwise phishing via the school's sender address)
            $errors[] = t('err.name_link');
        }
        $email = (string) ($in['email'] ?? '');
        if ($email !== '' && !is_valid_email($email)) {
            $errors[] = t('err.email');
        }
        return $errors;
    }

    /**
     * "Remember my appointment on this device": the parameter after booking is signed and valid for 10
     * minutes, so that a foreign link (/b/…?remember=1) cannot set a remember-me entry in other people's browsers.
     */
    private static function rememberSignature(#[\SensitiveParameter] string $token): string
    {
        $ts = (string) time();
        return $ts . '.' . substr(hash_hmac('sha256', 'remember|' . $token . '|' . $ts, (string) Config::get('secret', '')), 0, 32);
    }

    private static function rememberValid(#[\SensitiveParameter] string $token, string $value): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{32})$/', $value, $m) || time() - (int) $m[1] > 600 || (int) $m[1] > time() + 60) {
            return false;
        }
        return hash_equals(substr(hash_hmac('sha256', 'remember|' . $token . '|' . $m[1], (string) Config::get('secret', '')), 0, 32), $m[2]);
    }

    /** Remove control characters, collapse whitespace. */
    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\p{C}]+/u', ' ', $name);
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
