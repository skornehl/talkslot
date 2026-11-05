<?php
/** @var ?array $round @var array $data @var array $dayData @var array $errors @var array $parents upcoming bookings of the round, how many can be mailed */
use App\Csrf;
$action = $round ? url('/admin/round/' . (int) $round['id'] . '/edit') : url('/admin/round/new');
?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>"><?= e(__('Runden')) ?></a><?php if ($round): ?> › <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e($round['title']) ?></a><?php endif; ?></p>
<h1><?= e($title) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>

<?php if (!empty($templates)): ?>
<form method="get" action="<?= e(url('/admin/round/new')) ?>" class="card inline-form" aria-label="<?= e(__('Vorlage')) ?>">
  <div>
    <label for="template"><?= e(__('Vorlage')) ?></label>
    <select id="template" name="template">
      <option value=""><?= e(__('– ohne Vorlage –')) ?></option>
      <?php foreach ($templates as $tp): ?>
        <option value="<?= (int) $tp['id'] ?>"<?= ($template ?? null) === $tp['name'] ? ' selected' : '' ?>><?= e($tp['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="button"><?= e(__('Übernehmen')) ?></button>
  <?php if (!empty($template)): ?><p class="hint"><?= e(__('Zeiten, Pausen und Einstellungen aus „{name}“ sind eingetragen. Fehlt nur noch Titel und Datum; alles lässt sich noch ändern.', ['name' => $template])) ?></p><?php endif; ?>
</form>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="card form-grid" data-once>
  <?= Csrf::adminField() ?>
  <?php if (!empty($owners)): ?>
  <div class="field">
    <label for="user_id"><?= e(__('Lehrkraft')) ?></label>
    <select id="user_id" name="user_id" aria-describedby="owner-hint">
      <?php foreach ($owners as $o): ?>
        <option value="<?= (int) $o['id'] ?>"<?= (int) ($data['user_id'] ?? 0) === (int) $o['id'] ? ' selected' : '' ?>><?= e($o['display_name'] !== '' ? $o['display_name'] : $o['username']) ?><?= (int) $o['id'] === App\Auth::id() ? ' ' . e(__('(Sie)')) : '' ?><?= !$o['is_active'] ? ' ' . e(__('(deaktiviert)')) : '' ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="owner-hint"><?= e(__('Die Runde erscheint bei dieser Lehrkraft, Eltern sehen ihren Namen und Zusammenfassungen gehen an sie.')) ?></p>
  </div>
  <?php endif; ?>
  <div class="field">
    <label for="title"><?= e(__('Titel')) ?></label>
    <input type="text" id="title" name="title" required maxlength="200" value="<?= e($data['title']) ?>" placeholder="<?= e(__('z. B. Elternsprechtag August 2026')) ?>" aria-describedby="title-hint">
    <?php /* Expert review P2-4: a title such as "Beratung Förderbedarf" would make every booking a hint at special needs */ ?>
    <p class="hint" id="title-hint"><?= e(__('Bitte neutral halten: Titel und Beschreibung stehen in den E-Mails an Eltern und in Kalenderdateien, der Titel auch im Protokoll und beim Anlegen in der Adresse der Runde. Keine Hinweise auf Förderbedarf, Gesundheit oder andere sensible Themen.')) ?></p>
  </div>

  <?php if ($round): ?>
  <div class="field">
    <label for="slug"><?= e(__('Adresse (Slug)')) ?></label>
    <div class="input-prefix"><span><?= e(abs_url('/')) ?></span><input type="text" id="slug" name="slug" maxlength="100" value="<?= e($data['slug']) ?>" pattern="[a-z0-9-]+" aria-describedby="slug-hint"></div>
    <p class="hint" id="slug-hint"><?= e(__('Nur Kleinbuchstaben, Ziffern und Bindestriche, Umlaute werden umgewandelt. Achtung: Bereits verschickte Links funktionieren nach einer Änderung nicht mehr.')) ?></p>
  </div>
  <?php endif; ?>
  <div class="check">
    <input type="checkbox" id="random_suffix" name="random_suffix" value="1"<?= !empty($data['random_suffix']) ? ' checked' : '' ?>>
    <label for="random_suffix"><?= e($round ? __('Neuen Zufallsanteil an die Adresse anhängen (erzeugt auch einen neuen Rundencode; bisherige Links und Codes gelten nicht mehr)') : __('Zufallsanteil an die Adresse anhängen (empfohlen, damit die Runde nicht durch Ausprobieren gefunden wird)')) ?></label>
  </div>
  <?php if ($round && (string) ($round['code'] ?? '') !== ''): ?>
  <div class="check">
    <input type="checkbox" id="new_code" name="new_code" value="1"<?= !empty($data['new_code']) ? ' checked' : '' ?>>
    <label for="new_code"><?= e(__('Neuen Rundencode erzeugen (bisher {code}; der alte gilt danach nicht mehr)', ['code' => App\RoundCode::format($round['code'])])) ?></label>
  </div>
  <?php endif; ?>

  <input type="hidden" name="code_required_field" value="1">
  <div class="check">
    <input type="checkbox" id="code_required" name="code_required" value="1"<?= !empty($data['code_required']) ? ' checked' : '' ?> aria-describedby="cr-hint">
    <label for="code_required"><?= e(__('Nur mit Rundencode')) ?></label>
  </div>
  <div class="field">
    <label for="booking_limit"><?= e(__('Mehrfachbuchungen')) ?></label>
    <select id="booking_limit" name="booking_limit" aria-describedby="bl-hint">
      <?php foreach (['round' => __('Ein Termin pro Person in dieser Runde'), 'day' => __('Ein Termin pro Person und Tag'), 'none' => __('Erlaubt (nur Hinweis „Bitte buchen Sie nur einen Termin“)')] as $v => $label): ?>
        <option value="<?= e($v) ?>"<?= ($data['booking_limit'] ?? 'round') === $v ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="bl-hint"><?= e(__('Dieselbe Person = gleicher Name und, wenn angegeben, gleiche E-Mail-Adresse; ohne E-Mail zählt nur der Name. Geschwister buchen mit dem Namen des jeweiligen Kindes. Ihre eigenen Einträge sind nicht begrenzt.')) ?></p>
  </div>

  <p class="hint" id="cr-hint"><?= e(__('Ist das an, sieht wer nur den Link hat noch keine Termine: Er muss zusätzlich den Rundencode eingeben (steht im Einladungstext). Wer den Code auf der Startseite eingibt, ist direkt drin. Bestehende Buchungen bleiben über ihren persönlichen Link erreichbar.')) ?></p>

  <input type="hidden" name="remind_parents_field" value="1">
  <div class="check">
    <input type="checkbox" id="remind_parents" name="remind_parents" value="1"<?= !empty($data['remind_parents']) ? ' checked' : '' ?> aria-describedby="rp-hint">
    <label for="remind_parents"><?= e(__('Eltern am Vorabend an ihren Termin erinnern')) ?></label>
  </div>
  <p class="hint" id="rp-hint"><?= e(__('Per E-Mail, nur an Eltern, die bei der Buchung eine Adresse angegeben haben, mit dem Link zum Absagen. Wer erst am selben Tag gebucht hat, bekommt keine Erinnerung.')) ?></p>

  <?php if (!$round): ?>
    <?php require __DIR__ . '/_day_fields.php'; ?>
  <?php else: ?>
    <p class="hint"><?= __h('Tage, Zeiten und Pausen bearbeiten Sie auf der {link}.', ['link' => '<a href="' . e(url('/admin/round/' . (int) $round['id'])) . '">' . e(__('Seite der Runde')) . '</a>']) ?></p>
  <?php endif; ?>

  <div class="field">
    <label for="source_lang"><?= e(__('Sprache von Titel und Beschreibung')) ?></label>
    <select id="source_lang" name="source_lang" aria-describedby="sl-hint">
      <?php foreach (App\I18n::languages() as $code): ?>
        <option value="<?= e($code) ?>"<?= ($data['source_lang'] ?? 'de') === $code ? ' selected' : '' ?>><?= e(App\I18n::name($code)) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="sl-hint"><?= e(__('Nach dem Speichern können Sie Titel und Beschreibung auf der Seite der Runde im Browser in die anderen Sprachen übersetzen lassen (aktuelles Chrome oder Edge am Computer, mit einem Modell auf dem eigenen Gerät; beim ersten Mal ein Klick je Sprache). Dort können Sie die Übersetzungen auch prüfen, ändern oder von Hand eintragen.')) ?></p>
  </div>

  <div class="field">
    <label for="description"><?= e(__('Beschreibungstext (optional)')) ?></label>
    <textarea id="description" name="description" rows="4" maxlength="5000" aria-describedby="title-hint" placeholder="<?= e(__('z. B. Bitte nur einen Termin pro Kind buchen. Bringen Sie gern das letzte Zeugnis mit.')) ?>"><?= e($data['description'] ?? '') ?></textarea>
  </div>

  <div class="field">
    <label for="location"><?= e(__('Ort (optional)')) ?></label>
    <input type="text" id="location" name="location" maxlength="200" value="<?= e($data['location'] ?? '') ?>" placeholder="<?= e(__('z. B. Raum 1.12, Hauptgebäude')) ?>" aria-describedby="loc-hint">
    <p class="hint" id="loc-hint"><?= e(__('Steht auf der Buchungsseite, in der Bestätigung, in der Kalenderdatei und im Einladungstext. Findet ein Tag woanders statt, ändern Sie das unter „Tag bearbeiten“.')) ?><?= $round && $parents['upcoming'] > 0 && $parents['mailable'] === 0 ? ' ' . e(__('Bereits gebuchte Eltern werden bei einer Änderung NICHT automatisch benachrichtigt.')) : '' ?></p>
  </div>
  <?php if ($round && $parents['mailable'] > 0): /* ticked unless unticked before an error */ ?>
  <div class="check">
    <input type="checkbox" id="notify" name="notify" value="1"<?= ($data['notify'] ?? true) ? ' checked' : '' ?> aria-describedby="notify-hint">
    <label for="notify"><?= e(__('Eltern per E-Mail informieren')) ?></label>
  </div>
  <p class="hint hint-indent" id="notify-hint"><?= e(__('Ändern sich Ort oder Lehrkraft der Runde, erhalten die Eltern gebuchter Termine die neuen Daten per E-Mail in ihrer Sprache.')) ?><?= $parents['upcoming'] > $parents['mailable'] ? ' ' . e(__('{count} Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren.', ['count' => $parents['upcoming'] - $parents['mailable']])) : '' ?></p>
  <?php endif; ?>

  <div class="field-row">
    <div class="field">
      <label for="booking_deadline_hours"><?= e(__('Buchungsschluss (Stunden vorher)')) ?></label>
      <input type="number" id="booking_deadline_hours" name="booking_deadline_hours" min="0" max="720" required value="<?= e($data['booking_deadline_hours']) ?>">
    </div>
    <div class="field">
      <label for="cancel_deadline_hours"><?= e(__('Ändern/Absagen bis (Stunden vorher)')) ?></label>
      <input type="number" id="cancel_deadline_hours" name="cancel_deadline_hours" min="0" max="720" required value="<?= e($data['cancel_deadline_hours']) ?>">
    </div>
    <div class="field">
      <label for="delete_after_hours"><?= e(__('Runde löschen nach Ablauf (Stunden)')) ?></label>
      <input type="number" id="delete_after_hours" name="delete_after_hours" min="0" max="8760" value="<?= e($data['delete_after_hours'] ?? '') ?>" aria-describedby="del-hint">
      <p class="hint" id="del-hint"><?= e(__('Ab dem Tag nach dem letzten Termin. Leer = Runde nie automatisch löschen (Buchungen werden trotzdem am Folgetag gelöscht).')) ?></p>
    </div>
  </div>


  <div class="check">
    <input type="checkbox" id="is_active" name="is_active" value="1"<?= !empty($data['is_active']) ? ' checked' : '' ?>>
    <label for="is_active"><?= e(__('Aktiv (Eltern können über den Link buchen)')) ?></label>
  </div>

  <div class="actions">
    <button type="submit" class="button button-primary"><?= e($round ? __('Speichern') : __('Runde mit Terminen anlegen')) ?></button>
    <a class="button" href="<?= e($round ? url('/admin/round/' . (int) $round['id']) : url('/admin')) ?>"><?= e(__('Abbrechen')) ?></a>
  </div>
</form>
