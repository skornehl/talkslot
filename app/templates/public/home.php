<?php /** @var list<string> $errors @var string $code */ ?>
<h1><?= e(t('home.title')) ?></h1>
<p><?= e(t('home.text')) ?></p>
<?php require __DIR__ . '/_errors.php'; ?>
<form method="post" action="<?= e(url('/', ['lang' => null])) ?>" class="card pin-form">
  <?php if (App\I18n::explicit()): ?><input type="hidden" name="lang" value="<?= e(App\I18n::lang()) ?>"><?php endif; ?>
  <div class="field">
    <label for="code"><?= e(t('home.code.label')) ?></label>
    <input type="text" id="code" name="code" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"
           class="pin-input code-input" value="<?= e($code) ?>" aria-describedby="code-hint"<?= $errors ? ' aria-invalid="true" autofocus' : '' ?>>
    <p class="hint" id="code-hint"><?= e(t('home.code.hint')) ?></p>
  </div>
  <button type="submit" class="button button-primary"><?= e(t('home.code.submit')) ?></button>
</form>
