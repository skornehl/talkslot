<?php
/**
 * Bestätigungs- und Änderungsseite (erreichbar nur über den geheimen Link).
 * @var array $booking @var string $token @var bool $canChange @var array $days
 * @var string $csrf @var string $done @var bool $mailed @var bool $remember @var array $errors
 */
$label = fmt_slot($booking['day_date'], $booking['start_time'], $booking['end_time']);
$manageUrl = url('/b/' . $token);
?>
<?php if ($remember): ?>
  <div hidden data-remember-round="<?= (int) $booking['round_id'] ?>" data-remember-url="<?= e($manageUrl) ?>" data-remember-label="<?= e($label) ?>"></div>
<?php else: ?>
  <div hidden data-update-round="<?= (int) $booking['round_id'] ?>" data-remember-url="<?= e($manageUrl) ?>" data-remember-label="<?= e($label) ?>"></div>
<?php endif; ?>

<h1><?= e(t('manage.title')) ?></h1>

<?php if ($done === 'booked' || $done === 'changed'): ?>
  <div class="alert alert-success" role="status">
    <p><strong><?= e(t($done === 'booked' ? 'manage.booked' : 'manage.changed')) ?></strong></p>
    <?php if ($mailed): ?><p><?= e(t('manage.mail_sent')) ?></p><?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_errors.php'; ?>

<div class="card">
  <dl class="details">
    <dt><?= e(t('manage.round')) ?></dt><dd><?= e($booking['title']) ?><?php if (!empty($booking['auto_translated'])): ?><br><span class="small muted" data-auto-translated><?= e(t('round.auto_translated', ['source' => App\I18n::name($booking['source_lang'])])) ?></span><?php endif; ?></dd>
    <dt><?= e(t('manage.teacher')) ?></dt><dd><?= e($booking['teacher_name']) ?></dd>
    <dt><?= e(t('manage.when')) ?></dt><dd><strong><?= e($label) ?></strong></dd>
    <dt><?= e(t('manage.name')) ?></dt><dd><?= e($booking['name']) ?></dd>
  </dl>
</div>

<div class="alert alert-warning">
  <p><?= e(t('manage.save_link')) ?></p>
  <p><label for="manage-link" class="small"><?= e(t('manage.link_label')) ?></label>
  <input id="manage-link" class="link-field" type="text" readonly value="<?= e(abs_url('/b/' . $token)) ?>"></p>
</div>
<p class="hint"><?= e(t('manage.deletion')) ?></p>

<?php if (!$canChange): ?>
  <p class="alert"><?= e(t('manage.locked', ['hours' => (int) $booking['cancel_deadline_hours']])) ?></p>
<?php else: ?>
  <?php if ($canMove): ?>
  <section class="card">
    <h2><?= e(t('manage.change')) ?></h2>
    <?php if (!$days): ?>
      <p><?= e(t('manage.change.none')) ?></p>
    <?php else: ?>
      <p><?= e(t('manage.change.text')) ?></p>
      <form method="post" action="<?= e($manageUrl) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="move">
        <?php $selected = ''; require __DIR__ . '/_slots.php'; ?>
        <button type="submit" class="button button-primary"><?= e(t('manage.change.submit')) ?></button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2><?= e(t('manage.cancel')) ?></h2>
    <p><?= e(t('manage.cancel.text')) ?></p>
    <form method="post" action="<?= e($manageUrl) ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="cancel">
      <div class="check">
        <input type="checkbox" id="confirm-cancel" name="confirm" value="1" required>
        <label for="confirm-cancel"><?= e(t('manage.cancel.confirm')) ?></label>
      </div>
      <button type="submit" class="button button-danger"><?= e(t('manage.cancel.submit')) ?></button>
    </form>
  </section>
<?php endif; ?>
