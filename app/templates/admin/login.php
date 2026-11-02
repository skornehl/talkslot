<?php /** @var string $error @var string $username @var string $csrf */ ?>
<div class="narrow">
  <h1>Anmelden</h1>
  <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><p><?= e($error) ?></p></div><?php endif; ?>
  <form method="post" action="<?= e(url('/admin/login')) ?>" class="card">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="field">
      <label for="username">Benutzername bzw. E-Mail-Adresse</label>
      <input type="text" id="username" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($username) ?>">
    </div>
    <div class="field">
      <label for="password">Passwort</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="button button-primary">Anmelden</button>
  </form>
  <p><a href="<?= e(url('/admin/forgot')) ?>">Passwort vergessen?</a></p>
</div>
