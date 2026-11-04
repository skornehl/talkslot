<?php /** "Already booked" box – filled by JavaScript from the remember-me entries in the browser (one item per booking). */ ?>
<section class="card remembered" id="remembered" hidden aria-live="polite">
  <h2><?= e(t('round.remembered.title')) ?></h2>
  <p><?= e(t('round.remembered.text')) ?></p>
  <ul class="remembered-list" data-remembered-list></ul>
  <template data-remembered-item>
    <li>
      <p class="remembered-label" data-remembered-label></p>
      <p class="actions"><a class="button button-primary" href="#" data-remembered-link><?= e(t('round.remembered.manage')) ?></a>
        <button type="button" class="button-link" data-remembered-forget><?= e(t('round.remembered.forget')) ?></button></p>
    </li>
  </template>
  <p class="hint"><?= e(t('round.remembered.forget_hint')) ?></p>
  <p><button type="button" class="button" hidden data-remembered-another><?= e(t('round.remembered.another')) ?></button></p>
</section>
