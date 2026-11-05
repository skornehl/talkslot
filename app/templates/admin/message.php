<?php /** @var string $title @var string $text @var array{0: string, 1: string} $link (optional: address and label instead of the overview) */ ?>
<h1><?= e($title) ?></h1>
<p><?= e($text) ?></p>
<p><a href="<?= e(isset($link) ? $link[0] : url('/admin')) ?>"><?= e(isset($link) ? $link[1] : __('Zur Übersicht')) ?></a></p>
