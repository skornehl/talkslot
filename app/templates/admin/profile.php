<?php /** @var array $user @var array $data @var array $errors @var string $generalContact the contact for parents set for all ('' = none) @var array $security own security events, previous sign-in, failed attempts since */ use App\Csrf; ?>
<h1><?= e(__('Mein Profil')) ?></h1>
<?php require __DIR__ . '/_errors.php'; ?>

<section class="card">
  <h2><?= e(__('Angaben')) ?></h2>
  <form method="post" action="<?= e(url('/admin/profile')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <div class="field">
      <label for="display_name"><?= e(__('Anzeigename')) ?></label>
      <input type="text" id="display_name" name="display_name" required maxlength="200" value="<?= e($data['display_name']) ?>" aria-describedby="dn-hint">
      <p class="hint" id="dn-hint"><?= e(__('So sehen Eltern Sie auf der Buchungsseite und in E-Mails, z. B. „Frau Muster“ oder „Sabrina Muster“.')) ?></p>
    </div>
    <?php if (App\Auth::isAdmin()): ?>
    <p class="hint"><?= __h('Die Angaben für Impressum und Datenschutz (die Schule bzw. der Betreiber) pflegen alle Admins gemeinsam unter {link}.', ['link' => '<a href="' . e(url('/admin/texts')) . '">' . e(__('„Texte“')) . '</a>']) ?></p>
    <?php endif; ?>
    <?php $domains = App\AccountDomains::allowed(); ?>
    <div class="field">
      <label for="email"><?= e(__('E-Mail-Adresse')) ?></label>
      <input type="email" id="email" name="email" maxlength="254" value="<?= e($data['email'] ?? '') ?>" aria-describedby="em-hint">
      <p class="hint" id="em-hint"><?= e(__('Für Ihre Zusammenfassungen und Links zum Zurücksetzen des Passworts. Solange Sie keine Antwortadresse für Eltern angeben, gehen auch die Antworten der Eltern auf E-Mails zu ihrem Termin an diese Adresse; Eltern sehen sie dann, sobald sie antworten.')) ?><?= $domains ? ' ' . e(__('Die Schule erlaubt nur Adressen bei {domains}.', ['domains' => implode(', ', $domains)])) : '' ?></p>
    </div>
    <div class="field">
      <label for="reply_to"><?= e(__('Antwortadresse für Eltern')) ?> <span class="muted small"><?= e(__('(optional)')) ?></span></label>
      <input type="email" id="reply_to" name="reply_to" maxlength="254" value="<?= e($data['reply_to'] ?? '') ?>" aria-describedby="rt-hint">
      <p class="hint" id="rt-hint"><?= e(__('Antworten der Eltern auf E-Mails zu ihrem Termin gehen an diese Adresse statt an Ihre E-Mail-Adresse, z. B. an Ihre dienstliche Adresse. Eltern sehen sie, sobald sie antworten.')) ?></p>
    </div>
    <div class="field">
      <label for="current_password_email"><?= e(__('Aktuelles Passwort')) ?> <span class="muted small"><?= e(__('(nur nötig, wenn Sie eine der beiden Adressen ändern)')) ?></span></label>
      <input type="password" id="current_password_email" name="current_password" autocomplete="current-password">
    </div>
    <div class="field">
      <label for="parent_contact"><?= e(__('Kontakt für Eltern bei Fragen')) ?> <span class="muted small"><?= e(__('(optional)')) ?></span></label>
      <input type="text" id="parent_contact" name="parent_contact" maxlength="<?= App\ParentContact::MAX_LENGTH ?>" value="<?= e($data['parent_contact'] ?? '') ?>" placeholder="<?= e(__('z. B. Sekretariat, Telefon 01234 5678, montags bis freitags 8 bis 12 Uhr')) ?>" aria-describedby="pc-hint">
      <p class="hint" id="pc-hint"><?= e(__('Eltern sehen diesen Text auf der Buchungsseite Ihrer Runden und bei ihrem Termin, z. B. wenn alle Termine vergeben sind. Jeder mit dem Link kann ihn lesen, bitte keine private Telefonnummer. Er wird nicht übersetzt.')) ?>
        <?= e(($generalContact ?? '') !== '' ? __('Ohne Eintrag sehen Eltern den allgemeinen Kontakt: {contact}', ['contact' => $generalContact]) : __('Ohne Eintrag sehen Eltern keinen Kontakt.')) ?></p>
    </div>
    <div class="field">
      <label for="lang">Sprache / Language</label>
      <select id="lang" name="lang">
        <?php foreach (['de' => 'Deutsch', 'en' => 'English'] as $code => $name): ?>
          <option value="<?= $code ?>"<?= ($data['lang'] ?? App\AdminI18n::default()) === $code ? ' selected' : '' ?>><?= $name ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <fieldset class="field pin-fieldset">
      <legend><?= e(__('Zusammenfassungen per E-Mail')) ?></legend>
      <div class="check">
        <input type="checkbox" id="summary_evening" name="summary_evening" value="1"<?= !empty($data['summary_evening']) ? ' checked' : '' ?>>
        <label for="summary_evening"><strong><?= e(__('Tagesübersicht am Vorabend:')) ?></strong> <?= e(__('Am Abend vor einem Termintag die Termine des nächsten Tages.')) ?></label>
      </div>
      <div class="check">
        <input type="checkbox" id="summary_deadline" name="summary_deadline" value="1"<?= !empty($data['summary_deadline']) ? ' checked' : '' ?>>
        <label for="summary_deadline"><strong><?= e(__('Übersicht nach Buchungsschluss:')) ?></strong> <?= e(__('Sobald für einen Tag keine Buchungen mehr möglich sind, die endgültige Terminliste dieses Tages.')) ?></label>
      </div>
      <p class="hint"><?= e(__('Die E-Mails nennen je Tag die Zahl der Termine, Auffälliges (z. B. denselben Namen mehrmals) und den Link zur Terminliste, aber nie E-Mail-Adressen der Eltern.')) ?></p>
      <?php if (App\Config::get('summary_names', false)): ?>
      <div class="check">
        <input type="checkbox" id="summary_names" name="summary_names" value="1"<?= App\Summaries::wantsNames($data) ? ' checked' : '' ?>>
        <label for="summary_names"><strong><?= e(__('Mit Namen:')) ?></strong> <?= e(__('zusätzlich Uhrzeit und Name jedes Termins und eine Kalenderdatei der Termine (ohne Namen). Ihre Schule hat das freigegeben. Bitte löschen Sie diese E-Mails, sobald Sie sie nicht mehr brauchen, spätestens zum Ende des Schuljahres.')) ?></label>
      </div>
      <?php endif; ?>
    </fieldset>
    <div class="actions"><button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button></div>
  </form>
