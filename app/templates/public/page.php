<?php
/**
 * Legal notice or privacy policy.
 * @var string $title @var string $html (Markdown, already rendered safely)
 * @var string $contentLang language of the text; another one than the page's if there is no complete text in it
 */
$lang = App\I18n::lang();
$contentLang = (string) ($contentLang ?? $lang);
$other = $html !== '' && $contentLang !== $lang;
?>
<article class="prose">
  <h1><?= e($title) ?></h1>
  <?php if ($other): ?>
    <p class="alert alert-info" data-content-lang="<?= e($contentLang) ?>"><?= e(t('page.fallback', ['language' => App\I18n::name($contentLang)])) ?></p>
    <div lang="<?= e($contentLang) ?>"><?= $html ?></div>
  <?php else: ?>
    <?= $html !== '' ? $html : '<p>' . e(t('page.missing')) . '</p>' ?>
  <?php endif; ?>
</article>
