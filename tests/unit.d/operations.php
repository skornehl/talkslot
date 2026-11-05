<?php
// Operations: no silent data loss (expert review P0-6). The clean-up itself, deleting a round and the
// defensive slot sync need the database: tests/smoke.d/operations.sh
echo "Operations: deletion date of a round\n";

$at = static function (array $round, ?string $lastDay): ?string {
    $d = App\Cleanup::deletionAt($round + ['is_active' => 0, 'was_used' => 0, 'expired_at' => null, 'delete_after_hours' => 24], $lastDay);
    return $d?->format('Y-m-d H:i');
};
check('active round: 0:00 after the last day plus the deletion period',
    $at(['is_active' => 1], '2026-11-10') === '2026-11-12 00:00' && $at(['is_active' => 1, 'delete_after_hours' => 36], '2026-11-10') === '2026-11-12 12:00'
    && $at(['is_active' => 1, 'delete_after_hours' => 0], '2026-11-10') === '2026-11-11 00:00');
check('inactive round that was in use (deactivated by hand, or with an entry): deleted the same way',
    $at(['was_used' => 1], '2026-11-10') === '2026-11-12 00:00');
check('draft that was never used (e.g. a copy with the old dates): not deleted automatically',
    $at([], '2020-01-01') === null && $at([], '2099-01-01') === null);
check('expired: the period counts from the expiry',
    $at(['expired_at' => '2026-11-11 00:15:00'], '2026-11-10') === '2026-11-12 00:15'
    && $at(['expired_at' => '2026-11-11 00:15:00', 'was_used' => 1, 'delete_after_hours' => 0], '2026-11-10') === '2026-11-11 00:15');
check('expired, but a day is still ahead: not before that day is over',
    $at(['expired_at' => '2026-11-11 00:15:00', 'was_used' => 1], '2026-12-01') === '2026-12-02 00:00');
check('never: no deletion period, or no days',
    $at(['is_active' => 1, 'delete_after_hours' => null], '2026-11-10') === null && $at(['expired_at' => '2026-11-11 00:15:00', 'delete_after_hours' => null], '2026-11-10') === null
    && $at(['is_active' => 1], null) === null);
