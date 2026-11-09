<?php
/**
 * Input for appointment days: date(s), time range, slot length, breaks + live preview.
 * Used when creating a round, for "Add day", for "Tag bearbeiten" ($singleDate: the one date) and (without dates,
 * $withDates = false) for templates.
 * @var array $dayData ['dates' => list, 'start' => 'HH:MM', 'end' => ..., 'minutes' => int, 'breaks' => list<[start,end,label]>]
 * @var list<string> $bookedSlots "Tag bearbeiten": start times of the bookings; the preview counts those that would no longer fit
 * @var string $singleDate "Tag bearbeiten": the date of the day
 */
$dates = $dayData['dates'] ?: [''];
// Break rows only for breaks already entered (e.g. after an error); "+ Pause hinzufügen"
// adds more from the template.
$breaks = $dayData['breaks'] ?: [];
$breakRow = static function (string $bs, string $be, string $bl): void { ?>
      <div class="inline-form break-row">
        <label><?= e(__('Von')) ?> <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="break_start[]" value="<?= e($bs) ?>"></label>
        <label><?= e(__('Bis')) ?> <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="break_end[]" value="<?= e($be) ?>"></label>
        <label><?= e(__('Bezeichnung')) ?> <input type="text" name="break_label[]" maxlength="100" value="<?= e($bl) ?>" placeholder="<?= e(__('z. B. Mittagspause')) ?>"></label>
        <button type="button" class="button-link" data-remove-break><?= e(__('Entfernen')) ?></button>
      </div>
<?php };
?>
<div class="day-fields" data-day-fields<?= isset($bookedSlots) ? ' data-booked="' . e(implode(',', $bookedSlots)) . '"' : '' ?>>
  <fieldset class="field pin-fieldset">
    <legend><?= e(__('Wann?')) ?></legend>
    <?php if ($withDates ?? true): ?>
    <div class="field" data-dates>
      <label><?= e(__('Datum')) ?> <span class="muted small"><?= e(__('(weitere Tage mit denselben Zeiten möglich)')) ?></span></label>
      <?php foreach ($dates as $i => $d): ?>
        <input type="date" data-de-date name="day_dates[]" value="<?= e($d) ?>"<?= $i === 0 ? ' required' : '' ?> class="date-input" aria-label="<?= e(__('Datum {n}', ['n' => $i + 1])) ?>">
      <?php endforeach; ?>
      <button type="button" class="button button-small" data-add-date><?= e(__('+ weiteren Tag')) ?></button>
    </div>
    <?php endif; ?>
    <?php if (isset($singleDate)): ?>
    <div class="field"><label for="day_date"><?= e(__('Datum')) ?></label><input type="date" data-de-date id="day_date" name="day_date" required value="<?= e($singleDate) ?>"></div>
    <?php endif; ?>
    <div class="field-row">
      <div class="field"><label for="start_time"><?= e(__('Erster Termin ab')) ?></label><input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time id="start_time" name="start_time" required value="<?= e($dayData['start']) ?>"></div>
      <div class="field"><label for="end_time"><?= e(__('Letzter Termin endet um')) ?></label><input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time id="end_time" name="end_time" required value="<?= e($dayData['end']) ?>"></div>
      <div class="field"><label for="slot_minutes"><?= e(__('Dauer pro Termin (Minuten)')) ?></label><input type="number" id="slot_minutes" name="slot_minutes" required min="5" max="240" step="<?= (int) $dayData['minutes'] % 5 ? 1 : 5 /* a length from before the steps of 5 stays valid */ ?>" value="<?= (int) $dayData['minutes'] ?>"></div>
    </div>
  </fieldset>

  <fieldset class="field pin-fieldset">
    <legend><?= e(__('Pausen (optional)')) ?></legend>
    <div data-breaks>
      <?php foreach ($breaks as [$bs, $be, $bl]) { $breakRow($bs, $be, $bl); } ?>
    </div>
    <template data-break-template><?php $breakRow('', '', ''); ?></template>
    <button type="button" class="button button-small" data-add-break><?= e(__('+ Pause hinzufügen')) ?></button>
    <p class="hint"><?= e(__('Uhrzeiten im 24-Stunden-Format, z. B. 14:30. In Pausen werden keine Termine angeboten; danach geht es ab Pausenende weiter.')) ?></p>
  </fieldset>

  <?php if (isset($bookedSlots)): ?><div class="alert alert-warning" data-slot-conflicts hidden></div><?php endif; ?>
  <div class="alert alert-info slot-preview" data-slot-preview hidden></div>
  <div class="visually-hidden" role="status" data-slot-status></div>
</div>
