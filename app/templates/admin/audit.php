<?php /** Log of the changes made by admins. @var array $rows @var int $older @var bool $paged @var int $days */ ?>
<h1>Protokoll</h1>
<p class="muted small">Änderungen durch Admins: wer wann welche Runde, welches Konto oder welchen Text geändert hat. Änderungen von Lehrkräften an ihren eigenen Runden werden nicht erfasst, Daten von Eltern (Namen, E-Mail-Adressen) nie. Einträge werden nach <?= (int) $days ?> Tagen gelöscht.</p>
<?php if (!$rows): ?>
  <p class="alert"><?= $paged ? 'Keine weiteren Einträge.' : 'Noch keine Einträge.' ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Zeitpunkt</th><th scope="col">Wer</th><th scope="col">Was</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="small nowrap"><?= e(date('d.m.Y H:i', (int) strtotime($r['created_at']))) ?></td>
      <td><?= e($r['actor']) ?><?= $r['user_id'] === null ? ' <span class="small muted">(gelöscht)</span>' : '' ?></td>
      <td><?= e($r['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php if ($paged || $older): ?>
<p class="actions">
  <?php if ($paged): ?><a class="button" href="<?= e(url('/admin/log')) ?>">Zu den neuesten Einträgen</a><?php endif; ?>
  <?php if ($older): ?><a class="button" href="<?= e(url('/admin/log', ['before' => $older])) ?>">Ältere Einträge</a><?php endif; ?>
</p>
<?php endif; ?>
