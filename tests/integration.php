<?php
/**
 * Integration tests against the database (expert review P1-14): the services are called directly, without HTTP.
 *   php tests/integration.php
 * Docker: docker compose -f dev/compose.yml exec app php /srv/app/tests/integration.php; tests/smoke.d/quality.sh
 * runs it as part of the smoke test. Needs config/config.php with a reachable database.
 *
 * Every run works in a throwaway schema of its own: the tables of app/schema.sql under a random prefix
 * (it<hex>_) in the configured database, dropped again at the end, also after a failure. The data of an
 * installation or of a running smoke test (prefix tp_) stay untouched. A transaction with rollback would not do:
 * DDL commits implicitly, and lock tests need a second connection. The clock is fixed with App\Time::setNow().
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
App\Platform::enforce(true);

use App\BookingException;
use App\BookingService;
use App\Db;
use App\SlotGenerator;
use App\Time;

// Warnings and deprecations fail the run, as in tests/unit.php
set_error_handler(static function (int $type, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $type)) {
        return false;
    }
    throw new ErrorException($message . ' in ' . str_replace(dirname(__DIR__), '', $file) . ':' . $line, 0, $type, $file, $line);
});

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    $fail += $ok ? 0 : 1;
}

/** The code of the BookingException $fn throws, or "booked" */
function refused(callable $fn): string
{
    try {
        $fn();
        return 'booked';
    } catch (BookingException $e) {
        return $e->getMessage();
    }
}

$cfg = App\Config::all();
$prefix = 'it' . bin2hex(random_bytes(4)) . '_';
App\Config::load(['db' => ['prefix' => $prefix] + (array) $cfg['db']] + $cfg);
$pdo = Db::pdo();
$ours = static fn (): array => $pdo->query("SHOW TABLES LIKE '" . str_replace('_', '\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN);
register_shutdown_function(static function () use ($pdo, $ours): void {
    Time::setNow(null);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($tables = $ours()) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE `' . implode('`, `', $tables) . '`');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
});

echo "Throwaway schema\n";
$schema = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(APP_ROOT . '/app/schema.sql'));
// Names of foreign keys are unique per database, not per table: the throwaway tables get names of their own
foreach (explode(';', str_replace('CONSTRAINT fk_', 'CONSTRAINT ' . $prefix . 'fk_', $schema)) as $stmt) {
    if (trim($stmt) !== '') {
        Db::execRaw(trim($stmt));
    }
}
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $schema, $m);
$expected = array_map(static fn (string $t): string => $prefix . $t, $m[1]);
sort($expected);
$tables = $ours();
sort($tables);
check('every table of schema.sql under the prefix of this run (' . count($tables) . ')', $tables === $expected);

Time::setNow('2026-11-29 12:00:00');
$now = Time::nowSql();
$userId = Db::insert("INSERT INTO users (username, password_hash, role, display_name, email, created_at) VALUES ('lehrerin', 'x', 'teacher', 'Frau Test', 'lehrerin@example.org', ?)", [$now]);
$roundId = Db::insert(
    "INSERT INTO rounds (user_id, title, slug, booking_limit, is_active, was_used, booking_deadline_hours, cancel_deadline_hours, delete_after_hours, created_at, updated_at)
     VALUES (?, 'Elternsprechtag 3b', 'elternsprechtag-3b', 'round', 1, 1, 24, 12, 24, ?, ?)",
    [$userId, $now, $now]
);
$dayId = Db::insert("INSERT INTO round_days (round_id, day_date, start_time, end_time, slot_minutes) VALUES (?, '2026-12-01', '14:00:00', '15:00:00', 15)", [$roundId]);
$round = Db::one('SELECT * FROM rounds WHERE id = ?', [$roundId]);
$slots = static fn (): array => Db::all('SELECT id, start_time, is_conflict FROM slots WHERE day_id = ? ORDER BY start_time', [$dayId]);

echo "Slots and bookings\n";
$conflicts = SlotGenerator::sync($dayId);
$ids = array_column($slots(), 'id');
check('SlotGenerator::sync(): 14:00–15:00 in steps of 15 minutes → 4 slots', $conflicts === 0
    && array_column($slots(), 'start_time') === ['14:00:00', '14:15:00', '14:30:00', '14:45:00']);
