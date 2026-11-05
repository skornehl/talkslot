<?php
/**
 * "Bereit für den Start?" (expert review P2-13): the setup as a checklist, each point with its state in words.
 * @var list<array{key: string, state: string, title: string, text: string, details: list<string>, link: ?string, linkText: string}> $items
 * @var bool $started a round has been public already
 */
use App\Readiness;
$icons = [Readiness::DONE => '✓', Readiness::CHECK => '!', Readiness::TODO => '✗'];
?>
<h1><?= e($title) ?></h1>
<p><?= e(__('Diese Übersicht zeigt, ob alles für die Terminvergabe bereit ist: {done} von {total} Punkten erledigt.', ['done' => Readiness::done($items), 'total' => count($items)])) ?>
  <?= e($started ? __('Ihre Terminvergabe ist bereits in Betrieb; Warnungen erscheinen weiterhin auch auf der Übersicht.') : __('Bis die erste Runde aktiv war, fasst die Übersicht der Runden diese Punkte zusammen.')) ?></p>
<ul class="readiness" data-readiness>
  <?php foreach ($items as $item): ?>
  <li class="card readiness-item readiness-<?= e($item['state']) ?>" data-check="<?= e($item['key']) ?>" data-state="<?= e($item['state']) ?>">
    <h2><span class="readiness-state"><span aria-hidden="true"><?= $icons[$item['state']] ?? '' ?></span> <?= e(Readiness::stateLabel($item['state'])) ?>:</span> <?= e($item['title']) ?></h2>
    <p><?= e($item['text']) ?></p>
    <?php if ($item['details']): ?><ul><?php foreach ($item['details'] as $detail): ?><li><?= e($detail) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($item['link'] !== null): ?><p><a href="<?= e($item['link']) ?>"><?= e($item['linkText']) ?></a></p><?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
