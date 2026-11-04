<?php /** @var string $error @var string $username @var string $csrf */
// A language chosen via ?lang= stays when the form is submitted
$langQuery = ['lang' => in_array($_GET['lang'] ?? null, App\AdminI18n::LANGUAGES, true) ? $_GET['lang'] : null]; ?>
<div class="narrow">
  <h1><?= e(__('Anmelden')) ?></h1>
  <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><p><?= e($error) ?></p></div><?php endif; ?>
  <form method="post" action="<?= e(url('/admin/login', $langQuery)) ?>" class="card">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="field">
      <label for="username"><?= e(__('Benutzername bzw. E-Mail-Adresse')) ?></label>
      <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($username) ?>">
    </div>
    <div class="field">
      <label for="password"><?= e(__('Passwort')) ?></label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="button button-primary"><?= e(__('Anmelden')) ?></button>
  </form>
  <p><a href="<?= e(url('/admin/forgot', $langQuery)) ?>"><?= e(__('Passwort vergessen?')) ?></a></p>
</div>
