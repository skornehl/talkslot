<?php
/** Print view (standalone page, can be saved as PDF in the browser). @var array $round @var array $days @var bool $showEmail @var int $dayFilter */
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($round['title']) ?> – Terminliste</title>
<link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="print-page">
<div class="no-print toolbar">
  <button type="button" data-print>Drucken / als PDF speichern</button>
  <a href="<?= e(url('/admin/round/' . (int) $round['id'] . '/print', ['day' => $dayFilter ?: null, 'email' => $showEmail ? null : '1'])) ?>"><?= $showEmail ? 'E-Mail-Adressen ausblenden' : 'E-Mail-Adressen einblenden' ?></a>
  <a href="<?= e(url('/admin/round/' . (int) $round['id'])) ?>">Zurück</a>
</div>

<?php foreach ($days as $i => $day): ?>
<section class="print-day<?= $i > 0 ? ' page-break' : '' ?>">
  <h1><?= e($round['title']) ?></h1>
  <h2><?= e(fmt_date($day['day_date'])) ?> · <?= e(fmt_range($day['start_time'], $day['end_time'])) ?> Uhr</h2>
  <table>
    <thead><tr><th class="col-time">Zeit</th><th>Name</th><?php if ($showEmail): ?><th>E-Mail</th><?php endif; ?><th class="col-notes">Notizen</th></tr></thead>
    <tbody>
    <?php foreach ($day['rows'] as $r): ?>
      <?php if (isset($r['break'])): ?>
        <tr class="row-break"><td><?= e(fmt_range($r['start_time'], $r['end_time'])) ?></td><td colspan="<?= $showEmail ? 3 : 2 ?>"><?= e($r['break']) ?></td></tr>
      <?php else: ?>
        <tr<?= $r['name'] ? '' : ' class="row-free"' ?>>
          <td><?= e(fmt_range($r['start_time'], $r['end_time'])) ?></td>
          <td><?= $r['name'] ? e($r['name']) : ($r['is_blocked'] ? 'gesperrt' : '–') ?><?= $r['is_conflict'] ? ' ⚠' : '' ?></td>
          <?php if ($showEmail): ?><td><?= e($r['email'] ?? '') ?></td><?php endif; ?>
          <td></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="print-foot">Stand: <?= e(date('d.m.Y H:i')) ?> Uhr · Vertraulich – nach dem Termin vernichten.</p>
</section>
<?php endforeach; ?>
<?php if (!$days): ?><p>Keine Tage vorhanden.</p><?php endif; ?>
</body>
</html>
