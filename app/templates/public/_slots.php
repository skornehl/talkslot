<?php
/**
 * Slot selection as radio buttons, grouped by day.
 * @var list<array{date:string,slots:list<array>}> $days
 * @var string $selected
 * data-when: the slot with its date (and location), for the chosen appointment above the booking button
 */
?>
<?php foreach ($days as $day): ?>
<fieldset class="day">
  <legend><?= e(fmt_date($day['date'])) ?><?php if (trim((string) ($day['location'] ?? '')) !== ''): ?> · <?= e(t('round.location', ['location' => trim((string) $day['location'])])) ?><?php endif; ?></legend>
  <div class="slot-grid">
    <?php foreach ($day['slots'] as $s): $sid = 'slot-' . (int) $s['id']; ?>
    <div class="slot">
      <input type="radio" name="slot_id" id="<?= $sid ?>" value="<?= (int) $s['id'] ?>" required<?= (string) $selected === (string) $s['id'] ? ' checked' : '' ?> data-when="<?= e(slot_label($day, $s, $round ?? [])) ?>">
      <label for="<?= $sid ?>"><?= e(fmt_range($s['start_time'], $s['end_time'])) ?><span class="visually-hidden"><?= e(t('time_suffix')) ?>, <?= e(fmt_date($day['date'])) ?></span></label>
    </div>
    <?php endforeach; ?>
  </div>
</fieldset>
<?php endforeach; ?>
