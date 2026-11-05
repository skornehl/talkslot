<?php /** @var ?array $user @var array $data @var array $errors @var bool $isSelf @var array $parents upcoming bookings in the account's rounds, how many can be mailed */ use App\Csrf; ?>
<p class="breadcrumb"><a href="<?= e(url('/admin/users')) ?>"><?= e(__('Benutzer')) ?></a></p>
<h1><?= e($title) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>

<form method="post" action="<?= e($user ? url('/admin/users/' . (int) $user['id'] . '/edit') : url('/admin/users/new')) ?>" class="card form-grid" novalidate>
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="display_name"><?= e(__('Name')) ?></label>
    <input type="text" id="display_name" name="display_name" required maxlength="200" value="<?= e($data['display_name']) ?>" placeholder="<?= e(__('z. B. Frau Muster')) ?>" aria-describedby="dn-hint">
    <p class="hint" id="dn-hint"><?= e(__('So wird die Person Eltern auf der Buchungsseite und in E-Mails angezeigt.')) ?></p>
  </div>
  <div class="field">
    <label for="email"><?= e(__('E-Mail-Adresse')) ?></label>
    <input type="email" id="email" name="email" required maxlength="254" autocapitalize="none" spellcheck="false" autocomplete="off" value="<?= e($data['email'] ?? '') ?>" aria-describedby="em-hint">
    <p class="hint" id="em-hint"><?= $user ? e(__('Für Zusammenfassungen und neue Startpasswörter.')) : e(__('Dient als Benutzername. Die Zugangsdaten werden an diese Adresse geschickt.')) ?></p>
  </div>
  <div class="field">
    <label for="lang">Sprache / Language</label>
    <select id="lang" name="lang" aria-describedby="lang-hint">
      <?php foreach (['de' => 'Deutsch', 'en' => 'English'] as $code => $name): ?>
        <option value="<?= $code ?>"<?= ($data['lang'] ?? ($user ? App\AdminI18n::default() : App\AdminI18n::lang())) === $code ? ' selected' : '' ?>><?= $name ?></option>
      <?php endforeach; ?>
    </select>
    <p class="hint" id="lang-hint"><?= e(__('Sprache des Verwaltungsbereichs und der E-Mails an diese Person (Einladung, Zusammenfassungen).')) ?></p>
  </div>
  <?php if ($user): ?>
  <div class="field">
    <label for="username"><?= e(__('Benutzername (Login)')) ?></label>
    <input type="text" id="username" name="username" required maxlength="254" autocapitalize="none" spellcheck="false" autocomplete="off" value="<?= e($data['username']) ?>">
  </div>
  <?php endif; ?>
  <?php if (!$isSelf): ?>
  <fieldset class="field">
    <legend><?= e(__('Rolle')) ?></legend>
    <div class="check">
      <input type="radio" id="role_teacher" name="role" value="teacher"<?= ($data['role'] ?? 'teacher') !== 'admin' ? ' checked' : '' ?>>
      <label for="role_teacher"><?= e(__('Lehrkraft – verwaltet ihre eigenen Terminrunden')) ?></label>
    </div>
    <div class="check">
      <input type="radio" id="role_admin" name="role" value="admin"<?= ($data['role'] ?? '') === 'admin' ? ' checked' : '' ?>>
      <label for="role_admin"><?= e(__('Admin – sieht und bearbeitet alle Runden, legt Runden für Lehrkräfte an, verwaltet Benutzer sowie Impressum und Datenschutz')) ?></label>
    </div>
  </fieldset>
  <div class="check">
    <input type="checkbox" id="is_active" name="is_active" value="1"<?= !empty($data['is_active']) ? ' checked' : '' ?>>
    <label for="is_active"><?= e(__('Konto aktiv (deaktiviert: kein Login, Runden öffentlich nicht erreichbar)')) ?></label>
  </div>
  <?php else: ?>
  <p class="small"><?= __h('Rolle: {role} (die eigene Rolle kann nur ein anderer Admin ändern).', ['role' => '<strong>' . (($user['role'] ?? '') === 'admin' ? 'Admin' : e(__('Lehrkraft'))) . '</strong>']) ?></p>
  <?php endif; ?>
  <div class="field">
    <label for="current_password"><?= e(__('Ihr Passwort (zur Bestätigung)')) ?></label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" aria-describedby="pw-hint">
    <p class="hint" id="pw-hint"><?= e(__('Nur nötig, wenn Sie Admin-Rechte vergeben oder entziehen, ein Admin-Konto deaktivieren oder wieder aktivieren oder die E-Mail-Adresse ändern. Dann geht eine E-Mail hinaus: bei Admin-Rechten an alle Admins und die betroffene Person, bei einer neuen E-Mail-Adresse an die bisherige.')) ?></p>
  </div>
  <?php if (!$user): ?>
    <p class="hint"><?= e(__('Beim Speichern erhält die Person eine Einladung per E-Mail mit einem Link, über den sie ihr Passwort selbst festlegt (einmalig nutzbar, {hours} Stunden gültig). Danach kann sie ihre Terminrunden selbst verwalten; Sie können Runden auch für sie anlegen.', ['hours' => (int) App\AccountLinks::validHours()])) ?></p>
  <?php endif; ?>
  <div class="actions">
    <button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button>
    <a class="button" href="<?= e(url('/admin/users')) ?>"><?= e(__('Abbrechen')) ?></a>
  </div>
