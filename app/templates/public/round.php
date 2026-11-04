<?php
/**
 * Public round page.
 * @var array $round @var array $days @var string $csrf @var array $errors @var array $old
 */
?>
<article class="round" data-round-id="<?= (int) $round['id'] ?>">
  <h1><?= e($round['title']) ?></h1>
  <p class="teacher"><?= e(t('round.teacher', ['name' => $round['teacher_name']])) ?></p>
  <?php if (trim((string) ($round['location'] ?? '')) !== ''): ?><p class="location"><?= e(t('round.location', ['location' => trim((string) $round['location'])])) ?></p><?php endif; ?>
  <?php if (!empty($round['description'])): ?>
    <div class="description"><?= nl2br(e($round['description'])) ?></div>
  <?php endif; ?>
  <?php if (!empty($round['auto_translated'])): ?>
    <p class="small muted" data-auto-translated><?= e(t('round.auto_translated', ['source' => App\I18n::name($round['source_lang'])])) ?></p>
  <?php endif; ?>
  <p class="alert alert-info"><?= e(t(App\BookingService::limitNotice($round))) ?></p>

  <?php require __DIR__ . '/_remembered.php'; ?>

  <?php /* data-keep-open: the form was sent (error, language switch) – it stays visible next to remembered appointments */ ?>
  <div id="booking-area"<?= $old ? ' data-keep-open' : '' ?>>
    <?php require __DIR__ . '/_errors.php'; ?>

    <?php if (!$days): ?>
      <p class="alert"><?= e(t('round.no_slots')) ?></p>
    <?php else: /* data-once: a second click while the booking is on its way would end on "already taken" instead of the page with the link */ ?>
    <form method="post" action="<?= e(url('/' . $round['slug'])) ?>" class="booking-form" id="booking-form" novalidate data-once>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="lang" value="<?= e(App\I18n::lang()) ?>">
      <?php if ($access !== ''): ?><input type="hidden" name="access" value="<?= e($access) ?>"><?php endif; ?>

      <h2><?= e(t('round.choose')) ?></h2>
      <?php $selected = (string) ($old['slot_id'] ?? ''); require __DIR__ . '/_slots.php'; ?>

      <h2><?= e(t('round.your_details')) ?></h2>
      <div class="field">
        <label for="name"><?= e(t('form.name')) ?> <span class="req" aria-hidden="true">*</span><span class="visually-hidden">(<?= e(t('form.required')) ?>)</span></label>
        <input type="text" id="name" name="name" required maxlength="200" autocomplete="off" aria-describedby="name-hint" value="<?= e($old['name'] ?? '') ?>">
        <p class="hint" id="name-hint"><?= e(t('form.name.hint')) ?></p>
      </div>
      <div class="field">
        <label for="email"><?= e(t('form.email')) ?></label>
        <input type="email" id="email" name="email" maxlength="254" autocomplete="email" inputmode="email" aria-describedby="email-hint" value="<?= e($old['email'] ?? '') ?>">
        <p class="hint" id="email-hint"><?= e(t(!empty($round['remind_parents']) ? 'form.email.hint_reminder' : 'form.email.hint')) ?></p>
      </div>

      <div class="hp" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <section class="privacy-summary" aria-labelledby="privacy-summary-title">
        <h3 id="privacy-summary-title"><?= e(t('privacy.summary.title')) ?></h3>
        <ul>
          <?php foreach ((array) App\I18n::raw('privacy.summary.items') as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
        </ul>
      </section>
      <div class="check">
        <input type="checkbox" id="privacy" name="privacy" value="1" required<?= !empty($old['privacy']) ? ' checked' : '' ?>>
        <label for="privacy"><?= e(t('form.privacy.before')) ?><a href="<?= e(url('/datenschutz', ['r' => $round['slug']])) ?>" target="_blank" rel="noopener"><?= e(t('form.privacy.link')) ?></a><?= e(t('form.privacy.after')) ?> <span class="req" aria-hidden="true">*</span></label>
      </div>
      <div class="check">
        <input type="checkbox" id="remember" name="remember" value="1" aria-describedby="remember-hint"<?= !empty($old['remember']) ? ' checked' : '' ?>>
        <label for="remember"><?= e(t('form.remember')) ?></label>
      </div>
      <p class="hint hint-indent" id="remember-hint"><?= e(t('form.remember.hint')) ?></p>

      <button type="submit" class="button button-primary button-large"><?= e(t('form.submit')) ?></button>
    </form>
    <?php endif; ?>
  </div>
</article>
