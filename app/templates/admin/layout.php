<?php
/** Admin layout. @var string $content @var string $title @var array $flash */
use App\Auth;
use App\Csrf;
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Admin · <?= e(app_name()) ?></title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" href="data:,">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="admin">
<a class="skip-link" href="#main">Zum Inhalt springen</a>
<header class="site-header">
  <div class="wrap wrap-wide header-inner">
    <a class="brand" href="<?= e(url('/admin')) ?>"><?= e(app_name()) ?> <span class="badge"><?= Auth::check() && Auth::isAdmin() ? 'Admin' : 'Verwaltung' ?></span></a>
    <?php if (Auth::check()): ?>
    <nav class="admin-nav" aria-label="Hauptmenü">
      <a href="<?= e(url('/admin')) ?>"><?= App\Auth::isAdmin() ? 'Alle Runden' : 'Meine Runden' ?></a>
      <a href="<?= e(url('/admin/profile')) ?>">Mein Profil</a>
      <?php if (Auth::isAdmin()): ?>
        <a href="<?= e(url('/admin/users')) ?>">Benutzer</a>
        <a href="<?= e(url('/admin/texts')) ?>">Texte</a>
        <a href="<?= e(url('/admin/templates')) ?>">Vorlagen</a>
        <a href="<?= e(url('/admin/log')) ?>">Protokoll</a>
      <?php endif; ?>
      <span class="nav-user" title="Angemeldet als"><?= e(Auth::user()['username']) ?></span>
      <form method="post" action="<?= e(url('/admin/logout')) ?>" class="inline">
        <?= Csrf::adminField() ?>
        <button type="submit" class="button-link">Abmelden</button>
      </form>
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
    <nav aria-label="Rechtliches">
      <a href="<?= e(url('/impressum')) ?>">Impressum</a>
      <a href="<?= e(url('/datenschutz')) ?>">Datenschutz</a>
      <span class="version">Version <?= e(App\Version::label()) ?></span>
    </nav>
    <?php require dirname(__DIR__) . '/_theme_switch.php'; ?>
  </div>
</footer>
</body>
</html>
