<?php /** Box „bereits gebucht“ – wird per JavaScript aus dem Browser-Merker gefüllt. */ ?>
<section class="card remembered" id="remembered" hidden aria-live="polite">
  <h2><?= e(t('round.remembered.title')) ?></h2>
  <p><?= e(t('round.remembered.text')) ?></p>
  <p class="remembered-label" data-remembered-label></p>
  <p><a class="button button-primary" href="#" data-remembered-link><?= e(t('round.remembered.manage')) ?></a></p>
  <p><button type="button" class="button-link" data-remembered-forget><?= e(t('round.remembered.forget')) ?></button></p>
</section>

