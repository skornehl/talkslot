<?php /** Mandatory password change after the initial password. @var array $errors */ use App\Csrf; ?>
<div class="narrow">
  <h1>Neues Passwort festlegen</h1>
  <p>Sie haben sich mit einem Startpasswort angemeldet. Bitte legen Sie jetzt ein eigenes Passwort fest, das nur Sie kennen.</p>
  <?php require __DIR__ . '/_errors.php'; ?>
  <form method="post" action="<?= e(url('/admin/password-change')) ?>" class="card form-grid">
    <?= Csrf::adminField() ?>
    <?php require __DIR__ . '/_password_fields.php'; ?>
    <button type="submit" class="button button-primary">Passwort festlegen</button>
  </form>
</div>
