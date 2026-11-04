<?php
/** Layout of the public pages. @var string $content @var string $title */
use App\Http;
use App\I18n;

/*
 * Language choice at the top of every page, as text in each language's own name: flags stand for
 * countries, not languages, and a switch at the end of a long page is found too late by exactly those
 * who need it. A control switches the language the way the page allows: a button of the booking form
 * keeps everything entered so far (switch_lang), a small form keeps the access to a round protected by
 * its code, otherwise a plain link with ?lang= (no cookie).
 */
$switchForm = (string) ($langSwitchForm ?? '');
$switchAccess = $switchForm === '' && !empty($langSwitchAccess) ? $langSwitchAccess : null;
// Language links only for harmless paths (otherwise e.g. /\\host → foreign domain), else home page
$langPath = preg_match('#^/(?:[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*)?$#', Http::path()) ? Http::path() : '/';
$langRound = in_array(Http::path(), ['/datenschutz', '/privacy'], true) ? ($privacyRound ?? null) : null;
$langControl = static function (string $code, string $label) use ($switchForm, $switchAccess, $langPath, $langRound): string {
    $attrs = ' lang="' . e($code) . '"' . ($code === I18n::lang() ? ' aria-current="true"' : '');
    if ($switchForm !== '') {
        return '<button type="submit" form="' . e($switchForm) . '" name="switch_lang" value="' . e($code) . '" formnovalidate class="button-link"' . $attrs . '>' . e($label) . '</button>';
    }
    if ($switchAccess !== null) {
        return '<form method="post" action="' . e($switchAccess['action']) . '" class="inline">'
            . '<input type="hidden" name="step" value="switch">'
            . '<input type="hidden" name="access" value="' . e($switchAccess['access']) . '">'
            . '<input type="hidden" name="lang" value="' . e($code) . '">'
            . '<button type="submit" class="button-link"' . $attrs . '>' . e($label) . '</button></form>';
    }
    return '<a href="' . e(url($langPath, ['lang' => $code, 'r' => $langRound])) . '" hreflang="' . e($code) . '"' . $attrs . '>' . e($label) . '</a>';
};
$multilingual = count(I18n::languages()) > 1;
// Browser language offered, if the visitor has not chosen one: only a link, the page does not switch by itself
$suggested = $multilingual ? I18n::suggested() : null;
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
<?php if ($multilingual && $switchForm !== ''):
  // The language buttons above belong to the booking form too. Enter in a field submits with the form's
  // first submit button, so this invisible one has to come first: it books instead of switching. ?>
<button type="submit" form="<?= e($switchForm) ?>" class="visually-hidden" tabindex="-1" aria-hidden="true" data-default-submit><?= e(t('form.submit')) ?></button>
<?php endif; ?>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="<?= e(url('/')) ?>"><?= e(app_name()) ?></a>
    <?php if ($multilingual): ?>
    <nav aria-label="<?= e(t('lang.label')) ?>" class="lang-switch">
      <svg class="lang-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>
<?php foreach (I18n::languages() as $code) {
    echo '      ', $langControl($code, I18n::name($code)), "\n";
} ?>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="wrap" tabindex="-1">
<?php if ($suggested !== null): ?>
<div class="alert alert-info lang-hint" lang="<?= e($suggested) ?>"><?= $langControl($suggested, I18n::textIn($suggested, 'lang.suggest')) ?></div>
<?php endif; ?>
<?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-inner">
    <nav aria-label="<?= e(t('footer.imprint')) ?> / <?= e(t('footer.privacy')) ?>">
      <a href="<?= e(url('/impressum')) ?>"><?= e(t('footer.imprint')) ?></a>
      <a href="<?= e(url('/datenschutz', ['r' => $privacyRound ?? null])) ?>"><?= e(t('footer.privacy')) ?></a>
      <?php require __DIR__ . '/_powered_by.php'; ?>
    </nav>
    <?php require __DIR__ . '/_theme_switch.php'; ?>
  </div>
</footer>
</body>
</html>
