<?php
/** Layout of the public pages. @var string $content @var string $title */
use App\Http;
use App\I18n;
?>
<!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . app_name() : app_name()) ?></title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="data:,">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main"><?= e(t('a11y.skip')) ?></a>
<header class="site-header">
  <div class="wrap"><a class="brand" href="<?= e(url('/')) ?>"><?= e(app_name()) ?></a></div>
</header>
<main id="main" class="wrap" tabindex="-1">
<?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-inner">
    <nav aria-label="<?= e(t('footer.imprint')) ?> / <?= e(t('footer.privacy')) ?>">
      <a href="<?= e(url('/impressum')) ?>"><?= e(t('footer.imprint')) ?></a>
      <a href="<?= e(url('/datenschutz', ['r' => $privacyRound ?? null])) ?>"><?= e(t('footer.privacy')) ?></a>
      <?php require __DIR__ . '/_powered_by.php'; ?>
    </nav>
    <?php if (count(I18n::languages()) > 1):
      // Language links only for harmless paths (otherwise e.g. /\\host → foreign domain), else home page
      $langPath = preg_match('#^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$#', Http::path()) ? Http::path() : '/'; ?>
    <nav aria-label="<?= e(t('footer.language')) ?>" class="lang-switch">
      <?php foreach (I18n::languages() as $code):
        // Flag with language name (for screen readers and as a tooltip in that language)
        $langName = I18n::name($code);
        $flag = is_file(asset_file('flags/' . $code . '.svg'))
            ? '<img src="' . e(asset('flags/' . $code . '.svg')) . '" alt="" width="28" height="19" class="flag"><span class="visually-hidden">' . e($langName) . '</span>'
            : e(strtoupper($code)); ?>
        <?php if (!empty($langSwitchForm)): ?>
        <button type="submit" form="<?= e($langSwitchForm) ?>" name="switch_lang" value="<?= e($code) ?>" formnovalidate class="button-link" lang="<?= e($code) ?>" title="<?= e($langName) ?>"<?= $code === I18n::lang() ? ' aria-current="true"' : '' ?>><?= $flag ?></button>
        <?php continue; elseif (!empty($langSwitchAccess)): ?>
        <form method="post" action="<?= e($langSwitchAccess['action']) ?>" class="inline">
          <input type="hidden" name="step" value="switch">
          <input type="hidden" name="access" value="<?= e($langSwitchAccess['access']) ?>">
          <input type="hidden" name="lang" value="<?= e($code) ?>">
          <button type="submit" class="button-link" lang="<?= e($code) ?>" title="<?= e($langName) ?>"<?= $code === I18n::lang() ? ' aria-current="true"' : '' ?>><?= $flag ?></button>
        </form>
        <?php continue; endif; ?>
        <a href="<?= e(url($langPath, ['lang' => $code, 'r' => in_array(Http::path(), ['/datenschutz', '/privacy'], true) ? ($privacyRound ?? null) : null])) ?>" lang="<?= e($code) ?>" title="<?= e($langName) ?>"<?= $code === I18n::lang() ? ' aria-current="true"' : '' ?>><?= $flag ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php require __DIR__ . '/_theme_switch.php'; ?>
  </div>
</footer>
</body>
</html>
