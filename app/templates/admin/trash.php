<?php /** Rounds without bookings that were deleted (App\RoundTrash). @var list<array> $rows @var bool $showOwner @var int $days */ use App\Csrf; ?>
<h1><?= e(__('Papierkorb')) ?></h1>
<p><?= e(__('Gelöschte Runden ohne Buchungen bleiben {days} Tage hier und lassen sich mit ihrer Adresse und ihrem Rundencode wiederherstellen. Runden mit Buchungen werden sofort endgültig gelöscht.', ['days' => $days])) ?></p>
<?php if (!$rows): ?>
  <p class="alert" data-trash-empty><?= e(__('Der Papierkorb ist leer.')) ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr>
    <th scope="col"><?= e(__('Runde')) ?></th>
    <?php if ($showOwner): ?><th scope="col"><?= e(__('Lehrkraft')) ?></th><?php endif; ?>
    <th scope="col"><?= e(__('Tage')) ?></th><th scope="col"><?= e(__('Gelöscht am')) ?></th><th scope="col"><?= e(__('Bleibt bis')) ?></th><th scope="col"><span class="visually-hidden"><?= e(__('Aktionen')) ?></span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-trash-entry>
      <td><?= e($r['title']) ?></td>
      <?php if ($showOwner): ?><td><?= e($r['owner']) ?></td><?php endif; ?>
      <td><?= (int) $r['day_count'] ?></td>
      <td class="small nowrap"><?= e(fmt_date(substr((string) $r['deleted_at'], 0, 10))) ?></td>
      <td class="small nowrap"><?= e(fmt_date((string) $r['until'])) ?></td>
      <td class="actions">
        <form method="post" action="<?= e(url('/admin/trash/' . (int) $r['id'] . '/restore')) ?>" class="inline"><?= Csrf::adminField() ?><button type="submit" class="button"><?= e(__('Wiederherstellen')) ?></button></form>
        <form method="post" action="<?= e(url('/admin/trash/' . (int) $r['id'] . '/delete')) ?>" class="inline" data-confirm="<?= e(__('Runde „{title}“ endgültig löschen? Das kann nicht rückgängig gemacht werden.', ['title' => $r['title']])) ?>"><?= Csrf::adminField() ?><button type="submit" class="button-link"><?= e(__('Endgültig löschen')) ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
