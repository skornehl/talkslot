<?php
/** Admin layout. @var string $content @var string $title @var array $flash @var ?string $refresh (URL to open at once, from this site) */
use App\Auth;
use App\Csrf;

// Imprint and privacy policy in the admin language, if the public pages offer it
$legalQuery = ['lang' => App\AdminI18n::lang() !== 'de' && App\I18n::lang() === App\AdminI18n::lang() ? App\I18n::lang() : null];

// translate="no" plus class/meta "notranslate": browsers do not offer their page translation, which
// would send the whole page with names and e-mail addresses to a cloud service (Translator API unaffected)
?>
<!doctype html>
<html lang="<?= e(App\AdminI18n::lang()) ?>" translate="no" class="notranslate">
<head>
<meta charset="utf-8">
<meta name="google" content="notranslate">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<?php if (isset($refresh)): ?>
<meta http-equiv="refresh" content="0; url=<?= e($refresh) ?>">
<?php endif; ?>
<title><?= e($title) ?> · Admin · <?= e(app_name()) ?></title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="data:,">
<?php if (App\AdminI18n::lang() === 'en'): ?>
<script type="application/json" id="tp-i18n"><?= json_encode(require APP_ROOT . '/app/lang/admin/en/js.php', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#main"><?= e(__('Zum Inhalt springen')) ?></a>
<header class="site-header">
  <div class="wrap wrap-wide header-inner">
    <a class="brand" href="<?= e(url('/admin')) ?>"><?= e(app_name()) ?> <span class="badge"><?= Auth::check() && Auth::isAdmin() ? 'Admin' : e(__('Verwaltung')) ?></span></a>
    <?php if (Auth::check()): ?>
    <nav class="admin-nav" aria-label="<?= e(__('Hauptmenü')) ?>">
      <a href="<?= e(url('/admin')) ?>"><?= App\Auth::isAdmin() ? e(__('Alle Runden')) : e(__('Meine Runden')) ?></a>
      <a href="<?= e(url('/admin/profile')) ?>"><?= e(__('Mein Profil')) ?></a>
      <?php if (Auth::isAdmin()): ?>
        <a href="<?= e(url('/admin/users')) ?>"><?= e(__('Benutzer')) ?></a>
        <a href="<?= e(url('/admin/texts')) ?>"><?= e(__('Texte')) ?></a>
        <a href="<?= e(url('/admin/templates')) ?>"><?= e(__('Vorlagen')) ?></a>
        <a href="<?= e(url('/admin/log')) ?>"><?= e(__('Protokoll')) ?></a>
      <?php endif; ?>
      <span class="nav-user" title="<?= e(__('Angemeldet als')) ?>"><?= e(Auth::user()['username']) ?></span>
      <form method="post" action="<?= e(url('/admin/logout')) ?>" class="inline">
        <?= Csrf::adminField() ?>
        <button type="submit" class="button-link"><?= e(__('Abmelden')) ?></button>
      </form>
    </nav>
    <?php else:
      // Before signing in: language links to the same page (only for harmless paths, otherwise the login page)
      $langPath = preg_match('#^/admin(?:/[A-Za-z0-9._~-]+)*\z#', App\Http::path()) ? App\Http::path() : '/admin/login'; ?>
    <nav class="admin-nav" aria-label="Sprache / Language">
      <?php foreach (['de' => 'Deutsch', 'en' => 'English'] as $code => $name): ?>
        <a href="<?= e(url($langPath, ['lang' => $code])) ?>" lang="<?= $code ?>"<?= App\AdminI18n::lang() === $code ? ' aria-current="true"' : '' ?>><?= $name ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="wrap wrap-wide" tabindex="-1">
  <?php foreach ($flash ?? [] as [$type, $msg]): ?>
    <div class="alert alert-<?= e($type) ?>" role="status"><p><?= e($msg) ?></p></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap wrap-wide footer-inner">
    <nav aria-label="<?= e(__('Rechtliches')) ?>">
      <a href="<?= e(url('/impressum', $legalQuery)) ?>"><?= e(__('Impressum')) ?></a>
      <a href="<?= e(url('/datenschutz', $legalQuery)) ?>"><?= e(__('Datenschutz')) ?></a>
      <?php require dirname(__DIR__) . '/_powered_by.php'; ?>
    </nav>
    <?php require dirname(__DIR__) . '/_theme_switch.php'; ?>
  </div>
</footer>
</body>
</html>
