<?php
declare(strict_types=1);

namespace App\Controller;

use App\AccountLinks;
use App\AdminI18n;
use App\Audit;
use App\Auth;
use App\BookingException;
use App\BookingService;
use App\Csrf;
use App\Db;
use App\Http;
use App\I18n;
use App\Mailer;
use App\Notifier;
use App\Operator;
use App\RateLimiter;
use App\RoundAccess;
use App\RoundCode;
use App\SlotGenerator;
use App\Slug;
use App\Time;
use App\Translator;
use App\View;

/**
 * Admin area (German or English, see AdminI18n).
 * Teachers see and change only their own rounds; admin accounts see and edit all
 * rounds including bookings, manage accounts and the global texts. Their changes are logged (Audit).
 * Enforced centrally in round()/day()/slot()/booking() via mayAccess().
 */
final class AdminController
{
    private const ROUTES = [
        ['GET', '#^/$#', 'dashboard'],
        ['POST', '#^/logout$#', 'logout'],
        ['GET', '#^/round/new$#', 'roundForm'],
        ['POST', '#^/round/new$#', 'roundSave'],
        ['GET', '#^/round/(\d+)$#', 'roundShow'],
        ['GET', '#^/round/(\d+)/edit$#', 'roundForm'],
        ['POST', '#^/round/(\d+)/edit$#', 'roundSave'],
        ['POST', '#^/round/(\d+)/toggle$#', 'roundToggle'],
        ['POST', '#^/round/(\d+)/duplicate$#', 'roundDuplicate'],
        ['POST', '#^/round/(\d+)/delete$#', 'roundDelete'],
        ['POST', '#^/round/(\d+)/day$#', 'dayAdd'],
        ['GET', '#^/round/(\d+)/print$#', 'printView'],
        ['POST', '#^/round/(\d+)/translations$#', 'translationsSave'],
        ['POST', '#^/round/(\d+)/translations/auto$#', 'translationsAuto'],
        ['GET', '#^/day/(\d+)/edit$#', 'dayForm'],
        ['POST', '#^/day/(\d+)/edit$#', 'daySave'],
        ['POST', '#^/day/(\d+)/delete$#', 'dayDelete'],
        ['POST', '#^/day/(\d+)/break$#', 'breakAdd'],
        ['POST', '#^/break/(\d+)/delete$#', 'breakDelete'],
        ['POST', '#^/slot/(\d+)/block$#', 'slotToggleBlock'],
        ['GET', '#^/slot/(\d+)/book$#', 'bookingNew'],
        ['POST', '#^/slot/(\d+)/book$#', 'bookingCreate'],
        ['GET', '#^/booking/(\d+)/edit$#', 'bookingEdit'],
        ['POST', '#^/booking/(\d+)/edit$#', 'bookingUpdate'],
        ['POST', '#^/booking/(\d+)/delete$#', 'bookingDelete'],
        ['GET', '#^/profile$#', 'profile'],
        ['POST', '#^/profile$#', 'profileSave'],
        ['POST', '#^/password$#', 'passwordSave'],
        ['GET', '#^/password-change$#', 'passwordChangeForm'],
        ['POST', '#^/password-change$#', 'passwordChangeSave'],
        // admin role only (see ADMIN_ONLY)
        ['GET', '#^/users$#', 'users'],
        ['GET', '#^/users/new$#', 'userForm'],
        ['POST', '#^/users/new$#', 'userSave'],
        ['GET', '#^/users/(\d+)/edit$#', 'userForm'],
        ['POST', '#^/users/(\d+)/edit$#', 'userSave'],
        ['POST', '#^/users/(\d+)/reset-password$#', 'userResetPassword'],
        ['POST', '#^/users/(\d+)/delete$#', 'userDelete'],
        ['GET', '#^/texts$#', 'texts'],
        ['POST', '#^/texts/operator$#', 'operatorSave'],
        ['GET', '#^/page/(imprint|privacy)/([a-z]{2})$#', 'pageForm'],
        ['POST', '#^/page/(imprint|privacy)/([a-z]{2})$#', 'pageSave'],
        ['GET', '#^/log$#', 'auditLog'],
        ['GET', '#^/templates$#', 'templates'],
        ['GET', '#^/templates/new$#', 'templateForm'],
        ['POST', '#^/templates/new$#', 'templateSave'],
        ['GET', '#^/templates/(\d+)/edit$#', 'templateForm'],
        ['POST', '#^/templates/(\d+)/edit$#', 'templateSave'],
        ['POST', '#^/templates/(\d+)/delete$#', 'templateDelete'],
        ['POST', '#^/templates/from-round/(\d+)$#', 'templateFromRound'],
    ];

    /** Paths that only the admin role may access */
    private const ADMIN_ONLY = '#^/(users|texts|page|log|templates)(/|$)#';