</form>

<?php if ($user && !$isSelf): ?>
<section class="card">
  <h2><?= $user['last_login_at'] === null ? e(__('Einladung erneut senden')) : e(__('Passwort zurücksetzen')) ?></h2>
  <p><?= e(__('Schickt einen neuen Link zum Festlegen des Passworts an {email}. Das bisherige Passwort und frühere Links gelten sofort nicht mehr.', ['email' => $user['email'] ?? ''])) ?></p>
  <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/reset-password')) ?>" data-confirm="<?= e(__('Neuen Link an „{name}“ senden? Das bisherige Passwort wird ungültig.', ['name' => $user['display_name']])) ?>">
    <?= Csrf::adminField() ?>
    <button type="submit" class="button"><?= $user['last_login_at'] === null ? e(__('Einladung erneut senden')) : e(__('Link zum Zurücksetzen senden')) ?></button>
  </form>
</section>
<section class="card">
  <h2><?= e(__('Benutzer löschen')) ?></h2>
  <p><?= __h('Löscht das Konto {bold} dieser Person. Das kann nicht rückgängig gemacht werden. Alternative: Konto deaktivieren.', ['bold' => '<strong>' . e(__('mit allen Terminrunden und Buchungen')) . '</strong>']) ?></p>
  <?php
  // What happens to the parents of upcoming appointments, with the box ticked (data-confirm) or not (data-confirm-off)
  $question = __('Konto „{username}“ mit allen Runden und Buchungen endgültig löschen?', ['username' => $user['username']]);
  $off = $parents['upcoming'] > 0 ? "\n\n" . __('Die Eltern von {count} Buchung(en) werden NICHT automatisch benachrichtigt – bitte selbst informieren.', ['count' => $parents['upcoming']]) : '';
  $on = $parents['mailable'] > 0 ? "\n\n" . __('Die Eltern von {count} Buchung(en) erhalten eine Absage per E-Mail.', ['count' => $parents['mailable']])
      . ($parents['upcoming'] > $parents['mailable'] ? ' ' . __('{count} Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren; die Namen stehen danach in der Meldung.', ['count' => $parents['upcoming'] - $parents['mailable']]) : '') : $off;
  ?>
  <form method="post" action="<?= e(url('/admin/users/' . (int) $user['id'] . '/delete')) ?>" class="inline-delete" data-confirm="<?= e($question . $on) ?>" data-confirm-off="<?= e($question . $off) ?>">
    <?= Csrf::adminField() ?>
    <label class="small"><input type="checkbox" name="confirm" value="1" required> <?= e(__('Ja, endgültig löschen')) ?></label>
    <?php if ($parents['mailable'] > 0): ?>
    <label class="small"><input type="checkbox" name="notify" value="1" checked> <?= e(__('Eltern per E-Mail informieren')) ?></label>
    <?php endif; ?>
    <?php if ($user['role'] === 'admin'): ?>
    <label class="small" for="delete_password"><?= e(__('Ihr Passwort (zur Bestätigung)')) ?></label>
    <input type="password" id="delete_password" name="current_password" required autocomplete="current-password">
    <?php endif; ?>
    <button type="submit" class="button button-danger"><?= e(__('Löschen')) ?></button>
  </form>
</section>
<?php endif; ?>
