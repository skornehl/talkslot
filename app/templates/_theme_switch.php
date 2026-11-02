<?php /** Hell/Dunkel-Schalter im Fuß (nur mit JavaScript sichtbar, siehe app.js). */ ?>
<div class="theme-switch" data-theme-switch role="group" aria-label="<?= e(t('theme.label')) ?>" hidden>
  <span class="muted"><?= e(t('theme.label')) ?>:</span>
  <button type="button" class="button-link" data-theme-choice="auto" aria-pressed="true"><?= e(t('theme.auto')) ?></button>
  <button type="button" class="button-link" data-theme-choice="light" aria-pressed="false"><?= e(t('theme.light')) ?></button>
  <button type="button" class="button-link" data-theme-choice="dark" aria-pressed="false"><?= e(t('theme.dark')) ?></button>
</div>
