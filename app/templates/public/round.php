<?php
/**
 * Public round page.
 * @var array $round @var array $days @var string $csrf @var array $errors @var array $old
 * @var string $controller name of the controller (first level of the privacy information)
 * @var ?string $operatorType operated by the school or privately (legal basis), null = not chosen yet
 * @var string $contact who parents can ask (App\ParentContact) @var ?string $unavailable why there is no free slot
 */
// Deadlines before booking (expert review P1-9): a deadline of 0 hours restricts nothing and is not mentioned
$deadlines = array_filter([
    (int) ($round['booking_deadline_hours'] ?? 0) > 0 ? t('round.deadline.booking', ['hours' => fmt_hours((int) $round['booking_deadline_hours'])]) : '',
    (int) ($round['cancel_deadline_hours'] ?? 0) > 0 ? t('round.deadline.change', ['hours' => fmt_hours((int) $round['cancel_deadline_hours'])]) : '',
]);
?>
<article class="round" data-round-id="<?= (int) $round['id'] ?>">
  <h1><?= e($round['title']) ?></h1>
  <p class="teacher"><?= e(t('round.teacher', ['name' => $round['teacher_name']])) ?></p>
  <?php if (trim((string) ($round['location'] ?? '')) !== ''): ?><p class="location"><?= e(t('round.location', ['location' => trim((string) $round['location'])])) ?></p><?php endif; ?>
  <?php if ($days): /* with no free slot, the contact is part of the notice below */ require __DIR__ . '/_contact.php'; endif; ?>
  <?php if (!empty($round['description'])): ?>
    <div class="description"><?= nl2br(e($round['description'])) ?></div>
  <?php endif; ?>
  <?php if (!empty($round['auto_translated'])): ?>
    <p class="small muted" data-auto-translated><?= e(t('round.auto_translated', ['source' => App\I18n::name($round['source_lang'])])) ?></p>
  <?php endif; ?>
  <p class="alert alert-info"><?= e(t(App\BookingService::limitNotice($round))) ?><?php if ($deadlines): ?><br><span data-deadlines><?= e(implode(' ', $deadlines)) ?></span><?php endif; ?></p>

  <?php require __DIR__ . '/_remembered.php'; ?>

  <?php /* data-keep-open: the form was sent (error, language switch) – it stays visible next to remembered appointments */ ?>
  <div id="booking-area"<?= $old ? ' data-keep-open' : '' ?>>
    <?php require __DIR__ . '/_errors.php'; ?>

    <?php if (!$days): ?>
      <?php /* Why: all booked, the booking deadline has passed, or nothing planned (BookingService::unavailableReason()) */ ?>
      <div class="alert" data-unavailable="<?= e($unavailable ?? 'none') ?>">
        <p><?= e(t(['full' => 'round.no_slots.full', 'closed' => 'round.no_slots.closed'][$unavailable ?? ''] ?? 'round.no_slots')) ?></p>
        <?php $contactFallback = true; require __DIR__ . '/_contact.php'; ?>
      </div>
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
        <?php /* The child's name, nothing more (expert review P2-4); the sibling rule is in the info box above */ ?>
        <p class="hint" id="name-hint"><?= e(t('form.name.hint')) ?></p>
      </div>
      <div class="field">
        <label for="email"><?= e(t('form.email')) ?></label>
        <input type="email" id="email" name="email" maxlength="254" autocomplete="email" inputmode="email" aria-describedby="email-hint" value="<?= e($old['email'] ?? '') ?>">
        <p class="hint" id="email-hint"><?= e(t(!empty($round['remind_parents']) ? 'form.email.hint_reminder' : 'form.email.hint')) ?></p>
      </div>
      <?php if (!empty($round['ask_interpreter'])): ?>
      <div class="field">
        <label for="interpreter_lang"><?= e(t('form.interpreter')) ?></label>
        <input type="text" id="interpreter_lang" name="interpreter_lang" maxlength="60" autocomplete="off" aria-describedby="interpreter-hint" value="<?= e($old['interpreter_lang'] ?? '') ?>">
        <p class="hint" id="interpreter-hint"><?= e(t('form.interpreter.hint')) ?></p>
      </div>
      <?php endif; ?>

      <div class="hp" aria-hidden="true">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="check">
        <input type="checkbox" id="remember" name="remember" value="1" aria-describedby="remember-hint"<?= !empty($old['remember']) ? ' checked' : '' ?>>
        <label for="remember"><?= e(t('form.remember')) ?></label>
      </div>
      <p class="hint hint-indent" id="remember-hint"><?= e(t('form.remember.hint')) ?></p>

      <?php /* Privacy information in two levels (Art. 12, 13 GDPR): who, what for, deletion and the link to the full
         notice right above the button, the rest folded away. Informing is enough, nothing to tick. */ ?>
      <section class="privacy-summary" aria-labelledby="privacy-summary-title" data-privacy-summary>
        <h3 id="privacy-summary-title"><?= e(t('privacy.summary.title')) ?></h3>
        <ul>
          <?php if (($controller ?? '') !== ''): ?><li><?= e(t('privacy.summary.controller', ['controller' => $controller])) ?></li><?php endif; ?>
          <li><?= e(t('privacy.summary.purpose')) ?></li>
          <li><?= e(t('privacy.summary.deletion')) ?></li>
        </ul>
        <details>
          <summary><?= e(t('privacy.summary.more')) ?></summary>
          <ul>
            <li><?= e(t(!empty($round['ask_interpreter']) ? 'privacy.summary.data_interpreter' : 'privacy.summary.data')) ?></li>
            <li><?= e(t('privacy.summary.teacher')) ?></li>
            <?php if (in_array($operatorType ?? null, App\Operator::TYPES, true)): ?><li><?= e(t('privacy.summary.basis.' . $operatorType)) ?></li><?php endif; ?>
            <li><?= e(t('privacy.summary.rights')) ?></li>
          </ul>
        </details>
        <p class="privacy-link"><a href="<?= e(url('/datenschutz', ['r' => $round['slug']])) ?>" target="_blank" rel="noopener"><?= e(t('privacy.summary.link')) ?></a></p>
      </section>

      <?php /* The chosen appointment right above the button, announced by screen readers (aria-live): app.js fills it in
         when a slot is chosen, after an error or a language switch the server already does. Empty, it takes no space. */
      $chosen = null;
      foreach ($days as $day) {
          foreach ($day['slots'] as $s) {
              if ((string) $s['id'] === (string) ($old['slot_id'] ?? '')) {
                  $chosen = slot_label($day, $s, $round);
              }
          }
      } ?>
      <p class="booking-summary" aria-live="polite" data-slot-summary="<?= e(t('round.selected')) ?>"><?= $chosen !== null ? e(t('round.selected', ['when' => $chosen])) : '' ?></p>
      <button type="submit" class="button button-primary button-large"><?= e(t('form.submit')) ?></button>
    </form>
    <?php endif; ?>
  </div>
</article>
