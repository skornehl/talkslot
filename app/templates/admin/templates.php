<?php /** Templates for new rounds. @var array $templates */ use App\Csrf; ?>
<div class="page-head">
  <h1>Vorlagen</h1>
  <a class="button button-primary" href="<?= e(url('/admin/templates/new')) ?>">+ Neue Vorlage</a>
</div>
<p class="muted small">Vorlagen füllen beim Anlegen einer neuen Runde Zeiten, Pausen und Einstellungen vor, z. B. „Elternsprechtag: 08:00–16:00 Uhr, 15 Minuten, Mittagspause“. Alle Konten können sie verwenden, nur Admins pflegen sie. Eine Vorlage lässt sich auch aus einer bestehenden Runde erstellen („Als Vorlage speichern“ auf der Seite der Runde). Änderungen an einer Vorlage wirken sich nicht auf bereits angelegte Runden aus.</p>
<?php if (!$templates): ?>
  <p class="alert">Noch keine Vorlagen.</p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Name</th><th scope="col">Inhalt</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $tp): $tid = (int) $tp['id']; ?>
    <tr>
      <td><strong><?= e($tp['name']) ?></strong></td>
      <td class="small"><?= e($tp['summary']) ?></td>
      <td class="row-actions">
        <a class="button button-small" href="<?= e(url('/admin/round/new', ['template' => $tid])) ?>">Runde anlegen</a>
        <a class="button button-small" href="<?= e(url('/admin/templates/' . $tid . '/edit')) ?>">Bearbeiten</a>
        <form method="post" action="<?= e(url('/admin/templates/' . $tid . '/delete')) ?>" class="inline" data-confirm="Vorlage „<?= e($tp['name']) ?>“ löschen? Bereits angelegte Runden bleiben unverändert."><?= Csrf::adminField() ?>
          <button type="submit" class="button button-small button-danger">Löschen</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
