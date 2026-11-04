<?php /** @var string $key @var string $lang @var string $content */ use App\Csrf; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin/texts')) ?>"><?= e(__('Texte')) ?></a> › <?= e($title) ?></p>
<h1><?= e($title) ?></h1>
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
