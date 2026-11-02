<?php /** Passwort vergessen. @var bool $done */ use App\Csrf; ?>
<div class="narrow">
  <h1>Passwort vergessen</h1>
  <?php if ($done): ?>
    <div class="alert alert-success" role="status"><p>Falls zu dieser Angabe ein Konto existiert, haben wir einen Link zum Festlegen eines neuen Passworts an die hinterlegte E-Mail-Adresse geschickt. Er ist <?= (int) App\AccountLinks::validHours() ?> Stunden gültig. Ihr bisheriges Passwort bleibt bis dahin gültig.</p></div>
    <p><a href="<?= e(url('/admin/login')) ?>">Zur Anmeldung</a></p>
  <?php else: ?>
    <p>Geben Sie Ihre E-Mail-Adresse oder Ihren Benutzernamen ein. Sie erhalten einen Link, über den Sie ein neues Passwort festlegen können.</p>
    <form method="post" action="<?= e(url('/admin/forgot')) ?>" class="card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="email">E-Mail-Adresse bzw. Benutzername</label>
        <input type="text" id="email" name="email" required autocomplete="username" autocapitalize="none" spellcheck="false">
      </div>
      <button type="submit" class="button button-primary">Link anfordern</button>
    </form>
    <p><a href="<?= e(url('/admin/login')) ?>">Zurück zur Anmeldung</a></p>
  <?php endif; ?>
</div>
