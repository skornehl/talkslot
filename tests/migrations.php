<?php
/**
 * Migrations against the database (expert review P2-5):
 *   php tests/migrations.php
 * Docker: docker compose -f dev/compose.yml exec app php /srv/app/tests/migrations.php (part of the smoke test).
 *
 * The schema of the first release (tests/fixtures/schema-v1.0.0.sql, schema version 12) with some data, brought up
 * to date by Migrations::run(), must end up like a new installation: the same tables, columns, indexes and foreign
 * keys. Then every step since that release runs once more, as after an interrupted update, and nothing may change. Works in throwaway
 * tables under a random prefix in the configured database, dropped at the end, so an installation stays untouched.
 * Only with "debug" in config.php (the Docker environment) or TALKSLOT_INTEGRATION=1.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
App\Platform::enforce(true);

use App\Db;
use App\Migrations;
use App\Settings;

if (!App\Config::get('debug', false) && getenv('TALKSLOT_INTEGRATION') !== '1') {
    fwrite(STDERR, "tests/migrations.php creates and drops tables: only with \"debug\" in config.php or TALKSLOT_INTEGRATION=1\n");
    exit(2);
}

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    $fail += $ok ? 0 : 1;
}

$cfg = App\Config::all();
$run = 'mg' . bin2hex(random_bytes(4));
$as = static function (string $prefix) use ($cfg): void {
    App\Config::load(['db' => ['prefix' => $prefix] + (array) $cfg['db']] + $cfg);
};
$pdo = Db::pdo();
$pdo->exec('SET SESSION lock_wait_timeout = 30');
register_shutdown_function(static function () use ($pdo, $run): void {
    $tables = $pdo->query("SHOW TABLES LIKE '" . $run . "%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($tables) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE `' . implode('`, `', $tables) . '`');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
});

$load = static function (string $file): void {
    foreach (explode(';', (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file))) as $stmt) {
        if (trim($stmt) !== '') {
            Db::execRaw(trim($stmt));
        }
    }
};

/** Everything that defines the schema of a prefix, without the prefix and without the order of the columns */
$snapshot = static function (string $prefix) use ($pdo): array {
    $query = static function (string $sql) use ($pdo, $prefix): array {
        $st = $pdo->prepare($sql);
        $st->execute([str_replace('_', '\_', $prefix) . '%']);
        return $st->fetchAll(PDO::FETCH_NUM);
    };
    $strip = static fn (?string $name): string => str_starts_with((string) $name, $prefix) ? substr((string) $name, strlen($prefix)) : (string) $name;
    $out = [];
    foreach ($query('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?') as [$t, $engine, $collation]) {
        $out["table {$strip($t)}"] = "$engine $collation";
    }
    foreach ($query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?') as [$t, $c, $type, $null, $default, $extra, $collation]) {
        $out["column {$strip($t)}.$c"] = "$type null=$null default=" . var_export($default, true) . " $extra $collation";
    }
    $indexes = [];
    foreach ($query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as [$t, $i, $nonUnique, $c, $sub]) {
        $key = "index {$strip($t)}.{$strip($i)}"; // an implicit index of a foreign key has the name of the key
        $indexes[$key] = ($indexes[$key] ?? ($nonUnique ? 'key' : 'unique')) . " $c" . ($sub !== null ? "($sub)" : '');
    }
    $out += $indexes;
    foreach ($query('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
           WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME LIKE ? AND k.REFERENCED_TABLE_NAME IS NOT NULL') as [$t, $name, $c, $refT, $refC, $update, $delete]) {
        $out["foreign key {$strip($t)}.$c"] = "{$strip($refT)}.$refC update=$update delete=$delete name={$strip($name)}";
    }
    ksort($out);
    return $out;
};

/** The differences of two snapshots, one per line: "- what only $a has", "+ what only $b has" */
$differences = static function (array $a, array $b): array {
    $lines = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
        if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
            $lines[] = "$key: " . ($a[$key] ?? '(missing)') . ' <> ' . ($b[$key] ?? '(missing)');
        }
    }
    return $lines;
};

