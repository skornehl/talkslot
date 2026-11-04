<?php
/** Form for a template. @var ?array $row @var array $data @var array $dayData @var array $errors */
use App\Csrf;
$action = $row ? url('/admin/templates/' . (int) $row['id'] . '/edit') : url('/admin/templates/new');
?>
<p class="breadcrumb"><a href="<?= e(url('/admin/templates')) ?>">Vorlagen</a></p>
<h1><?= e($title) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>

<form method="post" action="<?= e($action) ?>" class="card form-grid" data-once>
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="name">Name der Vorlage</label>
    <input type="text" id="name" name="name" required maxlength="100" value="<?= e($data['name']) ?>" placeholder="z. B. Elternsprechtag ganzer Tag">
  </div>

  <?php $withDates = false; require __DIR__ . '/_day_fields.php'; ?>

  <div class="field">
    <label for="booking_limit">Mehrfachbuchungen</label>
    <select id="booking_limit" name="booking_limit">
      <?php foreach (['round' => 'Ein Termin pro Person in der Runde', 'day' => 'Ein Termin pro Person und Tag', 'none' => 'Erlaubt (nur Hinweis „Bitte buchen Sie nur einen Termin“)'] as $v => $label): ?>
        <option value="<?= e($v) ?>"<?= $data['booking_limit'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="check">
    <input type="checkbox" id="code_required" name="code_required" value="1"<?= !empty($data['code_required']) ? ' checked' : '' ?>>
    <label for="code_required">Nur mit Rundencode</label>
  </div>
  <div class="check">
    <input type="checkbox" id="remind_parents" name="remind_parents" value="1"<?= !empty($data['remind_parents']) ? ' checked' : '' ?>>
    <label for="remind_parents">Eltern am Vorabend an ihren Termin erinnern</label>
  </div>

  <div class="field">
    <label for="location">Ort (optional)</label>
    <input type="text" id="location" name="location" maxlength="200" value="<?= e($data['location']) ?>" placeholder="z. B. Hauptgebäude">
  </div>
  <div class="field">
    <label for="description">Beschreibungstext (optional)</label>
    <textarea id="description" name="description" rows="4" maxlength="5000"><?= e($data['description']) ?></textarea>
  </div>

  <div class="field-row">
    <div class="field">
      <label for="booking_deadline_hours">Buchungsschluss (Stunden vorher)</label>
      <input type="number" id="booking_deadline_hours" name="booking_deadline_hours" min="0" max="720" required value="<?= e($data['booking_deadline_hours']) ?>">
    </div>
    <div class="field">
      <label for="cancel_deadline_hours">Ändern/Absagen bis (Stunden vorher)</label>
      <input type="number" id="cancel_deadline_hours" name="cancel_deadline_hours" min="0" max="720" required value="<?= e($data['cancel_deadline_hours']) ?>">
    </div>
    <div class="field">
      <label for="delete_after_hours">Runde löschen nach Ablauf (Stunden)</label>
      <input type="number" id="delete_after_hours" name="delete_after_hours" min="0" max="8760" value="<?= e($data['delete_after_hours'] ?? '') ?>">
    </div>
  </div>

  <div class="actions">
    <button type="submit" class="button button-primary">Vorlage speichern</button>
    <a class="button" href="<?= e(url('/admin/templates')) ?>">Abbrechen</a>
  </div>
</form>
