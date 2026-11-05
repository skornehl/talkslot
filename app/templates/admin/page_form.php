<?php /** @var string $key @var string $lang @var string $content @var list<string> $variants @var ?string $loaded @var ?string $type @var ?array{type: string, known: bool, changes: list<array{key: string, heading: ?string, diff: list<array{0: string, 1: string}>}>} $update @var bool $merged */ use App\Csrf;
use App\LegalTexts;
$labels = ['school' => __('Von der Schule betrieben'), 'private' => __('Privat betrieben')];
$update ??= null;
$merged ??= false;
// Version marker sent with the form (LegalTexts::base()): the template that was loaded or merged in
$base = $loaded ?? ($merged ? $update['type'] : null); ?>
<p class="breadcrumb"><a href="<?= e(url('/admin/texts')) ?>"><?= e(__('Texte')) ?></a> › <?= e($title) ?></p>
<h1><?= e($title) ?></h1>
<?php if ($loaded !== null): ?>
  <div class="alert alert-info" role="status"><p><?= e(__('Vorlage „{name}“ geladen. Sie ersetzt den gespeicherten Text erst, wenn Sie speichern. Ersetzen Sie danach alle Platzhalter in eckigen Klammern.', ['name' => $labels[$loaded]])) ?><?= $type !== null && $loaded !== $type ? ' <strong>' . e(__('Sie passt nicht zur gewählten Betriebsart „{type}“.', ['type' => $labels[$type]])) . '</strong>' : '' ?></p></div>
<?php elseif ($merged): ?>
  <div class="alert alert-info" role="status" data-legal-merged><p><?= e(__('Abschnitte aus der aktuellen Vorlage „{name}“ übernommen: {sections}. Ihre Angaben in Zeilen, die die Vorlage nicht geändert hat, bleiben erhalten, eigene Änderungen in diesen Abschnitten nicht. Der gespeicherte Text ändert sich erst, wenn Sie speichern – bitte vorher prüfen und neue Platzhalter in eckigen Klammern ersetzen.', [
      'name' => $labels[$update['type']], 'sections' => implode(', ', array_map(static fn (array $c): string => LegalTexts::sectionTitle($c['heading']), $update['changes']))])) ?></p></div>
<?php elseif ($update !== null): ?>
  <div class="alert alert-warning legal-update" role="status" data-legal-update>
    <p><?= e($update['known']
        ? __('Die Vorlage „{name}“ wurde geändert, seit dieser Text auf ihr beruht. Bearbeitete Texte werden nicht automatisch ersetzt. Geändert hat sich (− bisher in der Vorlage, + neu in der Vorlage):', ['name' => $labels[$update['type']]])
        : __('Mit welchem Stand der Vorlage „{name}“ dieser Text zuletzt abgeglichen wurde, ist nicht bekannt: Er wurde bearbeitet, bevor TalkSlot das festgehalten hat, oder stammt aus der Vorlage der anderen Betriebsart. In diesen Abschnitten weicht er von der aktuellen Vorlage ab (− nur in Ihrem Text, + nur in der Vorlage; ausgefüllte Platzhalter zählen nicht als Abweichung):', ['name' => $labels[$update['type']]])) ?></p>
    <?php foreach ($update['changes'] as $c): ?>
      <details class="legal-diff">
        <summary><?= e(LegalTexts::sectionTitle($c['heading'])) ?></summary>
        <ul>
          <?php foreach ($c['diff'] as [$op, $line]): ?>
            <li class="<?= $op === '+' ? 'diff-add' : 'diff-del' ?>"><span aria-hidden="true"><?= $op === '+' ? '+' : '−' ?></span> <span class="visually-hidden"><?= e($op === '+'
                ? ($update['known'] ? __('neu in der Vorlage:') : __('nur in der Vorlage:'))
                : ($update['known'] ? __('bisher in der Vorlage:') : __('nur in Ihrem Text:'))) ?></span> <?= e($line) ?></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endforeach; ?>
    <p><a class="button" href="<?= e(url('/admin/page/' . $key . '/' . $lang, ['merge' => '1'])) ?>"><?= e(__('Abschnitte aus der Vorlage übernehmen')) ?></a></p>
    <p class="small"><?= e(__('Übernehmen lädt Ihren Text mit diesen Abschnitten in der Fassung der Vorlage in das Formular; Ihre Angaben in Zeilen, die die Vorlage nicht geändert hat, bleiben. Gespeichert wird erst mit „Speichern“. Oder Sie passen den Text unten selbst an und bestätigen den Abgleich beim Speichern.')) ?></p>
  </div>
<?php endif; ?>
<?php if ($variants): ?>
  <p class="small"><?= e(__('Vorlage laden:')) ?>
    <?php foreach ($variants as $i => $v): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e(url('/admin/page/' . $key . '/' . $lang, ['template' => $v])) ?>"><?= e($labels[$v]) ?></a><?= $v === $type ? ' ' . e(__('(passend zur Betriebsart)')) : '' ?><?php endforeach; ?>
    <br><?= e($key === 'privacy'
        ? __('Von der Schule betrieben: Rechtsgrundlage Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz des Landes. Privat betrieben (Privatperson, Verein, freier Träger): Art. 6 Abs. 1 lit. b und f DSGVO.')
        : __('Von der Schule betrieben: Impressum der Schule als öffentliche Stelle. Privat betrieben: Impressum eines privaten, nicht kommerziellen Angebots.')) ?>
  </p>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/page/' . $key . '/' . $lang)) ?>" class="card form-grid">
  <?= Csrf::adminField() ?>
  <?php if ($base !== null): ?><input type="hidden" name="base" value="<?= e($base) ?>"><?php endif; ?>
  <div class="field">
    <label for="content"><?= e(__('Text')) ?></label>
    <textarea id="content" name="content" rows="28" class="mono" aria-describedby="md-hint"><?= e($content) ?></textarea>
    <p class="hint" id="md-hint"><?= e(__('Formatierung:')) ?> <code># <?= e(__('Überschrift')) ?></code>, <code>- <?= e(__('Aufzählung')) ?></code>, <code>**<?= e(__('fett')) ?>**</code>, <code>*<?= e(__('kursiv')) ?>*</code>, <code>[<?= e(__('Linktext')) ?>](https://…)</code>, <code>&gt; <?= e(__('Hinweis')) ?></code>. <?= e(__('Leerzeile = neuer Absatz.')) ?></p>
  </div>
  <?php if ($base === null && $update !== null): ?>
    <div class="check">
      <input type="checkbox" id="base" name="base" value="<?= e($update['type']) ?>">
      <label for="base"><?= e(__('Abgleich erledigt: Der Text berücksichtigt den aktuellen Stand der Vorlage „{name}“ (der Hinweis oben verschwindet nach dem Speichern).', ['name' => $labels[$update['type']]])) ?></label>
    </div>
  <?php endif; ?>
  <div class="actions">
    <button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button>
    <a class="button" href="<?= e(url($key === 'imprint' ? '/impressum' : '/datenschutz', ['lang' => $lang])) ?>" target="_blank" rel="noopener"><?= e(__('Ansehen')) ?></a>
  </div>
</form>
