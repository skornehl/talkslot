<?php
/**
 * Unit-Tests ohne Framework: php tests/unit.php
 * Benötigt nur die Konfiguration (keine Datenbank).
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
check('14–16 Uhr, 15 Min → 8 Slots', count(SlotGenerator::compute('14:00:00', '16:00:00', 15, [])) === 8);
check('Rest kürzer als Slot entfällt', $starts(SlotGenerator::compute('14:00:00', '14:50:00', 20, [])) === '14:00,14:20');
check('Pause überspringt, weiter ab Pausenende',
    $starts(SlotGenerator::compute('14:00:00', '16:00:00', 15, [['start_time' => '15:00:00', 'end_time' => '15:20:00']])) === '14:00,14:15,14:30,14:45,15:20,15:35');
check('mehrere/überlappende Pausen',
    $starts(SlotGenerator::compute('08:00:00', '10:00:00', 30, [
        ['start_time' => '08:15:00', 'end_time' => '08:45:00'],
        ['start_time' => '08:40:00', 'end_time' => '09:00:00'],
    ])) === '09:00,09:30');
check('Pause am Tagesende', $starts(SlotGenerator::compute('14:00:00', '15:00:00', 20, [['start_time' => '14:40:00', 'end_time' => '15:00:00']])) === '14:00,14:20');
check('Endzeit korrekt', SlotGenerator::compute('14:00:00', '15:00:00', 20, [])[2]['end'] === '15:00:00');

check('Zeiten 24 h: 9:30, 930, 9, 14.30', App\Time::normalizeTime('9:30') === '09:30:00' && App\Time::normalizeTime('930') === '09:30:00' && App\Time::normalizeTime('9') === '09:00:00' && App\Time::normalizeTime('14.30') === '14:30:00');
check('ungültige Zeiten', App\Time::normalizeTime('25:00') === null && App\Time::normalizeTime('2pm') === null && App\Time::normalizeTime('') === null);
echo "Slug\n";
check('Umlaute', Slug::fromText('Elternsprechtag Größe Übung Ära') === 'elternsprechtag-groesse-uebung-aera');
check('Sonderzeichen', Slug::fromText('  Klasse 3b – Gespräche!! ') === 'klasse-3b-gespraeche');
check('Zufallsanteil 8 Zeichen', (bool) preg_match('/^[a-z2-9]{8}$/', Slug::random()));
check('Länge begrenzt', strlen(Slug::fromText(str_repeat('abc ', 50))) <= 80);
$long = Slug::withSuffix(Slug::fromText(str_repeat('abc ', 50), 200));
check('mit Zufallsanteil + Eindeutigkeitszusatz passt in Spalte', strlen($long) + Slug::SUFFIX_RESERVE <= Slug::MAX_LENGTH);

echo "Token\n";
[$plain, $hash] = Token::generate();
check('43 Zeichen base64url', Token::isWellFormed($plain));
check('Hash = SHA-256', $hash === hash('sha256', $plain) && strlen($hash) === 64);
check('ungültige Form abgelehnt', !Token::isWellFormed('../../etc/passwd'));

echo "Markdown\n";
$html = Markdown::toHtml("# Titel\n\nText **fett** <script>x</script>\n\n- a\n- [Link](https://example.org)\n- [Böse](javascript:alert(1))");
check('Überschrift → h2', str_contains($html, '<h2>Titel</h2>'));
check('HTML escaped', str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'));
check('Fett', str_contains($html, '<strong>fett</strong>'));
check('https-Link erlaubt', str_contains($html, '<a href="https://example.org">Link</a>'));
check('javascript:-Link nicht verlinkt', !str_contains($html, 'href="javascript'));

echo "Datumsformat\n";
check('deutsch', fmt_date('2026-08-17') === 'Montag, 17.08.2026');
check('englisch', I18n::with('en', static fn () => fmt_date('2026-08-17')) === 'Monday, 17 August 2026');

echo "Kalenderexport\n";
$ics = App\IcsExport::forRound(['title' => 'Elternsprechtag, Test'], [
    ['id' => 7, 'name' => "Müller; Anna, Ben\nund ein sehr langer Name, der über die fünfundsiebzig Byte einer Zeile hinausgeht", 'day_date' => '2026-08-17', 'start_time' => '14:00:00', 'end_time' => '14:15:00'],
]);
check('Startzeit in UTC (Sommerzeit −2 h)', str_contains($ics, "DTSTART:20260817T120000Z\r\n"));
check('Sonderzeichen escaped', str_contains(str_replace("\r\n ", '', $ics), 'SUMMARY:Müller\; Anna\, Ben\nund'));
check('Zeilen ≤ 75 Byte, CRLF', max(array_map('strlen', explode("\r\n", $ics))) <= 75 && !preg_match("/[^\r]\n/", $ics));
check('Faltung zerschneidet kein UTF-8', mb_check_encoding(str_replace("\r\n ", '', $ics), 'UTF-8') && mb_check_encoding($ics, 'UTF-8'));

$pi = App\IcsExport::forParent(['id' => 3, 'title' => 'Elternsprechtag 3b', 'name' => 'Max Muster', 'email' => 'x@example.org',
    'day_date' => '2026-12-01', 'start_time' => '14:00:00', 'end_time' => '14:15:00',
    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:05:00'], 'Ändern: https://example.org/b/abc');
check('Eltern-ICS: Titel = Rundenname, Winterzeit −1 h', str_contains($pi, "SUMMARY:Elternsprechtag 3b\r\n") && str_contains($pi, 'DTSTART:20261201T130000Z'));
check('Eltern-ICS: feste UID, SEQUENCE steigt, keine E-Mail', str_contains($pi, 'UID:appointment-3@') && str_contains($pi, 'SEQUENCE:300') && !str_contains($pi, 'x@example.org'));

echo "Tabellen-Präfix\n";
App\Config::load(['db' => ['prefix' => 'tp_']] + (require dirname(__DIR__) . '/config/config.php'));
check('Tabellen werden präfixiert', App\Db::prefix('SELECT b.* FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id') === 'SELECT b.* FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id');
check('Spalten/Constraints bleiben unverändert', App\Db::prefix('ALTER TABLE rounds ADD CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES users (id)') === 'ALTER TABLE tp_rounds ADD CONSTRAINT fk_rounds_user FOREIGN KEY (user_id) REFERENCES tp_users (id)');
check('Werte in Anführungszeichen unverändert', App\Db::prefix("SELECT value FROM settings WHERE name = 'users'") === "SELECT value FROM tp_settings WHERE name = 'users'");

echo "Sprachdateien\n";
$ref = array_keys(require dirname(__DIR__) . '/app/lang/de.php');
foreach (glob(dirname(__DIR__) . '/app/lang/*.php') as $f) {
    $keys = array_keys(require $f);
    $missing = array_diff($ref, $keys);
    check(basename($f) . ': alle Texte vorhanden' . ($missing ? ' (fehlt: ' . implode(', ', $missing) . ')' : ''), !$missing);
}
foreach (['pl' => 'czwartek, 19 listopada 2026', 'ru' => 'четверг, 19 ноября 2026 г.', 'uk' => 'четвер, 19 листопада 2026 р.', 'tr' => '19 Kasım 2026 Perşembe'] as $l => $exp) {
    check("Datum $l", I18n::with($l, static fn () => fmt_date('2026-11-19')) === $exp);
}

echo "Übersetzung\n";
$names = new ReflectionProperty(I18n::class, 'names');
check('Sprachname wird zwischengespeichert', I18n::name('pl') === 'Polski' && ($names->getValue()['pl'] ?? null) === 'Polski' && I18n::name('xx') === 'XX');

echo "Version\n";
$lbl = static fn (string $v, string $at = '') => App\Version::label(['version' => $v, 'commit' => 'abc1234', 'built_at' => $at]);
check('Release-Tag', $lbl('v1.2.0') === '1.2.0' && $lbl('v2.0.0-rc.1') === '2.0.0-rc.1');
check('Commits nach dem Release', $lbl('v1.2.0-3-gabc1234def') === '1.2.0+3 (abc1234)');
check('noch kein Release / lokal', $lbl('abc1234') === 'abc1234' && $lbl('dev') === 'dev (abc1234)');
check('Zeitpunkt in Ortszeit', $lbl('v1.2.0', '2026-11-02T08:14:01Z') === '1.2.0 · 02.11.2026 09:14');
$nx = static fn (string $last, array $msgs) => App\Version::next($last, $msgs);
check('erstes Release v1.0.0', $nx('', ['feat: x']) === 'v1.0.0');
check('fix/chore/docs → Patch', $nx('v1.2.3', ['fix(ui): a', 'chore: b', 'Merge pull request #5 from x']) === 'v1.2.4');
check('feat → Minor', $nx('v1.2.3', ['fix: a', "feat(code): Rundencode\n\nText"]) === 'v1.3.0');
check('Breaking → Major', $nx('v1.2.3', ['feat!: neu']) === 'v2.0.0' && $nx('v1.2.3', ["fix: a\n\nBREAKING CHANGE: Schema"]) === 'v2.0.0');
check('„feature:“ ist kein feat', $nx('v1.2.3', ['feature: x']) === 'v1.2.4');
check('Versionsinfo ohne Datei: dev', App\Version::info()['version'] === (is_file(dirname(__DIR__) . '/app/version.php') ? (require dirname(__DIR__) . '/app/version.php')['version'] : 'dev'));

echo "Rundencode\n";
$codes = array_map(static fn () => RoundCode::generate(), range(1, 200));
check('8 Zeichen ohne 0/O/1/I/L', !array_filter($codes, static fn ($c) => !preg_match('/^[2-9A-HJKMNP-Z]{8}$/', $c)));
check('Eingabe: klein, Leerzeichen, Bindestrich', RoundCode::normalize(' k7mp-3qxa ') === 'K7MP3QXA' && RoundCode::normalize('K7MP 3QXA') === 'K7MP3QXA');
check('Gedankenstrich, Vollbreite', RoundCode::normalize('K7MP–3QXA') === 'K7MP3QXA' && RoundCode::normalize('Ｋ７ＭＰ－３ＱＸＡ') === 'K7MP3QXA');
check('kyrillische Doppelgänger (ru/uk-Tastatur)', RoundCode::normalize('К7МР-3QХА') === 'K7MP3QXA' && RoundCode::normalize('к7мр 3qха') === 'K7MP3QXA');
check('ungültige Eingaben abgelehnt', RoundCode::normalize('K7MP3QX') === null && RoundCode::normalize('K7MP3QXO') === null && RoundCode::normalize('K7MP3QX1') === null && RoundCode::normalize("K7MP3QX\xff") === null && RoundCode::normalize('482913') === null);
check('Anzeigeform', RoundCode::format('K7MP3QXA') === 'K7MP-3QXA' && RoundCode::format(null) === '');

echo "Darstellung\n";
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
preg_match('/:root:not\(\[data-theme="light"\]\) \{(.*?)\}/s', $css, $auto);
preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $forced);
check('Dunkle Palette: automatisch und „Dunkel“ gewählt identisch', isset($auto[1], $forced[1]) && trim($auto[1]) === trim($forced[1]) && str_contains($auto[1], '--bg:'));

echo "Mehrfachbuchungen\n";
$same = [App\BookingService::class, 'samePerson'];
check('dieselbe Person: Name egal wie geschrieben, ohne E-Mail nur der Name', $same('Max Muster', null, '  max   MUSTER ', '') && $same('Max Muster', 'a@example.org', 'Max Muster', null) && $same('Max Muster', null, 'Max Muster', 'a@example.org'));
check('dieselbe Person: gleiche E-Mail (Groß/Klein egal)', $same('Max Muster', 'A@Example.org', 'Max Muster', 'a@example.org'));
check('verschiedene Personen: anderer Name (Geschwister) bzw. andere E-Mail (andere Familie)', !$same('Erika Muster', 'a@example.org', 'Max Muster', 'a@example.org') && !$same('Max Muster', 'a@example.org', 'Max Muster', 'b@example.org'));
check('Umlaute im Namen: Groß-/Kleinschreibung egal', $same('JÜRGEN ÖZDEMİR-ÇAKIR', null, 'jürgen özdemi̇r-çakir', null) && $same('Ärne Übel', null, 'ärne übel', null));

echo "Vorlagen\n";
$stale = array_filter(App\Migrations::CURRENT_PAGE_HASHES, static fn ($h, $n) => hash_file('sha256', dirname(__DIR__) . "/app/templates/pages/$n.md") !== $h, ARRAY_FILTER_USE_BOTH);
check('CURRENT_PAGE_HASHES passen zu app/templates/pages' . ($stale ? ' (neu berechnen: ' . implode(', ', array_keys($stale)) . ')' : ''), !$stale);
check('alle Vorlagen erfasst', count(glob(dirname(__DIR__) . '/app/templates/pages/*.md')) === count(App\Migrations::CURRENT_PAGE_HASHES));

echo "Fehlerprotokoll\n";
check('keine Daten im Log', App\ErrorPage::sanitize("Duplicate entry 'Max Muster' for key, mail max@example.org") === "Duplicate entry '…' for key, mail [email]");
check('keine Daten im Log: Namen mit Apostroph, einzelnes Anführungszeichen, Werte in Klammern',
    App\ErrorPage::sanitize("Duplicate entry 'O'Brien|max.mustermann' for key 'uq'") === "Duplicate entry '…'"
    && App\ErrorPage::sanitize("Wert 'Anna Schmidt ohne Ende") === "Wert '…"
    && App\ErrorPage::sanitize('Failed to parse time string (Anna Schmidt) at position 0') === 'Failed to parse time string (…) at position 0');

echo "Fehlermeldungen per Mail\n";
$cfg = (static function (): array { return require dirname(__DIR__) . '/config/config.php'; })();
App\Config::load(['alert_email' => 'betrieb@example.org', 'base_url' => 'https://t.example'] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$sent = [];
App\Alert::$transport = static function (string $to, string $subject, string $body) use (&$sent): bool { $sent[] = [$to, $subject, $body]; return true; };
$boom = static fn () => new RuntimeException("Duplicate entry 'Erika Muster' für erika@example.org", 0, new LogicException('innen'));
App\Alert::exception($boom(), 'Test');
[$to, $subj, $body] = $sent[0] ?? ['', '', ''];
check('Mail an alert_email mit Betreff, Fehler, Ort und Ursache', $to === 'betrieb@example.org' && $subj === '[TalkSlot] RuntimeException (Test)'
    && str_contains($body, 'Fehler: RuntimeException') && str_contains($body, 'Ort: tests/unit.php:') && str_contains($body, 'Ursache: LogicException: innen') && str_contains($body, 'Ablauf:'));
check('keine personenbezogenen Daten in der Mail', !str_contains($body, 'Erika') && !str_contains($body, '@example.org') && str_contains($body, "'…'"));
App\Alert::exception($boom(), 'Test');
App\Alert::exception($boom(), 'Test');
check('gleicher Fehler innerhalb einer Stunde nur einmal gemeldet', count($sent) === 1);
App\Alert::notify('anderer', 'Anderer Fehler', ['x']);
check('anderer Fehler wird gemeldet', count($sent) === 2);
$state = json_decode((string) file_get_contents(App\Alert::$stateFile), true);
foreach ($state['keys'] as &$e) { $e['last'] -= App\Alert::THROTTLE_SECONDS; }
unset($e);
file_put_contents(App\Alert::$stateFile, json_encode($state));
$sent = [];
App\Alert::exception($boom(), 'Test'); // gleicher Ort (Closure oben) = gleicher Fehler
check('nach einer Stunde wieder gemeldet, mit Anzahl der unterdrückten', count($sent) === 1 && str_contains($sent[0][2], 'Seit der letzten Meldung 2 weitere Male'));
$state = json_decode((string) file_get_contents(App\Alert::$stateFile), true);
$state['_count'] = App\Alert::MAX_PER_DAY;
file_put_contents(App\Alert::$stateFile, json_encode($state));
$sent = [];
App\Alert::notify('neu', 'Neu', ['x']);
check('höchstens ' . App\Alert::MAX_PER_DAY . ' Mails am Tag', $sent === []);
check('Pfad ohne Token, Runden-Link und Query-String',
    App\Alert::describeRequest('post', '/b/Abc_DEF-123xyz?email=a@b.de') === 'POST /b/…'
    && App\Alert::describeRequest('GET', '/elternabend-3b-k7mp2q') === 'GET /…'
    && App\Alert::describeRequest('POST', '/admin/round/12/edit') === 'POST /admin/round/12/edit');
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
App\Config::load(['alert_email' => '', 'db' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'x', 'user' => 'x', 'pass' => 'x']] + $cfg);
$sent = [];
try { App\Db::pdo(); } catch (PDOException $e) { App\Alert::exception($e); }
check('Datenbank weg, ohne alert_email: keine Meldung (Admin-Adresse liegt in der Datenbank)', $sent === [] && App\Db::isUnavailable());
App\Config::load(['alert_email' => 'betrieb@example.org'] + $cfg);
App\Alert::exception($e);
check('Datenbank weg, mit alert_email: Betreff „Datenbank nicht erreichbar“', ($sent[0][1] ?? '') === '[TalkSlot] Datenbank nicht erreichbar');
@unlink(App\Alert::$stateFile);

echo "Markdown, CSRF, Sprachen, Hilfsfunktionen\n";
App\Config::load($cfg);
check('Zitat (>) als blockquote, auch nach Absatz und vor Liste',
    Markdown::toHtml("Text\n> Zitat\n> zwei\n- Punkt\n> neu") === "<p>Text</p>\n<blockquote>Zitat<br>zwei</blockquote>\n<ul><li>Punkt</li></ul>\n<blockquote>neu</blockquote>");
check('Absatz nach Zitat wird getrennt', Markdown::toHtml("> Zitat\nText") === "<blockquote>Zitat</blockquote>\n<p>Text</p>");
App\Config::load(['csrf_min_age_seconds' => 2, 'csrf_max_age_seconds' => 4 * 3600] + $cfg);
$tok = App\Csrf::publicToken('x');
check('öffentliches CSRF-Token: zu schnell, falscher Kontext, kaputt, abgelaufen',
    App\Csrf::checkPublic('x', $tok) === App\Csrf::TOO_FAST
    && App\Csrf::checkPublic('y', $tok) === App\Csrf::INVALID
    && App\Csrf::checkPublic('x', ['kein', 'string']) === App\Csrf::INVALID
    && App\Csrf::checkPublic('x', (time() - 5 * 3600) . '.' . hash_hmac('sha256', 'pub|x|' . (time() - 5 * 3600), $cfg['secret'])) === App\Csrf::INVALID);
App\Config::load(['csrf_min_age_seconds' => 0] + $cfg);
check('öffentliches CSRF-Token gültig', App\Csrf::checkPublic('x', App\Csrf::publicToken('x')) === App\Csrf::OK);
App\Config::load(['secret' => 'zu-kurz'] + $cfg);
try { App\Csrf::publicToken('x'); $ok = false; } catch (RuntimeException) { $ok = true; }
check('ohne ausreichend langes Secret keine Tokens', $ok);
App\Config::load($cfg);
I18n::set('xx');
check('unbekannte Sprache → Standardsprache, unbekannter Schlüssel → Schlüssel', I18n::lang() === 'de' && t('gibt.es.nicht') === 'gibt.es.nicht');
try { App\Version::next('release-1', []); $ok = false; } catch (InvalidArgumentException) { $ok = true; }
check('Version: ungültiger letzter Tag abgelehnt', $ok);
App\Config::load(['db' => ['prefix' => ''] + $cfg['db']] + $cfg);
check('ohne Tabellen-Präfix bleibt SQL unverändert', App\Db::prefix('SELECT * FROM rounds') === 'SELECT * FROM rounds');
App\Config::load(['db' => ['prefix' => 'tp; DROP'] + $cfg['db']] + $cfg);
try { App\Db::prefix('SELECT 1 FROM rounds'); $ok = false; } catch (RuntimeException) { $ok = true; }
check('ungültiges Tabellen-Präfix abgelehnt', $ok);
App\Config::load($cfg);
$keys = [];
foreach (['203.0.113.7', '203.0.113.200', '2001:db8:1:2::1', '2001:db8:1:ffff::9', 'kaputt'] as $ip) { $_SERVER['REMOTE_ADDR'] = $ip; $keys[] = App\RateLimiter::clientKey(); }
unset($_SERVER['REMOTE_ADDR']);
check('Rate-Limit-Schlüssel: IPv4 auf /24, IPv6 auf /48 gekürzt, ungültige IP eigener Schlüssel',
    $keys[0] === $keys[1] && $keys[2] === $keys[3] && $keys[0] !== $keys[2] && $keys[4] !== $keys[0] && strlen($keys[4]) === 64);
check('Slot-Dauer 0 → keine Slots', SlotGenerator::compute('14:00:00', '16:00:00', 0, []) === []);
$ics = App\IcsExport::forRound(['title' => 'R'], [['id' => 1, 'day_date' => '2026-11-20', 'start_time' => '14:00:00', 'end_time' => '14:15:00', 'name' => str_repeat('a', 66) . 'ÄÖÜäöüß' . str_repeat('ö', 40)]]);
$folded = array_filter(explode("\r\n", $ics), static fn ($l) => str_starts_with($l, 'SUMMARY') || str_starts_with($l, ' '));
check('Kalender: lange Zeilen gefaltet (max. 75 Byte), ohne UTF-8-Zeichen zu zerschneiden',
    count($folded) > 1 && !array_filter($folded, static fn ($l) => strlen($l) > 75 || !mb_check_encoding($l, 'UTF-8')));
try { App\Translator::localize(['title' => 'x'], 1, 'en'); $ok = false; } catch (LogicException) { $ok = true; }
check('Translator::localize ohne Felder der Runde → Programmierfehler', $ok);
check('Übergang beim Upload: ohne code_required gilt die alte PIN-Spalte',
    App\RoundAccess::required(['access_pin' => '1234']) && !App\RoundAccess::required(['access_pin' => '']) && !App\RoundAccess::required([]));

echo "Fehlerseite\n";
App\Config::load(['debug' => false] + $cfg);
ob_start(); App\ErrorPage::show(new RuntimeException('internes Detail')); $page = (string) ob_get_clean();
check('Fehlerseite ohne Details (debug aus)', str_contains($page, 'Es ist ein Fehler aufgetreten') && !str_contains($page, 'internes Detail'));
App\Config::load(['debug' => true] + $cfg);
ob_start(); App\ErrorPage::show(new RuntimeException('internes <Detail>')); $page = (string) ob_get_clean();
check('Fehlerseite mit Details nur bei debug, escaped', str_contains($page, 'internes &lt;Detail&gt;'));
App\Config::load($cfg);

echo "Mailversand\n";
check('ungültige Empfängeradresse → nicht gesendet', !App\Mailer::send('kaputt', 'S', 'B'));
App\Config::load(['mail' => ['from_address' => ''] + $cfg['mail']] + $cfg);
check('ohne Absenderadresse → nicht gesendet', !App\Mailer::send('a@example.org', 'S', 'B'));
App\Config::load(['mail' => ['transport' => 'none'] + $cfg['mail']] + $cfg);
check('Transport „none“ → gilt als gesendet', App\Mailer::send('a@example.org', 'S', 'B'));
App\Config::load(['alert_email' => 'betrieb@example.org', 'mail' => ['transport' => 'smtp', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'user' => '', 'pass' => '']] + $cfg['mail']] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
$sent = [];
check('SMTP nicht erreichbar → nicht gesendet, Fehlermeldung an den Betreiber',
    !App\Mailer::send('a@example.org', 'S', 'B') && ($sent[0][1] ?? '') === '[TalkSlot] Mailversand fehlgeschlagen' && !str_contains($sent[0][2], 'a@example.org'));
@unlink(App\Alert::$stateFile);
App\Config::load($cfg);

// Teilprozesse: php mit eigener Konfiguration bzw. eigenem Code; Rückgabe [Exit-Code, stdout, stderr]
$run = static function (array $args, array $env = [], ?string $stdin = null): array {
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env + getenv());
    fwrite($pipes[0], (string) $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $err];
};
$mailFile = tempnam(sys_get_temp_dir(), 'mail');
$sendmail = tempnam(sys_get_temp_dir(), 'sendmail'); // Ersatz für sendmail: schreibt die Mail in eine Datei
file_put_contents($sendmail, "#!/bin/sh\ncat > " . escapeshellarg($mailFile) . "\n");
chmod($sendmail, 0700);
[$rc] = $run(['-d', 'sendmail_path=' . $sendmail, '-r', '
    require "app/bootstrap.php";
    App\Config::load(["mail" => ["transport" => "mail", "from_address" => "absender@example.org", "from_name" => "TalkSlot", "reply_to" => ""]] + (require "config/config.php"));
    exit(App\Mailer::send("eltern@example.org", "Termin\r\nBcc: boese@example.org", "Hallo Ä\nZeile", [["name" => "termin.ics", "type" => "text/calendar", "content" => "BEGIN:VCALENDAR"]], "lehrkraft@example.org") ? 0 : 1);']);
$mail = (string) file_get_contents($mailFile);
@unlink($mailFile);
@unlink($sendmail);
check('Versand über mail(): Absender, Reply-To, Anhang, Umlaute', $rc === 0 && str_contains($mail, 'From: TalkSlot <absender@example.org>')
    && str_contains($mail, 'Reply-To: lehrkraft@example.org') && str_contains($mail, 'Content-Type: multipart/mixed') && str_contains($mail, base64_encode('BEGIN:VCALENDAR'))
    && str_contains($mail, 'Hallo =C3=84'));
check('Versand über mail(): keine Header-Injection über den Betreff', $mail !== '' && !preg_match('/^Bcc:/mi', $mail));

echo "Fehlerbehandlung (bootstrap)\n";
[$rc, $out] = $run(['-r', 'require "app/bootstrap.php"; $c = require "config/config.php"; $c["db"]["host"] = "127.0.0.1"; $c["db"]["port"] = 1;
    App\Config::load(["alert_email" => "", "debug" => false] + $c); ob_start();
    try { App\Db::one("SELECT 1"); } catch (PDOException $e) { App\ErrorPage::show($e); }
    $page = ob_get_clean(); echo http_response_code(), "|", str_contains($page, "SQLSTATE") ? "detail" : "neutral";']);
check('Datenbank nicht erreichbar: Fehlerseite mit 503, ohne Details', trim($out) === '503|neutral');
$capture = 'require "app/bootstrap.php"; App\Alert::$stateFile = tempnam(sys_get_temp_dir(), "alert"); App\Config::load(["alert_email" => "b@example.org"] + (require "config/config.php")); App\Alert::$transport = static function ($to, $s, $b) { fwrite(STDERR, "ALERT:$s\n"); return true; };';
[$rc, , $err] = $run(['-d', 'memory_limit=32M', '-r', $capture . ' $x = str_repeat("x", 64 * 1024 * 1024);']);
check('fataler Fehler (Speicher) wird gemeldet', $rc !== 0 && str_contains($err, 'ALERT:[TalkSlot] Fataler Fehler'));
[$rc, , $err] = $run(['-r', $capture . ' $a = []; echo @$a["still"]; echo $a["laut"];']);
check('Warnung wird gemeldet, mit @ unterdrückte nicht', $rc === 0 && substr_count($err, 'ALERT:') === 1 && str_contains($err, 'ALERT:[TalkSlot] PHP-Warnung'));
$script = tempnam(sys_get_temp_dir(), 'exc');
file_put_contents($script, '<?php chdir(' . var_export(dirname(__DIR__), true) . '); ' . $capture . ' throw new RuntimeException("kaputt mit max@example.org");');
[$rc, , $err] = $run([$script]); // eigene Datei: bei php -r ruft PHP keinen Exception-Handler auf
@unlink($script);
check('unbehandelte Exception: gemeldet, Exit-Code 1, Meldung ohne Daten', $rc === 1 && str_contains($err, 'ALERT:[TalkSlot] RuntimeException') && str_contains($err, 'Fehler: kaputt mit [email]'));
$tmpRoot = sys_get_temp_dir() . '/tp-noconfig-' . getmypid();
@mkdir($tmpRoot . '/app', 0700, true);
copy(dirname(__DIR__) . '/app/bootstrap.php', $tmpRoot . '/app/bootstrap.php');
copy(dirname(__DIR__) . '/app/helpers.php', $tmpRoot . '/app/helpers.php');
[$rc, $out] = $run(['-r', 'require ' . var_export($tmpRoot . '/app/bootstrap.php', true) . ';']);
array_map('unlink', glob($tmpRoot . '/app/*.php')); @rmdir($tmpRoot . '/app'); @rmdir($tmpRoot);
check('ohne config.php: Hinweis und Abbruch', $rc === 1 && str_contains($out, 'Konfiguration fehlt'));

echo "Atomares Deployment (Versionsverzeichnisse)\n";
$dt = sys_get_temp_dir() . '/tp-release-' . getmypid();
$base = $dt . '/talkslot-app';
@mkdir($dt . '/web', 0700, true);
copy(dirname(__DIR__) . '/bin/app-root.php', $dt . '/web/app_root.php');
foreach (['20261101-090000-v1.8.0', '20261102-090000-v1.9.0'] as $n) {
    @mkdir("$base/releases/$n/app", 0700, true);
    touch("$base/releases/$n/app/bootstrap.php");
}
@mkdir("$base/releases/20261103-090000-halb/app", 0700, true); // unvollständig: ohne bootstrap.php
$resolve = static fn () => $run(['-r', 'echo require ' . var_export($dt . '/web/app_root.php', true) . ';']);
file_put_contents("$base/current", "20261101-090000-v1.8.0\n");
check('Zeiger bestimmt die aktive Version', $resolve()[1] === "$base/releases/20261101-090000-v1.8.0");
unlink("$base/current");
check('Zeiger fehlt: neueste vollständige Version', $resolve()[1] === "$base/releases/20261102-090000-v1.9.0");
$fallback = true;
foreach (['../../etc', '20261103-090000-halb', 'gibtsnicht', ''] as $bad) {
    file_put_contents("$base/current", $bad);
    $fallback = $fallback && $resolve()[1] === "$base/releases/20261102-090000-v1.9.0";
}
check('ungültiger, unvollständiger oder leerer Zeiger: neueste vollständige Version', $fallback);
exec('rm -rf ' . escapeshellarg("$base/releases") . ' ' . escapeshellarg("$base/current"));
check('ohne Versionen: 503 statt Fehler', str_contains($resolve()[1], 'Vorübergehend nicht verfügbar'));

// Code in releases/<Name>/: Daten (app/var) im Basisverzeichnis, Assets unter /v/<Name>/
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
check('in einer Version: RELEASE, DATA_ROOT = Basis, APP_ROOT = Version', str_starts_with($out, "20261102-090000-v1.9.0|$base|$rel|"));
check('Assets unter /v/<Name>/ (mit Basispfad), Logs in <Basis>/app/var', str_ends_with($out, "|/termine/v/20261102-090000-v1.9.0/css/app.css|/web/v/20261102-090000-v1.9.0/flags/de.svg|$base/app/var/php-error.log"));
exec('rm -rf ' . escapeshellarg($dt));
check('lokal: kein RELEASE, Daten im Projekt, Assets unter /assets/ mit ?v=', RELEASE === '' && DATA_ROOT === APP_ROOT
    && preg_match('#^/assets/css/app\.css\?v=\d+$#', (function () { App\Config::load(['base_url' => 'http://localhost'] + $GLOBALS['cfg']); return asset('css/app.css'); })()) === 1);
App\Config::load($cfg);
exec('bash -n ' . escapeshellarg(dirname(__DIR__) . '/bin/build.sh') . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/deploy.sh') . ' 2>&1', $bashOut, $bashRc);
check('bin/build.sh und bin/deploy.sh syntaktisch korrekt', $bashRc === 0);

echo "HTTP\n";
App\Config::load(['base_url' => 'https://schule.example/termine'] + $cfg);
$_SERVER['REQUEST_URI'] = '/termine/admin/round/3?x=1';
check('Pfad relativ zur Anwendung (Installation in einem Unterverzeichnis)', App\Http::path() === '/admin/round/3');
unset($_SERVER['REQUEST_URI']);
App\Config::load($cfg);
[, $out] = $run(['-r', 'require "app/bootstrap.php"; App\Config::load(["force_https" => true] + (require "config/config.php")); App\Http::enforceHttps(); echo "weiter";']);
[, $outHttps] = $run(['-r', 'require "app/bootstrap.php"; $_SERVER["HTTPS"] = "on"; App\Config::load(["force_https" => true] + (require "config/config.php")); App\Http::enforceHttps(); echo "weiter";']);
check('HTTPS erzwingen: ohne HTTPS Abbruch (Weiterleitung), mit HTTPS weiter', $out === '' && $outHttps === 'weiter');

echo "Sicherheit (Audit)\n";
// SQL-Injection: Variablen im SQL-Text von Db::…-Aufrufen nur an geprüften Stellen (Werte immer als Parameter)
$allowed = [
    'BookingService.php:$perDay' => 'Wahrheitswert, wählt einen festen Zusatz',
    'Installer.php:$stmt' => 'Anweisungen aus app/schema.sql',
    'Migrations.php:$sql' => 'feste Anweisungen aus Migrations::STEPS',
    'Summaries.php:$where' => 'feste Bedingungen der Aufrufer in derselben Klasse',
    'AdminController.php:$all' => 'Wahrheitswert (Admin oder nicht)',
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
check('SQL-Text ohne eingebaute Variablen (nur geprüfte Ausnahmen' . ($unexpected ? '; neu: ' . implode(', ', array_map(static fn ($k, $l) => "$k Zeile $l", array_keys($unexpected), $unexpected)) : '') . ')', !$unexpected);
check('SQL: Tabellen-Präfix ersetzt nur Tabellennamen, nicht Werte in Anführungszeichen',
    App\Db::prefix("SELECT * FROM rounds r WHERE r.title = 'rounds' AND x = \"users\"") === "SELECT * FROM tp_rounds r WHERE r.title = 'rounds' AND x = \"users\"");
check('E-Mail: Sonderformen abgelehnt', is_valid_email('x.y+z@schule.example.org') && !is_valid_email('"a b"@example.org') && !is_valid_email('"<svg>"@x.yz') && !is_valid_email("a\n@b.de"));
check('Markdown: keine protokoll-relativen Links', !str_contains(Markdown::toHtml('[a](//evil.example) [b](/\\evil.example)'), '<a ') && str_contains(Markdown::toHtml('[c](/impressum)'), '<a href="/impressum">'));
check('Platzhalter in einem Durchgang (Werte werden nicht erneut ersetzt)', I18n::with('de', static fn () => t('manage.locked', ['hours' => '{x}', 'x' => 'BAD'])) === str_replace('{hours}', '{x}', I18n::with('de', static fn () => t('manage.locked'))));
$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.7'; $mapped = App\RateLimiter::clientKey();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; $plain4 = App\RateLimiter::clientKey(); unset($_SERVER['REMOTE_ADDR']);
check('Rate-Limit: IPv4 in IPv6-Schreibweise wie IPv4 (eigenes /24)', $mapped === $plain4);
ob_start(); App\ErrorPage::show(); $page = (string) ob_get_clean();
check('Fehlerseite ohne Inline-Styles (CSP)', !str_contains($page, 'style='));
App\Config::load(['alert_email' => 'betrieb@example.org'] + $cfg);
App\Alert::$stateFile = tempnam(sys_get_temp_dir(), 'alert');
file_put_contents(App\Alert::$stateFile, json_encode(['_day' => date('Y-m-d'), '_count' => App\Alert::MAX_PER_DAY, 'keys' => []]));
$sent = [];
App\Alert::notify('unwichtig', 'Warnung', ['x']);
App\Alert::notify('wichtig', 'Datenbank weg', ['x'], true);
check('Fehlermails: Tageslimit erreicht → wichtige Meldungen (DB weg, fatal) kommen trotzdem', count($sent) === 1 && $sent[0][1] === '[TalkSlot] Datenbank weg');
@unlink(App\Alert::$stateFile);
$log = tempnam(sys_get_temp_dir(), 'log'); $oldLog = ini_get('error_log'); ini_set('error_log', $log);
file_put_contents($log, str_repeat('x', App\ErrorPage::LOG_MAX_BYTES + 1));
App\ErrorPage::writeLog('neu');
check('Fehlerlog wird ab ' . (App\ErrorPage::LOG_MAX_BYTES >> 20) . ' MB rotiert', is_file($log . '.1') && filesize($log) < 1000 && str_contains((string) file_get_contents($log), 'neu'));
ini_set('error_log', (string) $oldLog); @unlink($log); @unlink($log . '.1');
check('Passwort: höchstens 72 Byte (bcrypt)', App\Auth::passwordPolicy(str_repeat('ä', 37)) !== null && App\Auth::passwordPolicy(str_repeat('a', 72)) === null);
App\Config::load($cfg);

echo "Skripte für das Deployment\n";
$cfgFile = tempnam(sys_get_temp_dir(), 'cfg');
$envOk = ['BASE_URL' => 'https://t.example/', 'APP_SECRET' => str_repeat('s', 40), 'INSTALL_KEY' => str_repeat('k', 20), 'DB_USER' => 'u', 'DB_PASS' => 'p',
    'DB_PREFIX' => 'x_', 'ALERT_EMAIL' => 'betrieb@example.org', 'MAIL_FROM' => 'f@example.org', ];
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['APP_SECRET' => ''] + $envOk);
check('build-config: fehlendes Secret → Abbruch', $rc === 1 && str_contains($err, 'Fehlende Variable/Secret: APP_SECRET'));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['DB_PASS' => 'CHANGE_ME'] + $envOk);
check('build-config: Platzhalter CHANGE_ME → Abbruch', $rc === 1 && str_contains($err, 'CHANGE_ME'));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['APP_SECRET' => 'kurz'] + $envOk);
check('build-config: zu kurzes Secret → Abbruch', $rc === 1 && str_contains($err, 'zu kurz'));
[$rc] = $run(['bin/build-config.php', $cfgFile], $envOk);
$built = (static fn ($f) => require $f)($cfgFile);
check('build-config: Werte übernommen (DB-Name = Benutzer, Präfix), debug aus, Datei nur für den Besitzer lesbar', $rc === 0
    && $built['base_url'] === 'https://t.example' && $built['alert_email'] === 'betrieb@example.org' && $built['db']['prefix'] === 'x_'
    && $built['db']['name'] === 'u' && $built['debug'] === false && (fileperms($cfgFile) & 0777) === 0600);
@unlink($cfgFile);
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['SMTP_ENCRYPTION' => 'starttls'] + $envOk);
check('build-config: unbekannte SMTP-Verschlüsselung → Abbruch (nie versehentlich Klartext)', $rc === 1 && str_contains($err, 'SMTP_ENCRYPTION'));
[$rc, , $err] = $run(['bin/build-config.php', $cfgFile], ['DB_PREFIX' => 'tp_;DROP'] + $envOk);
check('build-config: ungültiges Tabellen-Präfix → Abbruch', $rc === 1 && str_contains($err, 'DB_PREFIX'));
[$rc] = $run(['bin/build-config.php', $cfgFile], ['INSTALL_KEY' => str_repeat('k', 20)] + $envOk);
$noInstall = (static fn ($f) => require $f)($cfgFile);
[$rc2] = $run(['bin/build-config.php', $cfgFile], ['INSTALL_KEY' => str_repeat('k', 20), 'ALLOW_INSTALL' => '1'] + $envOk);
$withInstall = (static fn ($f) => require $f)($cfgFile);
[$rc3, , $err3] = $run(['bin/build-config.php', $cfgFile], ['ALLOW_INSTALL' => '1', 'INSTALL_KEY' => ''] + $envOk); // leere Werte gibt proc_open nicht weiter
check('build-config: Installationsschlüssel nur mit ALLOW_INSTALL=1 (dann Pflicht)', $rc === 0 && $noInstall['install_key'] === '' && $rc2 === 0 && $withInstall['install_key'] === str_repeat('k', 20) && $rc3 === 1 && str_contains($err3, 'INSTALL_KEY'));
@unlink($cfgFile);
$verFile = tempnam(sys_get_temp_dir(), 'ver');
[$rc] = $run(['bin/build-version.php', $verFile], ['VERSION' => 'v1.2.3', 'COMMIT' => 'nicht-hex']);
check('build-version: ungültiger Commit → Abbruch', $rc === 1);
[$rc] = $run(['bin/build-version.php', $verFile], ['VERSION' => 'v1.2.3', 'COMMIT' => str_repeat('a', 40)]);
$info = (static fn ($f) => require $f)($verFile);
check('build-version: Datei mit Version, Commit, Zeitpunkt', $rc === 0 && $info['version'] === 'v1.2.3' && $info['commit'] === str_repeat('a', 40) && preg_match('/^\d{4}-\d\d-\d\dT/', $info['built_at']));
@unlink($verFile);
[$rc, $out] = $run(['bin/next-version.php']);
check('next-version: gibt eine Version aus (' . trim($out) . ')', $rc === 0 && preg_match('/^v\d+\.\d+\.\d+$/', trim($out)));

echo $fail === 0 ? "ALLE UNIT-TESTS BESTANDEN\n" : "$fail FEHLER\n";
exit($fail === 0 ? 0 : 1);
