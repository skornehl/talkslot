<?php /** @var string $key @var string $lang @var string $content */ use App\Csrf; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin/texts')) ?>">Texte</a> › <?= e($title) ?></p>
<h1><?= e($title) ?></h1>
<form method="post" action="<?= e(url('/admin/page/' . $key . '/' . $lang)) ?>" class="card form-grid">
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="content">Text</label>
    <textarea id="content" name="content" rows="28" class="mono" aria-describedby="md-hint"><?= e($content) ?></textarea>
    <p class="hint" id="md-hint">Formatierung: <code># Überschrift</code>, <code>- Aufzählung</code>, <code>**fett**</code>, <code>*kursiv*</code>, <code>[Linktext](https://…)</code>, <code>&gt; Hinweis</code>. Leerzeile = neuer Absatz.</p>
  </div>
  <div class="actions">
    <button type="submit" class="button button-primary">Speichern</button>
    <a class="button" href="<?= e(url($key === 'imprint' ? '/impressum' : '/datenschutz', ['lang' => $lang])) ?>" target="_blank" rel="noopener">Ansehen</a>
  </div>
</form>
