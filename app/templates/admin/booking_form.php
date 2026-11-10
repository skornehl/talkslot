<?php
/** Create/edit an entry. @var array $slot @var array $round @var ?array $booking @var array $moveOptions @var array $old @var array $errors */
use App\Csrf;
use App\I18n;
$action = $booking ? url('/admin/booking/' . (int) $booking['id'] . '/edit') : url('/admin/slot/' . (int) $slot['id'] . '/book');
?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>"><?= e(__('Runden')) ?></a> › <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e($round['title']) ?></a></p>
<h1><?= e($title) ?></h1>
<p><strong><?= e(fmt_slot($slot['day_date'], $slot['start_time'], $slot['end_time'])) ?></strong></p>
<?php require __DIR__ . '/_errors.php'; ?>

<form method="post" action="<?= e($action) ?>" class="card form-grid" novalidate>
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="name"><?= e(__('Name(n)')) ?></label>
    <input type="text" id="name" name="name" required maxlength="200" value="<?= e($old['name']) ?>"<?= field_attrs($errors, 'name', 'name-hint') ?>>
    <?= field_error($errors, 'name') ?>
    <p class="hint" id="name-hint"><?= e(__('Name des Kindes wie bei der Online-Buchung, bei Bedarf mit Klasse.')) ?></p>
  </div>
  <div class="field">
    <label for="email"><?= e(__('E-Mail (optional)')) ?></label>
    <input type="email" id="email" name="email" maxlength="254"<?= field_attrs($errors, 'email') ?> value="<?= e($old['email']) ?>">
    <?= field_error($errors, 'email') ?>
  </div>
  <?php if (!empty($round['ask_interpreter']) || $old['interpreter_lang'] !== ''): ?>
  <div class="field">
    <label for="interpreter_lang"><?= e(__('Gespräch mit Dolmetschung gewünscht: Sprache (optional)')) ?></label>
    <input type="text" id="interpreter_lang" name="interpreter_lang" maxlength="60"<?= field_attrs($errors, 'interpreter_lang') ?> value="<?= e($old['interpreter_lang']) ?>">
    <?= field_error($errors, 'interpreter_lang') ?>
  </div>
  <?php endif; ?>
  <div class="field">
    <label for="lang"><?= e(__('Sprache für E-Mails und Änderungslink')) ?></label>
    <select id="lang" name="lang">
      <?php foreach (I18n::languages() as $code): ?>
        <option value="<?= e($code) ?>"<?= $old['lang'] === $code ? ' selected' : '' ?>><?= e(I18n::name($code)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($booking): ?>
  <div class="field">
    <label for="move_to"><?= e(__('Umbuchen auf')) ?></label>
    <select id="move_to" name="move_to">
      <option value=""><?= e(__('– Termin beibehalten –')) ?></option>
      <?php foreach ($moveOptions as $o): ?>
        <option value="<?= (int) $o['id'] ?>"<?= (string) ($old['move_to'] ?? '') === (string) $o['id'] ? ' selected' : '' ?>><?= e(fmt_slot($o['day_date'], $o['start_time'], $o['end_time'])) ?><?= $o['is_blocked'] ? ' ' . e(__('(gesperrt)')) : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <?php // Ticked by default, and as long as no address is entered (the script then follows the e-mail field) ?>
  <div class="check">
    <input type="checkbox" id="send_mail" name="send_mail" value="1" data-mail-for="email"<?= !empty($old['send_mail']) || trim((string) $old['email']) === '' ? ' checked' : '' ?><?= $booking ? ' aria-describedby="mail-hint"' : '' ?>>
    <label for="send_mail"><?= e($booking ? __('Änderung per E-Mail mitteilen (nur wenn eine Adresse eingetragen ist)') : __('Bestätigung mit Änderungslink per E-Mail senden (nur wenn eine Adresse eingetragen ist)')) ?></label>
  </div>
  <?php if ($booking): ?>
  <p class="hint hint-indent" id="mail-hint"><?= e(__('Die E-Mail nennt die aktuellen Termindaten in der Sprache der Eltern und geht auch ohne Änderung hier hinaus, z. B. nachdem Datum oder Ort des Tages geändert wurden.')) ?> <?= e(__('An eine neue Adresse gehen Änderungen, Absagen und Erinnerungen erst nach dieser E-Mail.')) ?></p>
  <?php endif; ?>

  <div class="actions">
    <button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button>
    <a class="button" href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>"><?= e(__('Abbrechen')) ?></a>
  </div>
</form>
