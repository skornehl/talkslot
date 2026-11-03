<?php
/**
 * Asks for the round code of a protected round ("Nur mit Rundencode").
 * The round's title and description are only shown after correct input.
 * @var array $round @var string $csrf @var array $errors
 */
?>
<article class="round" data-round-id="<?= (int) $round['id'] ?>">
  <h1><?= e(t('pin.title')) ?></h1>

  <?php require __DIR__ . '/_remembered.php'; ?>

  <div id="booking-area">
    <?php require __DIR__ . '/_errors.php'; ?>
    <p><?= e(t('pin.text')) ?></p>
    <form method="post" action="<?= e(url('/' . $round['slug'])) ?>" class="card pin-form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="lang" value="<?= e(App\I18n::lang()) ?>">
      <input type="hidden" name="step" value="unlock">
      <div class="field">
        <label for="code"><?= e(t('pin.label')) ?></label>
        <input type="text" id="code" name="code" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" autofocus class="pin-input code-input">
      </div>
      <button type="submit" class="button button-primary"><?= e(t('pin.submit')) ?></button>
    </form>
  </div>
</article>
