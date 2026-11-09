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
    <?php /* The name of the language marked as such (screen readers), inside a sentence in the parents' language */ ?>
    <p class="alert alert-info" data-content-lang="<?= e($contentLang) ?>"><?= str_replace('{language}', '<span lang="' . e($contentLang) . '">' . e(App\I18n::name($contentLang)) . '</span>', e(t('page.fallback', ['language' => '{language}']))) ?></p>
    <div lang="<?= e($contentLang) ?>"><?= $html ?></div>
  <?php else: ?>
    <?= $html !== '' ? $html : '<p>' . e(t('page.missing')) . '</p>' ?>
  <?php endif; ?>
</article>