    public function dispatch(string $path, string $method): void
    {
        // Login, "forgot password" and invitation need no session without an existing cookie
        // (stateless CSRF tokens, see formToken()); everything else does
        Auth::startSession(!($path === '/login' || $path === '/forgot' || str_starts_with($path, '/invite/')));
        header('X-Robots-Tag: noindex, nofollow');
        AdminI18n::set(AdminI18n::forRequest(Auth::check() ? Auth::user() : null));

        if ($path === '/login') {
            $method === 'POST' ? $this->loginPost() : $this->login();
            return;
        }
        // Invitation/password link and "forgot password" work without login
        if (preg_match('#^/invite/([A-Za-z0-9_-]{43})$#', $path, $m)) {
            $method === 'POST' ? $this->inviteSave($m[1]) : $this->inviteForm($m[1]);
            return;
        }
        if ($path === '/forgot') {
            $method === 'POST' ? $this->forgotSave() : $this->forgotForm();
            return;
        }
        if (!Auth::check()) {
            // e.g. account deactivated in the meantime or password changed; notices (such as "session
            // expired") are kept
            $flash = $_SESSION['flash'] ?? [];
            Auth::logout();
            Auth::startSession();
            if ($flash) {
                $_SESSION['flash'] = $flash;
            }
            Http::redirect('/admin/login');
        }
        if ($method === 'POST' && !Csrf::checkAdmin($_POST['csrf'] ?? null)) {
            $this->flash('error', __('Das Formular ist abgelaufen. Bitte erneut versuchen.'));
            Http::redirect('/admin');
        }
        // The initial password must be replaced before anything else
        if (Auth::user()['must_change_password'] && !in_array($path, ['/password-change', '/logout'], true)) {
            Http::redirect('/admin/password-change');
        }
        if (preg_match(self::ADMIN_ONLY, $path) && !Auth::isAdmin()) {
            $this->notFound();
        }
        foreach (self::ROUTES as [$verb, $pattern, $action]) {
            if ($verb === $method && preg_match($pattern, $path, $m)) {
                $this->$action(...array_map(__NAMESPACE__ . '\intval_or_string',array_slice($m, 1)));
                return;
            }
        }
        http_response_code(404);
        $this->render('admin/message', ['title' => __('Nicht gefunden'), 'text' => __('Diese Seite gibt es nicht.')]);
    }

    // ---------------------------------------------------------------- Login

