<?php /** @var array $rounds @var list<string> $profileHints @var list<string> $legalHints @var bool $showOwner @var bool $operatorMissing */ ?>
<?php foreach ($legalHints as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?> <a href="<?= e(url('/admin/texts')) ?>">Zu den Texten</a></p></div>
<?php endforeach; ?>
<?php if ($operatorMissing && !App\Auth::isAdmin()): ?>
  <div class="alert alert-warning" role="status"><p>Es sind noch keine Angaben für Impressum und Datenschutz hinterlegt (Aufgabe der Admins). Bis dahin sind keine Terminrunden öffentlich erreichbar.</p></div>
<?php endif; ?>
<?php if ($profileHints): ?>
  <div class="alert alert-warning" role="status">
    <p><strong>Bitte ergänzen Sie <a href="<?= e(url('/admin/profile')) ?>">Ihr Profil</a>:</strong></p>
    <ul><?php foreach ($profileHints as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<div class="page-head">
  <h1><?= e($title) ?></h1>
  <a class="button button-primary" href="<?= e(url('/admin/round/new')) ?>">+ Neue Runde</a>
</div>

<?php if (!$rounds): ?>
  <p class="alert alert-info">Noch keine Runden angelegt. Starten Sie mit „Neue Runde“.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead>
    <tr><th scope="col">Runde</th><?php if ($showOwner): ?><th scope="col">Lehrkraft</th><?php endif; ?><th scope="col">Zeitraum</th><th scope="col">Buchungen</th><th scope="col">Status</th><th scope="col">Link</th></tr>
  </thead>
  <tbody>
  <?php foreach ($rounds as $r): $link = abs_url('/' . $r['slug']); ?>
    <tr>
      <td><a href="<?= e(url('/admin/round/' . (int) $r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a><?= App\RoundAccess::required($r) ? ' <span class="badge" title="nur mit Rundencode erreichbar">Code</span>' : '' ?></td>
      <?php if ($showOwner): ?><td><?= e($r['owner_name']) ?><?= !$r['owner_active'] ? ' <span class="badge">deaktiviert</span>' : '' ?></td><?php endif; ?>
      <td><?= $r['first_day'] ? e(date('d.m.Y', strtotime($r['first_day']))) . ($r['last_day'] !== $r['first_day'] ? ' – ' . e(date('d.m.Y', strtotime($r['last_day']))) : '') : '<span class="muted">keine Tage</span>' ?></td>
      <td><?= (int) $r['booking_count'] ?> / <?= (int) $r['slot_count'] ?></td>
      <td>
        <?php if ($r['is_active']): ?><span class="status status-on">aktiv</span>
        <?php elseif ($r['expired_at']): ?><span class="status status-off">abgelaufen</span>
        <?php else: ?><span class="status status-off">inaktiv</span><?php endif; ?>
      </td>
      <td><button type="button" class="button button-small" data-copy="<?= e($link) ?>">Link kopieren</button></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
