<?php /** @var array $users @var int $me */ ?>
<div class="page-head">
  <h1>Benutzer</h1>
  <a class="button button-primary" href="<?= e(url('/admin/users/new')) ?>">+ Neuer Benutzer</a>
</div>
<p class="muted small">Neue Konten erhalten eine Einladung per E-Mail und legen ihr Passwort selbst fest. Lehrkräfte sehen und verwalten nur ihre eigenen Terminrunden. Als Admin sehen und bearbeiten Sie alle Runden samt Buchungen und können Runden für Lehrkräfte anlegen. Es gibt genau ein Admin-Konto.</p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Name</th><th scope="col">Benutzername</th><th scope="col">Rolle</th><th scope="col">Runden</th><th scope="col">Letzter Login</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><strong><?= e($u['display_name']) ?></strong><?php if ($u['email']): ?><br><span class="small"><?= e($u['email']) ?></span><?php endif; ?></td>
      <td><?= e($u['username']) ?><?= (int) $u['id'] === $me ? ' <span class="badge">Sie</span>' : '' ?></td>
      <td><?= $u['role'] === 'admin' ? 'Admin' : 'Lehrkraft' ?></td>
      <td><?= (int) $u['round_count'] ?></td>
      <td class="small nowrap"><?= $u['last_login_at'] ? e(date('d.m.Y H:i', strtotime($u['last_login_at']))) : '<span class="muted">nie</span>' ?></td>
      <td>
        <?php if (!$u['is_active']): ?><span class="status status-off">deaktiviert</span>
        <?php elseif ($u['last_login_at'] === null && $u['access_token_expires_at'] !== null && strtotime($u['access_token_expires_at']) > time()): ?><span class="status status-off">Einladung offen</span>
        <?php elseif ($u['last_login_at'] === null): ?><span class="status status-off">Einladung abgelaufen</span>
        <?php elseif ($u['must_change_password']): ?><span class="status status-off">Passwortwechsel offen</span>
        <?php else: ?><span class="status status-on">aktiv</span><?php endif; ?>
      </td>
      <td class="row-actions"><a class="button button-small" href="<?= e(url('/admin/users/' . (int) $u['id'] . '/edit')) ?>">Bearbeiten</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
