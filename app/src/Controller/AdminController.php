<?php
declare(strict_types=1);

namespace App\Controller;

use App\AccessRequest;
use App\AccountDomains;
use App\AccountLinks;
use App\AccountSecurity;
use App\AdminI18n;
use App\Audit;
use App\Auth;
use App\BookingException;
use App\BookingService;
use App\Cleanup;
use App\Csrf;
use App\Db;
use App\Email;
use App\Http;
use App\I18n;
use App\Invitation;
use App\LegalTexts;
use App\Notifier;
use App\Operator;
use App\Outbox;
use App\ParentContact;
use App\RateLimiter;
use App\Readiness;
use App\RoundCode;
use App\RoundTrash;
use App\ScheduleChange;
use App\ScheduleService;
use App\SlotGenerator;
use App\Slug;
use App\Summaries;
use App\Time;
use App\Translator;
use App\TrustedDevice;
use App\View;
use App\WhatsNew;

/**
 * Admin area (German or English, see AdminI18n).
 * Teachers see and change only their own rounds; admin accounts see and edit all
 * rounds including bookings, manage accounts and the global texts. Their changes are logged (Audit).
 * Enforced centrally in round()/day()/slot()/booking() via mayAccess().
 */
final class AdminController
{
    /**
     * Routes after signing in: method, path pattern, action and the role the route needs ('teacher' = every
     * signed-in account, 'admin' = admin accounts only). dispatch() checks the role of the matching route;
     * a route without a known role is admin-only, so a forgotten role fails closed. Login, "forgot
     * password" and invitation links work without an account and are handled in dispatch() before this table.
     */
    private const ROUTES = [
        ['GET', '#^/\z#', 'dashboard', 'teacher'],
        ['POST', '#^/logout\z#', 'logout', 'teacher'],
        ['GET', '#^/round/new\z#', 'roundForm', 'teacher'],
        ['POST', '#^/round/new\z#', 'roundSave', 'teacher'],
        ['GET', '#^/round/(\d+)\z#', 'roundShow', 'teacher'],
        ['GET', '#^/round/(\d+)/edit\z#', 'roundForm', 'teacher'],
        ['POST', '#^/round/(\d+)/edit\z#', 'roundSave', 'teacher'],
        ['POST', '#^/round/(\d+)/toggle\z#', 'roundToggle', 'teacher'],
        ['POST', '#^/round/(\d+)/duplicate\z#', 'roundDuplicate', 'teacher'],
        ['POST', '#^/round/(\d+)/delete\z#', 'roundDelete', 'teacher'],
        ['GET', '#^/trash\z#', 'trash', 'teacher'],
        ['POST', '#^/trash/(\d+)/restore\z#', 'trashRestore', 'teacher'],
        ['POST', '#^/trash/(\d+)/delete\z#', 'trashDelete', 'teacher'],
        ['POST', '#^/round/(\d+)/day\z#', 'dayAdd', 'teacher'],
        ['GET', '#^/round/(\d+)/print\z#', 'printView', 'teacher'],
        ['POST', '#^/round/(\d+)/translations\z#', 'translationsSave', 'teacher'],
        ['POST', '#^/round/(\d+)/translations/auto\z#', 'translationsAuto', 'teacher'],
        ['GET', '#^/day/(\d+)/edit\z#', 'dayForm', 'teacher'],
        ['POST', '#^/day/(\d+)/edit\z#', 'daySave', 'teacher'],
        ['POST', '#^/day/(\d+)/delete\z#', 'dayDelete', 'teacher'],
        ['GET', '#^/day/(\d+)/bookings\z#', 'dayBookingsForm', 'teacher'],
        ['POST', '#^/day/(\d+)/bookings/delete\z#', 'dayBookingsDelete', 'teacher'],
        ['POST', '#^/day/(\d+)/break\z#', 'breakAdd', 'teacher'],
        ['POST', '#^/break/(\d+)/delete\z#', 'breakDelete', 'teacher'],
        ['POST', '#^/slot/(\d+)/block\z#', 'slotToggleBlock', 'teacher'],
        ['GET', '#^/slot/(\d+)/book\z#', 'bookingNew', 'teacher'],
        ['POST', '#^/slot/(\d+)/book\z#', 'bookingCreate', 'teacher'],
        ['GET', '#^/booking/(\d+)/edit\z#', 'bookingEdit', 'teacher'],
        ['POST', '#^/booking/(\d+)/edit\z#', 'bookingUpdate', 'teacher'],
        ['POST', '#^/booking/(\d+)/delete\z#', 'bookingDelete', 'teacher'],
        ['GET', '#^/profile\z#', 'profile', 'teacher'],
        ['POST', '#^/profile\z#', 'profileSave', 'teacher'],
        ['POST', '#^/profile/summaries\z#', 'summaryNoticeSeen', 'teacher'],
        ['POST', '#^/news\z#', 'newsSeen', 'teacher'],
        ['POST', '#^/password\z#', 'passwordSave', 'teacher'],
        ['GET', '#^/password-change\z#', 'passwordChangeForm', 'teacher'],
        ['POST', '#^/password-change\z#', 'passwordChangeSave', 'teacher'],
        ['GET', '#^/users\z#', 'users', 'admin'],
        ['GET', '#^/users/new\z#', 'userForm', 'admin'],
        ['POST', '#^/users/new\z#', 'userSave', 'admin'],
        ['GET', '#^/users/(\d+)/edit\z#', 'userForm', 'admin'],
        ['POST', '#^/users/(\d+)/edit\z#', 'userSave', 'admin'],
        ['POST', '#^/users/(\d+)/reset-password\z#', 'userResetPassword', 'admin'],
        ['POST', '#^/users/(\d+)/delete\z#', 'userDelete', 'admin'],
        ['POST', '#^/users/end-sessions\z#', 'endAllSessions', 'admin'],
        ['GET', '#^/texts\z#', 'texts', 'admin'],
        ['GET', '#^/start\z#', 'readiness', 'admin'],
        ['POST', '#^/texts/operator\z#', 'operatorSave', 'admin'],
        ['POST', '#^/texts/contact\z#', 'parentContactSave', 'admin'],
        ['GET', '#^/page/(imprint|privacy)/([a-z]{2})\z#', 'pageForm', 'admin'],
        ['POST', '#^/page/(imprint|privacy)/([a-z]{2})\z#', 'pageSave', 'admin'],
        ['GET', '#^/log\z#', 'auditLog', 'admin'],
        ['GET', '#^/access-request\z#', 'accessRequestForm', 'admin'],
        ['POST', '#^/access-request\z#', 'accessRequestSearch', 'admin'],
        ['GET', '#^/templates\z#', 'templates', 'admin'],
        ['GET', '#^/templates/new\z#', 'templateForm', 'admin'],
        ['POST', '#^/templates/new\z#', 'templateSave', 'admin'],
        ['GET', '#^/templates/(\d+)/edit\z#', 'templateForm', 'admin'],
        ['POST', '#^/templates/(\d+)/edit\z#', 'templateSave', 'admin'],
        ['POST', '#^/templates/(\d+)/delete\z#', 'templateDelete', 'admin'],
        ['POST', '#^/templates/from-round/(\d+)\z#', 'templateFromRound', 'admin'],
    ];

    public function dispatch(string $path, string $method): void
    {
        // A session only for a browser that brings the session cookie: login, "forgot password" and invitation
        // work without one (stateless CSRF tokens, see formToken()), every other page sends a browser without it
        // to the login page before a session exists (signInFirst())
        Auth::startSession(false);
        header('X-Robots-Tag: noindex, nofollow');
        AdminI18n::set(AdminI18n::forRequest(Auth::check() ? Auth::user() : null));

        if ($path === '/login') {
            $method === 'POST' ? $this->loginPost() : $this->login();
            return;
        }
        // Invitation/password link and "forgot password" work without login
        if (preg_match('#^/invite/([A-Za-z0-9_-]{43})\z#', $path, $m)) {
            $method === 'POST' ? $this->inviteSave($m[1]) : $this->inviteForm($m[1]);
            return;
        }
        if ($path === '/forgot') {
            $method === 'POST' ? $this->forgotSave() : $this->forgotForm();
            return;
        }
        if (!Auth::check()) {
            $this->signInFirst($path, $method);
        }
        if ($method === 'POST' && !Csrf::checkAdmin($_POST['csrf'] ?? null)) {
            $this->flash('error', __('Das Formular ist abgelaufen. Bitte erneut versuchen.'));
            Http::redirect('/admin');
        }
        // The initial password must be replaced before anything else
        if (Auth::user()['must_change_password'] && !in_array($path, ['/password-change', '/logout'], true)) {
            Http::redirect('/admin/password-change');
        }
        foreach (self::ROUTES as $route) {
            [$verb, $pattern, $action] = $route;
            if ($verb === $method && preg_match($pattern, $path, $m)) {
                if (!self::allows($route, Auth::isAdmin())) {
                    $this->notFound();
                }
                $this->$action(...array_map(__NAMESPACE__ . '\intval_or_string', array_slice($m, 1)));
                return;
            }
        }
        http_response_code(404);
        $this->render('admin/message', ['title' => __('Nicht gefunden'), 'text' => __('Diese Seite gibt es nicht.')]);
    }

    /**
     * Not signed in: to the login page, which leads back to the page asked for (a GET, see Auth::returnPath()).
     * - Without the session cookie no session is started, so no file for scanners and no new cookie in place
     *   of a valid one. A page opened from another site, such as the link in a summary in a webmailer, comes
     *   without the cookie even in a signed-in browser, as it is SameSite=Strict: an interim page opens it
     *   again from this site (meta refresh), then the browser sends the cookie. It does so once: the address it
     *   opens carries REOPENED, so a browser that still holds the cookie back gets the login page, not a loop.
     * - With the cookie of a session that is no longer valid (expired, account deactivated, password changed)
     *   the session ends; a new one only to keep its notices (such as "session expired").
     */
    private function signInFirst(string $path, string $method): never
    {
        $target = $method === 'GET' ? Auth::returnPath('/admin' . ($path !== '/' ? $path : '') . self::queryString()) : null;
        if (session_status() !== PHP_SESSION_ACTIVE && Http::isCrossSiteGet() && !isset($_GET[self::REOPENED])) {
            $url = Http::basePath() . ($target ?? '/admin');
            $url .= (str_contains($url, '?') ? '&' : '?') . self::REOPENED . '=1';
            $this->render('admin/message', ['title' => __('Weiterleitung'), 'refresh' => $url,
                'text' => __('Die Seite öffnet sich gleich. Falls nicht, wählen Sie „Weiter“.'), 'link' => [$url, __('Weiter')]]);
            exit;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $flash = $_SESSION['flash'] ?? [];
            Auth::logout();
            if ($flash) {
                Auth::startSession();
                $_SESSION['flash'] = $flash;
            }
        }
        Http::redirect('/admin/login', ['next' => $target]);
    }

    /** Query parameter of the address the interim page opens (signInFirst()). */
    private const REOPENED = 'reopened';

    /** "?a=1&b=2" of the request (without REOPENED), or ''. */
    private static function queryString(): string
    {
        $query = implode('&', array_filter(explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')),
            static fn (string $pair): bool => $pair !== '' && explode('=', $pair, 2)[0] !== self::REOPENED));
        return $query !== '' ? '?' . $query : '';
    }

    /** To a page of the admin area (from Auth::returnPath()) or, without one, the overview. */
    private static function redirectTo(?string $target): never
    {
        $parts = parse_url($target ?? '/admin');
        parse_str((string) ($parts['query'] ?? ''), $query);
        Http::redirect((string) ($parts['path'] ?? '/admin'), $query);
    }

    /** May the signed-in account use this route? Only 'teacher' routes are open to teachers (fails closed). */
    private static function allows(array $route, bool $isAdmin): bool
    {
        return $isAdmin || ($route[3] ?? null) === 'teacher';
    }

    // ---------------------------------------------------------------- Login

    private function login(string $error = ''): void
    {
        // The page to go back to after signing in (see signInFirst())
        $next = Auth::returnPath(Http::isPost() ? Http::post('next') : Http::query('next'));
        if (Auth::check()) {
            self::redirectTo($next);
        }
        $username = (string) ($_SESSION['login_user'] ?? '');
        unset($_SESSION['login_user']);
        $this->render('admin/login', ['title' => __('Anmelden'), 'error' => $error, 'username' => $username, 'csrf' => self::formToken('login'),
            'next' => $next ?? ''], 'admin/layout');
    }

    /**
     * Stateless CSRF token for the login, "forgot password" and invitation forms (without a
     * minimum age, so that password managers can submit immediately).
     */
    private static function formToken(string $form): string
    {
        return Csrf::publicToken('admin:' . $form);
    }

    private static function checkFormToken(string $form): bool
    {
        return Csrf::checkPublic('admin:' . $form, $_POST['csrf'] ?? null, 0) === Csrf::OK;
    }

    private function loginPost(): void
    {
        if (!self::checkFormToken('login')) {
            $this->login(__('Das Formular ist abgelaufen. Bitte erneut versuchen.'));
            return;
        }
        // A new account's username is its address, with umlauts in the domain as punycode (App\Email)
        $result = Auth::attempt(Email::input(Http::post('username')), Http::postRaw('password'));
        if ($result === true) {
            self::redirectTo(Auth::returnPath(Http::post('next')));
        }
        http_response_code(401);
        $this->login((string) $result);
    }

    private function logout(): void
    {
        $lang = AdminI18n::lang();
        Auth::logout();
        Http::redirect('/admin/login', ['lang' => $lang !== AdminI18n::default() ? $lang : null]);
    }

    // ---------------------------------------------------------------- Rounds

    private function dashboard(): void
    {
        $all = Auth::isAdmin(); // admins see all rounds, teachers only their own
        $rounds = Db::all(
            'SELECT r.*, u.display_name AS owner_name, u.is_active AS owner_active,
                    (SELECT MIN(d.day_date) FROM round_days d WHERE d.round_id = r.id) AS first_day,
                    (SELECT MAX(d.day_date) FROM round_days d WHERE d.round_id = r.id) AS last_day,
                    (SELECT COUNT(*) FROM slots s JOIN round_days d ON d.id = s.day_id WHERE d.round_id = r.id AND s.is_conflict = 0) AS slot_count,
                    (SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id WHERE d.round_id = r.id) AS booking_count
               FROM rounds r JOIN users u ON u.id = r.user_id' . ($all ? '' : ' WHERE r.user_id = ?') . '
              ORDER BY r.is_active DESC, first_day IS NULL, first_day DESC, r.id DESC',
            $all ? [] : [Auth::id()]
        );
        foreach ($rounds as &$r) {
            $r['deletion_at'] = Cleanup::deletionAt($r, $r['last_day']);
        }
        unset($r);
        $legalHints = Auth::isAdmin() ? array_merge(self::operatorHints(), self::legalHints(LegalTexts::outdated())) : [];
        $this->render('admin/dashboard', [
            'trashCount' => RoundTrash::count($all ? null : (int) Auth::id()),
            'title' => $all ? __('Alle Terminrunden') : __('Meine Terminrunden'),
            'rounds' => $rounds,
            'showOwner' => $all,
            // config.php readable for every user of the server (only admins can have that fixed)
            'configExposed' => Auth::isAdmin() ? \App\Config::exposedFiles() : [],
            // Database server not supported, settings in config.php that do not work as written
            'setupHints' => Auth::isAdmin() ? array_merge(array_filter([\App\Health::serverProblem()]), \App\ConfigSchema::warnings(\App\Config::all())) : [],
            // Cron job late or never run (only admins can have it fixed)
            'cron' => Auth::isAdmin() ? \App\CronMonitor::status() : null,
            'profileHints' => $this->profileHints(),
            // Summaries without names: what changed for an account from before (until it confirms)
            'summaryNotice' => Summaries::notice(Auth::user(), Auth::isAdmin()),
            // allowed_account_domains: active accounts with an address (e-mail or reply address) that is not allowed;
            // nothing goes there (no summaries, no replies of parents)
            'foreignAddresses' => Auth::isAdmin() && AccountDomains::allowed() ? count(array_filter(
                Db::all("SELECT email, reply_to FROM users WHERE is_active = 1 AND CONCAT(COALESCE(email, ''), COALESCE(reply_to, '')) <> ''"),
                static fn (array $u): bool => array_filter([(string) $u['email'], (string) $u['reply_to']],
                    static fn (string $a): bool => $a !== '' && !AccountDomains::permits($a)) !== []
            )) : 0,
            'operatorMissing' => !Operator::ready(),
            'typeMissing' => !Auth::isAdmin() && Operator::ready() && Operator::type() === null,
            'textsOpen' => !Auth::isAdmin() && Operator::ready() && (LegalTexts::splitOpen(LegalTexts::open())[0] !== [] || LegalTexts::missing() !== [] || LegalTexts::germanEmpty()),
            'legalHints' => $legalHints,
            // Until the first round has been public: one checklist ("Bereit für den Start?") instead of the setup warnings
            'readiness' => Auth::isAdmin() && !Readiness::started() ? Readiness::checks($legalHints) : null,
            // "Was ist neu" after an update: what changed in how admins and teachers work
            'whatsNew' => WhatsNew::unseen((array) Auth::user()),
            // Offboarding: active accounts nobody has signed in to for a long time (only the number)
            'unusedAccounts' => Auth::isAdmin() ? (int) Db::value(
                'SELECT COUNT(*) FROM users WHERE is_active = 1 AND COALESCE(last_login_at, created_at) < ?',
                [self::inactiveSince()]
            ) : 0,
            // Mails that should have gone out long ago: cron job missing or mail server failing
            'mailsLate' => Auth::isAdmin() ? Outbox::late() : null,
        ]);
    }

