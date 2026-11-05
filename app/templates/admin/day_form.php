<?php
/**
 * "Tag bearbeiten": the same fields as for a new day (_day_fields.php, expert review P2-3), plus date and location.
 * @var array $day @var array $round @var array $parents upcoming bookings, how many can be mailed @var array $errors
 * @var bool $notify the box as sent (ticked by default) @var array $dayData times and breaks, as stored or as sent
 * @var list<string> $bookedSlots booked slots as "HH:MM-HH:MM" (the preview counts those that would no longer fit)
 */
use App\Csrf; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>"><?= e(__('Runden')) ?></a> › <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e($round['title']) ?></a></p>
<h1><?= e(__('Tag bearbeiten')) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>
<p class="alert alert-info"><?= e(__('Gebuchte Termine werden bei Änderungen nie automatisch gelöscht. Passt ein gebuchter Termin nicht mehr in den neuen Zeitplan, wird er als Konflikt markiert.')) ?> <?= e(__('Die Eltern erfahren davon, wenn Sie ihn umbuchen oder löschen.')) ?></p>
<?php if ($parents['mailable'] > 0): ?>
<p class="alert alert-warning"><?= e(__('An diesem Tag sind {count} Termin(e) gebucht. Ändern sich Datum, Uhrzeit oder Ort eines Termins, erhalten die Eltern die neuen Daten per E-Mail, solange „Eltern per E-Mail informieren“ angehakt ist.', ['count' => $parents['upcoming']])) ?><?= $parents['upcoming'] > $parents['mailable'] ? ' ' . e(__('{count} Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren.', ['count' => $parents['upcoming'] - $parents['mailable']])) : '' ?></p>
<?php elseif ($parents['upcoming'] > 0): ?>
<p class="alert alert-warning"><?= e(__('An diesem Tag sind {count} Termin(e) gebucht. Die Eltern werden über Änderungen an Datum, Zeiten oder Ort NICHT automatisch benachrichtigt – bitte selbst informieren.', ['count' => $parents['upcoming']])) ?></p>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/day/' . (int) $day['id'] . '/edit')) ?>" class="card form-grid">
  <?= Csrf::adminField() ?>
  <input type="hidden" name="breaks_field" value="1">
  <div class="field"><label for="day_date"><?= e(__('Datum')) ?></label><input type="date" data-de-date id="day_date" name="day_date" required value="<?= e($dayData['date'] ?? $day['day_date']) ?>"></div>
  <?php $withDates = false; require __DIR__ . '/_day_fields.php'; ?>
  <div class="field">
    <label for="location"><?= e(__('Ort an diesem Tag (optional)')) ?></label>
    <input type="text" id="location" name="location" maxlength="200" value="<?= e($dayData['location'] ?? ($day['location'] ?? '')) ?>" placeholder="<?= e(trim((string) ($round['location'] ?? '')) !== '' ? __('wie die Runde: {location}', ['location' => trim((string) $round['location'])]) : __('z. B. Raum 2.14')) ?>" aria-describedby="dloc-hint">
    <p class="hint" id="dloc-hint"><?= e(__('Nur ausfüllen, wenn der Tag nicht am Ort der Runde stattfindet.')) ?></p>
  </div>
  <?php if ($parents['mailable'] > 0): ?>
  <div class="check">
    <input type="checkbox" id="notify" name="notify" value="1"<?= ($notify ?? true) ? ' checked' : '' ?>>
    <label for="notify"><?= e(__('Eltern per E-Mail informieren')) ?></label>
  </div>
  <?php endif; ?>
  <div class="actions">
    <button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button>
    <a class="button" href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e(__('Abbrechen')) ?></a>
  </div>
</form>
