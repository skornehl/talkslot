<?php /** @var string $title @var string $html (bereits sicher gerendertes Markdown) */ ?>
<article class="prose">
  <h1><?= e($title) ?></h1>
  <?= $html !== '' ? $html : '<p>' . e(t('page.missing')) . '</p>' ?>
</article>