</section>

<section class="card">
  <h2><?= e(__('Passwort ändern')) ?></h2>
  <form method="post" action="<?= e(url('/admin/password')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <?php require __DIR__ . '/_password_fields.php'; ?>
    <div class="actions"><button type="submit" class="button button-primary"><?= e(__('Passwort ändern')) ?></button></div>
  </form>
</section>

<?php /* Expert review P2-9: what happened to this account, for its holder only (SecurityEvents), no IP */ ?>
<section class="card" id="security">
  <h2><?= e(__('Sicherheit')) ?></h2>
  <p><?= e($security['previous'] !== null
      ? __('Ihre vorige Anmeldung: {date}. Fehlgeschlagene Anmeldeversuche seitdem: {count}.', ['date' => date('d.m.Y H:i', (int) strtotime((string) $security['previous'])), 'count' => $security['failed']])
      : __('Fehlgeschlagene Anmeldeversuche seit Ihrer ersten Anmeldung: {count}.', ['count' => $security['failed']])) ?></p>
  <?php if ($security['events']): ?>
  <div class="table-wrap">
  <table class="table">
    <caption><?= e(__('Sicherheitsereignisse der letzten {days} Tage', ['days' => $security['days']])) ?></caption>
    <thead><tr><th scope="col"><?= e(__('Zeitpunkt')) ?></th><th scope="col"><?= e(__('Ereignis')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($security['events'] as $ev): ?>
      <tr><td class="small nowrap"><?= e(date('d.m.Y H:i', (int) strtotime((string) $ev['created_at']))) ?></td><td><?= e(App\SecurityEvents::label((string) $ev['event'], (bool) $ev['by_admin'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <p class="muted"><?= e(__('Keine Sicherheitsereignisse in den letzten {days} Tagen.', ['days' => $security['days']])) ?></p>
  <?php endif; ?>
  <p class="small muted"><?= e(__('Erfasst werden Änderungen an Passwort, E-Mail-Adresse, Benutzername und Admin-Rechten sowie ein erreichtes Anmeldelimit, ohne IP-Adresse oder Gerät. Das sehen nur Sie, auch Admins nicht. Einträge werden nach {days} Tagen gelöscht.', ['days' => $security['days']])) ?></p>
</section>

<p class="small muted"><?= __h('Angemeldet als {username} ({role}).', ['username' => '<strong>' . e($user['username']) . '</strong>', 'role' => $user['role'] === 'admin' ? 'Admin' : e(__('Lehrkraft'))]) ?></p>
