<?php
/**
 * Eingabe für Termintage: Datum(e), Zeitraum, Termindauer, Pausen + Live-Vorschau.
 * Genutzt beim Anlegen einer Runde und bei „Tag hinzufügen“.
 * @var array $dayData ['dates' => list, 'start' => 'HH:MM', 'end' => ..., 'minutes' => int, 'breaks' => list<[start,end,label]>]
 */
$dates = $dayData['dates'] ?: [''];
// Pausenzeilen nur für eingegebene Pausen (z. B. nach einem Fehler); weitere fügt
// „+ Pause hinzufügen“ aus der Vorlage hinzu.
$breaks = $dayData['breaks'] ?: [];
$breakRow = static function (string $bs, string $be, string $bl): void { ?>
      <div class="inline-form break-row">
        <label>Von <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="break_start[]" value="<?= e($bs) ?>"></label>
        <label>Bis <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="break_end[]" value="<?= e($be) ?>"></label>
        <label>Bezeichnung <input type="text" name="break_label[]" maxlength="100" value="<?= e($bl) ?>" placeholder="z. B. Mittagspause"></label>
        <button type="button" class="button-link" data-remove-break>Entfernen</button>
      </div>
<?php };
?>
<div class="day-fields" data-day-fields>
  <fieldset class="field pin-fieldset">
    <legend>Wann?</legend>
    <div class="field" data-dates>
      <label>Datum <span class="muted small">(weitere Tage mit denselben Zeiten möglich)</span></label>
      <?php foreach ($dates as $i => $d): ?>
        <input type="date" data-de-date name="day_dates[]" value="<?= e($d) ?>"<?= $i === 0 ? ' required' : '' ?> class="date-input" aria-label="Datum <?= $i + 1 ?>">
      <?php endforeach; ?>
      <button type="button" class="button button-small" data-add-date>+ weiteren Tag</button>
    </div>
    <div class="field-row">
      <div class="field"><label for="start_time">Erster Termin ab</label><input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time id="start_time" name="start_time" required value="<?= e($dayData['start']) ?>"></div>
      <div class="field"><label for="end_time">Letzter Termin endet um</label><input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time id="end_time" name="end_time" required value="<?= e($dayData['end']) ?>"></div>
      <div class="field"><label for="slot_minutes">Dauer pro Termin (Minuten)</label><input type="number" id="slot_minutes" name="slot_minutes" required min="5" max="240" step="5" value="<?= (int) $dayData['minutes'] ?>"></div>
    </div>
  </fieldset>

  <fieldset class="field pin-fieldset">
    <legend>Pausen (optional)</legend>
    <div data-breaks>
      <?php foreach ($breaks as [$bs, $be, $bl]) { $breakRow($bs, $be, $bl); } ?>
    </div>
    <template data-break-template><?php $breakRow('', '', ''); ?></template>
    <button type="button" class="button button-small" data-add-break>+ Pause hinzufügen</button>
    <p class="hint">Uhrzeiten im 24-Stunden-Format, z. B. 14:30. In Pausen werden keine Termine angeboten; danach geht es ab Pausenende weiter.</p>
  </fieldset>

  <div class="alert alert-info slot-preview" data-slot-preview aria-live="polite" hidden></div>
</div>