    private function login(string $error = ''): void
    {
        if (Auth::check()) {
            Http::redirect('/admin');
        }
        $username = (string) ($_SESSION['login_user'] ?? '');
        unset($_SESSION['login_user']);
        $this->render('admin/login', ['title' => __('Anmelden'), 'error' => $error, 'username' => $username, 'csrf' => self::formToken('login')], 'admin/layout');
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
        $result = Auth::attempt(Http::post('username'), Http::postRaw('password'));
        if ($result === true) {
            Http::redirect('/admin');
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
        $this->render('admin/dashboard', [
            'title' => $all ? __('Alle Terminrunden') : __('Meine Terminrunden'),
            'rounds' => $rounds,
            'showOwner' => $all,
            // config.php readable for every user of the server (only admins can have that fixed)
            'configPermissions' => Auth::isAdmin() ? \App\Config::exposedPermissions() : null,
            'profileHints' => $this->profileHints(),
            'operatorMissing' => !Operator::ready(),
            'legalHints' => Auth::isAdmin() ? array_merge(self::operatorHints(), self::legalHints()) : [],
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
                    'booking_limit' => $s['booking_limit'], 'remind_parents' => $s['remind_parents'], 'user_id' => Auth::id(),
                ];
                $dayData = ['dates' => [''], 'start' => $s['start'], 'end' => $s['end'], 'minutes' => $s['minutes'], 'breaks' => $s['breaks']];
            }
        }
        $data ??= $round ?? [
            'title' => '', 'slug' => '', 'description' => '', 'location' => '', 'source_lang' => I18n::defaultLanguage(), 'is_active' => 1,
            'booking_deadline_hours' => 24, 'cancel_deadline_hours' => 12,
            'delete_after_hours' => 24, 'random_suffix' => 1,
            'code_required' => 0, 'booking_limit' => 'round', 'remind_parents' => 1, 'user_id' => Auth::id(),
        ];
        $this->render('admin/round_form', [
            'title' => $round ? __('Runde bearbeiten') : __('Neue Runde'),
            'round' => $round,
            'data' => $data,
            'owners' => Auth::isAdmin() ? self::ownerChoices((int) ($round['user_id'] ?? 0)) : [],
            'dayData' => $dayData ?? self::defaultDayData(),
            'errors' => $errors,
            'templates' => $round ? [] : Db::all('SELECT id, name FROM round_templates ORDER BY name'),
            'template' => $template,
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
            // Teacher of the round: only the admin chooses; otherwise whoever creates it or the previous teacher
            'user_id' => Auth::isAdmin() && ctype_digit(Http::post('user_id')) ? (int) Http::post('user_id') : (int) ($round['user_id'] ?? Auth::id()),
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
            $errors[] = $hint;
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
            $data['code_required'], $data['booking_limit'], $data['remind_parents'], $data['is_active'],
            (int) $data['booking_deadline_hours'], (int) $data['cancel_deadline_hours'],
            $deleteAfter === '' ? null : (int) $deleteAfter, $now,
        ];
        if ($round) {
            Db::run(
                'UPDATE rounds SET title = ?, slug = ?, description = ?, location = ?, source_lang = ?, code_required = ?, booking_limit = ?, remind_parents = ?, is_active = ?, booking_deadline_hours = ?,
                        cancel_deadline_hours = ?, delete_after_hours = ?, updated_at = ?,
                        expired_at = IF(? = 1, NULL, expired_at), user_id = ?
                  WHERE id = ? AND user_id = ?',
                [...$values, $data['is_active'], (int) $owner['id'], $id, (int) $round['user_id']]
            );
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
                . ($newCode ? ', ' . __('neuer Rundencode') : ''), $id);
            if ($ownerChanged) {
                $booked = (int) Db::value('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id WHERE d.round_id = ?', [$id]);
                $this->flash('info', __('Die Runde gehört jetzt {name}.', ['name' => $owner['display_name'] ?: $owner['username']])
                    . ($booked ? ' ' . __('Die {count} bestehende(n) Buchung(en) sieht ab jetzt diese Lehrkraft; Zusammenfassungen gehen an sie.', ['count' => $booked]) : ''));
            }
        } else {
            // Round and days together: if creating the days fails, no empty round is left behind
            $ownerId = (int) $owner['id'];
            [$id, $count] = Db::transaction(function () use ($values, $now, $dayData, $ownerId): array {
                $id = Db::insert(
                    'INSERT INTO rounds (title, slug, description, location, source_lang, code_required, booking_limit, remind_parents, is_active,
                                         booking_deadline_hours, cancel_deadline_hours, delete_after_hours, updated_at, created_at, user_id, code)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$values, $now, $ownerId, RoundCode::unique()]
                );
                return [$id, $this->createDays($id, $dayData)];
            });
            $this->flash('success', __('Runde angelegt: {days} Tag(e), {slots} Termine erzeugt.', ['days' => count($dayData['dates']), 'slots' => $count]));
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
            $day['slots'] = Db::all(
                'SELECT s.*, b.id AS booking_id, b.name, b.email, b.source, b.created_at AS booked_at, b.updated_at AS booking_updated
                   FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id
                  WHERE s.day_id = ? ORDER BY s.start_time',
                [$day['id']]
            );
            foreach ($day['slots'] as $s) {
                $conflicts += (int) $s['is_conflict'];
            }
        }
        unset($day);
        $this->render('admin/round', [
            'title' => $round['title'],
            'round' => $round,
            'days' => $days,
            'conflicts' => $conflicts,
            'publicUrl' => abs_url('/' . $round['slug']),
            'homeUrl' => abs_url('/'),
            'invitation' => self::invitationText($round, $owner),
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

    /** Text to pass on to parents (link and access code if applicable). */
    private static function invitationText(array $round, array $owner): string
    {
        $lines = [
            __('Liebe Eltern,'),
            '',
            __('über den folgenden Link können Sie einen Gesprächstermin für „{title}“ bei {teacher} buchen:', ['title' => $round['title'], 'teacher' => $owner['display_name'] ?: __('mir')]),
            abs_url('/' . $round['slug']),
        ];
        if (trim((string) ($round['location'] ?? '')) !== '') {
            $lines[] = '';
            $lines[] = __('Ort: {location}', ['location' => trim((string) $round['location'])]);
        }
        $code = RoundCode::format($round['code'] ?? null);
        if ($code !== '' && RoundAccess::required($round)) {
            $lines[] = '';
            $lines[] = __('Code der Terminvergabe: {code} (wird nach dem Öffnen des Links abgefragt).', ['code' => $code]);
            $lines[] = __('Alternativ können Sie auf {url} nur diesen Code eingeben.', ['url' => abs_url('/')]);
            $lines[] = __('Bitte geben Sie Link und Code nicht weiter.');
        } elseif ($code !== '') {
            $lines[] = __('Alternativ können Sie auf {url} den Code der Terminvergabe {code} eingeben.', ['url' => abs_url('/'), 'code' => $code]);
        }
        $lines[] = '';
        $lines[] = __('Bitte buchen Sie nur einen Termin. Nach der Buchung erhalten Sie einen persönlichen Link, über den Sie den Termin ändern oder absagen können.');
        $lines[] = '';
        $lines[] = __('Viele Grüße');
        return implode("\n", $lines);
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
        Db::run('UPDATE rounds SET is_active = ?, expired_at = IF(? = 1, NULL, expired_at), updated_at = ? WHERE id = ?', [$active, $active, Time::nowSql(), $id]);
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
                'INSERT INTO rounds (title, slug, description, location, source_lang, code_required, booking_limit, remind_parents, is_active,
                                     booking_deadline_hours, cancel_deadline_hours, delete_after_hours, created_at, updated_at, user_id, code)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)',
                [$title, Slug::unique(Slug::withSuffix(Slug::fromText($title))), $round['description'], $round['location'], $round['source_lang'],
                 (int) $round['code_required'], (string) $round['booking_limit'], (int) $round['remind_parents'],
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
        $this->audit(fn () => $booked ? __('gelöscht (mit {count} Buchung(en))', ['count' => $booked]) : __('gelöscht'), $id);
        Db::run('DELETE FROM rounds WHERE id = ?', [$id]);
        $this->flash('success', __('Runde mit allen Tagen und Buchungen gelöscht.'));
        Http::redirect('/admin');
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

    private function dayForm(int $id, array $errors = []): void
    {
        $day = $this->day($id);
        $this->render('admin/day_form', [
            'title' => __('Tag bearbeiten'),
            'day' => $day,
            'round' => $this->round((int) $day['round_id']),
            'errors' => $errors,
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
            $this->dayForm($id, $errors);
            return;
        }
        Db::run(
            'UPDATE round_days SET day_date = ?, start_time = ?, end_time = ?, slot_minutes = ?, location = ? WHERE id = ?',
            [$in['date'], $in['start'], $in['end'], $in['minutes'], $in['location'] !== '' ? $in['location'] : null, $id]
        );
        // As with "Tag hinzufügen": a day from today on lifts the expiry (otherwise the round including
        // its bookings would be deleted after the deletion period)
        if ($in['date'] >= Time::today()) {
            Db::run('UPDATE rounds SET expired_at = NULL WHERE id = ? AND expired_at IS NOT NULL', [$day['round_id']]);
        }
        $this->audit(fn () => $in['date'] !== $day['day_date']
            ? __('Tag {date} bearbeitet (jetzt {new})', ['date' => self::when($day['day_date']), 'new' => self::when($in['date'])])
            : __('Tag {date} bearbeitet', ['date' => self::when($day['day_date'])]), (int) $day['round_id']);
        $this->afterSync((int) $day['round_id'], $id, __('Tag gespeichert.'));
    }

    private function dayDelete(int $id): void
    {
        $day = $this->day($id);
        $bookings = (int) Db::value('SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id = b.slot_id WHERE s.day_id = ?', [$id]);
        if ($bookings > 0 && Http::post('confirm') !== '1') {
            $this->flash('error', __('An diesem Tag gibt es {count} Buchung(en). Bitte das Löschen ausdrücklich bestätigen.', ['count' => $bookings]));
            Http::redirect('/admin/round/' . $day['round_id'], [], 'day-' . $id);
        }
        $this->audit(fn () => $bookings
            ? __('Tag {date} gelöscht (mit {count} Buchung(en))', ['date' => self::when($day['day_date']), 'count' => $bookings])
            : __('Tag {date} gelöscht', ['date' => self::when($day['day_date'])]), (int) $day['round_id']);
        Db::run('DELETE FROM round_days WHERE id = ?', [$id]);
        $this->flash('success', $bookings ? __('Tag gelöscht (inkl. {count} Buchung(en)).', ['count' => $bookings]) : __('Tag gelöscht.'));
        Http::redirect('/admin/round/' . $day['round_id']);
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

    private function afterSync(int $roundId, int $dayId, string $message): never
    {
        $conflicts = SlotGenerator::sync($dayId);
        if ($conflicts > 0) {
            $this->flash('warning', $message . ' ' . __('Achtung: {count} gebuchte(r) Termin(e) passen nicht mehr zum Zeitplan und sind als Konflikt markiert. Bitte umbuchen oder löschen.', ['count' => $conflicts]));
        } else {
            $this->flash('success', $message . ' ' . __('Slots wurden aktualisiert.'));
        }
        Http::redirect('/admin/round/' . $roundId, [], 'day-' . $dayId);
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
            'old' => $old + ['name' => '', 'email' => '', 'lang' => I18n::defaultLanguage(), 'send_mail' => false],
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
            [$id, $token] = BookingService::adminBook($round, $slotId, PublicController::cleanName($old['name']), $old['email'] ?: null, $old['lang']);
        } catch (BookingException) {
            $this->flash('error', __('Dieser Slot ist bereits belegt.'));
            Http::redirect('/admin/round/' . $round['id'], [], 'slot-' . $slotId);
        }
        $this->audit(fn () => __('Buchung {slot} eingetragen', ['slot' => self::when($slot['day_date'], $slot['start_time'])]), (int) $round['id']);
        $msg = __('Eintrag angelegt. Änderungslink für die Eltern (wird nur jetzt angezeigt): {link}', ['link' => abs_url('/b/' . $token, ['lang' => $old['lang']])]);
        if ($old['send_mail'] && $old['email'] !== '' && $this->teacherMailAllowed($msg)) {
            $booking = BookingService::findById($id);
            $msg .= ' – ' . ($booking && Notifier::parentBooked($booking, $token) ? __('Bestätigung per E-Mail gesendet.') : __('E-Mail konnte nicht gesendet werden.'));
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
            'old' => $old + ['name' => $booking['name'], 'email' => (string) $booking['email'], 'lang' => $booking['lang'], 'send_mail' => false, 'move_to' => ''],
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
        BookingService::update($id, PublicController::cleanName($old['name']), $old['email'] ?: null, $old['lang']);
        $target = $moved ? BookingService::findById($id) : null;
        $this->audit(fn () => $target
            ? __('Buchung {slot} geändert, umgebucht auf {target}', ['slot' => self::when($booking['day_date'], $booking['start_time']), 'target' => self::when($target['day_date'], $target['start_time'])])
            : __('Buchung {slot} geändert', ['slot' => self::when($booking['day_date'], $booking['start_time'])]), (int) $booking['round_id']);
        $msg = $moved ? __('Eintrag gespeichert und umgebucht.') : __('Eintrag gespeichert.');
        if ($old['send_mail'] && $old['email'] !== '' && $this->teacherMailAllowed($msg)) {
            $updated = BookingService::findById($id);
            $msg .= ' ' . ($updated && Notifier::parentChanged($updated, null) ? __('Änderung per E-Mail mitgeteilt.') : __('E-Mail konnte nicht gesendet werden.'));
        }
        $this->flash('success', $msg);
        Http::redirect('/admin/round/' . $booking['round_id'], [], 'day-' . (BookingService::findById($id)['day_id'] ?? ''));
    }

    private function bookingDelete(int $id): void
    {
        $booking = $this->booking($id);
        BookingService::delete($booking);
        $this->audit(fn () => __('Buchung {slot} gelöscht', ['slot' => self::when($booking['day_date'], $booking['start_time'])]), (int) $booking['round_id']);
        $this->flash('success', __('Eintrag gelöscht – der Slot ist wieder frei.'));
        Http::redirect('/admin/round/' . $booking['round_id'], [], 'day-' . $booking['day_id']);
    }

    /** @return array{name:string,email:string,lang:string,send_mail:bool} */
    private function bookingInput(): array
    {
        $lang = Http::post('lang');
        return [
            'name' => Http::post('name'),
            'email' => Http::post('email'),
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
                'SELECT s.start_time, s.end_time, s.is_blocked, s.is_conflict, b.name, b.email
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

    /**
     * Why a round of this teacher may not be activated (yet), or null.
     * Required: the teacher's display name (parents see it) and the operator's details for
     * legal notice and privacy policy (the operator is the controller for all rounds).
     */
    private function publishBlocker(array $owner): ?string
    {
        if (!Operator::ready()) {
            return Auth::isAdmin()
                ? __('Bevor Runden aktiviert werden können, tragen Sie bitte unter „Texte“ die Angaben für Impressum und Datenschutz ein (die Schule bzw. der Betreiber ist für alle Runden datenschutzrechtlich verantwortlich).')
                : __('Runden können erst aktiviert werden, wenn ein Admin die Angaben für Impressum und Datenschutz hinterlegt hat.');
        }
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
        ]);
    }

    private function profileSave(): void
    {
        $data = [
            'display_name' => mb_substr(Http::post('display_name'), 0, 200),
            'email' => Http::post('email'),
            'summary_evening' => Http::post('summary_evening') === '1' ? 1 : 0,
            'summary_deadline' => Http::post('summary_deadline') === '1' ? 1 : 0,
            'lang' => in_array(Http::post('lang'), AdminI18n::LANGUAGES, true) ? Http::post('lang') : AdminI18n::of(Auth::user()),
        ];
        $errors = [];
        if ($data['display_name'] === '') {
            $errors[] = __('Bitte einen Anzeigenamen angeben (z. B. „Frau Muster“).');
        }
        if ($data['email'] !== '' && !is_valid_email($data['email'])) {
            $errors[] = __('Die E-Mail-Adresse ist ungültig.');
        } elseif ($data['email'] !== '' && $this->emailTaken($data['email'], Auth::id())) {
            $errors[] = __('Diese E-Mail-Adresse gehört bereits zu einem anderen Konto.');
        }
        // Password reset links go to this address: changing it requires the current password
        $oldEmail = (string) (Auth::user()['email'] ?? '');
        $emailChanged = mb_strtolower($data['email']) !== mb_strtolower($oldEmail);
        if (!$errors && $emailChanged && !password_verify(Http::postRaw('current_password'), (string) Auth::user()['password_hash'])) {
            $errors[] = __('Zum Ändern der E-Mail-Adresse bitte Ihr aktuelles Passwort eingeben.');
        }
        if ($errors) {
            $this->profile($errors, $data);
            return;
        }
        if ($emailChanged && is_valid_email($oldEmail)) {
            // In the language of the account (as it was before this change)
            AdminI18n::with(AdminI18n::of(Auth::user()), static function () use ($oldEmail, $data): void {
                $vars = ['app' => app_name(), 'url' => abs_url('/admin', ['lang' => null]), 'email' => $data['email']];
                Mailer::send($oldEmail, __('Ihre E-Mail-Adresse wurde geändert'), implode("\n", [
                    __('Hallo {name},', ['name' => Auth::user()['display_name'] ?: Auth::user()['username']]), '',
                    $data['email'] !== ''
                        ? __('für Ihr Konto bei {app} ({url}) wurde die E-Mail-Adresse geändert auf {email}.', $vars)
                        : __('für Ihr Konto bei {app} ({url}) wurde die E-Mail-Adresse geändert (keine Adresse mehr hinterlegt).', $vars),
                    __('Waren Sie das nicht, wenden Sie sich bitte umgehend an einen Admin.'),
                ]));
            });
        }
        Db::run(
            'UPDATE users SET display_name = ?, email = ?, summary_evening = ?, summary_deadline = ?, lang = ? WHERE id = ?',
            [$data['display_name'], $data['email'] !== '' ? $data['email'] : null, $data['summary_evening'], $data['summary_deadline'], $data['lang'], Auth::id()]
        );
        AdminI18n::set($data['lang']); // the confirmation already in the new language
        $this->flash('success', __('Profil gespeichert.'));
        Http::redirect('/admin/profile');
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
        $users = Db::all(
            'SELECT u.id, u.username, u.display_name, u.email, u.role, u.is_active, u.must_change_password, u.last_login_at, u.access_token_expires_at,
                    (SELECT COUNT(*) FROM rounds r WHERE r.user_id = u.id) AS round_count
               FROM users u ORDER BY u.is_active DESC, u.display_name, u.username'
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
        ]);
    }

    private function userSave(?int $id = null): void
    {
        $user = $id ? $this->userById($id) : null;
        $isSelf = $user && (int) $user['id'] === Auth::id();
        $data = [
            'display_name' => mb_substr(Http::post('display_name'), 0, 200),
            'email' => mb_strtolower(Http::post('email')),
            // Admins can make others admins and take the role away again, but not change their own
            'role' => $isSelf ? $user['role'] : (Http::post('role') === 'admin' ? 'admin' : 'teacher'),
            'is_active' => $user ? (Http::post('is_active') === '1' ? 1 : 0) : 1,
            // Language of the account (admin area, invitation and summaries); new accounts: the inviter's
            'lang' => in_array(Http::post('lang'), AdminI18n::LANGUAGES, true) ? Http::post('lang') : ($user ? AdminI18n::of($user) : AdminI18n::lang()),
        ];
        // New accounts: username = email address. Freely changeable when editing.
        $data['username'] = $user ? Http::post('username') : $data['email'];
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
        }
        if (!preg_match('/^[A-Za-z0-9._@+-]{3,254}$/', $data['username'])) {
            $errors[] = __('Benutzername: 3–254 Zeichen, nur Buchstaben, Ziffern und . _ @ + -');
        } elseif (Db::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$data['username'], $id ?? 0]) !== null) {
            $errors[] = __('Für diese E-Mail-Adresse bzw. diesen Benutzernamen gibt es bereits ein Konto.');
        }
        // At least one active admin must remain (counting the others; this account only if it stays an active admin)
        $otherActiveAdmins = $this->activeAdminCount() - ($user && $user['role'] === 'admin' && $user['is_active'] ? 1 : 0);
        if ($user && $user['role'] === 'admin' && ($data['role'] !== 'admin' || !$data['is_active']) && $otherActiveAdmins < 1) {
            $errors[] = __('Es muss mindestens ein aktives Admin-Konto geben.');
        }
        if ($errors) {
            $this->userForm($id, $errors, $data);
            return;
        }
        if ($user) {
            Db::run(
                'UPDATE users SET username = ?, display_name = ?, email = ?, role = ?, is_active = ?, lang = ? WHERE id = ?',
                [$data['username'], $data['display_name'], $data['email'], $data['role'], $data['is_active'], $data['lang'], $id]
            );
            // A closure, so that the log gets its own language (see audit())
            $changes = static fn (): array => array_filter([
                $data['role'] !== $user['role'] ? __('Rolle jetzt {role}', ['role' => self::roleLabel($data['role'])]) : '',
                $data['is_active'] !== (int) $user['is_active'] ? ($data['is_active'] ? __('aktiviert') : __('deaktiviert')) : '',
                $data['display_name'] !== $user['display_name'] ? __('Name jetzt „{name}“', ['name' => $data['display_name']]) : '',
                $data['email'] !== (string) $user['email'] || $data['username'] !== $user['username'] ? __('E-Mail/Benutzername geändert') : '',
            ]);
            $this->audit(fn () => __('Konto {account} bearbeitet', ['account' => self::accountLabel($user)]) . ($changes() ? ': ' . implode(', ', $changes()) : ''));
            $this->flash('success', __('Benutzer gespeichert.') . (!$data['is_active'] ? ' ' . __('Das Konto ist deaktiviert; seine Runden sind öffentlich nicht mehr erreichbar.') : ''));
        } else {
            $id = Db::insert(
                'INSERT INTO users (username, password_hash, role, display_name, email, lang, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
                [$data['username'], AccountLinks::unusablePasswordHash(), $data['role'], $data['display_name'], $data['email'], $data['lang'], Time::nowSql()]
            );
            $this->audit(fn () => __('Konto {account} angelegt ({role}) und eingeladen', ['account' => self::accountLabel($data), 'role' => self::roleLabel($data['role'])]));
            $this->linkFlash($this->userById($id), AccountLinks::INVITE);
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
        if (!filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
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

    /** Send the link and show the result; on mail failure show the link once. */
    private function linkFlash(array $user, string $kind): void
    {
        [$sent, $link] = AccountLinks::send($user, $kind, Auth::user());
        $vars = [
            'what' => $kind === AccountLinks::INVITE ? __('Einladung') : __('Link zum Festlegen eines neuen Passworts'),
            'email' => $user['email'], 'hours' => AccountLinks::validHours(), 'link' => $link,
        ];
        if ($sent) {
            $this->flash('success', __('{what} an {email} gesendet (gültig {hours} Stunden, einmalig nutzbar).', $vars));
        } else {
            $this->flash('warning', __('{what} konnte nicht an {email} gesendet werden. Link (wird nur jetzt angezeigt, bitte persönlich weitergeben): {link}', $vars));
        }
    }

    // ---------------------------------------------------------------- Invitation / forgot password (without login)

    private function inviteForm(string $token, array $errors = []): void
    {
        $user = $this->linkUser($token);
        if (!$user) {
            http_response_code(404);
            $this->render('admin/message', ['title' => __('Link ungültig'), 'text' => __('Dieser Link ist abgelaufen, wurde bereits verwendet oder ist ungültig. Bitte fordern Sie über „Passwort vergessen?“ auf der Anmeldeseite einen neuen Link an.')]);
            return;
        }
        $this->render('admin/invite', ['title' => __('Passwort festlegen'), 'user' => $user, 'token' => $token, 'errors' => $errors, 'csrf' => self::formToken('invite')]);
    }

    private function inviteSave(string $token): void
    {
        $user = $this->linkUser($token);
        if (!$user || !self::checkFormToken('invite')) {
            $this->inviteForm($token, $user ? [__('Das Formular ist abgelaufen. Bitte erneut versuchen.')] : []);
            return;
        }
        $new = Http::postRaw('new_password');
        $error = Auth::passwordPolicy($new) ?? ($new !== Http::postRaw('new_password2') ? __('Die Passwörter stimmen nicht überein.') : null);
        if ($error !== null) {
            $this->inviteForm($token, [$error]);
            return;
        }
        AccountLinks::consume((int) $user['id'], $new);
        Auth::startSession(true);
        $this->flash('success', __('Ihr Passwort ist gespeichert. Bitte melden Sie sich jetzt an.'));
        $_SESSION['login_user'] = $user['username']; // not in the URL (access logs)
        $lang = AdminI18n::of($user);
        Http::redirect('/admin/login', ['lang' => $lang !== AdminI18n::default() ? $lang : null]);
    }

    /** Check the token; failed attempts limited per IP. */
    private function linkUser(string $token): ?array
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
            $input = mb_strtolower(Http::post('email'));
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
        if ($user['role'] === 'admin' && $user['is_active'] && $this->activeAdminCount() <= 1) {
            $this->flash('error', __('Es muss mindestens ein aktives Admin-Konto geben.'));
            Http::redirect('/admin/users');
        }
        if (Http::post('confirm') !== '1') {
            $this->flash('error', __('Bitte das Löschen ausdrücklich bestätigen.'));
            Http::redirect('/admin/users/' . $id . '/edit');
        }
        $rounds = (int) Db::value('SELECT COUNT(*) FROM rounds WHERE user_id = ?', [$id]);
        $this->audit(fn () => $rounds
            ? __('Konto {account} gelöscht (mit {count} Runde(n))', ['account' => self::accountLabel($user), 'count' => $rounds])
            : __('Konto {account} gelöscht', ['account' => self::accountLabel($user)]));
        Db::run('DELETE FROM users WHERE id = ?', [$id]);
        $this->flash('success', __('Benutzer „{name}“ mit allen Runden und Buchungen gelöscht.', ['name' => $user['username']]));
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

    private function activeAdminCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
    }

    // ---------------------------------------------------------------- Global texts (admin)

    /**
     * Notes on outdated, hand-edited legal texts (they are not replaced automatically).
     * @return list<string>
     */
    private static function legalHints(): array
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
        $noPlaceholder = Db::all("SELECT lang FROM pages WHERE page_key IN ('imprint', 'privacy') AND content NOT LIKE '%[[VERANTWORTLICHE]]%' GROUP BY lang ORDER BY lang");
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
        $hosting = array_values(array_filter(
            array_column(Db::all("SELECT DISTINCT lang FROM pages WHERE page_key = 'privacy' AND content LIKE '%[[HOSTER]]%' ORDER BY lang"), 'lang'),
            static fn ($l) => !\App\Hosting::configured((string) $l)
        ));
        if ($hosting) {
            $hints[] = __('Für die Datenschutzerklärung ({languages}) fehlen die Angaben zum Hoster (Konfiguration „hosting“, beim Deployment die Variablen HOSTING_DE bzw. HOSTING_EN) – bis dahin steht dort ein Platzhalter.', ['languages' => implode(', ', array_map('strtoupper', $hosting))]);
        }
        return $hints;
    }

    /** What is missing in the operator's details (shown to admins on the dashboard and the texts page). */
    private static function operatorHints(): array
    {
        if (!Operator::ready()) {
            return [__('Die Angaben für Impressum und Datenschutz fehlen – ohne sie ist keine Runde öffentlich erreichbar.')];
        }
        if (!str_contains(Operator::contact(), '@')) {
            return [__('In den Angaben für Impressum und Datenschutz fehlt eine E-Mail-Adresse (für das Impressum vorgeschrieben).')];
        }
        return [];
    }

    private function operatorSave(): void
    {
        $contact = Http::post('controller_contact');
        if (mb_strlen($contact) > 2000) {
            $this->flash('error', __('Die Angaben sind zu lang (höchstens 2000 Zeichen).'));
            Http::redirect('/admin/texts');
        }
        Operator::save($contact);
        $this->audit(fn () => __('Angaben für Impressum und Datenschutz geändert'));
        $this->flash('success', __('Angaben für Impressum und Datenschutz gespeichert.'));
        Http::redirect('/admin/texts');
    }

    private function texts(): void
    {
        $this->render('admin/texts', [
            'operatorContact' => Operator::contact(),
            'operatorHints' => self::operatorHints(),
            'legalHints' => self::legalHints(),
            'title' => __('Impressum & Datenschutz'),
            'languages' => I18n::languages(),
            'mailTransport' => (string) \App\Config::get('mail.transport', 'mail'),
            'mailFrom' => (string) \App\Config::get('mail.from_address', ''),
        ]);
    }

    private function pageForm(string $key, string $lang): void
    {
        $this->assertLang($lang);
        $this->render('admin/page_form', [
            'title' => self::pageLabel($key) . ' (' . strtoupper($lang) . ')',
            'key' => $key,
            'lang' => $lang,
            'content' => (string) Db::value('SELECT content FROM pages WHERE page_key = ? AND lang = ?', [$key, $lang]),
        ]);
    }

    private function pageSave(string $key, string $lang): void
    {
        $this->assertLang($lang);
        $content = str_replace("\r\n", "\n", Http::postRaw('content'));
        Db::run(
            'INSERT INTO pages (page_key, lang, content, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content = ?, updated_at = ?',
            [$key, $lang, $content, Time::nowSql(), $content, Time::nowSql()]
        );
        $this->audit(fn () => __('{page} ({lang}) geändert', ['page' => self::pageLabel($key), 'lang' => strtoupper($lang)]));
        $this->flash('success', __('Text gespeichert.'));
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
        'booking_limit' => 'round', 'code_required' => 0, 'remind_parents' => 1, 'location' => '', 'description' => '',
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
            'remind_parents' => (int) $round['remind_parents'], 'location' => (string) ($round['location'] ?? ''),
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

    /**
     * Logs a change if an admin made it (see Audit). With $roundId the round is named first.
     * $message is a closure so that the entry is written in the default language of the installation,
     * whatever the language of the admin (one language in the log).
     */
    private function audit(\Closure $message, ?int $roundId = null): void
    {
        if (!Auth::isAdmin()) {
            return;
        }
        Audit::log(AdminI18n::with(AdminI18n::default(), static function () use ($message, $roundId): string {
            $text = $message();
            if ($roundId === null) {
                return $text;
            }
            $round = Db::one(
                "SELECT r.title, COALESCE(NULLIF(u.display_name, ''), u.username) AS owner FROM rounds r JOIN users u ON u.id = r.user_id WHERE r.id = ?",
                [$roundId]
            );
            return __('Runde „{title}“ ({owner}): {message}', ['title' => $round['title'] ?? '?', 'owner' => $round['owner'] ?? '?', 'message' => $text]);
        }));
    }

    private static function when(string $date, ?string $time = null): string
    {
        return date('d.m.Y', (int) strtotime($date)) . ($time !== null ? ' ' . substr($time, 0, 5) : '');
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
