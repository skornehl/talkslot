<?php /** @var string $key @var string $lang @var string $content @var list<string> $variants @var ?string $loaded @var ?string $type */ use App\Csrf;
$labels = ['school' => __('Von der Schule betrieben'), 'private' => __('Privat betrieben')]; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin/texts')) ?>"><?= e(__('Texte')) ?></a> › <?= e($title) ?></p>
<h1><?= e($title) ?></h1>
<?php if ($loaded !== null): ?>
  <div class="alert alert-info" role="status"><p><?= e(__('Vorlage „{name}“ geladen. Sie ersetzt den gespeicherten Text erst, wenn Sie speichern. Ersetzen Sie danach alle Platzhalter in eckigen Klammern.', ['name' => $labels[$loaded]])) ?><?= $type !== null && $loaded !== $type ? ' <strong>' . e(__('Sie passt nicht zur gewählten Betriebsart „{type}“.', ['type' => $labels[$type]])) . '</strong>' : '' ?></p></div>
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
  <div class="field">
    <label for="content"><?= e(__('Text')) ?></label>
    <textarea id="content" name="content" rows="28" class="mono" aria-describedby="md-hint"><?= e($content) ?></textarea>
    <p class="hint" id="md-hint"><?= e(__('Formatierung:')) ?> <code># <?= e(__('Überschrift')) ?></code>, <code>- <?= e(__('Aufzählung')) ?></code>, <code>**<?= e(__('fett')) ?>**</code>, <code>*<?= e(__('kursiv')) ?>*</code>, <code>[<?= e(__('Linktext')) ?>](https://…)</code>, <code>&gt; <?= e(__('Hinweis')) ?></code>. <?= e(__('Leerzeile = neuer Absatz.')) ?></p>
  </div>
  <div class="actions">
    <button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button>
    <a class="button" href="<?= e(url($key === 'imprint' ? '/impressum' : '/datenschutz', ['lang' => $lang])) ?>" target="_blank" rel="noopener"><?= e(__('Ansehen')) ?></a>
  </div>
</form>