[$annaId, $annaToken] = BookingService::book($round, $ids[0], 'Anna Muster', 'anna@example.org', 'de');
check('book(): stored with the hash of the link, found again by the link', (BookingService::findByToken($annaToken)['id'] ?? null) === $annaId
    && Db::value('SELECT token_hash FROM bookings WHERE id = ?', [$annaId]) === hash('sha256', $annaToken));
check('the same slot once more: taken', refused(static fn () => BookingService::book($round, $ids[0], 'Ben Beispiel', null, 'de')) === BookingService::TAKEN);
check('limit "one appointment per round": the same person (case, spaces) is refused another slot',
    refused(static fn () => BookingService::book($round, $ids[1], ' anna  MUSTER ', 'Anna@Example.org', 'de')) === BookingService::LIMIT_ROUND);

// The booking deadline (24 hours) with a fixed clock: at 14:30 the day before, 14:30 is no longer bookable
Time::setNow('2026-11-30 14:30:00');
check('booking deadline: free slots only after now + 24 hours', array_column(BookingService::freeSlots($round), 'id') === [$ids[3]]);
$late = refused(static fn () => BookingService::book($round, $ids[2], 'Cem Yılmaz', null, 'tr'));
Time::setNow('2026-11-30 14:29:59');
check('booking deadline: a slot inside it is refused, one second earlier it can be booked', $late === BookingService::DEADLINE
    && refused(static fn () => BookingService::book($round, $ids[2], 'Cem Yılmaz', null, 'tr')) === 'booked');
Time::setNow('2026-11-30 14:30:00');
[$entryId] = BookingService::adminBook($round, $ids[1], 'Dana Doe', null, 'en');
check('entry by the teacher: ignores the deadline, source "admin", no reminder until the parents got a mail',
    Db::one('SELECT source, remind_ok FROM bookings WHERE id = ?', [$entryId]) === ['source' => 'admin', 'remind_ok' => 0]);

// A parallel transaction on a second connection holds the slot: the booking waits, retries once and then
// reports "taken" instead of a server error
$db = (array) $cfg['db'];
$other = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', (int) ($db['port'] ?? 3306), $db['name'] ?? ''),
    (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$other->beginTransaction();
$other->prepare(Db::prefix('SELECT id FROM slots WHERE id = ? FOR UPDATE'))->execute([$ids[3]]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
$t = microtime(true);
$locked = refused(static fn () => BookingService::book($round, $ids[3], 'Eva Ernst', null, 'de'));
$waited = microtime(true) - $t;
$other->rollBack();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
check(sprintf('slot locked by another transaction: two attempts, then "taken" (%.1f s)', $waited), $locked === BookingService::TAKEN && $waited >= 2);

echo "Changing the day\n";
Db::insert("INSERT INTO day_breaks (day_id, start_time, end_time, label) VALUES (?, '14:15:00', '14:30:00', 'Pause')", [$dayId]);
$conflicts = SlotGenerator::sync($dayId);
check('a break over a booked slot: the booking stays, its slot is marked as a conflict, the others are kept', $conflicts === 1
    && array_column($slots(), 'is_conflict', 'start_time') === ['14:00:00' => 0, '14:15:00' => 1, '14:30:00' => 0, '14:45:00' => 0]
    && Db::value('SELECT slot_id FROM bookings WHERE id = ?', [$entryId]) === $ids[1]);
BookingService::move(BookingService::findById($entryId), $ids[3], true);
check('moved by the teacher to a free slot: the conflict slot, now without a booking, is removed',
    array_column($slots(), 'start_time') === ['14:00:00', '14:30:00', '14:45:00'] && Db::value('SELECT slot_id FROM bookings WHERE id = ?', [$entryId]) === $ids[3]);

echo "Deleting after the day\n";
Time::setNow('2026-12-01 23:59:59');
check('on the day itself nothing is deleted', App\Cleanup::run()['bookings_deleted'] === 0);
Time::setNow('2026-12-02 00:00:01');
$first = App\Cleanup::run();
Time::setNow('2026-12-03 00:00:01');
$second = App\Cleanup::run();
check('from 0:00 the day after: bookings deleted, round expired; the round itself 24 hours later (delete_after_hours)',
    $first['bookings_deleted'] === 3 && $first['rounds_expired'] === 1 && $first['rounds_deleted'] === 0
    && $second['rounds_deleted'] === 1 && Db::value('SELECT COUNT(*) FROM rounds') === 0 && Db::value('SELECT COUNT(*) FROM slots') === 0);

echo $fail === 0 ? "ALL INTEGRATION TESTS PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
