<?php
/**
 * Input of the round code (start page, and the way on from "not found"). Sent via POST so that the code does not
 * end up in the address bar, history and server logs.
 * @var list<string> $errors @var string $code
 */
$codeErrors = $errors ?? [];
?>
<form method="post" action="<?= e(url('/', ['lang' => null])) ?>" class="card pin-form" data-code-form>
  <?php if (App\I18n::explicit()): ?><input type="hidden" name="lang" value="<?= e(App\I18n::lang()) ?>"><?php endif; ?>
  <div class="field">
    <label for="code"><?= e(t('home.code.label')) ?></label>
    <input type="text" id="code" name="code" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"
           class="pin-input code-input" value="<?= e($code ?? '') ?>" aria-describedby="code-hint"<?= $codeErrors ? ' aria-invalid="true" autofocus' : '' ?>>
    <p class="hint" id="code-hint"><?= e(t('home.code.hint')) ?></p>
  </div>
  <button type="submit" class="button button-primary"><?= e(t('home.code.submit')) ?></button>
</form>
