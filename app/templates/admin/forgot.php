<?php /** Forgot password. @var bool $done */ use App\Csrf;
// A language chosen via ?lang= stays when the form is submitted
$langQuery = ['lang' => in_array($_GET['lang'] ?? null, App\AdminI18n::LANGUAGES, true) ? $_GET['lang'] : null]; ?>
<div class="narrow">
  <h1><?= e(__('Passwort vergessen')) ?></h1>
  <?php if ($done): ?>
    <div class="alert alert-success" role="status"><p><?= e(__('Falls zu dieser Angabe ein Konto existiert, haben wir einen Link zum Festlegen eines neuen Passworts an die hinterlegte E-Mail-Adresse geschickt. Er ist {hours} Stunden gültig. Ihr bisheriges Passwort bleibt bis dahin gültig.', ['hours' => (int) App\AccountLinks::validHours()])) ?></p></div>
    <p><a href="<?= e(url('/admin/login', $langQuery)) ?>"><?= e(__('Zur Anmeldung')) ?></a></p>
  <?php else: ?>
    <p><?= e(__('Geben Sie Ihre E-Mail-Adresse oder Ihren Benutzernamen ein. Sie erhalten einen Link, über den Sie ein neues Passwort festlegen können.')) ?></p>
    <form method="post" action="<?= e(url('/admin/forgot', $langQuery)) ?>" class="card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="email"><?= e(__('E-Mail-Adresse bzw. Benutzername')) ?></label>
        <input type="text" id="email" name="email" required autocomplete="username" autocapitalize="none" spellcheck="false">
      </div>
      <button type="submit" class="button button-primary"><?= e(__('Link anfordern')) ?></button>
    </form>
    <p><a href="<?= e(url('/admin/login', $langQuery)) ?>"><?= e(__('Zurück zur Anmeldung')) ?></a></p>
  <?php endif; ?>
</div>
