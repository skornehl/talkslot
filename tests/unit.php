<?php
/**
 * Unit tests without a framework: php tests/unit.php
 * Only needs the configuration (no database).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\I18n;
use App\Markdown;
use App\RoundCode;
use App\SlotGenerator;
use App\Slug;
use App\Token;

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    $fail += $ok ? 0 : 1;
}
I18n::set('de');
$starts = static fn (array $slots) => implode(',', array_map(static fn ($s) => substr($s['start'], 0, 5), $slots));

echo "SlotGenerator\n";
check('2–4 pm, 15 min → 8 slots', count(SlotGenerator::compute('14:00:00', '16:00:00', 15, [])) === 8);
check('remainder shorter than a slot is dropped', $starts(SlotGenerator::compute('14:00:00', '14:50:00', 20, [])) === '14:00,14:20');
check('break is skipped, continues at end of break',
    $starts(SlotGenerator::compute('14:00:00', '16:00:00', 15, [['start_time' => '15:00:00', 'end_time' => '15:20:00']])) === '14:00,14:15,14:30,14:45,15:20,15:35');
check('multiple/overlapping breaks',
    $starts(SlotGenerator::compute('08:00:00', '10:00:00', 30, [
        ['start_time' => '08:15:00', 'end_time' => '08:45:00'],
        ['start_time' => '08:40:00', 'end_time' => '09:00:00'],
    ])) === '09:00,09:30');
check('break at end of day', $starts(SlotGenerator::compute('14:00:00', '15:00:00', 20, [['start_time' => '14:40:00', 'end_time' => '15:00:00']])) === '14:00,14:20');
check('end time correct', SlotGenerator::compute('14:00:00', '15:00:00', 20, [])[2]['end'] === '15:00:00');

check('24 h times: 9:30, 930, 9, 14.30', App\Time::normalizeTime('9:30') === '09:30:00' && App\Time::normalizeTime('930') === '09:30:00' && App\Time::normalizeTime('9') === '09:00:00' && App\Time::normalizeTime('14.30') === '14:30:00');
check('invalid times', App\Time::normalizeTime('25:00') === null && App\Time::normalizeTime('2pm') === null && App\Time::normalizeTime('') === null);
echo "Slug\n";
check('umlauts', Slug::fromText('Elternsprechtag Größe Übung Ära') === 'elternsprechtag-groesse-uebung-aera');
check('special characters', Slug::fromText('  Klasse 3b – Gespräche!! ') === 'klasse-3b-gespraeche');
check('random part 8 characters', (bool) preg_match('/^[a-z2-9]{8}$/', Slug::random()));
check('length limited', strlen(Slug::fromText(str_repeat('abc ', 50))) <= 80);
$long = Slug::withSuffix(Slug::fromText(str_repeat('abc ', 50), 200));
check('with random part + uniqueness suffix fits in column', strlen($long) + Slug::SUFFIX_RESERVE <= Slug::MAX_LENGTH);

echo "Token\n";
[$plain, $hash] = Token::generate();
check('43 characters base64url', Token::isWellFormed($plain));
check('Hash = SHA-256', $hash === hash('sha256', $plain) && strlen($hash) === 64);
check('invalid format rejected', !Token::isWellFormed('../../etc/passwd'));

echo "Markdown\n";
$html = Markdown::toHtml("# Titel\n\nText **fett** <script>x</script>\n\n- a\n- [Link](https://example.org)\n- [Böse](javascript:alert(1))");
check('heading → h2', str_contains($html, '<h2>Titel</h2>'));
check('HTML escaped', str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'));
check('bold', str_contains($html, '<strong>fett</strong>'));
check('https link allowed', str_contains($html, '<a href="https://example.org">Link</a>'));
check('javascript: link not linked', !str_contains($html, 'href="javascript'));

echo "Date format\n";
check('German', fmt_date('2026-08-17') === 'Montag, 17.08.2026');
check('English', I18n::with('en', static fn () => fmt_date('2026-08-17')) === 'Monday, 17 August 2026');

echo "Calendar export\n";
$ics = App\IcsExport::forRound(['title' => 'Elternsprechtag, Test'], [
    ['id' => 7, 'name' => "Müller; Anna, Ben\nund ein sehr langer Name, der über die fünfundsiebzig Byte einer Zeile hinausgeht", 'day_date' => '2026-08-17', 'start_time' => '14:00:00', 'end_time' => '14:15:00'],
]);
check('start time in UTC (summer time −2 h)', str_contains($ics, "DTSTART:20260817T120000Z\r\n"));
check('special characters escaped', str_contains(str_replace("\r\n ", '', $ics), 'SUMMARY:Müller\; Anna\, Ben\nund'));
check('lines ≤ 75 bytes, CRLF', max(array_map('strlen', explode("\r\n", $ics))) <= 75 && !preg_match("/[^\r]\n/", $ics));
check('folding does not split UTF-8', mb_check_encoding(str_replace("\r\n ", '', $ics), 'UTF-8') && mb_check_encoding($ics, 'UTF-8'));

$pi = App\IcsExport::forParent(['id' => 3, 'title' => 'Elternsprechtag 3b', 'name' => 'Max Muster', 'email' => 'x@example.org',
    'day_date' => '2026-12-01', 'start_time' => '14:00:00', 'end_time' => '14:15:00',
    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:05:00'], 'Ändern: https://example.org/b/abc');
check('parent ICS: title = round name, winter time −1 h', str_contains($pi, "SUMMARY:Elternsprechtag 3b\r\n") && str_contains($pi, 'DTSTART:20261201T130000Z'));
check('parent ICS: stable UID, SEQUENCE increases, no email', str_contains($pi, 'UID:appointment-3@') && str_contains($pi, 'SEQUENCE:300') && !str_contains($pi, 'x@example.org'));

echo "Table prefix\n";
App\Config::load(['db' => ['prefix' => 'tp_']] + (require dirname(__DIR__) . '/config/config.php'));
check('tables get prefixed', App\Db::prefix('SELECT b.* FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id') === 'SELECT b.* FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id');
check('columns/constraints stay unchanged', App\Db::prefix('ALTER TABLE rounds ADD CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES users (id)') === 'ALTER TABLE tp_rounds ADD CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES tp_users (id)');
check('quoted values unchanged', App\Db::prefix("SELECT value FROM settings WHERE name = 'users'") === "SELECT value FROM tp_settings WHERE name = 'users'");

echo "Language files\n";
$ref = array_keys(require dirname(__DIR__) . '/app/lang/de.php');
foreach (glob(dirname(__DIR__) . '/app/lang/*.php') as $f) {
    $keys = array_keys(require $f);
    $missing = array_diff($ref, $keys);
    check(basename($f) . ': all texts present' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''), !$missing);
}
foreach (['pl' => 'czwartek, 19 listopada 2026', 'ru' => 'четверг, 19 ноября 2026 г.', 'uk' => 'четвер, 19 листопада 2026 р.', 'tr' => '19 Kasım 2026 Perşembe'] as $l => $exp) {
    check("date $l", I18n::with($l, static fn () => fmt_date('2026-11-19')) === $exp);
}

echo "Translation\n";
$names = new ReflectionProperty(I18n::class, 'names');
check('language name is cached', I18n::name('pl') === 'Polski' && ($names->getValue()['pl'] ?? null) === 'Polski' && I18n::name('xx') === 'XX');

echo "Version\n";
$lbl = static fn (string $v, string $at = '') => App\Version::label(['version' => $v, 'commit' => 'abc1234', 'built_at' => $at]);
check('release tag', $lbl('v1.2.0') === '1.2.0' && $lbl('v2.0.0-rc.1') === '2.0.0-rc.1');
check('commits after the release', $lbl('v1.2.0-3-gabc1234def') === '1.2.0+3 (abc1234)');
check('no release yet / local', $lbl('abc1234') === 'abc1234' && $lbl('dev') === 'dev (abc1234)');
check('timestamp in local time', $lbl('v1.2.0', '2026-11-02T08:14:01Z') === '1.2.0 · 02.11.2026 09:14');
$nx = static fn (string $last, array $msgs) => App\Version::next($last, $msgs);
check('first release v1.0.0', $nx('', ['feat: x']) === 'v1.0.0');
check('fix/chore/docs → Patch', $nx('v1.2.3', ['fix(ui): a', 'chore: b', 'Merge pull request #5 from x']) === 'v1.2.4');
check('feat → Minor', $nx('v1.2.3', ['fix: a', "feat(code): Rundencode\n\nText"]) === 'v1.3.0');
check('breaking → major', $nx('v1.2.3', ['feat!: neu']) === 'v2.0.0' && $nx('v1.2.3', ["fix: a\n\nBREAKING CHANGE: Schema"]) === 'v2.0.0');
check('"feature:" is not feat', $nx('v1.2.3', ['feature: x']) === 'v1.2.4');
check('version info without file: dev', App\Version::info()['version'] === (is_file(dirname(__DIR__) . '/app/version.php') ? (require dirname(__DIR__) . '/app/version.php')['version'] : 'dev'));

echo "Round code\n";
$codes = array_map(static fn () => RoundCode::generate(), range(1, 200));
check('8 characters without 0/O/1/I/L', !array_filter($codes, static fn ($c) => !preg_match('/^[2-9A-HJKMNP-Z]{8}$/', $c)));
check('input: lower case, spaces, hyphen', RoundCode::normalize(' k7mp-3qxa ') === 'K7MP3QXA' && RoundCode::normalize('K7MP 3QXA') === 'K7MP3QXA');
check('en dash, full width', RoundCode::normalize('K7MP–3QXA') === 'K7MP3QXA' && RoundCode::normalize('Ｋ７ＭＰ－３ＱＸＡ') === 'K7MP3QXA');
check('Cyrillic look-alikes (ru/uk keyboard)', RoundCode::normalize('К7МР-3QХА') === 'K7MP3QXA' && RoundCode::normalize('к7мр 3qха') === 'K7MP3QXA');
check('invalid input rejected', RoundCode::normalize('K7MP3QX') === null && RoundCode::normalize('K7MP3QXO') === null && RoundCode::normalize('K7MP3QX1') === null && RoundCode::normalize("K7MP3QX\xff") === null && RoundCode::normalize('482913') === null);
check('display format', RoundCode::format('K7MP3QXA') === 'K7MP-3QXA' && RoundCode::format(null) === '');

echo "Appearance\n";
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
preg_match('/:root:not\(\[data-theme="light"\]\) \{(.*?)\}/s', $css, $auto);
preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $forced);
check('dark palette: automatic and explicitly chosen "dark" identical', isset($auto[1], $forced[1]) && trim($auto[1]) === trim($forced[1]) && str_contains($auto[1], '--bg:'));

echo "Multiple bookings\n";
$same = [App\BookingService::class, 'samePerson'];
check('same person: name regardless of spelling, without email only the name', $same('Max Muster', null, '  max   MUSTER ', '') && $same('Max Muster', 'a@example.org', 'Max Muster', null) && $same('Max Muster', null, 'Max Muster', 'a@example.org'));
check('same person: same email (case-insensitive)', $same('Max Muster', 'A@Example.org', 'Max Muster', 'a@example.org'));
check('different persons: other name (siblings) or other email (other family)', !$same('Erika Muster', 'a@example.org', 'Max Muster', 'a@example.org') && !$same('Max Muster', 'a@example.org', 'Max Muster', 'b@example.org'));
check('umlauts in name: case-insensitive', $same('JÜRGEN ÖZDEMİR-ÇAKIR', null, 'jürgen özdemi̇r-çakir', null) && $same('Ärne Übel', null, 'ärne übel', null));

echo "Templates\n";
$stale = array_filter(App\Migrations::CURRENT_PAGE_HASHES, static fn ($h, $n) => hash_file('sha256', dirname(__DIR__) . "/app/templates/pages/$n.md") !== $h, ARRAY_FILTER_USE_BOTH);
check('CURRENT_PAGE_HASHES match app/templates/pages' . ($stale ? ' (recompute: ' . implode(', ', array_keys($stale)) . ')' : ''), !$stale);
check('all templates covered', count(glob(dirname(__DIR__) . '/app/templates/pages/*.md')) === count(App\Migrations::CURRENT_PAGE_HASHES));

echo "Error log\n";
check('no data in the log', App\ErrorPage::sanitize("Duplicate entry 'Max Muster' for key, mail max@example.org") === "Duplicate entry '…' for key, mail [email]");
check('no data in the log: names with apostrophe, single quote, values in parentheses',
    App\ErrorPage::sanitize("Duplicate entry 'O'Brien|max.mustermann' for key 'uq'") === "Duplicate entry '…'"
    && App\ErrorPage::sanitize("Wert 'Anna Schmidt ohne Ende") === "Wert '…"
    && App\ErrorPage::sanitize('Failed to parse time string (Anna Schmidt) at position 0') === 'Failed to parse time string (…) at position 0');

echo "Error reports by mail\n";
$cfg = (static function (): array { return require dirname(__DIR__) . '/config/config.php'; })();
App\Config::load(['alert_email' => 'betrieb@example.org', 'base_url' => 'https://t.example'] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$sent = [];
App\Alert::$transport = static function (string $to, string $subject, string $body) use (&$sent): bool { $sent[] = [$to, $subject, $body]; return true; };
$boom = static fn () => new RuntimeException("Duplicate entry 'Erika Muster' für erika@example.org", 0, new LogicException('innen'));
App\Alert::exception($boom(), 'Test');
[$to, $subj, $body] = $sent[0] ?? ['', '', ''];
check('mail to alert_email with subject, error, location and cause', $to === 'betrieb@example.org' && $subj === '[TalkSlot] RuntimeException (Test)'
    && str_contains($body, 'Fehler: RuntimeException') && str_contains($body, 'Ort: tests/unit.php:') && str_contains($body, 'Ursache: LogicException: innen') && str_contains($body, 'Ablauf:'));
check('no personal data in the mail', !str_contains($body, 'Erika') && !str_contains($body, '@example.org') && str_contains($body, "'…'"));
App\Alert::exception($boom(), 'Test');
App\Alert::exception($boom(), 'Test');
check('same error reported only once within an hour', count($sent) === 1);
App\Alert::notify('anderer', 'Anderer Fehler', ['x']);
check('different error is reported', count($sent) === 2);
$state = json_decode((string) file_get_contents(App\Alert::$stateFile), true);
foreach ($state['keys'] as &$e) { $e['last'] -= App\Alert::THROTTLE_SECONDS; }
unset($e);
file_put_contents(App\Alert::$stateFile, json_encode($state));
$sent = [];
App\Alert::exception($boom(), 'Test'); // same location (closure above) = same error
check('reported again after an hour, with number of suppressed ones', count($sent) === 1 && str_contains($sent[0][2], 'Seit der letzten Meldung 2 weitere Male'));
$state = json_decode((string) file_get_contents(App\Alert::$stateFile), true);
$state['_count'] = App\Alert::MAX_PER_DAY;
file_put_contents(App\Alert::$stateFile, json_encode($state));
$sent = [];
App\Alert::notify('neu', 'Neu', ['x']);
check('at most ' . App\Alert::MAX_PER_DAY . ' mails per day', $sent === []);
check('path without token, round link and query string',
    App\Alert::describeRequest('post', '/b/Abc_DEF-123xyz?email=a@b.de') === 'POST /b/…'
    && App\Alert::describeRequest('GET', '/elternabend-3b-k7mp2q') === 'GET /…'
    && App\Alert::describeRequest('POST', '/admin/round/12/edit') === 'POST /admin/round/12/edit');
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
App\Config::load(['alert_email' => '', 'db' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'x', 'user' => 'x', 'pass' => 'x']] + $cfg);
$sent = [];
try { App\Db::pdo(); } catch (PDOException $e) { App\Alert::exception($e); }
check('database down, without alert_email: no report (admin address is stored in the database)', $sent === [] && App\Db::isUnavailable());
App\Config::load(['alert_email' => 'betrieb@example.org'] + $cfg);
App\Alert::exception($e);
check('database down, with alert_email: subject "Datenbank nicht erreichbar"', ($sent[0][1] ?? '') === '[TalkSlot] Datenbank nicht erreichbar');
@unlink(App\Alert::$stateFile);

echo "Markdown, CSRF, languages, helpers\n";
App\Config::load($cfg);
check('quote (>) as blockquote, also after a paragraph and before a list',
    Markdown::toHtml("Text\n> Zitat\n> zwei\n- Punkt\n> neu") === "<p>Text</p>\n<blockquote>Zitat<br>zwei</blockquote>\n<ul><li>Punkt</li></ul>\n<blockquote>neu</blockquote>");
check('paragraph after quote is separated', Markdown::toHtml("> Zitat\nText") === "<blockquote>Zitat</blockquote>\n<p>Text</p>");
App\Config::load(['csrf_min_age_seconds' => 2, 'csrf_max_age_seconds' => 4 * 3600] + $cfg);
$tok = App\Csrf::publicToken('x');
check('public CSRF token: too fast, wrong context, broken, expired',
    App\Csrf::checkPublic('x', $tok) === App\Csrf::TOO_FAST
    && App\Csrf::checkPublic('y', $tok) === App\Csrf::INVALID
    && App\Csrf::checkPublic('x', ['kein', 'string']) === App\Csrf::INVALID
    && App\Csrf::checkPublic('x', (time() - 5 * 3600) . '.' . hash_hmac('sha256', 'pub|x|' . (time() - 5 * 3600), $cfg['secret'])) === App\Csrf::INVALID);
App\Config::load(['csrf_min_age_seconds' => 0] + $cfg);
check('public CSRF token valid', App\Csrf::checkPublic('x', App\Csrf::publicToken('x')) === App\Csrf::OK);
App\Config::load(['secret' => 'zu-kurz'] + $cfg);
try { App\Csrf::publicToken('x'); $ok = false; } catch (RuntimeException) { $ok = true; }
check('no tokens without a sufficiently long secret', $ok);
App\Config::load($cfg);
I18n::set('xx');
check('unknown language → default language, unknown key → key', I18n::lang() === 'de' && t('gibt.es.nicht') === 'gibt.es.nicht');
try { App\Version::next('release-1', []); $ok = false; } catch (InvalidArgumentException) { $ok = true; }
check('version: invalid last tag rejected', $ok);
App\Config::load(['db' => ['prefix' => ''] + $cfg['db']] + $cfg);
check('without table prefix SQL stays unchanged', App\Db::prefix('SELECT * FROM rounds') === 'SELECT * FROM rounds');
App\Config::load(['db' => ['prefix' => 'tp; DROP'] + $cfg['db']] + $cfg);
try { App\Db::prefix('SELECT 1 FROM rounds'); $ok = false; } catch (RuntimeException) { $ok = true; }
check('invalid table prefix rejected', $ok);
App\Config::load($cfg);
$keys = [];
foreach (['203.0.113.7', '203.0.113.200', '2001:db8:1:2::1', '2001:db8:1:ffff::9', 'kaputt'] as $ip) { $_SERVER['REMOTE_ADDR'] = $ip; $keys[] = App\RateLimiter::clientKey(); }
unset($_SERVER['REMOTE_ADDR']);
check('rate limit key: IPv4 truncated to /24, IPv6 to /48, invalid IP gets its own key',
    $keys[0] === $keys[1] && $keys[2] === $keys[3] && $keys[0] !== $keys[2] && $keys[4] !== $keys[0] && strlen($keys[4]) === 64);
check('slot duration 0 → no slots', SlotGenerator::compute('14:00:00', '16:00:00', 0, []) === []);
$ics = App\IcsExport::forRound(['title' => 'R'], [['id' => 1, 'day_date' => '2026-11-20', 'start_time' => '14:00:00', 'end_time' => '14:15:00', 'name' => str_repeat('a', 66) . 'ÄÖÜäöüß' . str_repeat('ö', 40)]]);
$folded = array_filter(explode("\r\n", $ics), static fn ($l) => str_starts_with($l, 'SUMMARY') || str_starts_with($l, ' '));
check('calendar: long lines folded (max. 75 bytes) without splitting UTF-8 characters',
    count($folded) > 1 && !array_filter($folded, static fn ($l) => strlen($l) > 75 || !mb_check_encoding($l, 'UTF-8')));
try { App\Translator::localize(['title' => 'x'], 1, 'en'); $ok = false; } catch (LogicException) { $ok = true; }
check('Translator::localize without the round fields → programming error', $ok);
check('transition during upload: without code_required the old PIN column applies',
    App\RoundAccess::required(['access_pin' => '1234']) && !App\RoundAccess::required(['access_pin' => '']) && !App\RoundAccess::required([]));

echo "Error page\n";
App\Config::load(['debug' => false] + $cfg);
ob_start(); App\ErrorPage::show(new RuntimeException('internes Detail')); $page = (string) ob_get_clean();
check('error page without details (debug off)', str_contains($page, 'Es ist ein Fehler aufgetreten') && !str_contains($page, 'internes Detail'));
App\Config::load(['debug' => true] + $cfg);
ob_start(); App\ErrorPage::show(new RuntimeException('internes <Detail>')); $page = (string) ob_get_clean();
check('error page with details only with debug, escaped', str_contains($page, 'internes &lt;Detail&gt;'));
App\Config::load($cfg);

echo "Sending mail\n";
check('invalid recipient address → not sent', !App\Mailer::send('kaputt', 'S', 'B'));
App\Config::load(['mail' => ['from_address' => ''] + $cfg['mail']] + $cfg);
check('without sender address → not sent', !App\Mailer::send('a@example.org', 'S', 'B'));
App\Config::load(['mail' => ['transport' => 'none'] + $cfg['mail']] + $cfg);
check('transport "none" → counts as sent', App\Mailer::send('a@example.org', 'S', 'B'));
App\Config::load(['alert_email' => 'betrieb@example.org', 'mail' => ['transport' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'user' => '', 'pass' => '']] + $cfg['mail']] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$sent = [];
check('SMTP unreachable → not sent, error report to the operator',
    !App\Mailer::send('a@example.org', 'S', 'B') && ($sent[0][1] ?? '') === '[TalkSlot] Mailversand fehlgeschlagen' && !str_contains($sent[0][2], 'a@example.org'));
@unlink(App\Alert::$stateFile);
App\Config::load($cfg);

// Subprocesses: php with its own configuration or code; returns [exit code, stdout, stderr]
$run = static function (array $args, array $env = [], ?string $stdin = null): array {
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + getenv());
    fwrite($pipes[0], (string) $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};
$mailFile = tempnam(sys_get_temp_dir(), 'mail');
$sendmail = tempnam(sys_get_temp_dir(), 'sendmail'); // sendmail replacement: writes the mail to a file
file_put_contents($sendmail, "#!/bin/sh\ncat > " . escapeshellarg($mailFile) . "\n");
chmod($sendmail, 0700);
[$rc] = $run(['-d', 'sendmail_path=' . $sendmail, '-r', '
    require "app/bootstrap.php";
    App\Config::load(["mail" => ["transport" => "mail", "from_address" => "absender@example.org", "from_name" => "TalkSlot", "reply_to" => ""]] + (require "config/config.php"));
    exit(App\Mailer::send("eltern@example.org", "Termin\r\nBcc: boese@example.org", "Hallo Ä\nZeile", [["name" => "termin.ics", "type" => "text/calendar", "content" => "BEGIN:VCALENDAR"]], "lehrkraft@example.org") ? 0 : 1);']);
$mail = (string) file_get_contents($mailFile);
@unlink($mailFile);
@unlink($sendmail);
check('sending via mail(): sender, Reply-To, attachment, umlauts', $rc === 0 && str_contains($mail, 'From: TalkSlot <absender@example.org>')
    && str_contains($mail, 'Reply-To: lehrkraft@example.org') && str_contains($mail, 'Content-Type: multipart/mixed') && str_contains($mail, base64_encode('BEGIN:VCALENDAR'))
    && str_contains($mail, 'Hallo =C3=84'));
check('sending via mail(): no header injection via the subject', $mail !== '' && !preg_match('/^Bcc:/mi', $mail));

echo "Error handling (bootstrap)\n";
[$rc, $out] = $run(['-r', 'require "app/bootstrap.php"; $c = require "config/config.php"; $c["db"]["host"] = "127.0.0.1"; $c["db"]["port"] = 1;
    App\Config::load(["alert_email" => "", "debug" => false] + $c); ob_start();
    try { App\Db::one("SELECT 1"); } catch (PDOException $e) { App\ErrorPage::show($e); }
    $page = ob_get_clean(); echo http_response_code(), "|", str_contains($page, "SQLSTATE") ? "detail" : "neutral";']);
check('database unreachable: error page with 503, without details', trim($out) === '503|neutral');
$capture = 'require "app/bootstrap.php"; App\Alert::$stateFile = tempnam(sys_get_temp_dir(), "alert"); App\Config::load(["alert_email" => "b@example.org"] + (require "config/config.php")); App\Alert::$transport = static function ($to, $s, $b) { fwrite(STDERR, "ALERT:$s\n"); return true; };';
[$rc, , $err] = $run(['-d', 'memory_limit=32M', '-r', $capture . ' $x = str_repeat("x", 64 * 1024 * 1024);']);
check('fatal error (memory) is reported', $rc !== 0 && str_contains($err, 'ALERT:[TalkSlot] Fataler Fehler'));
[$rc, , $err] = $run(['-r', $capture . ' $a = []; echo @$a["still"]; echo $a["laut"];']);
check('warning is reported, ones suppressed with @ are not', $rc === 0 && substr_count($err, 'ALERT:') === 1 && str_contains($err, 'ALERT:[TalkSlot] PHP-Warnung'));
$script = tempnam(sys_get_temp_dir(), 'exc');
file_put_contents($script, '<?php chdir(' . var_export(dirname(__DIR__), true) . '); ' . $capture . ' throw new RuntimeException("kaputt mit max@example.org");');
[$rc, , $err] = $run([$script]); // separate file: with php -r PHP does not call the exception handler
@unlink($script);
check('unhandled exception: reported, exit code 1, message without data', $rc === 1 && str_contains($err, 'ALERT:[TalkSlot] RuntimeException') && str_contains($err, 'Error: kaputt mit [email]'));
$tmpRoot = sys_get_temp_dir() . '/tp-noconfig-' . getmypid();
@mkdir($tmpRoot . '/app', 0700, true);
copy(dirname(__DIR__) . '/app/bootstrap.php', $tmpRoot . '/app/bootstrap.php');
copy(dirname(__DIR__) . '/app/helpers.php', $tmpRoot . '/app/helpers.php');
[$rc, $out] = $run(['-r', 'require ' . var_export($tmpRoot . '/app/bootstrap.php', true) . ';']);
array_map('unlink', glob($tmpRoot . '/app/*.php')); @rmdir($tmpRoot . '/app'); @rmdir($tmpRoot);
check('without config.php: notice and abort', $rc === 1 && str_contains($out, 'Configuration missing'));

echo "Atomic deployment (release directories)\n";
$dt = sys_get_temp_dir() . '/tp-release-' . getmypid();
$base = $dt . '/talkslot-app';
@mkdir($dt . '/web', 0700, true);
copy(dirname(__DIR__) . '/bin/app-root.php', $dt . '/web/app_root.php');
foreach (['20261101-090000-v1.8.0', '20261102-090000-v1.9.0'] as $n) {
    @mkdir("$base/releases/$n/app", 0700, true);
    touch("$base/releases/$n/app/bootstrap.php");
}
@mkdir("$base/releases/20261103-090000-halb/app", 0700, true); // incomplete: without bootstrap.php
$resolve = static fn () => $run(['-r', 'echo require ' . var_export($dt . '/web/app_root.php', true) . ';']);
file_put_contents("$base/current", "20261101-090000-v1.8.0\n");
check('pointer determines the active version', $resolve()[1] === "$base/releases/20261101-090000-v1.8.0");
unlink("$base/current");
check('pointer missing: newest complete version', $resolve()[1] === "$base/releases/20261102-090000-v1.9.0");
$fallback = true;
foreach (['../../etc', '20261103-090000-halb', 'gibtsnicht', ''] as $bad) {
    file_put_contents("$base/current", $bad);
    $fallback = $fallback && $resolve()[1] === "$base/releases/20261102-090000-v1.9.0";
}
check('invalid, incomplete or empty pointer: newest complete version', $fallback);
exec('rm -rf ' . escapeshellarg("$base/releases") . ' ' . escapeshellarg("$base/current"));
check('without versions: 503 instead of an error', str_contains($resolve()[1], 'Temporarily unavailable'));

// Code in releases/<name>/: data (app/var) in the base directory, assets under /v/<name>/
$rel = "$base/releases/20261102-090000-v1.9.0";
@mkdir("$rel/app", 0700, true);
@mkdir("$rel/config", 0700, true);
@mkdir("$base/app/var", 0700, true);
copy(dirname(__DIR__) . '/app/bootstrap.php', "$rel/app/bootstrap.php");
copy(dirname(__DIR__) . '/app/helpers.php', "$rel/app/helpers.php");
symlink(dirname(__DIR__) . '/app/src', "$rel/app/src");
file_put_contents("$rel/config/config.php", '<?php return ' . var_export(['base_url' => 'https://schule.example/termine'] + $cfg, true) . ';');
[, $out] = $run(['-r', 'define("PUBLIC_ROOT", "/web"); require ' . var_export("$rel/app/bootstrap.php", true) . ';
    echo implode("|", [RELEASE, DATA_ROOT, APP_ROOT, asset("css/app.css"), asset_file("flags/de.svg"), ini_get("error_log")]);']);
check('inside a version: RELEASE, DATA_ROOT = base, APP_ROOT = version', str_starts_with($out, "20261102-090000-v1.9.0|$base|$rel|"));
check('assets under /v/<name>/ (with base path), logs in <base>/app/var', str_ends_with($out, "|/termine/v/20261102-090000-v1.9.0/css/app.css|/web/v/20261102-090000-v1.9.0/flags/de.svg|$base/app/var/php-error.log"));
exec('rm -rf ' . escapeshellarg($dt));
check('local: no RELEASE, data in the project, assets under /assets/ with ?v=', RELEASE === '' && DATA_ROOT === APP_ROOT
    && preg_match('#^/assets/css/app\.css\?v=\d+$#', (function () { App\Config::load(['base_url' => 'http://localhost'] + $GLOBALS['cfg']); return asset('css/app.css'); })()) === 1);
App\Config::load($cfg);
exec('bash -n ' . escapeshellarg(dirname(__DIR__) . '/bin/build.sh') . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/deploy.sh') . ' 2>&1', $bashOut, $bashRc);
check('bin/build.sh and bin/deploy.sh syntactically correct', $bashRc === 0);

echo "HTTP\n";
App\Config::load(['base_url' => 'https://schule.example/termine'] + $cfg);
$_SERVER['REQUEST_URI'] = '/termine/admin/round/3?x=1';
check('path relative to the application (installation in a subdirectory)', App\Http::path() === '/admin/round/3');
unset($_SERVER['REQUEST_URI']);
App\Config::load($cfg);
[, $out] = $run(['-r', 'require "app/bootstrap.php"; App\Config::load(["force_https" => true] + (require "config/config.php")); App\Http::enforceHttps(); echo "weiter";']);
[, $outHttps] = $run(['-r', 'require "app/bootstrap.php"; $_SERVER["HTTPS"] = "on"; App\Config::load(["force_https" => true] + (require "config/config.php")); App\Http::enforceHttps(); echo "weiter";']);
check('force HTTPS: without HTTPS abort (redirect), with HTTPS continue', $out === '' && $outHttps === 'weiter');

echo "Security (audit)\n";
// SQL injection: variables in the SQL text of Db::… calls only at reviewed places (values always as parameters)
$allowed = [
    'BookingService.php:$perDay' => 'boolean, selects a fixed clause',
    'Installer.php:$stmt' => 'statements from app/schema.sql',
    'Migrations.php:$sql' => 'fixed statements from Migrations::STEPS',
    'Summaries.php:$where' => 'fixed conditions from callers in the same class',
    'AdminController.php:$all' => 'boolean (admin or not)',
];
$found = [];
foreach (array_merge(glob(dirname(__DIR__) . '/app/src/*.php'), glob(dirname(__DIR__) . '/app/src/Controller/*.php'), glob(dirname(__DIR__) . '/public/*.php'), glob(dirname(__DIR__) . '/bin/*.php')) as $f) {
    $tk = token_get_all((string) file_get_contents($f));
    for ($i = 0, $n = count($tk); $i < $n; $i++) {
        if (!is_array($tk[$i]) || $tk[$i][1] !== 'Db' || ($tk[$i + 1][0] ?? null) !== T_DOUBLE_COLON
            || !in_array($tk[$i + 2][1] ?? '', ['run', 'one', 'all', 'value', 'exec', 'insert', 'execRaw'], true)) {
            continue;
        }
        for ($j = $i + 4, $depth = 0; $j < $n; $j++) {
            $tok = $tk[$j];
            if ($tok === '(' || $tok === '[') { $depth++; }
            if (($tok === ')' || $tok === ']') && $depth-- === 0) { break; }
            if ($tok === ',' && $depth === 0) { break; }
            if (is_array($tok) && $tok[0] === T_VARIABLE) { $found[basename($f) . ':' . $tok[1]] = $tk[$i][2]; }
        }
    }
}
$unexpected = array_diff_key($found, $allowed);
check('SQL text without embedded variables (only reviewed exceptions' . ($unexpected ? '; new: ' . implode(', ', array_map(static fn ($k, $l) => "$k line $l", array_keys($unexpected), $unexpected)) : '') . ')', !$unexpected);
check('SQL: table prefix replaces only table names, not quoted values',
    App\Db::prefix("SELECT * FROM rounds r WHERE r.title = 'rounds' AND x = \"users\"") === "SELECT * FROM tp_rounds r WHERE r.title = 'rounds' AND x = \"users\"");
check('email: unusual forms rejected', is_valid_email('x.y+z@schule.example.org') && !is_valid_email('"a b"@example.org') && !is_valid_email('"<svg>"@x.yz') && !is_valid_email("a\n@b.de"));
check('Markdown: no protocol-relative links', !str_contains(Markdown::toHtml('[a](//evil.example) [b](/\\evil.example)'), '<a ') && str_contains(Markdown::toHtml('[c](/impressum)'), '<a href="/impressum">'));
check('placeholders in a single pass (values are not replaced again)', I18n::with('de', static fn () => t('manage.locked', ['hours' => '{x}', 'x' => 'BAD'])) === str_replace('{hours}', '{x}', I18n::with('de', static fn () => t('manage.locked'))));
$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.7'; $mapped = App\RateLimiter::clientKey();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $plain4 = App\RateLimiter::clientKey(); unset($_SERVER['REMOTE_ADDR']);
check('rate limit: IPv4 in IPv6 notation treated like IPv4 (same /24)', $mapped === $plain4);
ob_start(); App\ErrorPage::show(); $page = (string) ob_get_clean();
check('error page without inline styles (CSP)', !str_contains($page, 'style='));
App\Config::load(['alert_email' => 'betrieb@example.org'] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
file_put_contents(App\Alert::$stateFile, json_encode(['_day' => date('Y-m-d'), '_count' => App\Alert::MAX_PER_DAY, 'keys' => []]));
$sent = [];
App\Alert::notify('unwichtig', 'Warnung', ['x']);
App\Alert::notify('wichtig', 'Datenbank weg', ['x'], true);
check('error mails: daily limit reached → important reports (DB down, fatal) still go out', count($sent) === 1 && $sent[0][1] === '[TalkSlot] Datenbank weg');
@unlink(App\Alert::$stateFile);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
App\Config::load(['app_name' => "Schule am\nSee", 'alert_email' => 'b@example.org'] + $cfg);
$sent = [];
App\Alert::notify('name', 'Test', ['x']);
check('error mails: subject and text with the configured name (without line break)', ($sent[0][1] ?? '') === '[Schule am See] Test'
    && str_contains($sent[0][2] ?? '', 'Schule am See hat einen Fehler gemeldet.'));
@unlink(App\Alert::$stateFile);
$log = tempnam(sys_get_temp_dir(), 'log'); $oldLog = ini_get('error_log'); ini_set('error_log', $log);
file_put_contents($log, str_repeat('x', App\ErrorPage::LOG_MAX_BYTES + 1));
App\ErrorPage::writeLog('neu');
check('error log is rotated from ' . (App\ErrorPage::LOG_MAX_BYTES >> 20) . ' MB', is_file($log . '.1') && filesize($log) < 1000 && str_contains((string) file_get_contents($log), 'neu'));
ini_set('error_log', (string) $oldLog); @unlink($log); @unlink($log . '.1');
check('password: at most 72 bytes (bcrypt)', App\Auth::passwordPolicy(str_repeat('ä', 37)) !== null && App\Auth::passwordPolicy(str_repeat('a', 72)) === null);
App\Config::load($cfg);

// Every variable that build-config reads must also be passed by the deploy workflow in the build step
preg_match_all('/\$env\(\'([A-Z_]+)\'/', (string) file_get_contents(dirname(__DIR__) . '/bin/build-config.php'), $m);
$workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');
$passed = preg_match('/- name: Build\n(.*?)run: bin\/build\.sh/s', $workflow, $b) ? $b[1] : '';
$jobEnv = preg_match('/\n    env:\n(.*?)\n    steps:/s', $workflow, $j) ? $j[1] : '';
$missing = array_filter(array_unique($m[1]), static fn ($v) => !preg_match('/^\s+' . $v . ':/m', $passed . "\n" . $jobEnv));
check('deploy workflow passes all build-config variables' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''), count($m[1]) > 10 && !$missing);

echo "Web host in the privacy policy\n";
App\Config::load(['hosting' => ['de' => 'Beispiel Hosting GmbH, Weg 1']] + $cfg);
check('[[HOSTER]] from the configuration, per language of the page text', App\Hosting::fill("- **Hosting:** [[HOSTER]]\n", 'de') === "- **Hosting:** Beispiel Hosting GmbH, Weg 1\n");
check('not configured: visible placeholder in the language of the text (otherwise English)', str_starts_with(App\Hosting::fill('[[HOSTER]]', 'en'), '[WEB HOST WITH ADDRESS]')
    && str_starts_with(App\Hosting::fill('[[HOSTER]]', 'pl'), '[WEB HOST WITH ADDRESS]') && !App\Hosting::configured('en'));
check('templates contain [[HOSTER]]', str_contains((string) file_get_contents(dirname(__DIR__) . '/app/templates/pages/privacy.de.md'), '[[HOSTER]]')
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/app/templates/pages/privacy.en.md'), '[[HOSTER]]'));
App\Config::load($cfg);

echo "Deployment scripts\n";
$cfgFile = tempnam(sys_get_temp_dir(), 'cfg');
$envOk = ['BASE_URL' => 'https://t.example/', 'APP_SECRET' => str_repeat('s', 40), 'INSTALL_KEY' => str_repeat('k', 20), 'DB_USER' => 'u', 'DB_PASS' => 'p',
    'DB_PREFIX' => 'x_', 'ALERT_EMAIL' => 'betrieb@example.org', 'MAIL_FROM' => 'f@example.org', ];
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['APP_SECRET' => ''] + $envOk);
check('build-config: missing secret → abort', $rc === 1 && str_contains($err, 'Missing variable/secret: APP_SECRET'));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['DB_PASS' => 'CHANGE_ME'] + $envOk);
check('build-config: placeholder CHANGE_ME → abort', $rc === 1 && str_contains($err, 'CHANGE_ME'));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['APP_SECRET' => 'kurz'] + $envOk);
check('build-config: secret too short → abort', $rc === 1 && str_contains($err, 'too short'));
[$rc] = $run(['bin/build-config.php', $cfgFile], $envOk);
$built = (static fn ($f) => require $f)($cfgFile);
check('build-config: values applied (DB name = user, prefix), debug off, file readable only by the owner', $rc === 0
    && $built['base_url'] === 'https://t.example' && $built['alert_email'] === 'betrieb@example.org' && $built['db']['prefix'] === 'x_'
    && $built['db']['name'] === 'u' && $built['debug'] === false && (fileperms($cfgFile) & 0777) === 0600);
@unlink($cfgFile);
check('build-config: without APP_NAME/HOSTING default name, empty web host', $built['app_name'] === 'TalkSlot' && $built['mail']['from_name'] === 'TalkSlot'
    && $built['hosting'] === ['de' => '', 'en' => '']);
[$rc] = $run(['bin/build-config.php', $cfgFile], ['APP_NAME' => 'Terminplanung', 'HOSTING_DE' => 'Hoster GmbH, Weg 1', 'HOSTING_EN' => 'Host Ltd'] + $envOk);
$built = (static fn ($f) => require $f)($cfgFile);
check('build-config: APP_NAME (also sender name) and HOSTING_DE/EN applied', $rc === 0 && $built['app_name'] === 'Terminplanung'
    && $built['mail']['from_name'] === 'Terminplanung' && $built['hosting'] === ['de' => 'Hoster GmbH, Weg 1', 'en' => 'Host Ltd']);
@unlink($cfgFile);
[$rc] = $run(['bin/build-config.php', $cfgFile], $envOk);
$built = (static fn ($f) => require $f)($cfgFile);
check('build-config: without LANGUAGES/DEFAULT_LANGUAGE all languages, default German', $rc === 0
    && $built['languages'] === ['de', 'en', 'pl', 'ru', 'uk', 'tr'] && $built['default_language'] === 'de');
[$rc] = $run(['bin/build-config.php', $cfgFile], ['LANGUAGES' => ' EN, de ,tr,de', 'DEFAULT_LANGUAGE' => 'En'] + $envOk);
$built = (static fn ($f) => require $f)($cfgFile);
check('build-config: LANGUAGES (order, case, duplicates) and DEFAULT_LANGUAGE applied', $rc === 0
    && $built['languages'] === ['en', 'de', 'tr'] && $built['default_language'] === 'en');
[$rc] = $run(['bin/build-config.php', $cfgFile], ['LANGUAGES' => 'en,tr'] + $envOk);
$built = (static fn ($f) => require $f)($cfgFile);
check('build-config: default not given and German not offered → first language', $rc === 0 && $built['default_language'] === 'en');
@unlink($cfgFile);
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['LANGUAGES' => 'de,xx'] + $envOk);
check('build-config: unknown language → abort', $rc === 1 && str_contains($err, 'unknown language "xx"') && !is_file($cfgFile));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['LANGUAGES' => 'de,en', 'DEFAULT_LANGUAGE' => 'tr'] + $envOk);
check('build-config: default language not offered → abort', $rc === 1 && str_contains($err, 'DEFAULT_LANGUAGE must be one of LANGUAGES') && !is_file($cfgFile));
App\Config::load(['languages' => ['en', 'tr'], 'default_language' => 'de'] + $cfg);
check('runtime: configured default that is not offered falls back to the first language', App\I18n::defaultLanguage() === 'en');
App\Config::load(['languages' => ['de', 'en'], 'default_language' => 'en'] + $cfg);
App\I18n::init(null);
check('runtime: without a choice the configured default language is used', App\I18n::lang() === 'en' && App\I18n::defaultLanguage() === 'en');
App\Config::load($cfg);
App\I18n::set('de');
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['SMTP_ENCRYPTION' => 'starttls'] + $envOk);
check('build-config: unknown SMTP encryption → abort (never plain text by accident)', $rc === 1 && str_contains($err, 'SMTP_ENCRYPTION'));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['DB_PREFIX' => 'tp_;DROP'] + $envOk);
check('build-config: invalid table prefix → abort', $rc === 1 && str_contains($err, 'DB_PREFIX'));
[$rc] = $run(['bin/build-config.php', $cfgFile], ['INSTALL_KEY' => str_repeat('k', 20)] + $envOk);
$noInstall = (static fn ($f) => require $f)($cfgFile);
[$rc2] = $run(['bin/build-config.php', $cfgFile], ['INSTALL_KEY' => str_repeat('k', 20), 'ALLOW_INSTALL' => '1'] + $envOk);
$withInstall = (static fn ($f) => require $f)($cfgFile);
[$rc3, , $err3] = $run(['bin/build-config.php', $cfgFile], ['ALLOW_INSTALL' => '1', 'INSTALL_KEY' => ''] + $envOk); // proc_open does not pass on empty values
check('build-config: install key only with ALLOW_INSTALL=1 (then required)', $rc === 0 && $noInstall['install_key'] === '' && $rc2 === 0 && $withInstall['install_key'] === str_repeat('k', 20) && $rc3 === 1 && str_contains($err3, 'INSTALL_KEY'));
@unlink($cfgFile);
$verFile = tempnam(sys_get_temp_dir(), 'ver');
[$rc] = $run(['bin/build-version.php', $verFile], ['VERSION' => 'v1.2.3', 'COMMIT' => 'nicht-hex']);
check('build-version: invalid commit → abort', $rc === 1);
[$rc] = $run(['bin/build-version.php', $verFile], ['VERSION' => 'v1.2.3', 'COMMIT' => str_repeat('a', 40)]);
$info = (static fn ($f) => require $f)($verFile);
check('build-version: file with version, commit, timestamp', $rc === 0 && $info['version'] === 'v1.2.3' && $info['commit'] === str_repeat('a', 40) && preg_match('/^\d{4}-\d\d-\d\dT/', $info['built_at']));
@unlink($verFile);
[$rc, $out] = $run(['bin/next-version.php']);
check('next-version: prints a version (' . trim($out) . ')', $rc === 0 && preg_match('/^v\d+\.\d+\.\d+$/', trim($out)));

echo $fail === 0 ? "ALL UNIT TESTS PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
