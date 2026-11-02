<?php /** Formular „Tage hinzufügen“. @var array $days @var int $rid @var string $csrf @var array $addDayData @var array $addDayErrors */ ?>
<section class="card" id="add-day">
  <h2><?= $days ? 'Weitere Tage hinzufügen' : 'Termine anlegen' ?></h2>
  <?php if (!$days): ?><p>Diese Runde hat noch keine Termine. Legen Sie fest, wann die Gespräche stattfinden.</p><?php endif; ?>
  <?php $errors = $addDayErrors; require __DIR__ . '/_errors.php'; ?>
  <form method="post" action="<?= e(url('/admin/round/' . $rid . '/day')) ?>" class="form-grid"><?= $csrf ?>
    <?php $dayData = $addDayData; require __DIR__ . '/_day_fields.php'; ?>
    <div class="actions"><button type="submit" class="button button-primary">Tag(e) anlegen</button></div>
  </form>
</section>
