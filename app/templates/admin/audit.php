<?php /** Log of the changes made by admins. @var array $rows @var int $older @var bool $paged @var int $days */ ?>
<h1><?= e(__('Protokoll')) ?></h1>
<p class="muted small"><?= e(__('Änderungen durch Admins: wer wann welche Runde, welches Konto oder welchen Text geändert hat. Änderungen von Lehrkräften an ihren eigenen Runden werden nicht erfasst, Daten von Eltern (Namen, E-Mail-Adressen) nie. Auch Suchen zu Auskunftsanfragen stehen hier, ohne die Adresse. Einträge werden nach {days} Tagen gelöscht.', ['days' => (int) $days])) ?></p>
<p><a href="<?= e(url('/admin/access-request')) ?>"><?= e(__('Auskunft zu einer E-Mail-Adresse (Art. 15 DSGVO)')) ?></a></p>
<?php if (!$rows): ?>
  <p class="alert"><?= e($paged ? __('Keine weiteren Einträge.') : __('Noch keine Einträge.')) ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col"><?= e(__('Zeitpunkt')) ?></th><th scope="col"><?= e(__('Wer')) ?></th><th scope="col"><?= e(__('Was')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="small nowrap"><?= e(date('d.m.Y H:i', (int) strtotime($r['created_at']))) ?></td>
      <td><?= e($r['actor']) ?><?= $r['user_id'] === null && $r['actor'] !== App\Audit::SYSTEM ? ' <span class="small muted">' . e(__('(gelöscht)')) . '</span>' : '' ?></td>
      <td><?= e($r['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php if ($paged || $older): ?>
<p class="actions">
  <?php if ($paged): ?><a class="button" href="<?= e(url('/admin/log')) ?>"><?= e(__('Zu den neuesten Einträgen')) ?></a><?php endif; ?>
  <?php if ($older): ?><a class="button" href="<?= e(url('/admin/log', ['before' => $older])) ?>"><?= e(__('Ältere Einträge')) ?></a><?php endif; ?>
</p>
<?php endif; ?>
