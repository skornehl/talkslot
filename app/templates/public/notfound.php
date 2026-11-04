<?php /** @var string $title @var string $text @var string $forgetUrl link of a booking that no longer exists ('' = none) */ ?>
<?php if (($forgetUrl ?? '') !== ''): ?><div hidden data-forget-url="<?= e($forgetUrl) ?>"></div><?php endif; ?>
<h1><?= e($title) ?></h1>
<p><?= e($text) ?></p>
