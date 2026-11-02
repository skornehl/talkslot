<?php /** Web-Installer. @var array $errors @var array $old @var bool $done */ ?>
<h1>Installation</h1>
<?php if ($done): ?>
  <div class="alert alert-success"><p>Installation abgeschlossen. Der Installer ist jetzt gesperrt<?= is_file(PUBLIC_ROOT . '/install.php') ? ' – bitte löschen Sie zusätzlich die Datei <code>install.php</code> im Webverzeichnis' : '' ?>.</p></div>
  <p><a class="button button-primary" href="<?= e(url('/admin')) ?>">Zum Admin-Bereich</a></p>
<?php else: ?>
  <p>Legt die Datenbanktabellen und das erste Konto (Admin) an. Weitere Lehrkräfte legen Sie danach im Admin-Bereich unter „Benutzer“ an. Die Datenbank-Zugangsdaten stehen in <code>config/config.php</code>.</p>
  <?php require __DIR__ . '/admin/_errors.php'; ?>
  <form method="post" class="card form-grid">
    <div class="field">
      <label for="install_key">Installationsschlüssel (aus config.php, <code>install_key</code>)</label>
      <input type="password" id="install_key" name="install_key" required autocomplete="off">
    </div>
    <div class="field">
      <label for="username">Admin-Benutzername</label>
      <input type="text" id="username" name="username" required autocomplete="username" value="<?= e($old['username'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="display_name">Anzeigename (z. B. „Frau Muster“, optional)</label>
      <input type="text" id="display_name" name="display_name" maxlength="200" value="<?= e($old['display_name'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Passwort (mind. 12 Zeichen)</label>
      <input type="password" id="password" name="password" required minlength="12" autocomplete="new-password">
    </div>
    <div class="field">
      <label for="password2">Passwort wiederholen</label>
      <input type="password" id="password2" name="password2" required minlength="12" autocomplete="new-password">
    </div>
    <div class="field">
      <label for="notify_email">E-Mail für Zusammenfassungen (optional)</label>
      <input type="email" id="notify_email" name="notify_email" value="<?= e($old['notify_email'] ?? '') ?>">
    </div>
    <button type="submit" class="button button-primary">Installieren</button>
  </form>
<?php endif; ?>
