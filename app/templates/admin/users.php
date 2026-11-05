<?php /** @var array $users @var int $me */ use App\Csrf; ?>
<div class="page-head">
  <h1><?= e(__('Benutzer')) ?></h1>
  <a class="button button-primary" href="<?= e(url('/admin/users/new')) ?>"><?= e(__('+ Neuer Benutzer')) ?></a>
</div>
<p class="muted small"><?= e(__('Neue Konten erhalten eine Einladung per E-Mail und legen ihr Passwort selbst fest. Lehrkräfte sehen und verwalten nur ihre eigenen Terminrunden. Admins sehen und bearbeiten alle Runden samt Buchungen, können Runden für Lehrkräfte anlegen und verwalten Benutzer sowie Impressum und Datenschutz. Es kann mehrere Admins geben.')) ?></p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col"><?= e(__('Name')) ?></th><th scope="col"><?= e(__('Benutzername')) ?></th><th scope="col"><?= e(__('Rolle')) ?></th><th scope="col"><?= e(__('Runden')) ?></th><th scope="col"><?= e(__('Letzter Login')) ?></th><th scope="col"><?= e(__('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(__('Aktionen')) ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= e($u['display_name']) ?></strong><?php if ($u['email']): ?><br><span class="small"><?= e($u['email']) ?></span><?php endif; ?></td>
      <td><?= e($u['username']) ?><?= (int) $u['id'] === $me ? ' <span class="badge">' . e(__('Sie')) . '</span>' : '' ?></td>
      <td><?= $u['role'] === 'admin' ? 'Admin' : e(__('Lehrkraft')) ?></td>
      <td><?= (int) $u['round_count'] ?></td>
      <td class="small"><?= $u['never_signed_in'] ? '<span class="muted">' . e(__('nie')) . '</span>' : ($u['long_inactive'] ? '<strong>' . e(__('vor über 12 Monaten')) . '</strong>' : e(__('in den letzten 12 Monaten'))) ?></td>
      <td>
        <?php if (!$u['is_active']): ?><span class="status status-off"><?= e(__('deaktiviert')) ?></span>
        <?php elseif ($u['never_signed_in'] && $u['invite_open']): ?><span class="status status-off"><?= e(__('Einladung offen')) ?></span>
        <?php elseif ($u['never_signed_in']): ?><span class="status status-off"><?= e(__('Einladung abgelaufen')) ?></span>
        <?php elseif ($u['must_change_password']): ?><span class="status status-off"><?= e(__('Passwortwechsel offen')) ?></span>
        <?php else: ?><span class="status status-on"><?= e(__('aktiv')) ?></span><?php endif; ?>
      </td>
      <td class="row-actions"><a class="button button-small" href="<?= e(url('/admin/users/' . (int) $u['id'] . '/edit')) ?>"><?= e(__('Bearbeiten')) ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<section class="card">
  <h2><?= e(__('Alle Sitzungen beenden')) ?></h2>
  <p><?= e(__('Meldet alle Konten auf allen Geräten ab, nur diese Sitzung bleibt bestehen. Für den Notfall, z. B. wenn ein Gerät verloren gegangen ist oder Sie einen Missbrauch vermuten. Die Passwörter bleiben unverändert; alle Admins erhalten eine E-Mail.')) ?></p>
  <form method="post" action="<?= e(url('/admin/users/end-sessions')) ?>" class="form-grid" data-confirm="<?= e(__('Alle Konten auf allen Geräten abmelden?')) ?>">
    <?= Csrf::adminField() ?>
    <div class="field">
      <label for="end_sessions_password"><?= e(__('Ihr Passwort (zur Bestätigung)')) ?></label>
      <input type="password" id="end_sessions_password" name="current_password" required autocomplete="current-password">
    </div>
    <div class="actions"><button type="submit" class="button button-danger"><?= e(__('Alle Sitzungen beenden')) ?></button></div>
  </form>
</section>
