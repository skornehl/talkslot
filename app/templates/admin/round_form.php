<?php
/** @var ?array $round @var array $data @var array $dayData @var array $errors */
use App\Csrf;
$action = $round ? url('/admin/round/' . (int) $round['id'] . '/edit') : url('/admin/round/new');
?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>">Runden</a><?php if ($round): ?> › <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e($round['title']) ?></a><?php endif; ?></p>
<h1><?= e($title) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>

<?php if (!empty($templates)): ?>
<form method="get" action="<?= e(url('/admin/round/new')) ?>" class="card inline-form" aria-label="Vorlage">
  <div>
    <label for="template">Vorlage</label>
    <select id="template" name="template">
      <option value="">– ohne Vorlage –</option>
      <?php foreach ($templates as $tp): ?>
        <option value="<?= (int) $tp['id'] ?>"<?= ($template ?? null) === $tp['name'] ? ' selected' : '' ?>><?= e($tp['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="button">Übernehmen</button>
  <?php if (!empty($template)): ?><p class="hint">Zeiten, Pausen und Einstellungen aus „<?= e($template) ?>“ sind eingetragen. Fehlt nur noch Titel und Datum; alles lässt sich noch ändern.</p><?php endif; ?>
</form>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="card form-grid" data-once>
  <?= Csrf::adminField() ?>
  <?php if (!empty($owners)): ?>
  <div class="field">
    <label for="user_id">Lehrkraft</label>
    <select id="user_id" name="user_id" aria-describedby="owner-hint">
      <?php foreach ($owners as $o): ?>
        <option value="<?= (int) $o['id'] ?>"<?= (int) ($data['user_id'] ?? 0) === (int) $o['id'] ? ' selected' : '' ?>><?= e($o['display_name'] !== '' ? $o['display_name'] : $o['username']) ?><?= (int) $o['id'] === App\Auth::id() ? ' (Sie)' : '' ?><?= !$o['is_active'] ? ' (deaktiviert)' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="owner-hint">Die Runde erscheint bei dieser Lehrkraft, Eltern sehen ihren Namen und Zusammenfassungen gehen an sie.</p>
  </div>
  <?php endif; ?>
  <div class="field">
    <label for="title">Titel</label>
    <input type="text" id="title" name="title" required maxlength="200" value="<?= e($data['title']) ?>" placeholder="z. B. Elternsprechtag August 2026">
  </div>

  <?php if ($round): ?>
  <div class="field">
    <label for="slug">Adresse (Slug)</label>
    <div class="input-prefix"><span><?= e(abs_url('/')) ?></span><input type="text" id="slug" name="slug" maxlength="100" value="<?= e($data['slug']) ?>" pattern="[a-z0-9-]+" aria-describedby="slug-hint"></div>
    <p class="hint" id="slug-hint">Nur Kleinbuchstaben, Ziffern und Bindestriche, Umlaute werden umgewandelt. Achtung: Bereits verschickte Links funktionieren nach einer Änderung nicht mehr.</p>
  </div>
  <?php endif; ?>
  <div class="check">
    <input type="checkbox" id="random_suffix" name="random_suffix" value="1"<?= !empty($data['random_suffix']) ? ' checked' : '' ?>>
    <label for="random_suffix"><?= $round ? 'Neuen Zufallsanteil an die Adresse anhängen (erzeugt auch einen neuen Rundencode; bisherige Links und Codes gelten nicht mehr)' : 'Zufallsanteil an die Adresse anhängen (empfohlen, damit die Runde nicht durch Ausprobieren gefunden wird)' ?></label>
  </div>
  <?php if ($round && (string) ($round['code'] ?? '') !== ''): ?>
  <div class="check">
    <input type="checkbox" id="new_code" name="new_code" value="1"<?= !empty($data['new_code']) ? ' checked' : '' ?>>
    <label for="new_code">Neuen Rundencode erzeugen (bisher <?= e(App\RoundCode::format($round['code'])) ?>; der alte gilt danach nicht mehr)</label>
  </div>
  <?php endif; ?>

  <input type="hidden" name="code_required_field" value="1">
  <div class="check">
    <input type="checkbox" id="code_required" name="code_required" value="1"<?= !empty($data['code_required']) ? ' checked' : '' ?> aria-describedby="cr-hint">
    <label for="code_required">Nur mit Rundencode</label>
  </div>
  <div class="field">
    <label for="booking_limit">Mehrfachbuchungen</label>
    <select id="booking_limit" name="booking_limit" aria-describedby="bl-hint">
      <?php foreach (['round' => 'Ein Termin pro Person in dieser Runde', 'day' => 'Ein Termin pro Person und Tag', 'none' => 'Erlaubt (nur Hinweis „Bitte buchen Sie nur einen Termin“)'] as $v => $label): ?>
        <option value="<?= e($v) ?>"<?= ($data['booking_limit'] ?? 'round') === $v ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="bl-hint">Dieselbe Person = gleicher Name und, wenn angegeben, gleiche E-Mail-Adresse; ohne E-Mail zählt nur der Name. Geschwister buchen mit dem Namen des jeweiligen Kindes. Ihre eigenen Einträge sind nicht begrenzt.</p>
  </div>

  <input type="hidden" name="remind_parents_field" value="1">
  <div class="check">
    <input type="checkbox" id="remind_parents" name="remind_parents" value="1"<?= !empty($data['remind_parents']) ? ' checked' : '' ?> aria-describedby="rp-hint">
    <label for="remind_parents">Eltern am Vorabend an ihren Termin erinnern</label>
  </div>
  <p class="hint" id="rp-hint">Per E-Mail, nur an Eltern, die bei der Buchung eine Adresse angegeben haben, mit dem Link zum Absagen. Wer erst am selben Tag gebucht hat, bekommt keine Erinnerung.</p>

  <p class="hint" id="cr-hint">Ist das an, sieht wer nur den Link hat noch keine Termine: Er muss zusätzlich den Rundencode eingeben (steht im Einladungstext). Wer den Code auf der Startseite eingibt, ist direkt drin. Bestehende Buchungen bleiben über ihren persönlichen Link erreichbar.</p>

  <?php if (!$round): ?>
    <?php require __DIR__ . '/_day_fields.php'; ?>
  <?php else: ?>
    <p class="hint">Tage, Zeiten und Pausen bearbeiten Sie auf der <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>">Seite der Runde</a>.</p>
  <?php endif; ?>

  <div class="field">
    <label for="source_lang">Sprache von Titel und Beschreibung</label>
    <select id="source_lang" name="source_lang" aria-describedby="sl-hint">
      <?php foreach (App\I18n::languages() as $code): ?>
        <option value="<?= e($code) ?>"<?= ($data['source_lang'] ?? 'de') === $code ? ' selected' : '' ?>><?= e(App\I18n::name($code)) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="sl-hint">Nach dem Speichern können Sie Titel und Beschreibung auf der Seite der Runde im Browser in die anderen Sprachen übersetzen lassen (aktuelles Chrome oder Edge am Computer, mit einem Modell auf dem eigenen Gerät; beim ersten Mal ein Klick je Sprache). Dort können Sie die Übersetzungen auch prüfen, ändern oder von Hand eintragen.</p>
  </div>

  <div class="field">
    <label for="description">Beschreibungstext (optional)</label>
    <textarea id="description" name="description" rows="4" maxlength="5000" placeholder="z. B. Bitte nur einen Termin pro Kind buchen. Bringen Sie gern das letzte Zeugnis mit."><?= e($data['description'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label for="location">Ort (optional)</label>
    <input type="text" id="location" name="location" maxlength="200" value="<?= e($data['location'] ?? '') ?>" placeholder="z. B. Raum 1.12, Hauptgebäude" aria-describedby="loc-hint">
    <p class="hint" id="loc-hint">Steht auf der Buchungsseite, in der Bestätigung, in der Kalenderdatei und im Einladungstext. Findet ein Tag woanders statt, ändern Sie das unter „Tag bearbeiten“.</p>
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
      <input type="number" id="delete_after_hours" name="delete_after_hours" min="0" max="8760" value="<?= e($data['delete_after_hours'] ?? '') ?>" aria-describedby="del-hint">
      <p class="hint" id="del-hint">Ab dem Tag nach dem letzten Termin. Leer = Runde nie automatisch löschen (Buchungen werden trotzdem am Folgetag gelöscht).</p>
    </div>
  </div>


  <div class="check">
    <input type="checkbox" id="is_active" name="is_active" value="1"<?= !empty($data['is_active']) ? ' checked' : '' ?>>
    <label for="is_active">Aktiv (Eltern können über den Link buchen)</label>
  </div>

  <div class="actions">
    <button type="submit" class="button button-primary"><?= $round ? 'Speichern' : 'Runde mit Terminen anlegen' ?></button>
    <a class="button" href="<?= e($round ? url('/admin/round/' . (int) $round['id']) : url('/admin')) ?>">Abbrechen</a>
  </div>
</form>