    private function roundForm(?int $id = null, array $errors = [], ?array $data = null, ?array $dayData = null): void
    {
        $round = $id ? $this->round($id) : null;
        // New round from a template: times, breaks and settings prefilled (still editable)
        $template = null;
        if (!$round && $data === null && ctype_digit(Http::query('template'))) {
            $row = Db::one('SELECT * FROM round_templates WHERE id = ?', [(int) Http::query('template')]);
            if ($row) {
                $template = $row['name'];
                $s = self::templateSettings($row);
                $data = [
                    'title' => '', 'slug' => '', 'description' => $s['description'], 'location' => $s['location'],
                    'source_lang' => I18n::defaultLanguage(), 'is_active' => 1,
                    'booking_deadline_hours' => $s['booking_deadline_hours'], 'cancel_deadline_hours' => $s['cancel_deadline_hours'],
                    'delete_after_hours' => $s['delete_after_hours'], 'random_suffix' => 1, 'code_required' => $s['code_required'],
                    'booking_limit' => $s['booking_limit'], 'remind_parents' => $s['remind_parents'], 'ask_interpreter' => $s['ask_interpreter'], 'user_id' => Auth::id(),
                ];
                $dayData = ['dates' => [''], 'start' => $s['start'], 'end' => $s['end'], 'minutes' => $s['minutes'], 'breaks' => $s['breaks']];
            }
        }
        $fresh = $data === null || $template !== null;
        $data ??= $round ?? [
            'title' => '', 'slug' => '', 'description' => '', 'location' => '', 'source_lang' => I18n::defaultLanguage(), 'is_active' => 1,
            'booking_deadline_hours' => 24, 'cancel_deadline_hours' => 12,
            'delete_after_hours' => 24, 'random_suffix' => 1,
            'code_required' => 0, 'booking_limit' => 'round', 'remind_parents' => 1, 'ask_interpreter' => 0, 'user_id' => Auth::id(),
        ];
        // Rounds cannot go public yet (e.g. open placeholders in the legal texts): say so at the checkbox, and the round
        // starts unticked – a new one, and one whose activation was just refused – so that it can be saved and prepared
        // right away and activated later. Admins choose the teacher in the form: only what applies to every teacher.
        $blocker = null;
        if (!$round || !$round['is_active']) {
            $blocker = self::platformBlocker() ?? (Auth::isAdmin() ? null : $this->ownerBlocker((array) Auth::user()));
        }
        $refused = $blocker !== null && in_array($blocker . ' ' . self::saveInactiveHint(), $errors, true);
        if ($blocker !== null && ((!$round && $fresh) || $refused)) {
            $data['is_active'] = 0;
        }
        $this->render('admin/round_form', [
            'title' => $round ? __('Runde bearbeiten') : __('Neue Runde'),
            'round' => $round,
            'data' => $data,
            'parents' => self::parentCounts($round ? ScheduleService::concerned('round', (int) $round['id']) : []),
            'owners' => Auth::isAdmin() ? self::ownerChoices((int) ($round['user_id'] ?? 0)) : [],
            'dayData' => $dayData ?? self::defaultDayData(),
            'errors' => $errors,
            'templates' => $round ? [] : Db::all('SELECT id, name FROM round_templates ORDER BY name'),
            'template' => $template,
            'activationBlocked' => $blocker !== null ? $blocker . ' ' . self::saveInactiveHint() : null,
        ]);
    }

