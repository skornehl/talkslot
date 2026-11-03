<?php /** @var ?array $user @var array $data @var array $errors @var bool $isSelf */ use App\Csrf; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin/users')) ?>">Benutzer</a></p>
<h1><?= e($title) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>

<form method="post" action="<?= e($user ? url('/admin/users/' . (int) $user['id'] . '/edit') : url('/admin/users/new')) ?>" class="card form-grid">
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="display_name">Name</label>
    <input type="text" id="display_name" name="display_name" required maxlength="200" value="<?= e($data['display_name']) ?>" placeholder="z. B. Frau Muster" aria-describedby="dn-hint">
    <p class="hint" id="dn-hint">So wird die Person Eltern auf der Buchungsseite und in E-Mails angezeigt.</p>
  </div>
  <div class="field">
    <label for="email">E-Mail-Adresse</label>
    <input type="email" id="email" name="email" required maxlength="254" autocapitalize="none" spellcheck="false" value="<?= e($data['email'] ?? '') ?>" aria-describedby="em-hint">
    <p class="hint" id="em-hint"><?= $user ? 'Für Zusammenfassungen und neue Startpasswörter.' : 'Dient als Benutzername. Die Zugangsdaten werden an diese Adresse geschickt.' ?></p>
  </div>
  <?php if ($user): ?>
  <div class="field">
    <label for="username">Benutzername (Login)</label>
    <input type="text" id="username" name="username" required maxlength="254" autocapitalize="none" spellcheck="false" value="<?= e($data['username']) ?>">
  </div>
  <?php endif; ?>
  <?php if (!$isSelf): ?>
  <fieldset class="field">
    <legend>Rolle</legend>
    <div class="check">
      <input type="radio" id="role_teacher" name="role" value="teacher"<?= ($data['role'] ?? 'teacher') !== 'admin' ? ' checked' : '' ?>>
      <label for="role_teacher">Lehrkraft – verwaltet ihre eigenen Terminrunden</label>
    </div>
    <div class="check">
      <input type="radio" id="role_admin" name="role" value="admin"<?= ($data['role'] ?? '') === 'admin' ? ' checked' : '' ?>>
      <label for="role_admin">Admin – sieht und bearbeitet alle Runden, legt Runden für Lehrkräfte an, verwaltet Benutzer sowie Impressum und Datenschutz</label>
    </div>
  </fieldset>
  <div class="check">
    <input type="checkbox" id="is_active" name="is_active" value="1"<?= !empty($data['is_active']) ? ' checked' : '' ?>>
    <label for="is_active">Konto aktiv (deaktiviert: kein Login, Runden öffentlich nicht erreichbar)</label>
  </div>
  <?php else: ?>
  <p class="small">Rolle: <strong><?= ($user['role'] ?? '') === 'admin' ? 'Admin' : 'Lehrkraft' ?></strong> (die eigene Rolle kann nur ein anderer Admin ändern).</p>
  <?php endif; ?>
  <?php if (!$user): ?>
    <p class="hint">Beim Speichern erhält die Person eine Einladung per E-Mail mit einem Link, über den sie ihr Passwort selbst festlegt (einmalig nutzbar, <?= (int) App\AccountLinks::validHours() ?> Stunden gültig). Danach kann sie ihre Terminrunden selbst verwalten; Sie können Runden auch für sie anlegen. Kann die Mail nicht versendet werden, wird der Link einmalig angezeigt.</p>
  <?php endif; ?>
  <div class="actions">
    <button type="submit" class="button button-primary">Speichern</button>
    <a class="button" href="<?= e(url('/admin/users')) ?>">Abbrechen</a>
  </div>
</form>

<?php if ($user && !$isSelf): ?>
<section class="card">
  <h2><?= $user['last_login_at'] === null ? 'Einladung erneut senden' : 'Passwort zurücksetzen' ?></h2>
  <p>Schickt einen neuen Link zum Festlegen des Passworts an <?= e($user['email'] ?? '') ?>. Das bisherige Passwort und frühere Links gelten sofort nicht mehr.</p>
  <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/reset-password')) ?>" data-confirm="Neuen Link an „<?= e($user['display_name']) ?>“ senden? Das bisherige Passwort wird ungültig.">
    <?= Csrf::adminField() ?>
    <button type="submit" class="button"><?= $user['last_login_at'] === null ? 'Einladung erneut senden' : 'Link zum Zurücksetzen senden' ?></button>
  </form>
</section>
<section class="card">
  <h2>Benutzer löschen</h2>
  <p>Löscht das Konto <strong>mit allen Terminrunden und Buchungen</strong> dieser Person. Das kann nicht rückgängig gemacht werden. Alternative: Konto deaktivieren.</p>
  <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/delete')) ?>" class="inline-delete" data-confirm="Konto „<?= e($user['username']) ?>“ mit allen Runden und Buchungen endgültig löschen?">
    <?= Csrf::adminField() ?>
    <label class="small"><input type="checkbox" name="confirm" value="1" required> Ja, endgültig löschen</label>
    <button type="submit" class="button button-danger">Löschen</button>
  </form>
</section>
<?php endif; ?>