echo "Migrations from the first release ($run)\n";
$old = $run . 'a_';
$as($old);
$load(__DIR__ . '/fixtures/schema-v1.0.0.sql');
$now = '2026-11-01 10:00:00';
$userId = Db::insert("INSERT INTO users (username, password_hash, role, display_name, email, created_at) VALUES ('Sabrina.Test', 'x', 'admin', 'Frau Test', 'lehrer@example.org', ?)", [$now]);
$roundId = Db::insert("INSERT INTO rounds (user_id, title, slug, created_at, updated_at) VALUES (?, 'Elternsprechtag', 'elternsprechtag', ?, ?)", [$userId, $now, $now]);
$dayId = Db::insert("INSERT INTO round_days (round_id, day_date, start_time, end_time, slot_minutes) VALUES (?, '2026-12-01', '14:00:00', '15:00:00', 30)", [$roundId]);
$slotId = Db::insert("INSERT INTO slots (day_id, start_time, end_time) VALUES (?, '14:00:00', '14:30:00')", [$dayId]);
Db::insert("INSERT INTO bookings (slot_id, name, token_hash, created_at, updated_at) VALUES (?, 'Anna Muster', ?, ?, ?)", [$slotId, str_repeat('a', 64), $now, $now]);
Settings::set('schema_version', '12');

Migrations::run();
check('every step from the first release on ran: schema version ' . Settings::get('schema_version') . ' of ' . Migrations::LATEST, (int) Settings::get('schema_version') === Migrations::LATEST);
check('the data of the first release is still there, and the steps filled in the new columns',
    Db::value('SELECT name FROM bookings WHERE slot_id = ?', [$slotId]) === 'Anna Muster'
    && Db::value('SELECT COUNT(*) FROM rounds WHERE id = ? AND user_id = ?', [$roundId, $userId]) === 1
    && (int) Db::value('SELECT was_used FROM rounds WHERE id = ?', [$roundId]) === 1 && (int) Db::value('SELECT remind_ok FROM bookings WHERE slot_id = ?', [$slotId]) === 1);
$hash = str_repeat('a', 64);
check('step 29: the username keeps its spelling and still matches without regard to case; a hash matches exactly',
    Db::value('SELECT username FROM users WHERE id = ?', [$userId]) === 'Sabrina.Test' && (int) Db::value('SELECT id FROM users WHERE username = ?', ['SABRINA.test']) === $userId
    && Db::value('SELECT token_hash FROM bookings WHERE slot_id = ?', [$slotId]) === $hash && Db::value('SELECT COUNT(*) FROM bookings WHERE token_hash = ?', [strtoupper($hash)]) === 0);
$migrated = $snapshot($old);
$rows = static fn (): string => (string) json_encode([Db::all('SELECT * FROM users'), Db::all('SELECT * FROM rounds'), Db::all('SELECT * FROM round_days'), Db::all('SELECT * FROM slots'), Db::all('SELECT * FROM bookings')]);
$before = $rows();

// As after an interrupted update: every step again, on the finished schema and with the data
Settings::set('schema_version', '12');
Migrations::run();
$again = $differences($migrated, $snapshot($old));
check('every step since the first release once more (as after an interrupted update): no error, nothing changes' . ($again ? "\n      " . implode("\n      ", $again) : ''),
    !$again && (int) Settings::get('schema_version') === Migrations::LATEST && $rows() === $before);

$fresh = $run . 'b_';
$as($fresh);
$load(APP_ROOT . '/app/schema.sql');
$diff = $differences($migrated, $snapshot($fresh));
check('migrated from the first release = new installation (tables, columns, indexes, foreign keys)' . ($diff ? "\n      " . implode("\n      ", $diff) : ''), !$diff && count($migrated) > 100);

echo $fail === 0 ? "ALL MIGRATION TESTS PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