    private function roundSave(?int $id = null): void
    {
        $round = $id ? $this->round($id) : null;
        $deleteAfter = Http::post('delete_after_hours');
        $data = [
            'title' => Http::post('title'),
            'slug' => Http::post('slug'),
            'description' => str_replace("\r\n", "\n", Http::post('description')),
            // If the field is missing (form opened before the update), the previous value stays
            'location' => isset($_POST['location']) ? trim(Http::post('location')) : (string) ($round['location'] ?? ''),
            'source_lang' => in_array(Http::post('source_lang'), I18n::languages(), true) ? Http::post('source_lang') : I18n::defaultLanguage(),
            'is_active' => Http::post('is_active') === '1' ? 1 : 0,
            'booking_deadline_hours' => Http::post('booking_deadline_hours'),
            'cancel_deadline_hours' => Http::post('cancel_deadline_hours'),
            'delete_after_hours' => $deleteAfter,
            'random_suffix' => Http::post('random_suffix') === '1' ? 1 : 0,
            // Only take over the switch if the form knows it (otherwise keep the previous value, e.g. for
            // a form opened before the update)
            'code_required' => !$round || Http::post('code_required_field') === '1'
                ? (Http::post('code_required') === '1' ? 1 : 0)
                : (int) $round['code_required'],
            'new_code' => Http::post('new_code') === '1' ? 1 : 0,
            // Multiple bookings; if the field is missing (form from before the update), the previous value stays
            'booking_limit' => in_array(Http::post('booking_limit'), BookingService::LIMITS, true)
                ? Http::post('booking_limit') : (string) ($round['booking_limit'] ?? 'round'),
            // Reminder to parents; if the form does not know the field yet, the previous value stays
            'remind_parents' => !$round || Http::post('remind_parents_field') === '1'
                ? (Http::post('remind_parents') === '1' ? 1 : 0)
                : (int) $round['remind_parents'],
            // Request for an interpreter; likewise
            'ask_interpreter' => !$round || Http::post('ask_interpreter_field') === '1'
                ? (Http::post('ask_interpreter') === '1' ? 1 : 0)
                : (int) $round['ask_interpreter'],
            // Teacher of the round: only the admin chooses; otherwise whoever creates it or the previous teacher
            'user_id' => Auth::isAdmin() && ctype_digit(Http::post('user_id')) ? (int) Http::post('user_id') : (int) ($round['user_id'] ?? Auth::id()),
            // Tell booked parents about a new location or teacher (the box is ticked in the form)
            'notify' => Http::post('notify') === '1',
        ];
        $owner = Db::one('SELECT * FROM users WHERE id = ?', [$data['user_id']]);
        // New assignment only to active accounts; the previous teacher is kept even if deactivated
        if ($owner && !$owner['is_active'] && (int) $owner['id'] !== (int) ($round['user_id'] ?? 0)) {
            $owner = null;
        }

        $errors = [];
        if (mb_strlen($data['title']) < 1 || mb_strlen($data['title']) > 200) {
            $errors[] = __('Bitte einen Titel (max. 200 Zeichen) angeben.');
        }
        if (mb_strlen($data['description']) > 5000) {
            $errors[] = __('Der Beschreibungstext ist zu lang (max. 5000 Zeichen).');
        }
        if (mb_strlen($data['location']) > 200) {
            $errors[] = __('Ort: höchstens 200 Zeichen.');
        }
        foreach ([
            'booking_deadline_hours' => __('Buchungsfrist: ganze Zahl zwischen 0 und 720 Stunden.'),
            'cancel_deadline_hours' => __('Änderungsfrist: ganze Zahl zwischen 0 und 720 Stunden.'),
        ] as $field => $error) {
            if (!ctype_digit((string) $data[$field]) || (int) $data[$field] > 720) {
                $errors[] = $error;
            }
        }
        if ($deleteAfter !== '' && (!ctype_digit($deleteAfter) || (int) $deleteAfter > 8760)) {
            $errors[] = __('Löschfrist: leer lassen (nie löschen) oder 0–8760 Stunden.');
        }
        // Block publishing only if the round would (newly) become public as a result: when
        // activating it or reassigning an active round – not on every change to it
        $publishing = $owner && $data['is_active'] && (!$round || !$round['is_active'] || (int) $owner['id'] !== (int) $round['user_id']);
        if (!$owner) {
            $errors[] = __('Bitte eine aktive Lehrkraft für die Runde wählen.');
        } elseif ($publishing && ($hint = $this->publishBlocker($owner)) !== null) {
            // A round that is online stays so with its previous teacher; any other can be saved inactive meanwhile
            $errors[] = $hint . ' ' . ($round && $round['is_active'] ? __('Behalten Sie bis dahin die bisherige Lehrkraft, dann bleibt die Runde aktiv.') : self::saveInactiveHint());
        }
        // New round: create the appointment days right away
        $dayData = null;
        if (!$round) {
            [$dayData, $dayErrors] = $this->daysInput(null);
            $errors = array_merge($errors, $dayErrors);
        }
        if ($errors) {
            $this->roundForm($id, $errors, $data, $dayData);
            return;
        }

        // Slug: for new rounds from the title, when editing from the field.
        // An unchanged slug is kept exactly so that links already sent remain valid.
        if ($round && !$data['random_suffix'] && ($data['slug'] === '' || $data['slug'] === $round['slug'])) {
            $slug = $round['slug'];
        } else {
            $base = $round && $data['slug'] !== ''
                ? Slug::fromText($data['slug'], Slug::MAX_LENGTH - Slug::SUFFIX_RESERVE)
                : Slug::fromText($data['title']);
            if ($data['random_suffix']) {
                $base = Slug::withSuffix($base);
            }
            $slug = Slug::unique($base, $id);
        }

        $now = Time::nowSql();
        $values = [
            $data['title'], $slug, $data['description'] !== '' ? $data['description'] : null,
            $data['location'] !== '' ? $data['location'] : null, $data['source_lang'],
            $data['code_required'], $data['booking_limit'], $data['remind_parents'], $data['ask_interpreter'], $data['is_active'],
            (int) $data['booking_deadline_hours'], (int) $data['cancel_deadline_hours'],
            $deleteAfter === '' ? null : (int) $deleteAfter, $now,
        ];
        if ($round) {
            // Parents whose appointment gets a new location or teacher are told (ScheduleService)
            $parents = ScheduleService::changeRound($id, $data['notify'], static fn () => Db::run(
                'UPDATE rounds SET title = ?, slug = ?, description = ?, location = ?, source_lang = ?, code_required = ?, booking_limit = ?, remind_parents = ?, ask_interpreter = ?, is_active = ?, booking_deadline_hours = ?,
                        cancel_deadline_hours = ?, delete_after_hours = ?, updated_at = ?,
                        expired_at = IF(? = 1, NULL, expired_at), was_used = GREATEST(was_used, ?), user_id = ?
                  WHERE id = ? AND user_id = ?',
                [...$values, $data['is_active'], $data['is_active'], (int) $owner['id'], $id, (int) $round['user_id']]
            ));
            // New address = old invitations should no longer work, so a new round code as well
            $newCode = $data['new_code'] || $slug !== $round['slug'];
            if ($newCode) {
                Db::run('UPDATE rounds SET code = ? WHERE id = ?', [RoundCode::unique(), $id]);
            }
            $this->flash('success', $newCode ? __('Runde gespeichert, neuer Rundencode erzeugt (der bisherige gilt nicht mehr).') : __('Runde gespeichert.'));
            $ownerChanged = (int) $owner['id'] !== (int) $round['user_id'];
            $this->audit(fn () => __('bearbeitet')
                . ($round['title'] !== $data['title'] ? ' (' . __('bisher „{title}“', ['title' => $round['title']]) . ')' : '')
                . ((int) $round['is_active'] !== $data['is_active'] ? ', ' . ($data['is_active'] ? __('aktiviert') : __('deaktiviert')) : '')
                . ($ownerChanged ? ', ' . __('Lehrkraft bisher {account}', ['account' => self::accountLabel($this->userById((int) $round['user_id']))]) : '')
                . ($newCode ? ', ' . __('neuer Rundencode') : '') . self::parentsAudit($parents), $id);
            if ($ownerChanged) {
                $booked = (int) Db::value('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id WHERE d.round_id = ?', [$id]);
                $this->flash('info', __('Die Runde gehört jetzt {name}.', ['name' => $owner['display_name'] ?: $owner['username']])
                    . ($booked ? ' ' . __('Die {count} bestehende(n) Buchung(en) sieht ab jetzt diese Lehrkraft; Zusammenfassungen gehen an sie.', ['count' => $booked]) : ''));
            }
            // Told: parents whose teacher or location changed (days with a location of their own keep it)
            $this->parentsFlash($parents, true);
            if ($parents->movedConflicts > 0) {
                $this->flash('warning', __('Achtung: {count} gebuchte(r) Termin(e) sind als Konflikt markiert und haben jetzt einen anderen Ort oder eine andere Lehrkraft; die Eltern kennen die neuen Daten noch nicht.', ['count' => $parents->movedConflicts])
                    . ' ' . self::conflictHint());
            }
        } else {
            // Round and days together: if creating the days fails, no empty round is left behind
            $ownerId = (int) $owner['id'];
            $used = $data['is_active'];
            [$id, $count] = Db::transaction(function () use ($values, $now, $dayData, $ownerId, $used): array {
                $id = Db::insert(
                    'INSERT INTO rounds (title, slug, description, location, source_lang, code_required, booking_limit, remind_parents, ask_interpreter, is_active,
                                         booking_deadline_hours, cancel_deadline_hours, delete_after_hours, updated_at, created_at, user_id, code, was_used)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$values, $now, $ownerId, RoundCode::unique(), $used]
                );
                return [$id, $this->createDays($id, $dayData)];
            });
            $this->flash('success', __('Runde angelegt: {days} Tag(e), {slots} Termine erzeugt.', ['days' => count($dayData['dates']), 'slots' => $count])
                . ($data['is_active'] ? '' : ' ' . __('Sie ist noch inaktiv: Eltern können erst buchen, wenn Sie sie hier mit „Aktivieren“ freischalten.')));
            $this->audit(fn () => __('angelegt ({dates})', ['dates' => implode(', ', array_map(static fn ($d) => self::when($d), $dayData['dates']))])
                . ($data['is_active'] ? ', ' . __('aktiv') : ''), $id);
        }
        Http::redirect('/admin/round/' . $id);
    }

    private function roundShow(int $id, array $addDayErrors = [], ?array $addDayData = null): void
    {
        $round = $this->round($id);
        $owner = $this->userById((int) $round['user_id']);
        if ((string) ($round['code'] ?? '') === '') {
            RoundCode::backfill();
            $round = $this->round($id);
        }
        $days = Db::all('SELECT * FROM round_days WHERE round_id = ? ORDER BY day_date, start_time', [$id]);
        $conflicts = 0;
        foreach ($days as &$day) {
            $day['breaks'] = Db::all('SELECT * FROM day_breaks WHERE day_id = ? ORDER BY start_time', [$day['id']]);
            // mail_waiting: a mail that failed and is retried (not one queued since, e.g. the reminder)
            $day['slots'] = Db::all(
                'SELECT s.*, b.id AS booking_id, b.name, b.email, b.interpreter_lang, b.source, b.notified_at, b.created_at AS booked_at, b.updated_at AS booking_updated,
                        b.mail_failed_at, (SELECT COUNT(*) FROM mail_outbox o WHERE o.booking_id = b.id AND o.attempts > 0) AS mail_waiting
                   FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
                  WHERE s.day_id = ? ORDER BY s.start_time',
                [$day['id']]
            );
            // For the delete dialogs: is the appointment ahead, and can its parents be told by mail?
            $upcoming = [];
            foreach ($day['slots'] as &$s) {
                $conflicts += (int) $s['is_conflict'];
                $s['upcoming'] = $s['booking_id'] !== null && ScheduleService::upcoming(['day_date' => $day['day_date']] + $s);
                $s['mailable'] = $s['upcoming'] && ScheduleService::mailable($s);
                if ($s['upcoming']) {
                    $upcoming[] = $s;
                }
            }
            unset($s);
            $day['parents'] = self::parentCounts($upcoming);
        }
        unset($day);
        $this->render('admin/round', [
            'title' => $round['title'],
            'round' => $round,
            'days' => $days,
            'parents' => ['upcoming' => array_sum(array_column(array_column($days, 'parents'), 'upcoming')), 'mailable' => array_sum(array_column(array_column($days, 'parents'), 'mailable'))],
            'conflicts' => $conflicts,
            'deletion' => Cleanup::deletionAt($round, $days ? (string) max(array_column($days, 'day_date')) : null),
            'publicUrl' => abs_url('/' . $round['slug']),
            'homeUrl' => abs_url('/'),
            'invitation' => Invitation::text($round, $owner),
            'owner' => $owner,
            'showOwner' => Auth::isAdmin(),
            'addDayData' => $addDayData ?? self::defaultDayData(),
            'addDayErrors' => $addDayErrors,
            'translations' => self::translationRows($round),
            'autoTranslate' => [
                'url' => url('/admin/round/' . $id . '/translations/auto'),
                'source' => $round['source_lang'],
                'hash' => Translator::sourceHash($round),
                'title' => $round['title'],
                'description' => (string) ($round['description'] ?? ''),
                'pending' => Translator::pendingLanguages($round),
                'all' => Translator::targetLanguages($round),
            ],
        ]);
    }

    /** Translations per target language for the round page (with status). */
    private static function translationRows(array $round): array
    {
        $hash = Translator::sourceHash($round);
        $existing = Translator::forRound((int) $round['id']);
        $rows = [];
        foreach (I18n::languages() as $lang) {
            if ($lang === $round['source_lang']) {
                continue;
            }
            $t = $existing[$lang] ?? null;
            $rows[$lang] = [
                'title' => $t['title'] ?? '',
                'description' => (string) ($t['description'] ?? ''),
                'status' => !$t ? 'missing' : ($t['source_hash'] !== $hash ? 'outdated' : (trim((string) $t['title']) === '' ? 'none' : ($t['is_manual'] ? 'manual' : 'auto'))),
                'seen' => self::translationFingerprint($t),
            ];
        }
        return $rows;
    }

    /** Marker of a stored translation, to detect stale forms ('' = none). */
    private static function translationFingerprint(?array $t): string
    {
        return $t ? hash('sha256', $t['title'] . "\0" . ($t['description'] ?? '') . "\0" . $t['source_hash']) : '';
    }

    // ---------------------------------------------------------------- Translations

    /**
     * Receives the translations created in the teacher's browser (fetch from app.js,
     * JSON response). They are only saved if they belong to the current original; without force
     * only missing or stale languages.
     */
    private function translationsAuto(int $id): void
    {
        $round = $this->round($id);
        $titles = is_array($_POST['t_title'] ?? null) ? $_POST['t_title'] : [];
        $descs = is_array($_POST['t_description'] ?? null) ? $_POST['t_description'] : [];
        $texts = [];
        foreach (Translator::targetLanguages($round) as $lang) {
            if (is_string($titles[$lang] ?? null)) {
                $texts[$lang] = ['title' => $titles[$lang], 'description' => is_string($descs[$lang] ?? null) ? $descs[$lang] : ''];
            }
        }
        $saved = Translator::acceptAuto($round, Http::post('hash'), $texts, Http::post('force') === '1');
        header('Content-Type: application/json; charset=utf-8');
        if ($saved === null) {
            http_response_code(409);
            echo json_encode(['error' => 'outdated']);
            return;
        }
        // app.js immediately puts the saved values into the "Übersetzungen" form (no reload)
        $existing = Translator::forRound($id);
        $rows = [];
        foreach ($saved as $lang) {
            $t = $existing[$lang];
            $rows[$lang] = [
                'title' => (string) $t['title'],
                'description' => (string) ($t['description'] ?? ''),
                'seen' => self::translationFingerprint($t),
            ];
        }
        echo json_encode(['saved' => count($saved), 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Save translations by hand (they count as manual until the original changes).
     * Only what the teacher changed is saved: the form sends all languages,
     * an unchanged stale translation stays stale unless it is explicitly confirmed as
     * still fitting (t_confirm). If a language was changed elsewhere since the form
     * was displayed (t_seen no longer matches, e.g. a second window),
     * it is left untouched.
     */
    private function translationsSave(int $id): void
    {
        $round = $this->round($id);
        $hash = Translator::sourceHash($round);
        $titles = is_array($_POST['t_title'] ?? null) ? $_POST['t_title'] : [];
        $descs = is_array($_POST['t_description'] ?? null) ? $_POST['t_description'] : [];
        $confirmed = is_array($_POST['t_confirm'] ?? null) ? $_POST['t_confirm'] : [];
        $seen = is_array($_POST['t_seen'] ?? null) ? $_POST['t_seen'] : [];
        $existing = Translator::forRound($id);
        $saved = 0;
        $removed = 0;
        $conflicts = [];
        foreach (I18n::languages() as $lang) {
            if ($lang === $round['source_lang'] || !isset($titles[$lang]) || !is_string($titles[$lang])) {
                continue;
            }
            // Compare with the untruncated text; truncation only happens when saving
            $title = trim($titles[$lang]);
            $desc = trim(str_replace("\r\n", "\n", is_string($descs[$lang] ?? null) ? $descs[$lang] : ''));
            $current = $existing[$lang] ?? null;
            if (isset($seen[$lang]) && $seen[$lang] !== self::translationFingerprint($current)) {
                $conflicts[] = $lang; // changed elsewhere in the meantime: don't overwrite anything
                continue;
            }
            if ($title === '') {
                // empty = no translation, parents see the original. Deliberately saved as empty
                // (current, by hand) so that the browser translation does not fill it in again.
                if ($current && trim((string) $current['title']) !== '') {
                    Translator::save($id, $lang, '', '', $hash, true);
                    $removed++;
                }
                continue;
            }
            $changed = !$current
                || trim((string) $current['title']) !== $title
                || trim((string) $current['description']) !== $desc;
            $confirm = $current && ($confirmed[$lang] ?? '') === '1' && $current['source_hash'] !== $hash;
            if (!$changed && !$confirm) {
                continue; // unchanged (a stale translation also stays stale)
            }
            Translator::save($id, $lang, $title, $desc, $hash, true);
            $saved++;
        }
        // A closure, so that the log gets its own language (see audit())
        $parts = static fn (): string => implode(', ', array_filter([
            $saved ? __('{count} Übersetzung(en) gespeichert', ['count' => $saved]) : '',
            $removed ? __('{count} entfernt', ['count' => $removed]) : '',
        ]));
        if ($saved || $removed) {
            $this->audit(fn () => __('Übersetzungen bearbeitet ({changes})', ['changes' => $parts()]), $id);
        }
        $this->flash('success', $saved || $removed ? $parts() . '.' : __('Keine Änderungen.'));
        if ($conflicts) {
            $this->flash('warning', __('Nicht gespeichert, weil inzwischen anderweitig geändert (z. B. in einem anderen Fenster): {languages}. Bitte die aktuellen Texte prüfen und erneut speichern.',
                ['languages' => implode(', ', array_map([I18n::class, 'name'], $conflicts))]));
        }
        Http::redirect('/admin/round/' . $id, [], 'translations');
    }

    private function roundToggle(int $id): void
    {
        $round = $this->round($id);
        $active = $round['is_active'] ? 0 : 1;
        if ($active && ($hint = $this->publishBlocker($this->userById((int) $round['user_id']))) !== null) {
            $this->flash('error', $hint);
            Http::redirect('/admin/round/' . $id);
        }
        Db::run('UPDATE rounds SET is_active = ?, expired_at = IF(? = 1, NULL, expired_at), was_used = GREATEST(was_used, ?), updated_at = ? WHERE id = ?', [$active, $active, $active, Time::nowSql(), $id]);
        $this->audit(fn () => $active ? __('aktiviert') : __('deaktiviert'), $id);
        $this->flash('success', $active ? __('Runde aktiviert – der Link ist jetzt erreichbar.') : __('Runde deaktiviert – keine neuen Buchungen mehr; Eltern können über ihren Link weiterhin absagen.'));
        Http::redirect('/admin/round/' . $id);
    }

    private function roundDuplicate(int $id): void
    {
        $round = $this->round($id);
        // Optional: new date of the first day; all days keep their distance to it
        $firstDate = Http::post('first_date');
        $shift = 0;
        if ($firstDate !== '') {
            $oldFirst = Db::value('SELECT MIN(day_date) FROM round_days WHERE round_id = ?', [$id]);
            if (!Time::isDate($firstDate) || $oldFirst === null) {
                $this->flash('error', $oldFirst === null ? __('Die Runde hat keine Tage, die verschoben werden könnten.') : __('Bitte ein gültiges Datum für den ersten Tag angeben.'));
                Http::redirect('/admin/round/' . $id);
            }
            $shift = (int) (new \DateTimeImmutable((string) $oldFirst))->diff(new \DateTimeImmutable($firstDate))->format('%r%a');
        }
        $newId = Db::transaction(function () use ($round, $shift): int {
            $now = Time::nowSql();
            $title = rtrim(mb_substr(__('{title} (Kopie)', ['title' => $round['title']]), 0, 200));
            $newId = Db::insert(
                'INSERT INTO rounds (title, slug, description, location, source_lang, code_required, booking_limit, remind_parents, ask_interpreter, is_active,
                                     booking_deadline_hours, cancel_deadline_hours, delete_after_hours, created_at, updated_at, user_id, code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)',
                [$title, Slug::unique(Slug::withSuffix(Slug::fromText($title))), $round['description'], $round['location'], $round['source_lang'],
                 (int) $round['code_required'], (string) $round['booking_limit'], (int) $round['remind_parents'], (int) $round['ask_interpreter'],
                 $round['booking_deadline_hours'], $round['cancel_deadline_hours'],
                 $round['delete_after_hours'], $now, $now, (int) $round['user_id'], RoundCode::unique()]
            );
            // Carry over translations. Those matching the original get the same suffix as the
            // title (unless it was truncated) and stay current, including ones corrected
            // by hand. Stale ones are copied unchanged so that their hash still matches
            // their text; the browser fills them in afterwards on the round page.
            $suffix = mb_substr($title, mb_strlen($round['title']));
            $oldHash = Translator::sourceHash($round);
            $newHash = Translator::sourceHash(['title' => $title] + $round);
            foreach (Translator::forRound((int) $round['id']) as $lang => $t) {
                $current = $t['source_hash'] === $oldHash;
                Translator::save(
                    $newId, $lang, $current && trim((string) $t['title']) !== '' ? $t['title'] . $suffix : $t['title'], (string) $t['description'],
                    $current ? $newHash : $t['source_hash'], (bool) $t['is_manual']
                );
            }
            foreach (Db::all('SELECT * FROM round_days WHERE round_id = ?', [$round['id']]) as $day) {
                $dayId = Db::insert(
                    'INSERT INTO round_days (round_id, day_date, start_time, end_time, slot_minutes, location) VALUES (?, ?, ?, ?, ?, ?)',
                    [$newId, (new \DateTimeImmutable((string) $day['day_date']))->modify(sprintf('%+d days', $shift))->format('Y-m-d'),
                     $day['start_time'], $day['end_time'], $day['slot_minutes'], $day['location']]
                );
                Db::run(
                    'INSERT INTO day_breaks (day_id, start_time, end_time, label) SELECT ?, start_time, end_time, label FROM day_breaks WHERE day_id = ?',
                    [$dayId, $day['id']]
                );
                Db::run(
                    'INSERT INTO slots (day_id, start_time, end_time, is_blocked)
                     SELECT ?, start_time, end_time, is_blocked FROM slots WHERE day_id = ? AND is_conflict = 0',
                    [$dayId, $day['id']]
                );
            }
            return $newId;
        });
        $this->audit(fn () => $firstDate !== '' ? __('dupliziert mit erstem Tag am {date}', ['date' => self::when($firstDate)]) : __('dupliziert'), $id);
        $this->flash('success', $firstDate !== ''
            ? __('Runde dupliziert (ohne Buchungen, inaktiv), erster Tag am {date}. Bitte den Titel prüfen.', ['date' => fmt_date($firstDate)])
            : __('Runde dupliziert (ohne Buchungen, inaktiv). Bitte Titel und Daten anpassen.'));
        Http::redirect('/admin/round/' . $newId);
    }

    private function roundDelete(int $id): void
    {
        $this->round($id);
        $booked = (int) Db::value('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id WHERE d.round_id = ?', [$id]);
        // As with a day: bookings are only deleted with the box ticked (the dialog alone needs JavaScript)
        if ($booked > 0 && Http::post('confirm') !== '1') {
            $this->flash('error', __('In dieser Runde gibt es {count} Buchung(en). Bitte das Löschen ausdrücklich bestätigen.', ['count' => $booked]));
            Http::redirect('/admin/round/' . $id, [], 'delete-round');
        }
        $label = self::roundLabel($id); // for the log: the round is gone afterwards
        // Without bookings: into the trash, restorable for a while. Booked in the meantime: ask again
        if ($booked === 0) {
            if (RoundTrash::put($id) === null) {
                $this->flash('error', __('Inzwischen hat sich an dieser Runde etwas geändert (z. B. eine neue Buchung). Bitte prüfen und erneut löschen.'));
                Http::redirect('/admin/round/' . $id, [], 'delete-round');
            }
            $this->audit(fn () => __('in den Papierkorb gelegt'), $label);
            $this->flash('success', __('Runde in den Papierkorb gelegt. Sie lässt sich {days} Tage lang wiederherstellen (Link „Papierkorb“ oben auf dieser Seite).', ['days' => RoundTrash::KEEP_DAYS]));
            Http::redirect('/admin');
        }
        $parents = ScheduleService::cancelRound($id, Http::post('notify') === '1');
        $this->audit(fn () => __('gelöscht (mit {count} Buchung(en))', ['count' => $booked]) . self::parentsAudit($parents), $label);
        $this->flash('success', __('Runde mit allen Tagen und Buchungen gelöscht.'));
        $this->parentsFlash($parents);
        Http::redirect('/admin');
    }

    // ---------------------------------------------------------------- Trash (rounds without bookings)

    private function trash(): void
    {
        $this->render('admin/trash', ['title' => __('Papierkorb'), 'rows' => RoundTrash::entries(Auth::isAdmin() ? null : (int) Auth::id()),
            'showOwner' => Auth::isAdmin(), 'days' => RoundTrash::KEEP_DAYS]);
    }

    /** An entry of the trash the signed-in account may act on, else 404 (own entries; admins all). */
    private function trashEntry(int $id): array
    {
        $entry = RoundTrash::find($id);
        return $entry && self::mayAccess((int) $entry['user_id']) ? $entry : $this->notFound();
    }

    private function trashRestore(int $id): void
    {
        $entry = $this->trashEntry($id);
        // As when activating: a round only goes public if what that requires is (still) there
        $blocker = $this->publishBlocker($this->userById((int) $entry['user_id']));
        $restored = RoundTrash::restore($id, $blocker === null);
        if ($restored === null) {
            $this->flash('error', __('Dieser Eintrag ist nicht mehr im Papierkorb.'));
            Http::redirect('/admin/trash');
        }
        $this->audit(fn () => __('aus dem Papierkorb wiederhergestellt'), $restored['id']);
        $this->flash('success', $restored['same_address']
            ? __('Runde wiederhergestellt, mit ihrer bisherigen Adresse und ihrem Rundencode. War sie aktiv, ist sie wieder buchbar.')
            : __('Runde wiederhergestellt. Ihre bisherige Adresse oder ihr Rundencode war vergeben, deshalb hat sie neue: Schon verteilte Links oder Codes führen nicht mehr zu dieser Runde, bitte geben Sie die neuen weiter.'));
        if ($restored['deactivated']) {
            $this->flash('error', __('Die Runde bleibt deaktiviert: {hint}', ['hint' => (string) $blocker]));
        }
        Http::redirect('/admin/round/' . $restored['id']);
    }

    private function trashDelete(int $id): void
    {
        $entry = $this->trashEntry($id);
        RoundTrash::remove($id);
        $owner = Db::one("SELECT COALESCE(NULLIF(display_name, ''), username) AS owner FROM users WHERE id = ?", [$entry['user_id']]);
        $this->audit(fn () => __('aus dem Papierkorb endgültig gelöscht'), ['title' => $entry['title'], 'owner' => $owner['owner'] ?? '?']);
        $this->flash('success', __('Runde endgültig gelöscht.'));
        Http::redirect('/admin/trash');
    }

    // ---------------------------------------------------------------- Days & breaks

    private function dayAdd(int $roundId): void
    {
        $round = $this->round($roundId);
        [$dayData, $errors] = $this->daysInput($roundId);
        if ($errors) {
            http_response_code(422);
            $this->roundShow($roundId, $errors, $dayData);
            return;
        }
        $count = $this->createDays($roundId, $dayData);
        if ($round['expired_at'] !== null && max($dayData['dates']) >= Time::today()) {
            Db::run('UPDATE rounds SET expired_at = NULL WHERE id = ?', [$roundId]);
        }
        $this->audit(fn () => __('Tag(e) hinzugefügt: {dates}', ['dates' => implode(', ', array_map(static fn ($d) => self::when($d), $dayData['dates']))]), $roundId);
        $this->flash('success', __('{days} Tag(e) angelegt: {slots} Termine erzeugt.', ['days' => count($dayData['dates']), 'slots' => $count]));
        Http::redirect('/admin/round/' . $roundId);
    }

    private static function defaultDayData(): array
    {
        return ['dates' => [''], 'start' => '14:00', 'end' => '18:00', 'minutes' => 15, 'breaks' => []];
    }

    /**
     * Reads date(s), times, appointment duration and breaks from the form.
     * @return array{0:array,1:list<string>} [dayData, errors]
     */
    private function daysInput(?int $roundId): array
    {
        $arr = static fn (string $k): array => array_values(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            is_array($_POST[$k] ?? null) ? $_POST[$k] : []
        ));
        $errors = [];
        $dates = array_values(array_unique(array_filter($arr('day_dates'), static fn ($d) => $d !== '')));
        sort($dates);
        $start = Time::normalizeTime(Http::post('start_time'));
        $end = Time::normalizeTime(Http::post('end_time'));
        $minutes = Http::post('slot_minutes');

        if (!$dates) {
            $errors[] = __('Bitte mindestens ein Datum angeben.');
        } elseif (count($dates) > 30) {
            $errors[] = __('Höchstens 30 Tage auf einmal.');
        }
        foreach ($dates as $d) {
            if (!Time::isDate($d)) {
                $errors[] = __('Ungültiges Datum: {date}', ['date' => $d]);
            } elseif ($roundId !== null && $this->dateTaken($roundId, $d)) {
                $errors[] = __('Den {date} gibt es in dieser Runde bereits – bitte den vorhandenen Tag bearbeiten.', ['date' => fmt_date($d)]);
            }
        }
        $errors = array_merge($errors, self::timeErrors($start, $end, $minutes));
        [$breaks, $breakErrors] = self::breaksInput();
        $errors = array_merge($errors, $breakErrors);

        $dayData = [
            'dates' => $dates ?: [''],
            'start' => $start ? fmt_time($start) : Http::post('start_time'),
            'end' => $end ? fmt_time($end) : Http::post('end_time'),
            'minutes' => (int) $minutes,
            'breaks' => array_map(static fn ($b) => [fmt_time($b[0]), fmt_time($b[1]), $b[2]], $breaks),
            'start_sql' => $start, 'end_sql' => $end, 'breaks_sql' => $breaks,
        ];
        if (!$errors && !SlotGenerator::compute($start, $end, (int) $minutes, array_map(
            static fn ($b) => ['start_time' => $b[0], 'end_time' => $b[1]], $breaks
        ))) {
            $errors[] = self::noSlots();
        }
        return [$dayData, $errors];
    }

    private static function noSlots(): string
    {
        return __('Mit diesen Zeiten und Pausen entsteht kein einziger Termin.');
    }

    /**
     * Breaks from the form (break_start[], break_end[], break_label[]); empty rows are skipped.
     * @return array{0:list<array{0:string,1:string,2:string}>,1:list<string>} [breaks as [start, end, label], errors]
     */
    private static function breaksInput(): array
    {
        $arr = static fn (string $k): array => array_values(array_map(
            static fn ($v) => is_string($v) ? trim($v) : '',
            is_array($_POST[$k] ?? null) ? $_POST[$k] : []
        ));
        $be = $arr('break_end');
        $bl = $arr('break_label');
        $breaks = [];
        $errors = [];
        foreach ($arr('break_start') as $i => $s) {
            $e = $be[$i] ?? '';
            if ($s === '' && $e === '') {
                continue;
            }
            $ns = Time::normalizeTime($s);
            $ne = Time::normalizeTime($e);
            if (!$ns || !$ne || $ns >= $ne) {
                $errors[] = __('Pause {n}: Bitte Start- und Endzeit angeben (Start vor Ende).', ['n' => $i + 1]);
                continue;
            }
            $breaks[] = [$ns, $ne, mb_substr($bl[$i] ?? '', 0, 100)];
        }
        return [$breaks, $errors];
    }

    /** Shared check of time range and appointment duration (create and edit). */
    private static function timeErrors(?string $start, ?string $end, string $minutes): array
    {
        $errors = [];
        if (!$start || !$end || $start >= $end) {
            $errors[] = __('Bitte gültige Zeiten angeben (erster Termin vor dem Ende, z. B. 14:00 bis 18:00).');
        }
        if (!ctype_digit($minutes) || (int) $minutes < 5 || (int) $minutes > 240) {
            $errors[] = __('Dauer pro Termin: 5 bis 240 Minuten.');
        }
        return $errors;
    }

    /** Does the round already have this date (optionally excluding the day $exceptDayId)? */
    private function dateTaken(int $roundId, string $date, int $exceptDayId = 0): bool
    {
        return Db::value('SELECT id FROM round_days WHERE round_id = ? AND day_date = ? AND id <> ?', [$roundId, $date, $exceptDayId]) !== null;
    }

    /** Creates the days including breaks and generates the slots. @return int number of slots */
    private function createDays(int $roundId, array $dayData): int
    {
        return (int) Db::transaction(function () use ($roundId, $dayData): int {
            $count = 0;
            foreach ($dayData['dates'] as $date) {
                $dayId = Db::insert(
                    'INSERT INTO round_days (round_id, day_date, start_time, end_time, slot_minutes) VALUES (?, ?, ?, ?, ?)',
                    [$roundId, $date, $dayData['start_sql'], $dayData['end_sql'], $dayData['minutes']]
                );
                foreach ($dayData['breaks_sql'] as [$bs, $be, $bl]) {
                    Db::run('INSERT INTO day_breaks (day_id, start_time, end_time, label) VALUES (?, ?, ?, ?)', [$dayId, $bs, $be, $bl !== '' ? $bl : null]);
                }
                SlotGenerator::sync($dayId);
                $count += (int) Db::value('SELECT COUNT(*) FROM slots WHERE day_id = ?', [$dayId]);
            }
            return $count;
        });
    }

    /** $notify: the box "Eltern per E-Mail informieren" (ticked, unless it was unticked when the form was sent with an error). */
    private function dayForm(int $id, array $errors = [], bool $notify = true): void
    {
        $day = $this->day($id);
        $this->render('admin/day_form', [
            'title' => __('Tag bearbeiten'),
            'day' => $day,
            'round' => $this->round((int) $day['round_id']),
            'parents' => self::parentCounts(ScheduleService::concerned('day', $id)),
            'errors' => $errors,
            'notify' => $notify,
        ]);
    }

    private function daySave(int $id): void
    {
        $day = $this->day($id);
        [$in, $errors] = $this->dayInput();
        if (!$errors && $this->dateTaken((int) $day['round_id'], $in['date'], $id)) {
            $errors[] = __('Diesen Tag gibt es in der Runde bereits.');
        }
        if (!$errors) {
            $breaks = Db::all('SELECT start_time, end_time FROM day_breaks WHERE day_id = ?', [$id]);
            if (!SlotGenerator::compute($in['start'], $in['end'], $in['minutes'], $breaks)) {
                $errors[] = self::noSlots();
            }
        }
        if ($errors) {
            $this->dayForm($id, $errors, Http::post('notify') === '1');
            return;
        }
        // New date, times or location; the slots are recalculated, parents with a new appointment are told
        $parents = ScheduleService::changeDay($id, $in, Http::post('notify') === '1');
        // As with "Tag hinzufügen": a day from today on lifts the expiry (otherwise the round including
        // its bookings would be deleted after the deletion period)
        if ($in['date'] >= Time::today()) {
            Db::run('UPDATE rounds SET expired_at = NULL WHERE id = ? AND expired_at IS NOT NULL', [$day['round_id']]);
        }
        $this->audit(fn () => ($in['date'] !== $day['day_date']
            ? __('Tag {date} bearbeitet (jetzt {new})', ['date' => self::when($day['day_date']), 'new' => self::when($in['date'])])
            : __('Tag {date} bearbeitet', ['date' => self::when($day['day_date'])])) . self::parentsAudit($parents), (int) $day['round_id']);
        $this->afterSync((int) $day['round_id'], $id, __('Tag gespeichert.'), $parents);
    }

    private function dayDelete(int $id): void
    {
        $day = $this->day($id);
        $bookings = $this->dayBookings($id);
        if ($bookings > 0 && Http::post('confirm') !== '1') {
            $this->flash('error', __('An diesem Tag gibt es {count} Buchung(en). Bitte das Löschen ausdrücklich bestätigen.', ['count' => $bookings]));
            Http::redirect('/admin/round/' . $day['round_id'], [], 'day-' . $id);
        }
        $parents = ScheduleService::deleteDay($id, Http::post('notify') === '1');
        $this->audit(fn () => ($bookings
            ? __('Tag {date} gelöscht (mit {count} Buchung(en))', ['date' => self::when($day['day_date']), 'count' => $bookings])
            : __('Tag {date} gelöscht', ['date' => self::when($day['day_date'])])) . self::parentsAudit($parents), (int) $day['round_id']);
        $this->flash('success', $bookings ? __('Tag gelöscht (inkl. {count} Buchung(en)).', ['count' => $bookings]) : __('Tag gelöscht.'));
        $this->parentsFlash($parents);
        Http::redirect('/admin/round/' . $day['round_id']);
    }

    /**
     * Deleting several bookings of a day at once (expert review P1-7, after slots were taken with made-up names):
     * the bookings of the appointments within ?from–?to (without them all of the day, conflicts outside its
     * times too), each with a box, so the teacher keeps the genuine ones: ticked, except the teacher's own
     * entries. Nothing is deleted here (GET); dayBookingsDelete() deletes the ticked ones.
     */
    private function dayBookingsForm(int $id): void
    {
        $day = $this->day($id);
        $round = $this->round((int) $day['round_id']);
        $span = Http::query('from') === '' && Http::query('to') === '';
        $from = $span ? (string) $day['start_time'] : Time::normalizeTime(Http::query('from'));
        $to = $span ? (string) $day['end_time'] : Time::normalizeTime(Http::query('to'));
        $valid = $from && $to && $from < $to;
        $bookings = match (true) {
            !$valid => [],
            $span => Db::all(BookingService::DETAIL_SQL . ' WHERE d.id = ? ORDER BY s.start_time, b.id', [$id]),
            default => Db::all(
                BookingService::DETAIL_SQL . ' WHERE d.id = ? AND s.start_time >= ? AND s.end_time <= ? ORDER BY s.start_time, b.id',
                [$id, $from, $to]
            ),
        };
        foreach ($bookings as &$b) {
            $b['mailable'] = ScheduleService::upcoming($b) && ScheduleService::mailable($b);
        }
        unset($b);
        $this->render('admin/bookings_delete', [
            'title' => __('Mehrere Buchungen löschen'),
            'round' => $round,
            'day' => $day,
            'from' => $valid ? $from : Http::query('from'),
            'to' => $valid ? $to : Http::query('to'),
            'errors' => $valid ? [] : [__('Bitte gültige Start- und Endzeit angeben (Start vor Ende).')],
            'bookings' => $bookings,
            'mailable' => count(array_filter(array_column($bookings, 'mailable'))),
        ]);
    }

    /** Deletes the bookings of the day ticked in dayBookingsForm(); the parents are told only if asked to. */
    private function dayBookingsDelete(int $id): void
    {
        $day = $this->day($id);
        $ids = is_array($_POST['booking'] ?? null) ? array_filter($_POST['booking'], static fn ($v): bool => is_string($v) && ctype_digit($v)) : [];
        if (!$ids) {
            $this->flash('error', __('Keine Buchung ausgewählt – es wurde nichts gelöscht.'));
            Http::redirect('/admin/round/' . $day['round_id'], [], 'day-' . $id);
        }
        // The cancellations go to the outbox (bulk mails), nothing is sent while the page waits
        $notify = Http::post('notify') === '1';
        [$deleted, $parents] = ScheduleService::deleteBookings($id, array_values($ids), $notify);
        if ($deleted === 0) { // deleted meanwhile (another tab, the parents) or not of this day
            $this->flash('error', __('Keine der ausgewählten Buchungen gibt es noch – es wurde nichts gelöscht.'));
            Http::redirect('/admin/round/' . $day['round_id'], [], 'day-' . $id);
        }
        $this->audit(fn () => __('Tag {date}: {count} Buchung(en) auf einmal gelöscht', ['date' => self::when($day['day_date']), 'count' => $deleted])
            . self::parentsAudit($parents), (int) $day['round_id']);
        $this->flash('success', __('{count} Buchung(en) gelöscht – die Slots sind wieder frei.', ['count' => $deleted]));
        // Without the box nobody is told on purpose (made-up names): no list of people to inform
        $notify ? $this->parentsFlash($parents) : $this->flash('info', __('Es wurden keine Absagen per E-Mail versendet.'));
        Http::redirect('/admin/round/' . $day['round_id'], [], 'day-' . $id);
    }

    private function dayBookings(int $dayId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id = b.slot_id WHERE s.day_id = ?', [$dayId]);
    }

    private function breakAdd(int $dayId): void
    {
        $day = $this->day($dayId);
        $start = Time::normalizeTime(Http::post('start_time'));
        $end = Time::normalizeTime(Http::post('end_time'));
        $label = mb_substr(Http::post('label'), 0, 100);
        if (!$start || !$end || $start >= $end) {
            $this->flash('error', __('Pause: Bitte gültige Start- und Endzeit angeben (Start vor Ende).'));
            Http::redirect('/admin/round/' . $day['round_id'], [], 'day-' . $dayId);
        }
        Db::run('INSERT INTO day_breaks (day_id, start_time, end_time, label) VALUES (?, ?, ?, ?)', [$dayId, $start, $end, $label !== '' ? $label : null]);
        $this->audit(fn () => __('Pause am {date} hinzugefügt ({time})', ['date' => self::when($day['day_date']), 'time' => fmt_range($start, $end)]), (int) $day['round_id']);
        $this->afterSync((int) $day['round_id'], $dayId, __('Pause hinzugefügt.'));
    }

    private function breakDelete(int $id): void
    {
        $break = Db::one('SELECT * FROM day_breaks WHERE id = ?', [$id]) ?? $this->notFound();
        // Ownership is checked via the day
        $day = $this->day((int) $break['day_id']);
        Db::run('DELETE FROM day_breaks WHERE id = ?', [$id]);
        $this->audit(fn () => __('Pause am {date} entfernt ({time})', ['date' => self::when($day['day_date']), 'time' => fmt_range($break['start_time'], $break['end_time'])]), (int) $day['round_id']);
        $this->afterSync((int) $day['round_id'], (int) $day['id'], __('Pause entfernt.'));
    }

    /**
     * Message after the slots of a day were recalculated ($parents, see ScheduleService); without $parents
     * (a break added or removed) they are recalculated here. Conflicts are not mailed about: the parents learn
     * of them when the teacher moves or deletes the booking.
     *
     * A break has no box "Eltern per E-Mail informieren": it never changes a booking that fits (its slot keeps
     * its time), it only makes bookings conflicts or lets former conflicts fit again. Only the parents of such a
     * former conflict get mail, and only if its details changed while it was a conflict (ScheduleService): what
     * they know is out of date then, as after a change of the day with the box ticked. The message names them.
     */
    private function afterSync(int $roundId, int $dayId, string $message, ?ScheduleChange $parents = null): never
    {
        $parents ??= ScheduleService::resyncDay($dayId, true);
        if ($parents->conflicts > 0) {
            $this->flash('warning', $message . ' ' . __('Achtung: {count} gebuchte(r) Termin(e) passen nicht mehr zum Zeitplan und sind als Konflikt markiert. Bitte umbuchen oder löschen.', ['count' => $parents->conflicts])
                . self::movedNote($parents) . ' ' . self::conflictHint());
        } else {
            $this->flash('success', $message . ' ' . __('Slots wurden aktualisiert.'));
        }
        $this->parentsFlash($parents, true);
        Http::redirect('/admin/round/' . $roundId, [], 'day-' . $dayId);
    }

    /** Conflicts that got a new date, location or teacher: their parents do not know it (for the conflict warning). */
    private static function movedNote(ScheduleChange $parents): string
    {
        return $parents->movedConflicts > 0 ? ' ' . __('Bei {count} davon haben sich zugleich Datum, Ort oder Lehrkraft geändert; die Eltern kennen die neuen Daten noch nicht.', ['count' => $parents->movedConflicts]) : '';
    }

    private static function conflictHint(): string
    {
        return __('Die Eltern erfahren davon, wenn Sie den Termin umbuchen (mit „Änderung per E-Mail mitteilen“) oder löschen (mit „Eltern per E-Mail informieren“); ohne E-Mail-Adresse bitte persönlich informieren.');
    }

    /** @return array{0:array,1:list<string>} */
    private function dayInput(): array
    {
        $date = Http::post('day_date');
        $start = Time::normalizeTime(Http::post('start_time'));
        $end = Time::normalizeTime(Http::post('end_time'));
        $minutes = Http::post('slot_minutes');
        $location = trim(Http::post('location'));
        $errors = Time::isDate($date) ? [] : [__('Bitte ein gültiges Datum angeben.')];
        $errors = array_merge($errors, self::timeErrors($start, $end, $minutes));
        if (mb_strlen($location) > 200) {
            $errors[] = __('Ort: höchstens 200 Zeichen.');
        }
        return [['date' => $date, 'start' => $start, 'end' => $end, 'minutes' => (int) $minutes, 'location' => $location], $errors];
    }

    // ---------------------------------------------------------------- Slots & bookings

    private function slotToggleBlock(int $id): void
    {
        $slot = $this->slot($id);
        Db::run('UPDATE slots SET is_blocked = 1 - is_blocked WHERE id = ?', [$id]);
        $this->audit(fn () => $slot['is_blocked']
            ? __('Termin {slot} freigegeben', ['slot' => self::when($slot['day_date'], $slot['start_time'])])
            : __('Termin {slot} gesperrt', ['slot' => self::when($slot['day_date'], $slot['start_time'])]), (int) $slot['round_id']);
        $this->flash('success', $slot['is_blocked'] ? __('Slot freigegeben.') : __('Slot gesperrt.'));
        Http::redirect('/admin/round/' . $slot['round_id'], [], 'slot-' . $id);
    }

    private function bookingNew(int $slotId, array $errors = [], array $old = []): void
    {
        $slot = $this->slot($slotId);
        $this->render('admin/booking_form', [
            'title' => __('Eintrag anlegen'),
            'slot' => $slot,
            'round' => $this->round((int) $slot['round_id']),
            'booking' => null,
            'moveOptions' => [],
            // Mail on by default: it is the only way the parents learn about an entry (and its privacy notice)
            'old' => $old + ['name' => '', 'email' => '', 'interpreter_lang' => '', 'lang' => I18n::defaultLanguage(), 'send_mail' => true],
            'errors' => $errors,
        ]);
    }

    private function bookingCreate(int $slotId): void
    {
        $slot = $this->slot($slotId);
        $round = $this->round((int) $slot['round_id']);
        $old = $this->bookingInput();
        $errors = PublicController::validate($old, false);
        if ($errors) {
            $this->bookingNew($slotId, $errors, $old);
            return;
        }
        try {
            [$id, $token] = BookingService::adminBook($round, $slotId, PublicController::cleanName($old['name']), $old['email'] ?: null, $old['lang'], PublicController::cleanLanguage($old['interpreter_lang']));
        } catch (BookingException) {
            $this->flash('error', __('Dieser Slot ist bereits belegt.'));
            Http::redirect('/admin/round/' . $round['id'], [], 'slot-' . $slotId);
        }
        if (!$round['was_used']) {
            // An entry puts a draft to use: from now on it expires after its last day (Cleanup)
            Db::run('UPDATE rounds SET was_used = 1 WHERE id = ?', [$round['id']]);
        }
        // Booking events with the date only (expert review P2-4): with the time, a summary mail or a printed list would
        // tell whose booking it was for 90 days after the booking itself is gone
        $this->audit(fn () => __('Buchung am {date} eingetragen', ['date' => self::when($slot['day_date'])]), (int) $round['id']);
        $msg = __('Eintrag angelegt. Änderungslink für die Eltern (wird nur jetzt angezeigt): {link}', ['link' => abs_url('/b/' . $token, ['lang' => $old['lang']])]);
        if ($old['send_mail'] && $old['email'] !== '' && $this->teacherMailAllowed($msg)) {
            $mail = $this->withoutSession(static fn () => Notifier::sendOrQueue((array) BookingService::findById($id), 'booked', $token));
            $msg .= ' – ' . self::mailMessage($mail, __('Bestätigung per E-Mail gesendet.'));
        }
        $this->flash('success', $msg);
        Http::redirect('/admin/round/' . $round['id'], [], 'slot-' . $slotId);
    }

    private function bookingEdit(int $id, array $errors = [], array $old = []): void
    {
        $booking = $this->booking($id);
        $round = $this->round((int) $booking['round_id']);
        $moveOptions = Db::all(
            'SELECT s.id, s.start_time, s.end_time, s.is_blocked, d.day_date
               FROM slots s JOIN round_days d ON d.id = s.day_id LEFT JOIN bookings b ON b.slot_id = s.id
              WHERE d.round_id = ? AND b.id IS NULL AND s.is_conflict = 0
              ORDER BY d.day_date, s.start_time',
            [$round['id']]
        );
        $this->render('admin/booking_form', [
            'title' => __('Eintrag bearbeiten'),
            'slot' => array_merge($booking, ['id' => $booking['slot_id']]),
            'round' => $round,
            'booking' => $booking,
            'moveOptions' => $moveOptions,
            'old' => $old + ['name' => $booking['name'], 'email' => (string) $booking['email'], 'interpreter_lang' => (string) $booking['interpreter_lang'], 'lang' => $booking['lang'], 'send_mail' => true, 'move_to' => ''],
            'errors' => $errors,
        ]);
    }

    private function bookingUpdate(int $id): void
    {
        $booking = $this->booking($id);
        $old = $this->bookingInput() + ['move_to' => Http::post('move_to')];
        $errors = PublicController::validate($old, false);
        if ($errors) {
            $this->bookingEdit($id, $errors, $old);
            return;
        }
        // Rebook first: if that fails, the entry stays completely unchanged.
        $moved = false;
        if ((int) $old['move_to'] > 0 && (int) $old['move_to'] !== (int) $booking['slot_id']) {
            try {
                BookingService::move($booking, (int) $old['move_to'], true);
                $moved = true;
            } catch (BookingException) {
                $this->bookingEdit($id, [__('Der gewählte Slot ist nicht mehr frei. Es wurde nichts geändert.')], $old);
                return;
            }
        }
        $newAddress = BookingService::update($id, PublicController::cleanName($old['name']), $old['email'] ?: null, $old['lang'], PublicController::cleanLanguage($old['interpreter_lang']));
        $target = $moved ? BookingService::findById($id) : null;
        $this->audit(fn () => match (true) {
            $target === null => __('Buchung am {date} geändert', ['date' => self::when($booking['day_date'])]),
            $target['day_date'] === $booking['day_date'] => __('Buchung am {date} geändert, auf eine andere Uhrzeit am selben Tag umgebucht', ['date' => self::when($booking['day_date'])]),
            default => __('Buchung am {date} geändert, umgebucht auf den {target}', ['date' => self::when($booking['day_date']), 'target' => self::when($target['day_date'])]),
        }, (int) $booking['round_id']);
        $msg = $moved ? __('Eintrag gespeichert und umgebucht.') : __('Eintrag gespeichert.');
        // Also without a change here: this is how parents learn the new date, location or teacher of their
        // appointment after the day or the round was changed (the mail carries the current details)
        $mail = null;
        if ($old['send_mail'] && $old['email'] !== '' && $this->teacherMailAllowed($msg)) {
            $mail = $this->withoutSession(static fn () => Notifier::sendOrQueue((array) BookingService::findById($id), 'changed', null));
            $msg .= ' ' . self::mailMessage($mail, __('Änderung per E-Mail mitgeteilt.'));
        }
        $this->flash('success', $msg);
        // A new address gets mails after changes only once one from here reached it (BookingService::update())
        if ($newAddress && $mail === null) {
            $this->flash('warning', __('Neue E-Mail-Adresse ohne E-Mail gespeichert: Die Eltern erhalten dort Änderungen, Absagen und Erinnerungen erst, wenn Sie den Eintrag mit „Änderung per E-Mail mitteilen“ speichern.'));
        }
        Http::redirect('/admin/round/' . $booking['round_id'], [], 'day-' . (BookingService::findById($id)['day_id'] ?? ''));
    }

    private function bookingDelete(int $id): void
    {
        $booking = $this->booking($id);
        $notify = Http::post('notify') === '1';
        // The cancellation goes out right away (session closed meanwhile), or from the outbox if the server refuses
        $mail = $this->withoutSession(static fn () => ScheduleService::deleteBooking($booking, $notify));
        $this->audit(fn () => __('Buchung am {date} gelöscht', ['date' => self::when($booking['day_date'])])
            . ($mail !== null ? ', ' . __('Eltern per E-Mail informiert') : ''), (int) $booking['round_id']);
        $this->flash('success', __('Eintrag gelöscht – der Slot ist wieder frei.') . match ($mail) {
            Notifier::SENT => ' ' . __('Absage per E-Mail an die Eltern gesendet.'),
            Notifier::QUEUED => ' ' . __('Die Absage per E-Mail konnte gerade nicht gesendet werden. Sie wird automatisch erneut versucht.'),
            default => '',
        });
        // No mail: say so, with name and address, which the round page no longer shows
        if ($mail === null && ScheduleService::upcoming($booking)) {
            $this->flash('warning', match (true) {
                (string) $booking['email'] === '' => __('Die Eltern („{name}“) wurden NICHT automatisch benachrichtigt; es war keine E-Mail-Adresse hinterlegt – bitte persönlich informieren.', ['name' => $booking['name']]),
                // As the delete dialog says, which has no box then
                !ScheduleService::mailable($booking) => __('Die Eltern („{name}“, {email}) haben zu diesem Eintrag nie eine E-Mail erhalten und wurden deshalb nicht benachrichtigt – bitte persönlich informieren.', ['name' => $booking['name'], 'email' => $booking['email']]),
                default => __('Die Eltern („{name}“, {email}) wurden NICHT automatisch benachrichtigt – bitte selbst informieren.', ['name' => $booking['name'], 'email' => $booking['email']]),
            });
        }
        Http::redirect('/admin/round/' . $booking['round_id'], [], 'day-' . $booking['day_id']);
    }

    /** Message about a mail to the parents: $sent, or that it failed and is retried (Notifier::sendOrQueue()). */
    private static function mailMessage(?string $mail, string $sent): string
    {
        return $mail === Notifier::SENT ? $sent : __('Die E-Mail konnte gerade nicht gesendet werden. Sie wird automatisch erneut versucht; bis dahin steht beim Eintrag „E-Mail nicht zugestellt“.');
    }

    /**
     * Sends mail with the session closed: while the mail server answers, other tabs of the same person do
     * not wait for the session file (it stays locked while open). Reopened afterwards for the message.
     * @template T
     * @param callable(): T $send
     * @return T
     */
    private function withoutSession(callable $send): mixed
    {
        session_write_close();
        try {
            return $send();
        } finally {
            Auth::startSession();
        }
    }

    /** @return array{name:string,email:string,lang:string,send_mail:bool} */
    private function bookingInput(): array
    {
        $lang = Http::post('lang');
        return [
            'name' => Http::post('name'),
            'email' => Email::input(Http::post('email')),
            'interpreter_lang' => Http::post('interpreter_lang'),
            'lang' => in_array($lang, I18n::languages(), true) ? $lang : I18n::defaultLanguage(),
            'send_mail' => Http::post('send_mail') === '1',
        ];
    }

    // ---------------------------------------------------------------- Print view

    private function printView(int $roundId): void
    {
        $round = $this->round($roundId);
        $dayFilter = (int) Http::query('day');
        $days = Db::all(
            'SELECT * FROM round_days WHERE round_id = ? AND (? = 0 OR id = ?) ORDER BY day_date, start_time',
            [$roundId, $dayFilter, $dayFilter]
        );
        foreach ($days as &$day) {
            $rows = Db::all(
                'SELECT s.start_time, s.end_time, s.is_blocked, s.is_conflict, b.name, b.email, b.interpreter_lang
                   FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
                  WHERE s.day_id = ?',
                [$day['id']]
            );
            foreach (Db::all('SELECT * FROM day_breaks WHERE day_id = ?', [$day['id']]) as $br) {
                $rows[] = ['start_time' => $br['start_time'], 'end_time' => $br['end_time'], 'break' => $br['label'] ?: __('Pause')];
            }
            usort($rows, static fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));
            $day['rows'] = $rows;
        }
        unset($day);
        View::render('admin/print', [
            'title' => $round['title'],
            'round' => $round,
            'days' => $days,
            'showEmail' => ($_GET['email'] ?? '') === '1',
            'dayFilter' => $dayFilter,
        ], null);
    }

    // ---------------------------------------------------------------- Settings & texts

    // ---------------------------------------------------------------- Profile & password

    /** The way on when a round cannot be activated yet: it can be saved inactive and activated later. */
    private static function saveInactiveHint(): string
    {
        return __('Bis dahin können Sie die Runde ohne Häkchen bei „Aktiv“ speichern und alles vorbereiten; aktivieren Sie sie, sobald das erledigt ist, auf der Seite der Runde mit „Aktivieren“.');
    }

    /**
     * Why a round of this teacher may not be activated (yet), or null.
     * Required: the teacher's display name (parents see it), the operator's details for legal notice and
     * privacy policy (the operator is the controller for all rounds), the operator type (it decides which
     * legal texts fit) and both texts without open [PLACEHOLDERS]. Only checked when a round goes public:
     * rounds already active stay online.
     */
    private function publishBlocker(array $owner): ?string
    {
        return self::platformBlocker() ?? $this->ownerBlocker($owner);
    }

    /** The part of publishBlocker() that applies to every teacher: operator details, operator type, legal texts. */
    private static function platformBlocker(): ?string
    {
        if (!Operator::ready()) {
            return Auth::isAdmin()
                ? __('Bevor Runden aktiviert werden können, tragen Sie bitte unter „Texte“ die Angaben für Impressum und Datenschutz ein (die Schule bzw. der Betreiber ist für alle Runden datenschutzrechtlich verantwortlich).')
                : __('Runden können erst aktiviert werden, wenn ein Admin die Angaben für Impressum und Datenschutz hinterlegt hat.');
        }
        if (Operator::type() === null) {
            return Auth::isAdmin()
                ? __('Bevor Runden aktiviert werden können, wählen Sie bitte unter „Texte“ die Betriebsart: Wird die Terminvergabe von der Schule oder privat betrieben?')
                : __('Runden können erst aktiviert werden, wenn ein Admin die Betriebsart (von der Schule oder privat betrieben) gewählt hat.');
        }
        // Open placeholders in a translation (e.g. the Polish privacy policy) do not count: parents then get the
        // English or German text (LegalTexts::forParents())
        if (($open = LegalTexts::splitOpen(LegalTexts::open())[0]) !== []) {
            return Auth::isAdmin()
                ? __('Bevor Runden aktiviert werden können, ersetzen Sie bitte unter „Texte“ die offenen Platzhalter in eckigen Klammern: {texts}.', ['texts' => self::openTexts($open, false)])
                : __('Runden können erst aktiviert werden, wenn ein Admin die offenen Platzhalter in Impressum bzw. Datenschutzerklärung ausgefüllt hat.');
        }
        // A page empty in every fallback language: parents would get no text at all (LegalTexts::missing())
        if (($missing = LegalTexts::missing()) !== []) {
            return Auth::isAdmin()
                ? __('Bevor Runden aktiviert werden können, hinterlegen Sie bitte unter „Texte“ {texts} in mindestens einer dieser Sprachen: {languages}.', ['texts' => self::missingTexts($missing), 'languages' => strtoupper(implode(', ', LegalTexts::fallbackLanguages()))])
                : __('Runden können erst aktiviert werden, wenn ein Admin Impressum und Datenschutzerklärung hinterlegt hat.');
        }
        if (LegalTexts::germanEmpty()) {
            return Auth::isAdmin()
                ? __('Bevor Runden aktiviert werden können, hinterlegen Sie bitte unter „Texte“ die deutsche Datenschutzerklärung: Auf sie verweisen alle Übersetzungen als maßgebliche Fassung.')
                : __('Runden können erst aktiviert werden, wenn ein Admin Impressum und Datenschutzerklärung hinterlegt hat.');
        }
        return null;
    }

    /** The part of publishBlocker() that depends on the teacher: an active account with a display name. */
    private function ownerBlocker(array $owner): ?string
    {
        if (!$owner['is_active']) {
            return __('Das Konto dieser Lehrkraft ist deaktiviert – die Runde wäre öffentlich nicht erreichbar. Bitte die Runde einer aktiven Lehrkraft zuordnen oder das Konto wieder aktivieren.');
        }
        if (trim((string) $owner['display_name']) === '') {
            return (int) $owner['id'] === Auth::id()
                ? __('Bevor eine Runde aktiviert werden kann, tragen Sie bitte unter „Mein Profil“ Ihren Anzeigenamen ein (Eltern sehen ihn auf der Buchungsseite).')
                : __('Für diese Lehrkraft fehlt ein Anzeigename (unter „Benutzer“ ergänzen).');
        }
        return null;
    }

    /**
     * Accounts the admin can assign a round to: all active ones, plus the previous
     * teacher of the round, even if deactivated (otherwise they would not be preselected).
     * @return list<array>
     */
    private static function ownerChoices(int $currentOwner = 0): array
    {
        return Db::all(
            "SELECT id, display_name, username, role, is_active FROM users WHERE is_active = 1 OR id = ?
              ORDER BY id = ? DESC, display_name, username",
            [$currentOwner, Auth::id()]
        );
    }

    /** Notes on what is still missing in the profile (privacy notices/summaries need it). */
    private function profileHints(): array
    {
        $u = Auth::user();
        $hints = [];
        if (trim((string) $u['display_name']) === '') {
            $hints[] = __('Anzeigename fehlt – er wird Eltern auf der Buchungsseite angezeigt.');
        }
        if ((string) $u['email'] === '' && ($u['summary_evening'] || $u['summary_deadline'])) {
            $hints[] = __('Keine E-Mail-Adresse hinterlegt – Zusammenfassungen können nicht versendet werden.');
        } elseif ((string) $u['email'] !== '' && !AccountDomains::permits((string) $u['email'])) {
            $hints[] = __('Ihre E-Mail-Adresse ist keine dienstliche Adresse bei {domains}, wie die Schule es vorgibt – bitte ändern. Bis dahin erhalten Sie keine Zusammenfassungen.', ['domains' => implode(', ', AccountDomains::shown())]);
        }
        // A reply address from before allowed_account_domains: not used (Notifier::replyTo())
        if ((string) $u['reply_to'] !== '' && !AccountDomains::permits((string) $u['reply_to'])) {
            $hints[] = __('Ihre Antwortadresse für Eltern ist keine dienstliche Adresse bei {domains}, wie die Schule es vorgibt – bitte ändern. Bis dahin gehen Antworten der Eltern nicht an diese Adresse.', ['domains' => implode(', ', AccountDomains::shown())]);
        }
        return $hints;
    }

    private function profile(array $errors = [], ?array $data = null): void
    {
        $this->render('admin/profile', [
            'title' => __('Mein Profil'),
            'user' => Auth::user(),
            'data' => $data ?? Auth::user(),
            'errors' => $errors,
            'generalContact' => ParentContact::general(),
        ]);
    }

    private function profileSave(): void
    {
        $me = Auth::user();
        $data = [
            'display_name' => mb_substr(Http::post('display_name'), 0, 200),
            'email' => Email::input(Http::post('email')),
            'reply_to' => Email::input(Http::post('reply_to')),
            // Shown to parents (ParentContact); if the form does not know the field yet, the previous value stays
            'parent_contact' => isset($_POST['parent_contact']) ? ParentContact::clean(Http::post('parent_contact')) : (string) $me['parent_contact'],
            'summary_evening' => Http::post('summary_evening') === '1' ? 1 : 0,
            'summary_deadline' => Http::post('summary_deadline') === '1' ? 1 : 0,
            // Only offered where the school allows names; otherwise the choice so far stays (NULL included)
            'summary_names' => \App\Config::get('summary_names', false) ? (Http::post('summary_names') === '1' ? 1 : 0) : $me['summary_names'],
            'lang' => in_array(Http::post('lang'), AdminI18n::LANGUAGES, true) ? Http::post('lang') : AdminI18n::of($me),
        ];
        // Password reset links go to the e-mail address, the replies of parents to the reply address: changing
        // one requires the current password. Only a changed address has to be at an allowed domain, so that an
        // account from before the rule can still save its profile (and turn the summaries off)
        $emailChanged = mb_strtolower($data['email']) !== mb_strtolower((string) $me['email']);
        $replyChanged = mb_strtolower($data['reply_to']) !== mb_strtolower((string) $me['reply_to']);
        $errors = [];
        if ($data['display_name'] === '') {
            $errors[] = __('Bitte einen Anzeigenamen angeben (z. B. „Frau Muster“).');
        }
        if ($data['email'] !== '' && !is_valid_email($data['email'])) {
            $errors[] = __('Die E-Mail-Adresse ist ungültig.');
        } elseif ($data['email'] !== '' && $this->emailTaken($data['email'], Auth::id())) {
            $errors[] = __('Diese E-Mail-Adresse gehört bereits zu einem anderen Konto.');
        } elseif ($emailChanged && ($error = AccountDomains::error($data['email'])) !== null) {
            $errors[] = $error;
        }
        if ($data['reply_to'] !== '' && !is_valid_email($data['reply_to'])) {
            $errors[] = __('Die Antwortadresse für Eltern ist ungültig.');
        } elseif ($replyChanged && ($error = AccountDomains::error($data['reply_to'])) !== null) {
            $errors[] = __('Antwortadresse für Eltern:') . ' ' . $error;
        }
        if (mb_strlen($data['parent_contact']) > ParentContact::MAX_LENGTH) {
            $errors[] = __('Kontakt für Eltern: höchstens {max} Zeichen.', ['max' => ParentContact::MAX_LENGTH]);
        }
        if (!$errors && ($emailChanged || $replyChanged)
            && ($error = Auth::confirmPassword(Http::postRaw('current_password'), $emailChanged
                ? __('Zum Ändern der E-Mail-Adresse bitte Ihr aktuelles Passwort eingeben.')
                : __('Zum Ändern der Antwortadresse für Eltern bitte Ihr aktuelles Passwort eingeben.'))) !== null) {
            $errors[] = $error;
        }
        if ($errors) {
            $this->profile($errors, $data);
            return;
        }
        // To the previous address, in the language of the account (as it was before this change)
        $this->withoutSession(static fn () => AccountSecurity::contactChanged($me, ['email' => $data['email']] + $me, $me));
        Db::run(
            'UPDATE users SET display_name = ?, email = ?, reply_to = ?, parent_contact = ?, summary_evening = ?, summary_deadline = ?, summary_names = ?, lang = ? WHERE id = ?',
            [$data['display_name'], $data['email'] !== '' ? $data['email'] : null, $data['reply_to'] !== '' ? $data['reply_to'] : null,
                $data['parent_contact'] !== '' ? $data['parent_contact'] : null, $data['summary_evening'], $data['summary_deadline'], $data['summary_names'], $data['lang'], Auth::id()]
        );
        AdminI18n::set($data['lang']); // the confirmation already in the new language
        $this->flash('success', __('Profil gespeichert.'));
        Http::redirect('/admin/profile');
    }

    /**
     * The notice on the dashboard about the summaries without names is confirmed (Summaries::notice()). Where
     * the school allows names, the account keeps them as before; otherwise it is without names from now on,
     * like a new account, also if the school allows them later (then it chooses in the profile).
     */
    private function summaryNoticeSeen(): void
    {
        Db::run('UPDATE users SET summary_names = ? WHERE id = ? AND summary_names IS NULL', [\App\Config::get('summary_names', false) ? 1 : 0, Auth::id()]);
        Http::redirect('/admin');
    }

    /** "Was ist neu" confirmed: the notes so far are not shown again to this account. */
    private function newsSeen(): void
    {
        WhatsNew::markSeen(Auth::id());
        Http::redirect('/admin');
    }

    /** "Bereit für den Start?": the checklist of the setup (Readiness). */
    private function readiness(): void
    {
        $items = Readiness::checks(array_merge(self::operatorHints(), self::legalHints(LegalTexts::outdated())));
        $this->render('admin/readiness', ['title' => __('Bereit für den Start?'), 'items' => $items, 'started' => Readiness::started()]);
    }

    private function passwordSave(): void
    {
        $result = $this->changePasswordFromPost();
        if ($result !== true) {
            $this->profile([$result]);
            return;
        }
        $this->flash('success', __('Passwort geändert.'));
        Http::redirect('/admin/profile');
    }

    private function passwordChangeForm(array $errors = []): void
    {
        $this->render('admin/password_change', ['title' => __('Neues Passwort festlegen'), 'errors' => $errors]);
    }

    private function passwordChangeSave(): void
    {
        $result = $this->changePasswordFromPost();
        if ($result !== true) {
            $this->passwordChangeForm([$result]);
            return;
        }
        $this->flash('success', __('Passwort festgelegt. Bitte ergänzen Sie jetzt Ihr Profil.'));
        Http::redirect('/admin/profile');
    }

    private function changePasswordFromPost(): true|string
    {
        $new = Http::postRaw('new_password');
        if ($new !== Http::postRaw('new_password2')) {
            return __('Die neuen Passwörter stimmen nicht überein.');
        }
        return Auth::changePassword(Auth::id(), Http::postRaw('current_password'), $new);
    }

    // ---------------------------------------------------------------- User management (admin)

    private function users(): void
    {
        // Sign-ins only in coarse steps (never, within / more than 12 months ago): enough to clean up
        // accounts, without showing everybody's login times to all admins (employee data protection)
        $users = Db::all(
            'SELECT u.id, u.username, u.display_name, u.email, u.role, u.is_active, u.must_change_password,
                    u.last_login_at IS NULL AS never_signed_in, u.last_login_at < ? AS long_inactive,
                    COALESCE(u.access_token_expires_at > ?, 0) AS invite_open,
                    (SELECT COUNT(*) FROM rounds r WHERE r.user_id = u.id) AS round_count
               FROM users u ORDER BY u.is_active DESC, u.display_name, u.username',
            [self::inactiveSince(), Time::nowSql()]
        );
        $this->render('admin/users', ['title' => __('Benutzer'), 'users' => $users, 'me' => Auth::id()]);
    }

    private function userForm(?int $id = null, array $errors = [], ?array $data = null): void
    {
        $user = $id ? $this->userById($id) : null;
        $this->render('admin/user_form', [
            'title' => $user ? __('Benutzer bearbeiten') : __('Neuer Benutzer'),
            'user' => $user,
            'data' => $data ?? $user ?? ['username' => '', 'display_name' => '', 'email' => '', 'role' => 'teacher', 'is_active' => 1],
            'errors' => $errors,
            'isSelf' => $user && (int) $user['id'] === Auth::id(),
            'parents' => self::parentCounts($user ? ScheduleService::concerned('account', (int) $user['id']) : []),
        ]);
    }

    private function userSave(?int $id = null): void
    {
        $user = $id ? $this->userById($id) : null;
        $isSelf = $user && (int) $user['id'] === Auth::id();
        $data = [
            'display_name' => mb_substr(Http::post('display_name'), 0, 200),
            'email' => mb_strtolower(Email::input(Http::post('email'))),
            // Admins can make others admins and take the role away again, but not change their own
            'role' => $isSelf ? $user['role'] : (Http::post('role') === 'admin' ? 'admin' : 'teacher'),
            'is_active' => $user ? (Http::post('is_active') === '1' ? 1 : 0) : 1,
            // Language of the account (admin area, invitation and summaries); new accounts: the inviter's
            'lang' => in_array(Http::post('lang'), AdminI18n::LANGUAGES, true) ? Http::post('lang') : ($user ? AdminI18n::of($user) : AdminI18n::lang()),
        ];
        // New accounts: the username the admin chose, otherwise the e-mail address (expert review P2-1: a username
        // other than the address that parents see as Reply-To is harder to guess). Freely changeable when editing.
        $data['username'] = Http::post('username');
        // As entered for the form after an error; the fallback to the address only for checking and saving
        $username = $user || $data['username'] !== '' ? $data['username'] : $data['email'];
        if ($isSelf) {
            // own activation cannot be changed (otherwise locking oneself out would be possible)
            $data['is_active'] = 1;
        }
        $errors = [];
        if ($data['display_name'] === '') {
            $errors[] = __('Bitte einen Namen angeben (so wird er Eltern angezeigt, z. B. „Frau Muster“).');
        }
        if (!is_valid_email($data['email'])) {
            $errors[] = __('Bitte eine gültige E-Mail-Adresse angeben – an sie gehen die Zugangsdaten.');
        } elseif ($this->emailTaken($data['email'], $id ?? 0)) {
            $errors[] = __('Für diese E-Mail-Adresse bzw. diesen Benutzernamen gibt es bereits ein Konto.');
        } elseif ((!$user || $data['email'] !== mb_strtolower((string) $user['email'])) && ($error = AccountDomains::error($data['email'])) !== null) {
            // Only for a new or changed address: an account from before the rule can still be edited or deactivated
            $errors[] = $error;
        }
        // A username left empty is the e-mail address, checked above. A chosen one must not be another account's
        // username or e-mail address either: "forgot password" and the address checks look at both
        if (($user || $data['username'] !== '') && !Auth::validUsername($username)) {
            $errors[] = __('Benutzername: 3–254 Zeichen, nur Buchstaben, Ziffern und . _ @ + -');
        } elseif ($username !== $data['email'] && $this->emailTaken($username, $id ?? 0)) {
            $errors[] = __('Für diese E-Mail-Adresse bzw. diesen Benutzernamen gibt es bereits ein Konto.');
        }
        // Admin rights and e-mail addresses: only with the password of the acting admin
        if (!$errors && AccountSecurity::needsPassword($user, $data)
            && ($error = Auth::confirmPassword(Http::postRaw('current_password'), __('Zur Bestätigung bitte Ihr Passwort eingeben (nötig bei Admin-Rechten und E-Mail-Adressen).'))) !== null) {
            $errors[] = $error;
        }
        if ($errors) {
            $this->userForm($id, $errors, $data);
            return;
        }
        if ($user) {
            // At least one active admin must remain (checked under a lock, see AccountSecurity)
            $saved = AccountSecurity::keepingAnAdmin((int) $id, $data['role'] === 'admin' && $data['is_active'] === 1, static fn () => Db::run(
                'UPDATE users SET username = ?, display_name = ?, email = ?, role = ?, is_active = ?, lang = ? WHERE id = ?',
                [$username, $data['display_name'], $data['email'], $data['role'], $data['is_active'], $data['lang'], $id]
            ));
            if (!$saved) {
                $this->userForm($id, [__('Es muss mindestens ein aktives Admin-Konto geben.')], $data);
                return;
            }
            // A closure, so that the log gets its own language (see audit())
            $changes = static fn (): array => array_filter([
                $data['role'] !== $user['role'] ? __('Rolle jetzt {role}', ['role' => self::roleLabel($data['role'])]) : '',
                $data['is_active'] !== (int) $user['is_active'] ? ($data['is_active'] ? __('aktiviert') : __('deaktiviert')) : '',
                $data['display_name'] !== $user['display_name'] ? __('Name jetzt „{name}“', ['name' => $data['display_name']]) : '',
                $data['email'] !== (string) $user['email'] || $username !== $user['username'] ? __('E-Mail/Benutzername geändert') : '',
            ]);
            $this->audit(fn () => __('Konto {account} bearbeitet', ['account' => self::accountLabel($user)]) . ($changes() ? ': ' . implode(', ', $changes()) : ''));
            $after = $data + $user;
            $me = Auth::user();
            $this->withoutSession(static function () use ($user, $after, $me): void {
                AccountSecurity::contactChanged($user, $after, $me);
                if (($event = AccountSecurity::adminEvent($user, $after)) !== null) {
                    AccountSecurity::adminChange($event, $after, $me);
                }
            });
            $this->flash('success', __('Benutzer gespeichert.') . (!$data['is_active'] ? ' ' . __('Das Konto ist deaktiviert; seine Runden sind öffentlich nicht mehr erreichbar.') : ''));
        } else {
            $id = Db::insert(
                'INSERT INTO users (username, password_hash, role, display_name, email, lang, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
                [$username, AccountLinks::unusablePasswordHash(), $data['role'], $data['display_name'], $data['email'], $data['lang'], Time::nowSql()]
            );
            $this->audit(fn () => __('Konto {account} angelegt ({role}) und eingeladen', ['account' => self::accountLabel($data), 'role' => self::roleLabel($data['role'])]));
            $this->linkFlash($this->userById($id), AccountLinks::INVITE);
            if ($data['role'] === 'admin') {
                $invited = $this->userById($id);
                $me = Auth::user();
                $this->withoutSession(static fn () => AccountSecurity::adminChange(AccountSecurity::INVITED, $invited, $me));
            }
        }
        Http::redirect('/admin/users');
    }

    /** Admin: send a new password link. The previous password becomes invalid. */
    private function userResetPassword(int $id): void
    {
        $user = $this->userById($id);
        if ((int) $user['id'] === Auth::id()) {
            $this->flash('error', __('Ihr eigenes Passwort ändern Sie unter „Mein Profil“.'));
            Http::redirect('/admin/users');
        }
        if (!Email::isValid((string) $user['email'])) {
            $this->flash('error', __('Für dieses Konto ist keine E-Mail-Adresse hinterlegt.'));
            Http::redirect('/admin/users/' . $id . '/edit');
        }
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [AccountLinks::unusablePasswordHash(), $id]);
        Auth::endOtherSessions($id, false);
        $this->audit(fn () => $user['last_login_at'] === null
            ? __('Konto {account}: Einladung erneut gesendet', ['account' => self::accountLabel($user)])
            : __('Konto {account}: Link zum Zurücksetzen des Passworts gesendet', ['account' => self::accountLabel($user)]));
        $this->linkFlash($user, $user['last_login_at'] === null ? AccountLinks::INVITE : AccountLinks::RESET);
        Http::redirect('/admin/users');
    }

    /**
     * Send the link and show the result. The link itself is never shown, also not when the mail fails:
     * with it, the admin could set the password of somebody else's account and use it unnoticed.
     */
    private function linkFlash(array $user, string $kind): void
    {
        $me = Auth::user();
        $sent = $this->withoutSession(static fn () => AccountLinks::send($user, $kind, $me));
        $vars = [
            'what' => $kind === AccountLinks::INVITE ? __('Einladung') : __('Link zum Festlegen eines neuen Passworts'),
            'email' => $user['email'], 'hours' => AccountLinks::validHours(),
        ];
        if ($sent) {
            $this->flash('success', __('{what} an {email} gesendet (gültig {hours} Stunden, einmalig nutzbar).', $vars));
        } else {
            $this->flash('warning', __('{what} konnte nicht an {email} gesendet werden. Bitte prüfen Sie den Mailversand (unter „Texte“) und senden Sie den Link danach über „Bearbeiten“ erneut.', $vars));
        }
    }

    // ---------------------------------------------------------------- Invitation / forgot password (without login)

    private function inviteForm(#[\SensitiveParameter] string $token, array $errors = []): void
    {
        $user = $this->linkUser($token);
        if (!$user) {
            http_response_code(404);
            $this->render('admin/message', ['title' => __('Link ungültig'), 'text' => __('Dieser Link ist abgelaufen, wurde bereits verwendet oder ist ungültig. Bitte fordern Sie über „Passwort vergessen?“ auf der Anmeldeseite einen neuen Link an.')]);
            return;
        }
        $this->render('admin/invite', ['title' => __('Passwort festlegen'), 'user' => $user, 'token' => $token, 'errors' => $errors, 'csrf' => self::formToken('invite')]);
    }

    private function inviteSave(#[\SensitiveParameter] string $token): void
    {
        $user = $this->linkUser($token);
        if (!$user || !self::checkFormToken('invite')) {
            $this->inviteForm($token, $user ? [__('Das Formular ist abgelaufen. Bitte erneut versuchen.')] : []);
            return;
        }
        $new = Http::postRaw('new_password');
        $error = Auth::passwordPolicy($new, (string) $user['username'], (string) $user['email'])
            ?? ($new !== Http::postRaw('new_password2') ? __('Die Passwörter stimmen nicht überein.') : null);
        if ($error !== null) {
            $this->inviteForm($token, [$error]);
            return;
        }
        AccountLinks::consume((int) $user['id'], $new);
        // Whoever can use the link may sign in on this device even while strangers have used up the
        // account's login limit ("forgot password" would not help against a lockout otherwise)
        TrustedDevice::remember((int) $user['id']);
        Auth::startSession(true);
        $this->flash('success', __('Ihr Passwort ist gespeichert. Bitte melden Sie sich jetzt an.'));
        $_SESSION['login_user'] = $user['username']; // not in the URL (access logs)
        $lang = AdminI18n::of($user);
        Http::redirect('/admin/login', ['lang' => $lang !== AdminI18n::default() ? $lang : null]);
    }

    /** Check the token; failed attempts limited per IP. */
    private function linkUser(#[\SensitiveParameter] string $token): ?array
    {
        [$limit, $window] = RateLimiter::limits('invite', 20, 3600);
        if (RateLimiter::count('invite', $window) >= $limit) {
            return null;
        }
        $user = AccountLinks::findUser($token);
        if (!$user) {
            RateLimiter::hit('invite', $limit, $window);
        }
        return $user;
    }

    private function forgotForm(bool $done = false): void
    {
        $this->render('admin/forgot', ['title' => __('Passwort vergessen'), 'done' => $done, 'csrf' => self::formToken('forgot')]);
    }

    /**
     * The response is always the same and comes before sending the email – reveals neither through
     * the text nor through the response time whether an account exists for the address. A still
     * valid link sent less than 15 minutes ago is not replaced (otherwise strangers could keep
     * invalidating open links).
     */
    private function forgotSave(): void
    {
        $started = microtime(true);
        [$limit, $window] = RateLimiter::limits('forgot', 5, 3600);
        $user = null;
        if (self::checkFormToken('forgot') && RateLimiter::hit('forgot', $limit, $window)) {
            $input = mb_strtolower(Email::input(Http::post('email')));
            // Username before email (unique); with multiple matches the oldest account
            $user = $input !== '' ? Db::one(
                'SELECT * FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) AND is_active = 1 AND email IS NOT NULL
                  ORDER BY LOWER(username) = ? DESC, id LIMIT 1',
                [$input, $input, $input]
            ) : null;
            if ($user && AccountLinks::recentlyIssued($user)) {
                $user = null;
            }
        }
        $this->forgotForm(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // the response is with the browser, sending happens invisibly
        } else {
            // Without FPM: equalize the response time (output only comes at the end)
            usleep((int) max(0, (1.5 - (microtime(true) - $started)) * 1e6));
        }
        if ($user) {
            // The previous password stays valid until the link is used
            AccountLinks::send($user, AccountLinks::RESET);
        }
    }

    private function userDelete(int $id): void
    {
        $user = $this->userById($id);
        if ((int) $user['id'] === Auth::id()) {
            $this->flash('error', __('Sie können Ihr eigenes Konto nicht löschen.'));
            Http::redirect('/admin/users');
        }
        if (Http::post('confirm') !== '1') {
            $this->flash('error', __('Bitte das Löschen ausdrücklich bestätigen.'));
            Http::redirect('/admin/users/' . $id . '/edit');
        }
        if ($user['role'] === 'admin'
            && ($error = Auth::confirmPassword(Http::postRaw('current_password'), __('Zum Löschen eines Admin-Kontos bitte Ihr Passwort eingeben.'))) !== null) {
            $this->flash('error', $error);
            Http::redirect('/admin/users/' . $id . '/edit');
        }
        $rounds = (int) Db::value('SELECT COUNT(*) FROM rounds WHERE user_id = ?', [$id]);
        // At least one active admin must remain (checked under a lock, see AccountSecurity); the parents of
        // upcoming bookings in the account's rounds get a cancellation
        $parents = ScheduleService::deleteAccount($id, Http::post('notify') === '1');
        if ($parents === null) {
            $this->flash('error', __('Es muss mindestens ein aktives Admin-Konto geben.'));
            Http::redirect('/admin/users');
        }
        $this->audit(fn () => ($rounds
            ? __('Konto {account} gelöscht (mit {count} Runde(n))', ['account' => self::accountLabel($user), 'count' => $rounds])
            : __('Konto {account} gelöscht', ['account' => self::accountLabel($user)])) . self::parentsAudit($parents));
        if ($user['role'] === 'admin') {
            $me = Auth::user();
            $this->withoutSession(static fn () => AccountSecurity::adminChange(AccountSecurity::DELETED, $user, $me));
        }
        WhatsNew::forget($id);
        $this->flash('success', __('Benutzer „{name}“ mit allen Runden und Buchungen gelöscht.', ['name' => $user['username']]));
        $this->parentsFlash($parents);
        Http::redirect('/admin/users');
    }

    private function userById(int $id): array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]) ?? $this->notFound();
    }

    /**
     * Emails to parents from manual entries: at most 30 per account and hour, so that an account
     * cannot serve as a distributor for emails to arbitrary addresses. Otherwise a note is added to the message.
     */
    private function teacherMailAllowed(string &$msg): bool
    {
        [$limit, $window] = RateLimiter::limits('teacher_mail', 30, 3600);
        if (RateLimiter::hit('teacher_mail', $limit, $window, 'user:' . Auth::id())) {
            return true;
        }
        $msg .= ' ' . __('Keine E-Mail gesendet: Höchstzahl von Mails je Stunde erreicht, bitte später erneut speichern.');
        return false;
    }

    /** Email address already used by another account (as email or username)? */
    private function emailTaken(string $email, int $exceptId): bool
    {
        return Db::value('SELECT id FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) AND id <> ?',
            [mb_strtolower($email), mb_strtolower($email), $exceptId]) !== null;
    }

    /**
     * Admin: end the sessions of all accounts on all devices, e.g. after a lost device or a suspected
     * break-in (keeps the own session). Asks for the password; all admins are told by e-mail.
     */
    private function endAllSessions(): void
    {
        $error = Auth::confirmPassword(Http::postRaw('current_password'), __('Zum Beenden aller Sitzungen bitte Ihr Passwort eingeben.'));
        if ($error !== null) {
            $this->flash('error', $error);
            Http::redirect('/admin/users');
        }
        Auth::endAllSessions();
        $this->audit(fn () => __('Alle Sitzungen beendet'));
        $me = Auth::user();
        $this->withoutSession(static fn () => AccountSecurity::adminChange(AccountSecurity::SESSIONS_ENDED, null, $me));
        $this->flash('success', __('Alle Sitzungen beendet: Alle Konten müssen sich auf allen Geräten neu anmelden, nur diese Sitzung bleibt bestehen.'));
        Http::redirect('/admin/users');
    }

    /** Accounts without a sign-in since then count as long inactive (users list, dashboard). */
    private static function inactiveSince(): string
    {
        return Time::now()->modify('-12 months')->format('Y-m-d H:i:s');
    }

    // ---------------------------------------------------------------- Global texts (admin)

    /**
     * Notes on outdated, hand-edited legal texts (they are not replaced automatically); with $outdated
     * (LegalTexts::outdated()) also those on texts behind the current template (outdatedHints()).
     * @param ?list<array{key: string, lang: string, known: bool, changes: list<array{heading: ?string}>}> $outdated
     * @return list<string>
     */
    private static function legalHints(?array $outdated = null): array
    {
        $list = static fn (array $rows) => strtoupper(implode(', ', array_column($rows, 'lang')));
        $hints = [];
        $deepl = Db::all("SELECT lang FROM pages WHERE page_key = 'privacy' AND content LIKE '%DeepL%' ORDER BY lang");
        if ($deepl) {
            $hints[] = __('Die Datenschutzerklärung ({languages}) nennt noch DeepL. Übersetzt wird inzwischen im Browser der Lehrkraft – bitte den Absatz „Übersetzung der Texte der Lehrkraft“ durch den aus der Vorlage ersetzen.', ['languages' => $list($deepl)]);
        }
        // Old model: teacher as controller, operator as processor
        $old = Db::all(
            "SELECT lang FROM pages WHERE page_key = 'privacy' AND (content LIKE '%jeweils die Lehrkraft, bei der Sie%'
                OR content LIKE '%im Auftrag der Lehrkräfte%' OR content LIKE '%is the teacher with whom you book%'
                OR content LIKE '%on behalf of the teachers%') ORDER BY lang"
        );
        if ($old) {
            $hints[] = __('Die Datenschutzerklärung ({languages}) beschreibt noch das alte Modell (Lehrkräfte verantwortlich, Betreiberin als Auftragsverarbeiterin). Verantwortlich ist jetzt der Betreiber (die Angaben für Impressum und Datenschutz) – bitte Abschnitt 1, 6 und 9 an die Vorlage anpassen.', ['languages' => $list($old)]);
        }
        // Wording that does not fit the operator type (LegalTexts::mismatches()) or no longer fits at all
        // (LegalTexts::obsolete()), per kind the languages
        $mismatch = [];
        // Only texts of offered languages (admins cannot edit the others) that are not emptied (pick(): missing)
        foreach (LegalTexts::stored() as $texts) {
            foreach ($texts as $lang => $content) {
                foreach (trim($content) !== '' ? [...LegalTexts::mismatches($content, Operator::type()), ...LegalTexts::obsolete($content)] : [] as $kind) {
                    $mismatch[$kind][(string) $lang] = ['lang' => (string) $lang];
                }
            }
        }
        ksort($mismatch);
        foreach ($mismatch as &$langs) {
            ksort($langs);
        }
        unset($langs);
        if (isset($mismatch['old'])) {
            // Operated by the school, but the template up to v1.6: "private service" without a data protection
            // officer, legal bases for private operators only, "cancel at any time"
            $hints[] = __('Impressum bzw. Datenschutzerklärung ({languages}) folgen noch der alten Vorlage („privates Angebot“ ohne Datenschutzbeauftragte, Termin „jederzeit“ absagen). Für eine öffentliche Schule sind darin Rechtsgrundlagen, Datenschutzbeauftragte und Rechte falsch angegeben – bitte mit der passenden neuen Vorlage abgleichen („Vorlage laden“ beim jeweiligen Text).', ['languages' => $list($mismatch['old'])]);
        }
        if (isset($mismatch['private'])) {
            $hints[] = __('Die Datenschutzerklärung ({languages}) stützt sich auf Art. 6 Abs. 1 lit. b bzw. f DSGVO wie die Vorlage „Privat betrieben“. Für eine öffentliche Schule gilt Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz des Landes – bitte mit der Vorlage „Von der Schule betrieben“ abgleichen („Vorlage laden“) oder die Betriebsart prüfen.', ['languages' => $list($mismatch['private'])]);
        }
        if (isset($mismatch['school'])) {
            $hints[] = __('Impressum bzw. Datenschutzerklärung ({languages}) enthalten noch Angaben der Vorlage für Schulen (Art. 6 Abs. 1 lit. e DSGVO, Schulgesetz, Datenschutzbeauftragte der Schule). Für eine privat betriebene Terminvergabe passen sie nicht – bitte mit der Vorlage „Privat betrieben“ abgleichen („Vorlage laden“ beim jeweiligen Text) oder die Betriebsart prüfen.', ['languages' => $list($mismatch['school'])]);
        }
        if (isset($mismatch['scope'])) {
            // Operated privately, but the private template up to talkslot PR #39: the operator as controller for
            // all booked appointments and a promised deletion of the summary mails in the teacher's mailbox
            $hints[] = __('Die Datenschutzerklärung ({languages}) nennt den Betreiber noch als Verantwortlichen für „alle darüber gebuchten Termine“ und sagt vermutlich auch die Löschung der Zusammenfassungen im Postfach der Lehrkraft zu. Bei privatem Betrieb ist er nur für die Terminvergabe verantwortlich; für das Gespräch und das Postfach der Lehrkraft gelten die Regeln der Schule – bitte die Abschnitte 1, 4, 5 und 9 mit der Vorlage „Privat betrieben“ abgleichen („Vorlage laden“).', ['languages' => $list($mismatch['scope'])]);
        }
        if (isset($mismatch['checkbox'])) {
            // Expert review P1-9: the booking form no longer has the required checkbox "read the privacy notice"
            $hints[] = __('Die Datenschutzerklärung ({languages}) erwähnt noch das Pflicht-Häkchen im Buchungsformular. Das gibt es nicht mehr: Eltern werden informiert, bestätigen müssen sie nichts – bitte den letzten Absatz in Abschnitt 4 an die Vorlage anpassen.', ['languages' => $list($mismatch['checkbox'])]);
        }
        if (isset($mismatch['anytime'])) {
            $hints[] = __('Die Datenschutzerklärung ({languages}) sagt noch, Eltern könnten ihren Termin „jederzeit“ selbst absagen. Das geht nur bis zur Änderungsfrist der Terminrunde – bitte den Satz in Abschnitt 9 an die Vorlage anpassen.', ['languages' => $list($mismatch['anytime'])]);
        }
        $noPlaceholder = Db::all("SELECT lang FROM pages WHERE page_key IN ('imprint', 'privacy') AND TRIM(content) <> '' AND content NOT LIKE '%[[VERANTWORTLICHE]]%' GROUP BY lang ORDER BY lang");
        if ($noPlaceholder) {
            $hints[] = __('Impressum bzw. Datenschutzerklärung ({languages}) enthalten den Platzhalter [[VERANTWORTLICHE]] nicht – die Angaben für Impressum und Datenschutz erscheinen dort nicht automatisch.', ['languages' => $list($noPlaceholder)]);
        }
        // Reminder mails to parents: the privacy policy should name them as a purpose of the address
        $noReminder = Db::value('SELECT 1 FROM rounds WHERE remind_parents = 1 LIMIT 1') !== null
            ? Db::all("SELECT lang FROM pages WHERE page_key = 'privacy' AND lang IN ('de', 'en')
                         AND content NOT LIKE '%Erinnerung%' AND content NOT LIKE '%reminder%' ORDER BY lang")
            : [];
        if ($noReminder) {
            $hints[] = __('Die Datenschutzerklärung ({languages}) erwähnt die Erinnerung an Eltern am Vortag noch nicht – bitte den Punkt „E-Mail-Adresse“ in Abschnitt 2 an die Vorlage anpassen (oder die Erinnerung in den Runden abschalten).', ['languages' => $list($noReminder)]);
        }
        array_push($hints, ...($outdated !== null ? self::outdatedHints($outdated) : []));
        $hosting = array_values(array_filter(
            array_column(Db::all("SELECT DISTINCT lang FROM pages WHERE page_key = 'privacy' AND content LIKE '%[[HOSTER]]%' ORDER BY lang"), 'lang'),
            static fn ($l) => \App\Hosting::source((string) $l) === null
        ));
        if ($hosting) {
            $hints[] = __('Für die Datenschutzerklärung ({languages}) fehlen die Angaben zum Hoster (Konfiguration „hosting“, beim Deployment die Variablen HOSTING_DE bzw. HOSTING_EN) – bis dahin steht dort ein Platzhalter.', ['languages' => implode(', ', array_map('strtoupper', $hosting))]);
        }
        return $hints;
    }

    /**
     * What is missing before rounds can go public: the operator's details, the operator type and the open
     * placeholders in the texts (shown to admins on the dashboard and the texts page).
     * @return list<string>
     */
    private static function operatorHints(): array
    {
        $hints = [];
        if (!Operator::ready()) {
            $hints[] = __('Die Angaben für Impressum und Datenschutz fehlen – ohne sie ist keine Runde öffentlich erreichbar.');
        } elseif (!str_contains(Operator::contact(), '@')) {
            $hints[] = __('In den Angaben für Impressum und Datenschutz fehlt eine E-Mail-Adresse (für das Impressum vorgeschrieben).');
        }
        if (Operator::type() === null) {
            $hints[] = __('Bitte Betriebsart wählen: Wird die Terminvergabe von der Schule oder privat betrieben? Danach richten sich die Vorlagen und Hinweise für Impressum und Datenschutzerklärung. Bis dahin lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar.');
        } elseif (Operator::typeAuto()) {
            $hints[] = __('Die Betriebsart wurde beim Update auf „{type}“ gesetzt, weil Impressum und Datenschutzerklärung noch unverändert der bisherigen Vorlage entsprachen. Bitte prüfen Sie das unter „Texte“ und speichern Sie die Angaben dort einmal.', ['type' => Operator::typeLabel((string) Operator::type())]);
        }
        [$blocking, $translations] = LegalTexts::splitOpen(LegalTexts::open());
        if ($blocking !== []) {
            $hints[] = __('Impressum bzw. Datenschutzerklärung enthalten noch offene Platzhalter in eckigen Klammern – {texts}. Solange sie nicht ersetzt sind, lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar.', ['texts' => self::openTexts($blocking, true)]);
        }
        if ($translations !== []) {
            // Translations (expert review P1-9): parents get the English (or German) text until they are filled in
            $hints[] = __('Übersetzungen noch nicht vollständig: {texts}. Bitte ersetzen Sie dort die Platzhalter in eckigen Klammern durch dieselben Angaben wie in der deutschen Fassung. Bis dahin sehen Eltern in diesen Sprachen die englische bzw. deutsche Fassung mit einem Hinweis in ihrer Sprache; Runden lassen sich trotzdem aktivieren. Wenn Sie eine Übersetzung nicht anbieten möchten, leeren Sie den Text.', ['texts' => self::openTexts($translations, false)]);
        }
        if (($missing = LegalTexts::missing()) !== []) {
            $hints[] = __('Für {texts} gibt es in keiner dieser Sprachen einen Text: {languages}. Eltern bekommen dann keinen, und es lassen sich keine Runden aktivieren.', ['texts' => self::missingTexts($missing), 'languages' => strtoupper(implode(', ', LegalTexts::fallbackLanguages()))]);
        }
        if (LegalTexts::germanEmpty()) {
            $hints[] = __('Die deutsche Datenschutzerklärung ist leer. Auf sie verweisen alle Übersetzungen als maßgebliche Fassung; bis sie hinterlegt ist, lassen sich keine Runden aktivieren.');
        }
        // The German text is the authoritative version: what it says beyond the template, or a change made there since,
        // may be missing in the other languages
        $edited = LegalTexts::behindEditedGerman();
        if ($edited !== []) {
            $hints[] = __('Ihre deutsche Datenschutzerklärung weicht von der Vorlage ab. Diese Fassungen sind noch die Vorlage mit Ihren Angaben: {languages}. Maßgeblich ist die deutsche Fassung – bitte übertragen Sie Ihre Änderungen dorthin.', ['languages' => strtoupper(implode(', ', $edited))]);
        }
        if (($behind = array_values(array_diff(LegalTexts::behindGerman(), $edited))) !== []) {
            $hints[] = __('Die deutsche Datenschutzerklärung wurde geändert, nachdem diese Fassungen zuletzt gespeichert wurden: {languages}. Maßgeblich ist die deutsche Fassung – bitte prüfen Sie, ob sie dieselben Angaben enthalten, und speichern Sie sie danach (auch unverändert).', ['languages' => strtoupper(implode(', ', $behind))]);
        }
        return $hints;
    }

    /** The pages missing in every fallback language (LegalTexts::missing()): "Impressum und Datenschutzerklärung". */
    private static function missingTexts(array $missing): string
    {
        return implode(' ' . __('und') . ' ', array_map(self::pageLabel(...), $missing));
    }

    /**
     * The texts with open placeholders: "Datenschutzerklärung (DE, EN)", or with $details
     * "Datenschutzerklärung (DE): [LAND], [ZUSTÄNDIGE …]; …" (long placeholders shortened).
     * @param list<array{key: string, lang: string, placeholders: list<string>}> $open
     */
    private static function openTexts(array $open, bool $details): string
    {
        if ($details) {
            return implode('; ', array_map(static fn (array $t): string => self::pageLabel($t['key']) . ' (' . strtoupper($t['lang']) . '): '
                . implode(', ', array_map(static fn (string $p): string => mb_strlen($p) > 40 ? mb_substr($p, 0, 38) . '…]' : $p, $t['placeholders'])), $open));
        }
        $langs = [];
        foreach ($open as $t) {
            $langs[$t['key']][] = strtoupper($t['lang']);
        }
        return implode(', ', array_map(static fn (string $key, array $l): string => self::pageLabel($key) . ' (' . implode(', ', $l) . ')', array_keys($langs), $langs));
    }

    /**
     * Notes on the texts behind the current template of the operator type (version markers,
     * LegalTexts::outdated()): the sections the template changed since a text was stored from or reconciled
     * with it, or for a text edited before the markers existed, the sections in which it differs from it.
     * @param list<array{key: string, lang: string, known: bool, changes: list<array{heading: ?string}>}> $outdated
     * @return list<string>
     */
    private static function outdatedHints(array $outdated): array
    {
        $hints = [];
        if ($known = array_filter($outdated, static fn (array $t): bool => $t['known'])) {
            $hints[] = __('Die Vorlagen wurden geändert, seit diese Texte auf ihnen beruhen – {texts}. Bearbeitete Texte werden nicht automatisch ersetzt: Beim jeweiligen Text unter „Texte“ sehen Sie, was sich geändert hat, und können die Abschnitte übernehmen.', ['texts' => self::outdatedTexts($known)]);
        }
        if ($unknown = array_filter($outdated, static fn (array $t): bool => !$t['known'])) {
            $hints[] = __('Diese Texte weichen von der aktuellen Vorlage ab, und es ist nicht bekannt, mit welchem Stand der Vorlage sie zuletzt abgeglichen wurden – {texts}. Bearbeitete Texte werden nicht automatisch ersetzt: Beim jeweiligen Text unter „Texte“ sehen Sie die Unterschiede und können die Abschnitte übernehmen oder den Abgleich bestätigen.', ['texts' => self::outdatedTexts($unknown)]);
        }
        return $hints;
    }

    /**
     * The texts behind the template with their sections: "Datenschutzerklärung (DE): 5. Speicherdauer und
     * Löschung, 6. Empfänger" (more than three: their number).
     * @param array<array{key: string, lang: string, changes: list<array{heading: ?string}>}> $texts
     */
    private static function outdatedTexts(array $texts): string
    {
        return implode('; ', array_map(static fn (array $t): string => self::pageLabel($t['key']) . ' (' . strtoupper($t['lang']) . '): '
            . (count($t['changes']) > 3 ? __('{count} Abschnitte', ['count' => count($t['changes'])])
                : implode(', ', array_map(static fn (array $c): string => LegalTexts::sectionTitle($c['heading']), $t['changes']))), $texts));
    }

    /**
     * Details for legal notice and privacy policy and the operator type. A changed type replaces the texts
     * nobody has edited by the templates of the new type (LegalTexts::apply()); a form without the type
     * leaves it unchanged.
     */
    private function operatorSave(): void
    {
        $contact = Http::post('controller_contact');
        $type = Http::post('operator_type');
        if (mb_strlen($contact) > 2000) {
            $this->flash('error', __('Die Angaben sind zu lang (höchstens 2000 Zeichen).'));
            Http::redirect('/admin/texts');
        }
        if ($type !== '' && ($error = \App\Installer::validateType($type)) !== null) {
            $this->flash('error', $error);
            Http::redirect('/admin/texts');
        }
        Operator::save($contact);
        $this->audit(fn () => __('Angaben für Impressum und Datenschutz geändert'));
        $message = __('Angaben für Impressum und Datenschutz gespeichert.');
        if ($type !== '') {
            $changed = $type !== Operator::type();
            $confirmed = !$changed && Operator::typeAuto();
            Operator::saveType($type);
            if ($changed) {
                $replaced = LegalTexts::apply($type);
                $this->audit(fn () => __('Betriebsart geändert: {type}', ['type' => Operator::typeLabel($type)])
                    . ($replaced > 0 ? ' ' . __('({count} unveränderte Vorlagentexte ersetzt)', ['count' => $replaced]) : ''));
                $message .= $replaced > 0 ? ' ' . __('{count} unveränderte Vorlagentexte wurden durch die Vorlagen der neuen Betriebsart ersetzt.', ['count' => $replaced]) : '';
            } elseif ($confirmed) {
                // The type taken over on an update (Migrations::refreshPages) is now the admin's choice
                $this->audit(fn () => __('Betriebsart bestätigt (beim Update übernommen): {type}', ['type' => Operator::typeLabel($type)]));
            }
        }
        $this->flash('success', $message);
        Http::redirect('/admin/texts');
    }

    /** The contact for parents' questions for all accounts without their own (ParentContact). */
    private function parentContactSave(): void
    {
        $contact = ParentContact::clean(Http::post('parent_contact'));
        if (mb_strlen($contact) > ParentContact::MAX_LENGTH) {
            $this->flash('error', __('Kontakt für Eltern: höchstens {max} Zeichen.', ['max' => ParentContact::MAX_LENGTH]));
            Http::redirect('/admin/texts');
        }
        if ($contact !== ParentContact::general()) {
            ParentContact::saveGeneral($contact);
            $this->audit(fn () => $contact !== '' ? __('Kontakt für Eltern geändert: {contact}', ['contact' => $contact]) : __('Kontakt für Eltern entfernt'));
        }
        $this->flash('success', __('Kontakt für Eltern gespeichert.'));
        Http::redirect('/admin/texts');
    }

    private function texts(): void
    {
        $outdated = LegalTexts::outdated();
        $this->render('admin/texts', [
            'operatorContact' => Operator::contact(),
            'operatorType' => Operator::type(),
            'privacyTemplate' => LegalTexts::VARIANTS['privacy'][Operator::type() ?? 'school'],
            'operatorHints' => self::operatorHints(),
            'parentContact' => ParentContact::general(),
            // The notes on wording of the privacy template point to its file, those on newer templates to the text
            'legalHints' => self::legalHints(),
            'outdatedHints' => self::outdatedHints($outdated),
            // Per text "<key>/<lang>": behind the template, with (true) or without a version marker
            'outdated' => array_column(array_map(static fn (array $t): array => [$t['key'] . '/' . $t['lang'], $t['known']], $outdated), 1, 0),
            'title' => __('Impressum & Datenschutz'),
            'languages' => I18n::languages(),
            'mailTransport' => (string) \App\Config::get('mail.transport', 'mail'),
            'mailFrom' => (string) \App\Config::get('mail.from_address', ''),
        ]);
    }

    private function pageForm(string $key, string $lang): void
    {
        $this->assertLang($lang);
        // Templates for this language, the one of the operator type first; ?template=<type> puts one into the
        // form (saved only with "Speichern")
        $type = Operator::type();
        $variants = array_values(array_filter(array_unique([...($type !== null ? [$type] : []), ...array_keys(LegalTexts::VARIANTS[$key])]),
            static fn (string $v): bool => LegalTexts::template($key, $v, $lang) !== null));
        $loaded = in_array(Http::query('template'), $variants, true) ? Http::query('template') : null;
        $stored = Db::value('SELECT content FROM pages WHERE page_key = ? AND lang = ?', [$key, $lang]);
        // What the current template of the operator type changed since the text's version marker, or where the
        // text differs from it; ?merge=1 puts the text with these sections taken from the template into the form
        // (not the sections only the text has, unless the marker shows that the template dropped them)
        $update = $loaded === null && $stored !== null ? LegalTexts::updates($key, $lang, (string) $stored, $type) : null;
        $merged = $update !== null && Http::query('merge') === '1' && LegalTexts::mergeable($update);
        // The template the form is reconciled with ("base"): only that one becomes the text's version marker on
        // saving, not a newer one an update brought meanwhile
        $template = $loaded ?? $update['type'] ?? null;
        // Taken over: also the lines of the text that are left out (LegalTexts::lost()), for the admin to check
        $keys = array_column($update['changes'] ?? [], 'key');
        $content = match (true) {
            $loaded !== null => (string) LegalTexts::template($key, $loaded, $lang),
            $merged => LegalTexts::merge((string) $stored, (string) LegalTexts::template($key, $update['type'], $lang), $keys, $update['base']),
            default => (string) $stored,
        };
        $this->render('admin/page_form', [
            'title' => self::pageLabel($key) . ' (' . strtoupper($lang) . ')',
            'key' => $key,
            'lang' => $lang,
            'content' => $content,
            'variants' => $variants,
            'loaded' => $loaded,
            'type' => $type,
            'update' => $update,
            'merged' => $merged,
            'lost' => $merged ? LegalTexts::lost((string) $stored, $content, $keys, $update['base']) : [],
            'templateHash' => $template !== null ? hash('sha256', (string) LegalTexts::template($key, $template, $lang)) : '',
        ]);
    }

    private function pageSave(string $key, string $lang): void
    {
        $this->assertLang($lang);
        $content = str_replace("\r\n", "\n", Http::postRaw('content'));
        // The German text is the authoritative version (LegalTexts::behindGerman()): saved unchanged, it is not newer
        // than the translations. Saving a translation always counts, that is how admins confirm it.
        $was = $lang === 'de' ? Db::one('SELECT content, updated_at FROM pages WHERE page_key = ? AND lang = ?', [$key, $lang]) : null;
        $at = $was !== null && rtrim((string) $was['content']) === rtrim($content) ? (string) $was['updated_at'] : Time::nowSql();
        Db::run(
            'INSERT INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content = ?, updated_at = ?',
            [$key, $lang, $content, $at, $content, $at]
        );
        // Version marker (LegalTexts::base()): the template the form was loaded from or the admin reconciled the
        // text with ("base", an operator type) if it is still the one the form showed ("base_hash"), else the
        // current template of the type if the text is up to date with it (LegalTexts::follows())
        $type = Operator::type();
        $base = in_array(Http::post('base'), Operator::TYPES, true) ? Http::post('base') : null;
        $changed = $base !== null && !hash_equals(hash('sha256', (string) LegalTexts::template($key, $base, $lang)), Http::post('base_hash'));
        $base = $changed ? null : $base;
        if (($from = $base ?? ($type !== null && LegalTexts::follows($key, $lang, $content, $type, LegalTexts::base($key, $lang)) ? $type : null)) !== null) {
            LegalTexts::setBase($key, $lang, $from);
        }
        $vars = ['page' => self::pageLabel($key), 'lang' => strtoupper($lang)];
        $this->audit(fn () => $base !== null
            ? __('{page} ({lang}) geändert und mit dem aktuellen Stand der Vorlage „{template}“ abgeglichen', $vars + ['template' => $base === 'school' ? __('Von der Schule betrieben') : __('Privat betrieben')])
            : __('{page} ({lang}) geändert', $vars));
        $this->flash($changed ? 'warning' : 'success', $changed
            ? __('Text gespeichert, aber nicht als abgeglichen vermerkt: Die Vorlage wurde geändert, während das Formular offen war (z. B. durch ein Update). Bitte prüfen Sie den Hinweis zur Vorlage erneut.')
            : __('Text gespeichert.'));
        Http::redirect('/admin/page/' . $key . '/' . $lang);
    }

    private static function pageLabel(string $key): string
    {
        return $key === 'imprint' ? __('Impressum') : __('Datenschutzerklärung');
    }

    // ---------------------------------------------------------------- Helpers

    // ---------------------------------------------------------------- Templates for new rounds

    private const TEMPLATE_DEFAULTS = [
        'start' => '14:00', 'end' => '18:00', 'minutes' => 15, 'breaks' => [],
        'booking_deadline_hours' => 24, 'cancel_deadline_hours' => 12, 'delete_after_hours' => 24,
        'booking_limit' => 'round', 'code_required' => 0, 'remind_parents' => 1, 'ask_interpreter' => 0, 'location' => '', 'description' => '',
    ];

    /** Settings of a template, completed with the defaults (breaks as [start, end, label] in HH:MM). */
    private static function templateSettings(array $row): array
    {
        $s = json_decode((string) $row['data'], true);
        return array_replace(self::TEMPLATE_DEFAULTS, array_intersect_key(is_array($s) ? $s : [], self::TEMPLATE_DEFAULTS));
    }

    /** Short description for the list, e.g. "08:00–16:00 Uhr, 15 Min., 1 Pause". */
    private static function templateSummary(array $s): string
    {
        $parts = [
            __('{start}–{end} Uhr', ['start' => $s['start'], 'end' => $s['end']]),
            __('{minutes} Min. pro Termin', ['minutes' => (int) $s['minutes']]),
        ];
        if ($s['breaks']) {
            $parts[] = implode(', ', array_map(static fn ($b) => ($b[2] !== '' ? $b[2] : __('Pause')) . ' ' . $b[0] . '–' . $b[1], $s['breaks']));
        }
        $parts[] = ['round' => __('ein Termin pro Person'), 'day' => __('ein Termin pro Person und Tag')][$s['booking_limit']] ?? __('Mehrfachbuchungen erlaubt');
        if ($s['location'] !== '') {
            $parts[] = __('Ort: {location}', ['location' => $s['location']]);
        }
        return implode(' · ', $parts);
    }

    private function templates(): void
    {
        $rows = Db::all('SELECT * FROM round_templates ORDER BY name');
        foreach ($rows as &$row) {
            $row['summary'] = self::templateSummary(self::templateSettings($row));
        }
        unset($row);
        $this->render('admin/templates', ['title' => __('Vorlagen'), 'templates' => $rows]);
    }

    private function template(int $id): array
    {
        return Db::one('SELECT * FROM round_templates WHERE id = ?', [$id]) ?? $this->notFound();
    }

    private function templateForm(?int $id = null, array $errors = [], ?array $data = null): void
    {
        $row = $id ? $this->template($id) : null;
        $data ??= ['name' => $row['name'] ?? ''] + ($row ? self::templateSettings($row) : self::TEMPLATE_DEFAULTS);
        $this->render('admin/template_form', [
            'title' => $row ? __('Vorlage bearbeiten') : __('Neue Vorlage'),
            'row' => $row,
            'data' => $data,
            'dayData' => ['dates' => [''], 'start' => $data['start'], 'end' => $data['end'], 'minutes' => $data['minutes'], 'breaks' => $data['breaks']],
            'errors' => $errors,
        ]);
    }

    private function templateSave(?int $id = null): void
    {
        $row = $id ? $this->template($id) : null;
        $start = Time::normalizeTime(Http::post('start_time'));
        $end = Time::normalizeTime(Http::post('end_time'));
        $minutes = Http::post('slot_minutes');
        [$breaks, $errors] = self::breaksInput();
        $deleteAfter = Http::post('delete_after_hours');
        $data = [
            'name' => trim(Http::post('name')),
            'start' => $start ? fmt_time($start) : Http::post('start_time'),
            'end' => $end ? fmt_time($end) : Http::post('end_time'),
            'minutes' => (int) $minutes,
            'breaks' => array_map(static fn ($b) => [fmt_time($b[0]), fmt_time($b[1]), $b[2]], $breaks),
            'booking_deadline_hours' => Http::post('booking_deadline_hours'),
            'cancel_deadline_hours' => Http::post('cancel_deadline_hours'),
            'delete_after_hours' => $deleteAfter,
            'booking_limit' => in_array(Http::post('booking_limit'), BookingService::LIMITS, true) ? Http::post('booking_limit') : 'round',
            'code_required' => Http::post('code_required') === '1' ? 1 : 0,
            'remind_parents' => Http::post('remind_parents') === '1' ? 1 : 0,
            'ask_interpreter' => Http::post('ask_interpreter') === '1' ? 1 : 0,
            'location' => trim(Http::post('location')),
            'description' => trim(str_replace("\r\n", "\n", Http::post('description'))),
        ];
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            $errors[] = __('Bitte einen Namen für die Vorlage angeben (max. 100 Zeichen).');
        } elseif (Db::value('SELECT id FROM round_templates WHERE name = ? AND id <> ?', [$data['name'], $id ?? 0]) !== null) {
            $errors[] = __('Eine Vorlage mit diesem Namen gibt es bereits.');
        }
        $errors = array_merge($errors, self::timeErrors($start, $end, $minutes));
        if (!$errors && !SlotGenerator::compute($start, $end, (int) $minutes, array_map(
            static fn ($b) => ['start_time' => $b[0], 'end_time' => $b[1]], $breaks
        ))) {
            $errors[] = self::noSlots();
        }
        foreach ([
            'booking_deadline_hours' => __('Buchungsfrist: ganze Zahl zwischen 0 und 720 Stunden.'),
            'cancel_deadline_hours' => __('Änderungsfrist: ganze Zahl zwischen 0 und 720 Stunden.'),
        ] as $field => $error) {
            if (!ctype_digit((string) $data[$field]) || (int) $data[$field] > 720) {
                $errors[] = $error;
            }
        }
        if ($deleteAfter !== '' && (!ctype_digit($deleteAfter) || (int) $deleteAfter > 8760)) {
            $errors[] = __('Löschfrist: leer lassen (nie löschen) oder 0–8760 Stunden.');
        }
        if (mb_strlen($data['location']) > 200) {
            $errors[] = __('Ort: höchstens 200 Zeichen.');
        }
        if (mb_strlen($data['description']) > 5000) {
            $errors[] = __('Der Beschreibungstext ist zu lang (max. 5000 Zeichen).');
        }
        if ($errors) {
            $this->templateForm($id, $errors, $data);
            return;
        }
        $settings = ['start' => $data['start'], 'end' => $data['end'], 'minutes' => $data['minutes'], 'breaks' => $data['breaks'],
            'booking_deadline_hours' => (int) $data['booking_deadline_hours'], 'cancel_deadline_hours' => (int) $data['cancel_deadline_hours'],
            'delete_after_hours' => $deleteAfter === '' ? null : (int) $deleteAfter] + $data;
        unset($settings['name']);
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($row) {
            Db::run('UPDATE round_templates SET name = ?, data = ?, updated_at = ? WHERE id = ?', [$data['name'], $json, Time::nowSql(), $id]);
            $this->audit(fn () => __('Vorlage „{name}“ bearbeitet', ['name' => $data['name']])
                . ($row['name'] !== $data['name'] ? ' (' . __('bisher „{title}“', ['title' => $row['name']]) . ')' : ''));
        } else {
            Db::insert('INSERT INTO round_templates (name, data, created_at, updated_at) VALUES (?, ?, ?, ?)', [$data['name'], $json, Time::nowSql(), Time::nowSql()]);
            $this->audit(fn () => __('Vorlage „{name}“ angelegt', ['name' => $data['name']]));
        }
        $this->flash('success', __('Vorlage gespeichert. Sie steht jetzt beim Anlegen einer neuen Runde zur Auswahl.'));
        Http::redirect('/admin/templates');
    }

    private function templateDelete(int $id): void
    {
        $row = $this->template($id);
        Db::run('DELETE FROM round_templates WHERE id = ?', [$id]);
        $this->audit(fn () => __('Vorlage „{name}“ gelöscht', ['name' => $row['name']]));
        $this->flash('success', __('Vorlage gelöscht. Bereits angelegte Runden bleiben unverändert.'));
        Http::redirect('/admin/templates');
    }

    /** Admin: new template from a round (times and breaks of its first day, settings of the round). */
    private function templateFromRound(int $roundId): void
    {
        $round = $this->round($roundId);
        $day = Db::one('SELECT * FROM round_days WHERE round_id = ? ORDER BY day_date, start_time LIMIT 1', [$roundId]);
        $settings = [
            'booking_deadline_hours' => (int) $round['booking_deadline_hours'], 'cancel_deadline_hours' => (int) $round['cancel_deadline_hours'],
            'delete_after_hours' => $round['delete_after_hours'] === null ? null : (int) $round['delete_after_hours'],
            'booking_limit' => (string) $round['booking_limit'], 'code_required' => (int) $round['code_required'],
            'remind_parents' => (int) $round['remind_parents'], 'ask_interpreter' => (int) $round['ask_interpreter'], 'location' => (string) ($round['location'] ?? ''),
            'description' => (string) ($round['description'] ?? ''),
        ];
        if ($day) {
            $settings += ['start' => fmt_time($day['start_time']), 'end' => fmt_time($day['end_time']), 'minutes' => (int) $day['slot_minutes'],
                'breaks' => array_map(
                    static fn ($b) => [fmt_time($b['start_time']), fmt_time($b['end_time']), (string) ($b['label'] ?? '')],
                    Db::all('SELECT * FROM day_breaks WHERE day_id = ? ORDER BY start_time', [$day['id']])
                )];
        }
        $name = mb_substr($round['title'], 0, 90);
        for ($n = 2; Db::value('SELECT id FROM round_templates WHERE name = ?', [$name]) !== null; $n++) {
            $name = mb_substr($round['title'], 0, 90) . " ($n)";
        }
        $id = Db::insert(
            'INSERT INTO round_templates (name, data, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, json_encode($settings + self::TEMPLATE_DEFAULTS, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Time::nowSql(), Time::nowSql()]
        );
        $this->audit(fn () => __('Vorlage „{name}“ aus der Runde erstellt', ['name' => $name]), $roundId);
        $this->flash('success', __('Vorlage aus der Runde erstellt (Zeiten und Pausen des ersten Tages, Einstellungen der Runde). Bitte Namen und Angaben prüfen.'));
        Http::redirect('/admin/templates/' . $id . '/edit');
    }

    // ---------------------------------------------------------------- Admin log

    private const LOG_PAGE_SIZE = 100;

    private function auditLog(): void
    {
        // Paging by id ("older than entry x"), newest first
        $before = ctype_digit(Http::query('before')) ? (int) Http::query('before') : 0;
        $rows = Db::all(
            'SELECT * FROM audit_log WHERE (? = 0 OR id < ?) ORDER BY id DESC LIMIT ' . (self::LOG_PAGE_SIZE + 1),
            [$before, $before]
        );
        $more = count($rows) > self::LOG_PAGE_SIZE;
        $rows = array_slice($rows, 0, self::LOG_PAGE_SIZE);
        $this->render('admin/audit', [
            'title' => __('Protokoll'),
            'rows' => $rows,
            'older' => $more ? (int) end($rows)['id'] : 0,
            'paged' => $before > 0,
            'days' => Audit::retentionDays(),
        ]);
    }

    // ---------------------------------------------------------------- Requests for access (Art. 15 GDPR)

    /** Form for a request for access about one e-mail address (admins only, see AccessRequest). */
    private function accessRequestForm(): void
    {
        $this->render('admin/access_request', ['title' => __('Auskunft zu einer E-Mail-Adresse'), 'email' => '', 'result' => null, 'errors' => []]);
    }

    /**
     * The search: a POST, so that the address appears in no address bar, browser history or server log; only with
     * the box that it answers a request for access; logged with the number of hits, without the address (the log
     * holds no data of parents).
     */
    private function accessRequestSearch(): void
    {
        $email = Email::normalize(Http::post('email')); // stored form, also for a domain with umlauts
        $errors = [];
        if ($email === null) {
            $errors[] = __('Die E-Mail-Adresse ist ungültig.');
        }
        if (Http::post('purpose') !== '1') {
            $errors[] = __('Bitte bestätigen Sie, dass Sie ein Auskunftsersuchen der Person bearbeiten, der die Adresse gehört.');
        }
        if ($errors) {
            http_response_code(422);
            $this->render('admin/access_request', ['title' => __('Auskunft zu einer E-Mail-Adresse'), 'email' => mb_substr(Http::post('email'), 0, 254), 'result' => null, 'errors' => $errors]);
            return;
        }
        $result = AccessRequest::find($email);
        $this->audit(fn () => __('Auskunftssuche zu einer E-Mail-Adresse (Auskunftsersuchen): {bookings} Buchung(en), {mails} wartende Absage-E-Mail(s) gefunden',
            ['bookings' => count($result['bookings']), 'mails' => count($result['cancellations'])]));
        $this->render('admin/access_request', ['title' => __('Auskunft zu einer E-Mail-Adresse'), 'email' => $email, 'result' => $result, 'errors' => []]);
    }

    /**
     * Logs a change if an admin made it (see Audit). With $round (its id, or roundLabel() fetched before the
     * round was deleted) the round is named first.
     * $message is a closure so that the entry is written in the default language of the installation,
     * whatever the language of the admin (one language in the log).
     */
    private function audit(\Closure $message, int|array|null $round = null): void
    {
        if (!Auth::isAdmin()) {
            return;
        }
        $round = is_int($round) ? self::roundLabel($round) : $round;
        Audit::log(AdminI18n::with(AdminI18n::default(), static function () use ($message, $round): string {
            $text = $message();
            if ($round === null) {
                return $text;
            }
            return __('Runde „{title}“ ({owner}): {message}', ['title' => $round['title'] ?? '?', 'owner' => $round['owner'] ?? '?', 'message' => $text]);
        }));
    }

    /** Title and teacher of a round, for the log. */
    private static function roundLabel(int $roundId): array
    {
        return Db::one(
            "SELECT r.title, COALESCE(NULLIF(u.display_name, ''), u.username) AS owner FROM rounds r JOIN users u ON u.id = r.user_id WHERE r.id = ?",
            [$roundId]
        ) ?? [];
    }

    /** For the log: how many parents were told by mail (no names, see Audit). */
    private static function parentsAudit(ScheduleChange $parents): string
    {
        return $parents->mailed > 0 ? ', ' . __('Eltern von {count} Buchung(en) per E-Mail informiert', ['count' => $parents->mailed]) : '';
    }

    private static function when(string $date, ?string $time = null): string
    {
        return fmt_datetime($date, 'd.m.Y') . ($time !== null ? ' ' . substr($time, 0, 5) : '');
    }

    private static function accountLabel(array $user): string
    {
        return __('„{name}“', ['name' => $user['display_name'] !== '' ? $user['display_name'] : $user['username']]);
    }

    private static function roleLabel(string $role): string
    {
        return $role === 'admin' ? __('Admin') : __('Lehrkraft');
    }

    private function render(string $template, array $vars, string $layout = 'admin/layout'): void
    {
        $vars['flash'] = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        View::render($template, $vars, $layout);
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [$type, $message];
    }

    /**
     * After a change by the teacher (ScheduleService): which parents get a mail, and who has to be told in
     * person, named once with the address (after a deletion nothing shows them any more), so nobody relies on
     * the system having informed them. Nothing if no upcoming booking is concerned.
     * $resend: the bookings still exist (new details), so their parents can also be sent them from the entry.
     */
    private function parentsFlash(ScheduleChange $parents, bool $resend = false): void
    {
        if ($parents->mailed > 0) {
            $this->flash('info', $resend
                ? __('Die Eltern von {count} Buchung(en) erhalten die neuen Termindaten per E-Mail in ihrer Sprache.', ['count' => $parents->mailed])
                : __('Die Eltern von {count} Buchung(en) erhalten eine Absage per E-Mail in ihrer Sprache.', ['count' => $parents->mailed]));
        }
        $names = static fn (array $persons): string => implode('; ', array_map(
            static fn (array $p): string => $p['name'] . ($p['email'] !== '' ? ', ' . $p['email'] : '') . ' (' . self::when($p['day_date'], $p['start_time']) . ')',
            $persons
        ));
        if ($parents->held) {
            $this->flash('warning', __('Keine E-Mail an die Eltern von {count} Buchung(en): Ihr Konto hat die Höchstzahl von Änderungsmitteilungen je Stunde erreicht. Bitte persönlich informieren oder später den Eintrag mit „Änderung per E-Mail mitteilen“ speichern:', ['count' => count($parents->held)])
                . ' ' . $names($parents->held) . '.');
        }
        if (!$parents->inPerson) {
            return;
        }
        $list = $names($parents->inPerson);
        $count = ['count' => count($parents->inPerson)];
        $this->flash('warning', $parents->notify
            ? __('Keine E-Mail an die Eltern von {count} Buchung(en) (keine Adresse hinterlegt oder Eintrag nie per E-Mail bestätigt) – bitte persönlich informieren:', $count) . ' ' . $list . '.'
            : __('Die Eltern von {count} Buchung(en) wurden NICHT automatisch benachrichtigt – bitte selbst informieren.', $count)
                . ($resend ? ' ' . __('Eltern mit E-Mail-Adresse erhalten die neuen Daten in ihrer Sprache, wenn Sie ihren Eintrag bearbeiten und mit „Änderung per E-Mail mitteilen“ speichern.') : '')
                . ' ' . __('Betroffen:') . ' ' . $list . '.');
    }

    /**
     * Upcoming bookings concerned by a change, and how many of their parents can be told by mail
     * (ScheduleService::mailable()): for the hints and dialogs of the forms.
     * @return array{upcoming:int,mailable:int}
     */
    private static function parentCounts(array $bookings): array
    {
        return ['upcoming' => count($bookings), 'mailable' => count(array_filter($bookings, [ScheduleService::class, 'mailable']))];
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->render('admin/message', ['title' => __('Nicht gefunden'), 'text' => __('Der Eintrag existiert nicht (mehr).')]);
        exit;
    }

    private function assertLang(string $lang): void
    {
        if (!in_array($lang, I18n::languages(), true)) {
            $this->notFound();
        }
    }

    // Access control: all records are only loaded if the round belongs to the logged-in
    // person (the admin may access all). Foreign IDs behave like non-existent ones (404).

    /** May the logged-in user see and edit rounds of this teacher? Admin: all. */
    private static function mayAccess(int $ownerId): bool
    {
        return Auth::isAdmin() || $ownerId === Auth::id();
    }

    private function round(int $id): array
    {
        $round = Db::one('SELECT * FROM rounds WHERE id = ?', [$id]);
        return $round && self::mayAccess((int) $round['user_id']) ? $round : $this->notFound();
    }

    private function day(int $id): array
    {
        $day = Db::one(
            'SELECT d.*, r.user_id AS owner_id FROM round_days d JOIN rounds r ON r.id = d.round_id WHERE d.id = ?',
            [$id]
        );
        return $day && self::mayAccess((int) $day['owner_id']) ? $day : $this->notFound();
    }

    private function slot(int $id): array
    {
        $slot = Db::one(
            'SELECT s.*, d.day_date, d.round_id, d.id AS day_id, r.user_id AS owner_id
               FROM slots s JOIN round_days d ON d.id = s.day_id JOIN rounds r ON r.id = d.round_id
              WHERE s.id = ?',
            [$id]
        );
        return $slot && self::mayAccess((int) $slot['owner_id']) ? $slot : $this->notFound();
    }

    private function booking(int $id): array
    {
        $booking = BookingService::findById($id);
        return $booking && self::mayAccess((int) $booking['user_id']) ? $booking : $this->notFound();
    }
}

/** Route parameters: digits as int, otherwise string. */
function intval_or_string(string $v): int|string
{
    return ctype_digit($v) ? (int) $v : $v;
}
