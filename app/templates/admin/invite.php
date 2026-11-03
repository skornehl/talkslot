<?php /** Set password via invitation/reset link. @var array $user @var string $token @var array $errors */ use App\Csrf; ?>
<div class="narrow">
  <h1>Passwort festlegen</h1>
  <p>Hallo <?= e($user['display_name']) ?>, legen Sie hier Ihr Passwort fest. Ihr Benutzername: <strong><?= e($user['username']) ?></strong></p>
  <?php require __DIR__ . '/_errors.php'; ?>
  <form method="post" action="<?= e(url('/admin/invite/' . $token)) ?>" class="card form-grid">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <div class="field"><label for="new_password">Neues Passwort (mind. 12 Zeichen)</label><input type="password" id="new_password" name="new_password" required minlength="12" autocomplete="new-password" autofocus></div>
    <div class="field"><label for="new_password2">Passwort wiederholen</label><input type="password" id="new_password2" name="new_password2" required minlength="12" autocomplete="new-password"></div>
    <button type="submit" class="button button-primary">Passwort speichern</button>
  </form>
</div>
