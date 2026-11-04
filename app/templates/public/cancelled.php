<?php /** @var array $booking @var string $forgetUrl link of the cancelled booking (its remember-me entry is removed) */ ?>
<div hidden data-forget-url="<?= e($forgetUrl) ?>"></div>
<h1><?= e(t('cancelled.title')) ?></h1>
<div class="alert alert-success" role="status"><p><?= e(t('cancelled.text')) ?></p></div>
<?php if ($booking['is_active']): ?>
  <p><a class="button" href="<?= e(url('/' . $booking['slug'])) ?>"><?= e(t('cancelled.back')) ?></a></p>
<?php endif; ?>
