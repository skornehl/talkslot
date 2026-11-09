<?php
// Trash for rounds without bookings (expert review P3-9); the behaviour with the database: tests/smoke.d/round-trash.sh
echo "Round trash\n";
preg_match('/CREATE TABLE IF NOT EXISTS rounds \((.*?)\n  UNIQUE KEY/s', (string) file_get_contents(dirname(__DIR__, 2) . '/app/schema.sql'), $rtSchema);
preg_match_all('/^  ([a-z_]+) /m', $rtSchema[1] ?? '', $rtColumns);
$rtSchemaColumns = array_values(array_diff($rtColumns[1], ['id']));
check('the copy of a round has every column of rounds (a new column must be added to RoundTrash::ROUND_COLUMNS)',
    $rtSchemaColumns !== [] && array_values(array_diff($rtSchemaColumns, App\RoundTrash::ROUND_COLUMNS)) === [] && array_values(array_diff(App\RoundTrash::ROUND_COLUMNS, $rtSchemaColumns)) === []);
$rtSource = (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/RoundTrash.php');
$rtSchemaSql = (string) file_get_contents(dirname(__DIR__, 2) . '/app/schema.sql');
$rtMissing = [];
foreach (['round_days' => ['id', 'round_id'], 'day_breaks' => ['id', 'day_id'], 'slots' => ['id', 'day_id', 'is_conflict'], 'round_translations' => ['round_id']] as $rtTable => $rtSkip) {
    preg_match('/CREATE TABLE IF NOT EXISTS ' . $rtTable . ' \((.*?)\n\) ENGINE/s', $rtSchemaSql, $rtDef);
    preg_match_all('/^  ([a-z_]+) [A-Z]/m', $rtDef[1] ?? '', $rtCols);
    preg_match('/INSERT INTO ' . $rtTable . ' \(([^)]*)\)/', $rtSource, $rtInsert);
    $rtListed = array_map('trim', explode(',', $rtInsert[1] ?? ''));
    foreach (array_diff($rtCols[1], $rtSkip) as $rtColumn) {
        if (!in_array($rtColumn, $rtListed, true)) {
            $rtMissing[] = "$rtTable.$rtColumn";
        }
    }
}
check('restoring brings back every column of days, breaks, slots and translations' . ($rtMissing ? ' (missing: ' . implode(', ', $rtMissing) . ')' : ''), !$rtMissing);
check('the trash table gets the table prefix and is created by the migration and schema.sql',
    in_array('round_trash', App\Db::TABLES, true) && App\Migrations::LATEST >= 24
    && str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/app/schema.sql'), 'CREATE TABLE IF NOT EXISTS round_trash'));
check('an entry stays for 14 days', App\RoundTrash::KEEP_DAYS === 14 && App\RoundTrash::until('2026-11-05 14:00:00') === '2026-11-19');
