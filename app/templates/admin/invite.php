<?php /** Set password via invitation/reset link. @var array $user @var string $token @var array $errors */ use App\Csrf;
// A language chosen via ?lang= stays when the form is submitted
$langQuery = ['lang' => in_array($_GET['lang'] ?? null, App\AdminI18n::LANGUAGES, true) ? $_GET['lang'] : null]; ?>
<div class="narrow">
  <h1><?= e(__('Passwort festlegen')) ?></h1>
  <p><?= __h('Hallo {name}, legen Sie hier Ihr Passwort fest. Ihr Benutzername: {username}', ['username' => '<strong>' . e($user['username']) . '</strong>', 'name' => e($user['display_name'])]) ?></p>
  <?php require __DIR__ . '/_errors.php'; ?>
  <form method="post" action="<?= e(url('/admin/invite/' . $token, $langQuery)) ?>" class="card form-grid">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <div class="field"><label for="new_password"><?= e(__('Neues Passwort (mind. 12 Zeichen)')) ?></label><input type="password" id="new_password" name="new_password" required minlength="12" autocomplete="new-password" autofocus aria-describedby="password-hint"><?php require __DIR__ . '/_password_hint.php'; ?></div>
    <div class="field"><label for="new_password2"><?= e(__('Passwort wiederholen')) ?></label><input type="password" id="new_password2" name="new_password2" required minlength="12" autocomplete="new-password"></div>
    <button type="submit" class="button button-primary"><?= e(__('Passwort speichern')) ?></button>
  </form>
</div>
