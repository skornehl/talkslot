<?php /** @var string $title @var string $html (Markdown, already rendered safely) */ ?>
<article class="prose">
  <h1><?= e($title) ?></h1>
  <?= $html !== '' ? $html : '<p>' . e(t('page.missing')) . '</p>' ?>
</article>
