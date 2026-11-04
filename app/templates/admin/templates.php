<?php /** Templates for new rounds. @var array $templates */ use App\Csrf; ?>
<div class="page-head">
  <h1><?= e(__('Vorlagen')) ?></h1>
  <a class="button button-primary" href="<?= e(url('/admin/templates/new')) ?>"><?= e(__('+ Neue Vorlage')) ?></a>
</div>
<p class="muted small"><?= e(__('Vorlagen füllen beim Anlegen einer neuen Runde Zeiten, Pausen und Einstellungen vor, z. B. „Elternsprechtag: 08:00–16:00 Uhr, 15 Minuten, Mittagspause“. Alle Konten können sie verwenden, nur Admins pflegen sie. Eine Vorlage lässt sich auch aus einer bestehenden Runde erstellen („Als Vorlage speichern“ auf der Seite der Runde). Änderungen an einer Vorlage wirken sich nicht auf bereits angelegte Runden aus.')) ?></p>
<?php if (!$templates): ?>
  <p class="alert"><?= e(__('Noch keine Vorlagen.')) ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col"><?= e(__('Name')) ?></th><th scope="col"><?= e(__('Inhalt')) ?></th><th scope="col"><span class="visually-hidden"><?= e(__('Aktionen')) ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $tp): $tid = (int) $tp['id']; ?>
    <tr>
      <td><strong><?= e($tp['name']) ?></strong></td>
      <td class="small"><?= e($tp['summary']) ?></td>
      <td class="row-actions">
        <a class="button button-small" href="<?= e(url('/admin/round/new', ['template' => $tid])) ?>"><?= e(__('Runde anlegen')) ?></a>
        <a class="button button-small" href="<?= e(url('/admin/templates/' . $tid . '/edit')) ?>"><?= e(__('Bearbeiten')) ?></a>
        <form method="post" action="<?= e(url('/admin/templates/' . $tid . '/delete')) ?>" class="inline" data-confirm="<?= e(__('Vorlage „{name}“ löschen? Bereits angelegte Runden bleiben unverändert.', ['name' => $tp['name']])) ?>"><?= Csrf::adminField() ?>
          <button type="submit" class="button button-small button-danger"><?= e(__('Löschen')) ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
