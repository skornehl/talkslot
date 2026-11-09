<?php
// Security events of an account, for its holder only (expert review P2-9). Recording and showing them need the
// database: tests/smoke.d/security-events.sh
echo "Security events\n";
$seEvents = (new ReflectionClass(App\SecurityEvents::class))->getConstants();
check('security events: a text for each event, in German and English', count($seEvents) >= 8 && !array_filter($seEvents,
    static fn (string $e): bool => App\SecurityEvents::label($e, false) === $e
        || App\AdminI18n::with('en', static fn (): string => App\SecurityEvents::label($e, false)) === App\SecurityEvents::label($e, false)));
check('… "by an admin" only when an admin made the change', str_ends_with(App\SecurityEvents::label(App\SecurityEvents::EMAIL_CHANGED, true), '(durch einen Admin)')
    && !str_contains(App\SecurityEvents::label(App\SecurityEvents::EMAIL_CHANGED, false), 'Admin'));
check('… the names fit the column', max(array_map('strlen', $seEvents)) <= 30);
preg_match('/CREATE TABLE IF NOT EXISTS security_events \((.*?)\) ENGINE/s', (string) file_get_contents(APP_ROOT . '/app/schema.sql'), $seTable);
$seColumns = array_values(array_filter(array_map(static fn (string $l): string => (string) strtok(trim($l), ' '), explode("\n", trim($seTable[1] ?? ''))), static fn (string $c): bool => !in_array($c, ['KEY', 'CONSTRAINT', ''], true)));
check('table: no IP address, device or text, deleted with the account', $seColumns === ['id', 'user_id', 'event', 'by_admin', 'created_at']
    && str_contains($seTable[1] ?? '', 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'));
App\Config::load(['security_events_days' => 0] + $cfg);
$seMin = App\SecurityEvents::retentionDays();
App\Config::load($cfg);
App\Config::load(['security_events_days' => 3650] + $cfg);
$seMax = App\SecurityEvents::retentionDays();
App\Config::load($cfg);
check('retention: 30 days by default, at least 1, at most 90', App\SecurityEvents::retentionDays() === 30 && $seMin === 1 && $seMax === 90);
