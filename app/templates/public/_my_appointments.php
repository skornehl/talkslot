<?php /** "My appointments on this device": all remembered bookings, filled by JavaScript from the browser's own entries (nothing comes from the server). On a round's code prompt that round's entries are left out, the box above shows them. @var ?array $round */ ?>
<section class="card remembered" id="my-appointments" hidden aria-live="polite" data-my-appointments<?= !empty($round['id']) ? ' data-except-round="' . (int) $round['id'] . '"' : '' ?>>
  <h2><?= e(t('home.mine.title')) ?></h2>
  <p><?= e(t('round.remembered.text')) ?></p>
  <ul class="remembered-list" data-remembered-list></ul>
  <template data-remembered-item>
    <li>
      <p class="remembered-label" data-remembered-label></p>
      <p class="actions"><a class="button" href="#" data-remembered-link><?= e(t('round.remembered.manage')) ?></a>
        <button type="button" class="button-link" data-remembered-forget><?= e(t('round.remembered.forget')) ?></button></p>
    </li>
  </template>
  <p class="hint"><?= e(t('round.remembered.forget_hint')) ?></p>
</section>
