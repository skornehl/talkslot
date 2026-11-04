<?php /** @var array $day @var array $round @var array $errors */ use App\Csrf; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>"><?= e(__('Runden')) ?></a> › <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e($round['title']) ?></a></p>
<h1><?= e(__('Tag bearbeiten')) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>
<p class="alert alert-info"><?= e(__('Gebuchte Termine werden bei Änderungen nie automatisch gelöscht. Passt ein gebuchter Termin nicht mehr in den neuen Zeitplan, wird er als Konflikt markiert.')) ?></p>
<form method="post" action="<?= e(url('/admin/day/' . (int) $day['id'] . '/edit')) ?>" class="card form-grid">
  <?= Csrf::adminField() ?>
  <div class="field-row">
    <div class="field"><label for="day_date"><?= e(__('Datum')) ?></label><input type="date" data-de-date id="day_date" name="day_date" required value="<?= e($day['day_date']) ?>"></div>
    <div class="field"><label for="start_time"><?= e(__('Von')) ?></label><input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time id="start_time" name="start_time" required value="<?= e(fmt_time($day['start_time'])) ?>"></div>
    <div class="field"><label for="end_time"><?= e(__('Bis')) ?></label><input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time id="end_time" name="end_time" required value="<?= e(fmt_time($day['end_time'])) ?>"></div>
    <div class="field"><label for="slot_minutes"><?= e(__('Minuten pro Termin')) ?></label><input type="number" id="slot_minutes" name="slot_minutes" required min="5" max="240" value="<?= (int) $day['slot_minutes'] ?>"></div>
  </div>
  <div class="field">
    <label for="location"><?= e(__('Ort an diesem Tag (optional)')) ?></label>
    <input type="text" id="location" name="location" maxlength="200" value="<?= e($day['location'] ?? '') ?>" placeholder="<?= e(trim((string) ($round['location'] ?? '')) !== '' ? __('wie die Runde: {location}', ['location' => trim((string) $round['location'])]) : __('z. B. Raum 2.14')) ?>" aria-describedby="dloc-hint">
    <p class="hint" id="dloc-hint"><?= e(__('Nur ausfüllen, wenn der Tag nicht am Ort der Runde stattfindet. Bereits gebuchte Eltern werden nicht benachrichtigt.')) ?></p>
  </div>
  <div class="actions">
    <button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button>
    <a class="button" href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e(__('Abbrechen')) ?></a>
  </div>
</form>
