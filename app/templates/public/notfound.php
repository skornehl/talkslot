<?php
/**
 * @var string $title @var string $text @var string $forgetUrl link of a booking that no longer exists ('' = none)
 * @var bool $codeForm the way on: the code input of the start page (not for "too many requests")
 * @var string $contact who parents can ask (App\ParentContact::general(), '' = none)
 */
?>
<?php if (($forgetUrl ?? '') !== ''): ?><div hidden data-forget-url="<?= e($forgetUrl) ?>"></div><?php endif; ?>
<h1><?= e($title) ?></h1>
<p><?= e($text) ?></p>
<?php if ($codeForm ?? false): ?>
  <h2><?= e(t('notfound.code')) ?></h2>
  <?php $code = ''; $errors = []; require __DIR__ . '/_code_form.php'; ?>
<?php else: ?>
  <p><a class="button" href="<?= e(url('/')) ?>"><?= e(t('error.home')) ?></a></p>
<?php endif; ?>
<?php $contactFallback = true; require __DIR__ . '/_contact.php'; ?>
