<?php
/**
 * Confirmation and change page (reachable only via the secret link).
 * @var array $booking @var string $token @var bool $canChange @var bool $canMove @var array $days
 * @var string $csrf @var string $done @var bool $mailed @var bool $mailQueued @var bool $remember @var array $errors
 * @var string $open section whose form was sent with an error (move, cancel, mail): shown opened
 * @var string $mailTo address entered for the confirmation mail (only after an error)
 * @var string $contact who parents can ask, e.g. once changes are only possible in person (App\ParentContact)
 */
$label = fmt_slot($booking['day_date'], $booking['start_time'], $booking['end_time']);
$manageUrl = url('/b/' . $token);
$link = abs_url('/b/' . $token);
$open = (string) ($open ?? '');
$doneText = ['booked' => 'manage.booked', 'changed' => 'manage.changed', 'sent' => 'manage.mail.sent'][$done] ?? null;
?>
<?php /* Remember-me entry in the browser: created after booking (with consent), otherwise only updated; link, label and date (removed after that day) */ ?>
<div hidden <?= $remember ? 'data-remember-round' : 'data-update-round' ?>="<?= (int) $booking['round_id'] ?>" data-remember-url="<?= e($manageUrl) ?>" data-remember-label="<?= e($label) ?>" data-remember-date="<?= e($booking['day_date']) ?>"></div>

<h1><?= e(t('manage.title')) ?></h1>

<?php if ($doneText !== null): ?>
  <div class="alert alert-success" role="status">
    <p><strong><?= e(t($doneText)) ?></strong></p>
    <?php if ($mailed): ?><p><?= e(t('manage.mail_sent')) ?></p><?php endif; ?>
    <?php if ($mailQueued): ?><p data-mail-queued><?= e(t('manage.mail_queued')) ?></p><?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_errors.php'; ?>

<div class="card">
  <dl class="details">
    <dt><?= e(t('manage.round')) ?></dt><dd><?= e($booking['title']) ?><?php if (!empty($booking['auto_translated'])): ?><br><span class="small muted" data-auto-translated><?= e(t('round.auto_translated', ['source' => App\I18n::name($booking['source_lang'])])) ?></span><?php endif; ?></dd>
    <dt><?= e(t('manage.teacher')) ?></dt><dd><?= e($booking['teacher_name']) ?></dd>
    <dt><?= e(t('manage.when')) ?></dt><dd><strong><?= e($label) ?></strong></dd>
    <?php if (trim((string) ($booking['location'] ?? '')) !== ''): ?><dt><?= e(t('manage.location')) ?></dt><dd><?= e(trim((string) $booking['location'])) ?></dd><?php endif; ?>
    <dt><?= e(t('manage.name')) ?></dt><dd><?= e($booking['name']) ?></dd>
  </dl>
  <?php require __DIR__ . '/_contact.php'; ?>
</div>

<?php /* Keeping the link: copy and share need JavaScript (hidden without it), the calendar file is a POST button */ ?>
<div class="alert alert-warning manage-link">
  <p><?= e(t('manage.save_link')) ?></p>
  <p><label for="manage-link" class="small"><?= e(t('manage.link_label')) ?></label>
  <input id="manage-link" class="link-field" type="text" readonly value="<?= e($link) ?>"></p>
  <div class="actions">
    <button type="button" class="button" hidden data-copy="<?= e($link) ?>" data-copy-done="<?= e(t('manage.copied')) ?>" data-copy-prompt="<?= e(t('manage.copy.prompt')) ?>"><?= e(t('manage.copy')) ?></button>
    <form method="post" action="<?= e($manageUrl) ?>" class="inline" data-calendar>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="ics">
      <button type="submit" class="button"><?= e(t('manage.calendar')) ?></button>
    </form>
    <button type="button" class="button" hidden data-share="<?= e($link) ?>" data-share-title="<?= e($booking['title']) ?>" data-share-text="<?= e($label) ?>" aria-describedby="share-hint"><?= e(t('manage.share')) ?></button>
  </div>
  <p class="small" id="share-hint"><?= e(t('manage.share.hint')) ?></p>
</div>

<details class="card" id="confirm-mail"<?= $open === 'mail' ? ' open' : '' ?>>
  <summary><h2><?= e(t('manage.mail')) ?></h2></summary>
  <p><?= e(t('manage.mail.text')) ?></p>
  <form method="post" action="<?= e($manageUrl) ?>" data-once>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="mail">
    <div class="field">
      <label for="confirm-email"><?= e(t('manage.mail.label')) ?></label>
      <input type="email" id="confirm-email" name="email" required maxlength="254" autocomplete="email" inputmode="email" value="<?= e($mailTo ?? '') ?>">
    </div>
    <button type="submit" class="button"><?= e(t('manage.mail.submit')) ?></button>
  </form>
</details>
<p class="hint"><?= e(t('manage.deletion')) ?></p>

<?php if (!$canChange): ?>
  <p class="alert"><?= e(t('manage.locked', ['hours' => (int) $booking['cancel_deadline_hours']])) ?></p>
<?php else: ?>
  <?php /* Moving and cancelling folded away: right after booking, the slot list would be the first thing to see */ ?>
  <?php if ($canMove): ?>
  <details class="card" id="change"<?= $open === 'move' ? ' open' : '' ?>>
    <summary><h2><?= e(t('manage.change')) ?></h2></summary>
    <?php if (!$days): ?>
      <p><?= e(t('manage.change.none')) ?></p>
    <?php else: ?>
      <p><?= e(t('manage.change.text')) ?></p>
      <form method="post" action="<?= e($manageUrl) ?>" data-once>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="move">
        <?php $selected = ''; require __DIR__ . '/_slots.php'; ?>
        <button type="submit" class="button button-primary"><?= e(t('manage.change.submit')) ?></button>
      </form>
    <?php endif; ?>
  </details>
  <?php endif; ?>

  <details class="card" id="cancel"<?= $open === 'cancel' ? ' open' : '' ?>>
    <summary><h2><?= e(t('manage.cancel')) ?></h2></summary>
    <p><?= e(t('manage.cancel.text')) ?></p>
    <form method="post" action="<?= e($manageUrl) ?>" data-once>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="cancel">
      <div class="check">
        <input type="checkbox" id="confirm-cancel" name="confirm" value="1" required>
        <label for="confirm-cancel"><?= e(t('manage.cancel.confirm')) ?></label>
      </div>
      <button type="submit" class="button button-danger"><?= e(t('manage.cancel.submit')) ?></button>
    </form>
  </details>
<?php endif; ?>
