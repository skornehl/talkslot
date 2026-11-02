<?php /** @var array $user @var array $data @var array $errors */ use App\Csrf; ?>
<h1>Mein Profil</h1>
<?php require __DIR__ . '/_errors.php'; ?>

<section class="card">
  <h2>Angaben</h2>
  <form method="post" action="<?= e(url('/admin/profile')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <div class="field">
      <label for="display_name">Anzeigename</label>
      <input type="text" id="display_name" name="display_name" required maxlength="200" value="<?= e($data['display_name']) ?>" aria-describedby="dn-hint">
      <p class="hint" id="dn-hint">So sehen Eltern Sie auf der Buchungsseite und in E-Mails, z. B. „Frau Muster“ oder „Sabrina Muster“.</p>
    </div>
    <?php if (App\Auth::isAdmin()): ?>
    <div class="field">
      <label for="controller_contact">Angaben für Impressum und Datenschutz</label>
      <textarea id="controller_contact" name="controller_contact" rows="5" maxlength="2000" aria-describedby="cc-hint" placeholder="Vorname Nachname&#10;Straße Hausnummer&#10;PLZ Ort&#10;E-Mail: name@example.org"><?= e($data['controller_contact'] ?? '') ?></textarea>
      <p class="hint" id="cc-hint">Als Admin sind Sie für alle Terminrunden datenschutzrechtlich verantwortlich. Bitte <strong>vollständigen Namen, Anschrift und E-Mail-Adresse</strong> eintragen (wie im Beispiel) – genau dieser Text erscheint in Impressum und Datenschutzerklärung. Die E-Mail-Adresse weiter unten gilt nur für Zusammenfassungen. Ohne diese Angaben ist keine Runde öffentlich erreichbar.</p>
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="email">E-Mail-Adresse</label>
      <input type="email" id="email" name="email" maxlength="254" value="<?= e($data['email'] ?? '') ?>" aria-describedby="em-hint">
      <p class="hint" id="em-hint">Für Ihre Zusammenfassungen und Links zum Zurücksetzen des Passworts. Eltern sehen sie nicht, Antworten auf Bestätigungsmails gehen aber an diese Adresse (Reply-To).</p>
    </div>
    <div class="field">
      <label for="current_password_email">Aktuelles Passwort <span class="muted small">(nur nötig, wenn Sie die E-Mail-Adresse ändern)</span></label>
      <input type="password" id="current_password_email" name="current_password" autocomplete="current-password">
    </div>
    <fieldset class="field pin-fieldset">
      <legend>Zusammenfassungen per E-Mail</legend>
      <div class="check">
        <input type="checkbox" id="summary_evening" name="summary_evening" value="1"<?= !empty($data['summary_evening']) ? ' checked' : '' ?>>
        <label for="summary_evening"><strong>Tagesübersicht am Vorabend:</strong> Am Abend vor einem Termintag alle Termine des nächsten Tages (Uhrzeit und Name, mit Kalenderdatei).</label>
      </div>
      <div class="check">
        <input type="checkbox" id="summary_deadline" name="summary_deadline" value="1"<?= !empty($data['summary_deadline']) ? ' checked' : '' ?>>
        <label for="summary_deadline"><strong>Übersicht nach Buchungsschluss:</strong> Sobald für einen Tag keine Buchungen mehr möglich sind, die endgültige Terminliste dieses Tages.</label>
      </div>
      <p class="hint">Die Mails enthalten nur Uhrzeiten und Namen, keine E-Mail-Adressen der Eltern. Bitte spätestens 15 Monate nach dem Termin löschen.</p>
    </fieldset>
    <div class="actions"><button type="submit" class="button button-primary">Speichern</button></div>
  </form>
</section>

<section class="card">
  <h2>Passwort ändern</h2>
  <form method="post" action="<?= e(url('/admin/password')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <?php require __DIR__ . '/_password_fields.php'; ?>
    <div class="actions"><button type="submit" class="button button-primary">Passwort ändern</button></div>
  </form>
</section>

<p class="small muted">Angemeldet als <strong><?= e($user['username']) ?></strong> (<?= $user['role'] === 'admin' ? 'Admin' : 'Lehrkraft' ?>).</p>
