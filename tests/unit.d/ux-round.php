<?php
// Usability: the admin round page and its forms (expert review P2-3; see README.md in this directory)
echo "Round form and day form (expert review P2-3)\n";
check('deletion period: hours shown as days (whole or with decimals), empty stays empty',
    days_from_hours(24) === '1' && days_from_hours('36') === '1.5' && days_from_hours(0) === '0' && days_from_hours(8760) === '365'
    && days_from_hours(null) === '' && days_from_hours('') === '');
$_SESSION = ['csrf' => 'test'];
$rfData = ['title' => '', 'slug' => '', 'description' => '', 'location' => '', 'source_lang' => 'de', 'is_active' => 1, 'booking_deadline_hours' => 24,
    'cancel_deadline_hours' => 12, 'delete_after_hours' => 36, 'random_suffix' => 1, 'code_required' => 0, 'booking_limit' => 'round', 'remind_parents' => 1];
$rfForm = static fn (array $vars = []): string => App\View::capture('admin/round_form', $vars + ['title' => 'Neue Runde', 'round' => null, 'data' => $rfData, 'errors' => [],
    'parents' => ['upcoming' => 0, 'mailable' => 0], 'owners' => [], 'templates' => [], 'template' => null,
    'dayData' => ['dates' => [''], 'start' => '14:00', 'end' => '18:00', 'minutes' => 15, 'breaks' => []]]);
$rf = $rfForm();
$rfPos = static fn (string $needle): int => (int) strpos($rf, $needle);
check('round form: what, when, rules for parents, then the technical options folded away',
    $rfPos('<legend>Worum geht es?</legend>') < $rfPos('id="title"') && $rfPos('id="title"') < $rfPos('id="description"') && $rfPos('id="description"') < $rfPos('id="location"')
    && $rfPos('id="location"') < $rfPos('<legend>Wann?</legend>') && $rfPos('<legend>Wann?</legend>') < $rfPos('<legend>Regeln für Eltern</legend>')
    && $rfPos('<legend>Regeln für Eltern</legend>') < $rfPos('id="booking_limit"') && $rfPos('id="remind_parents"') < $rfPos('<details class="form-section">')
    && $rfPos('<details class="form-section">') < $rfPos('id="random_suffix"') && $rfPos('id="random_suffix"') < $rfPos('id="source_lang"') && $rfPos('id="source_lang"') < $rfPos('id="delete_after_days"')
    && $rfPos('id="delete_after_days"') < $rfPos('id="is_active"'));
check('round form: the hint on the round code right under its checkbox, the deletion period in days, opened after an error',
    (bool) preg_match('#<label for="code_required">Nur mit Rundencode</label>\s*</div>\s*<p class="hint hint-indent" id="cr-hint">#', $rf)
    && str_contains($rf, 'name="delete_after_days" min="0" max="365" step="any" value="1.5"') && !str_contains($rf, 'Slug')
    && str_contains($rfForm(['errors' => ['x']]), '<details class="form-section" open>')
    && str_contains($rfForm(['data' => ['delete_after_days' => 'abc'] + $rfData]), 'step="any" value="abc"'));
$dfForm = App\View::capture('admin/day_form', ['title' => 'Tag bearbeiten', 'errors' => [], 'notify' => true, 'parents' => ['upcoming' => 0, 'mailable' => 0],
    'day' => ['id' => 7, 'day_date' => '2026-12-01', 'location' => ''], 'round' => ['id' => 3, 'title' => 'Runde', 'location' => ''],
    'dayData' => ['dates' => [], 'date' => '2026-12-01', 'location' => '', 'start' => '14:00', 'end' => '16:00', 'minutes' => 15, 'breaks' => [['15:00', '15:15', 'Pause']]],
    'bookedSlots' => ['14:00', '15:30']]);
check('day form like a new day: the same fields and preview, breaks editable, the booked slots for the preview',
    str_contains($dfForm, 'data-day-fields data-booked="14:00,15:30"') && str_contains($dfForm, 'data-slot-conflicts') && str_contains($dfForm, 'role="status" data-slot-status') && !str_contains($dfForm, 'aria-live') && str_contains($dfForm, '<label for="start_time">Erster Termin ab</label>')
    && str_contains($dfForm, 'step="5" value="15"') && str_contains($dfForm, 'name="break_start[]" value="15:00"') && str_contains($dfForm, 'name="breaks_field" value="1"')
    && str_contains($dfForm, 'data-slot-preview') && !str_contains($dfForm, 'name="day_dates[]"') && (bool) preg_match('#<legend>Wann\?</legend>\s*<div class="field"><label for="day_date">#', $dfForm) && str_contains($dfForm, 'name="day_date" required value="2026-12-01"'));
$dfOld = App\View::capture('admin/day_form', ['title' => 'x', 'errors' => [], 'notify' => true, 'parents' => ['upcoming' => 0, 'mailable' => 0], 'day' => ['id' => 7, 'day_date' => '2026-12-01', 'location' => ''], 'round' => ['id' => 3, 'title' => 'R', 'location' => ''],
    'dayData' => ['dates' => [], 'start' => '14:00', 'end' => '16:00', 'minutes' => 12, 'breaks' => []], 'bookedSlots' => []]);
check('day form: a length from before the steps of five stays valid', str_contains($dfOld, 'step="1" value="12"'));
$_SESSION = [];
