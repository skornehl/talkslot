<?php
/**
 * Deleting several bookings of a day at once (AdminController::dayBookingsForm()): the bookings of the appointments
 * within $from–$to, each with a box, ticked except the teacher's own entries. @var array $round @var array $day @var string $from @var string $to
 * (as entered if invalid) @var list<string> $errors @var list<array> $bookings BookingService::DETAIL_SQL rows plus
 * 'mailable' @var int $mailable of them, how many parents could get a cancellation
 */
use App\Csrf;
$rid = (int) $round['id'];
$did = (int) $day['id'];
$back = url('/admin/round/' . $rid) . '#day-' . $did;
$time = static fn (string $t): string => ($n = App\Time::normalizeTime($t)) !== null ? fmt_time($n) : $t;
$range = ['date' => fmt_date($day['day_date']), 'from' => $time($from), 'to' => $time($to)];
?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>"><?= e(__('Runden')) ?></a> › <a href="<?= e($back) ?>"><?= e($round['title']) ?></a></p>
<h1><?= e(__('Mehrere Buchungen löschen')) ?></h1>
<p><?= e(__('Zum Aufräumen, z. B. wenn jemand Termine mit Fantasienamen belegt hat. Danach sehen Sie die Buchungen des Zeitraums und wählen aus, welche gelöscht werden.')) ?></p>
<?php require __DIR__ . '/_errors.php'; ?>
<form method="get" action="<?= e(url('/admin/day/' . $did . '/bookings')) ?>" class="inline-form">
  <span><?= e(fmt_date($day['day_date'])) ?></span>
  <label><?= e(__('Von')) ?> <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="from" required value="<?= e($range['from']) ?>"></label>
  <label><?= e(__('Bis')) ?> <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="to" required value="<?= e($range['to']) ?>"></label>
  <button type="submit" class="button button-small"><?= e(__('Buchungen anzeigen')) ?></button>
</form>
<?php if ($errors): ?>
  <p><a class="button" href="<?= e($back) ?>"><?= e(__('Zurück zur Runde')) ?></a></p>
<?php elseif (!$bookings): ?>
  <p class="alert alert-info"><?= e(__('Am {date} gibt es zwischen {from} und {to} Uhr keine Buchungen.', $range)) ?></p>
  <p><a class="button" href="<?= e($back) ?>"><?= e(__('Zurück zur Runde')) ?></a></p>
<?php else: ?>
  <p><?= e(__('Buchungen am {date} zwischen {from} und {to} Uhr. Gelöscht werden die angehakten; entfernen Sie den Haken bei Einträgen, die echt sind. Ihre eigenen Einträge sind nicht angehakt.', $range)) ?></p>
  <?php
  $question = __('Die angehakten Buchungen endgültig löschen? Die Slots werden wieder frei.');
  $note = $mailable > 0 ? "\n\n" . __('Die Eltern mit E-Mail-Adresse erhalten eine Absage per E-Mail.') : '';
  ?>
  <form method="post" action="<?= e(url('/admin/day/' . $did . '/bookings/delete')) ?>" class="card" data-confirm="<?= e($question . $note) ?>" data-confirm-off="<?= e($question . "\n\n" . __('Die Eltern werden NICHT automatisch benachrichtigt – bitte selbst informieren.')) ?>">
    <?= Csrf::adminField() ?>
    <div class="table-wrap">
    <table class="table">
      <caption class="visually-hidden"><?= e(__('Buchungen am {date} zwischen {from} und {to} Uhr', $range)) ?></caption>
      <thead><tr><th scope="col"><span class="visually-hidden"><?= e(__('Löschen')) ?></span></th><th scope="col"><?= e(__('Zeit')) ?></th><th scope="col"><?= e(__('Eintrag')) ?></th><th scope="col"><?= e(__('Gebucht am')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($bookings as $b): $bid = (int) $b['id']; ?>
        <tr>
          <td><input type="checkbox" name="booking[]" value="<?= $bid ?>" id="booking-<?= $bid ?>" aria-labelledby="time-<?= $bid ?> name-<?= $bid ?>"<?= $b['source'] === 'admin' ? '' : ' checked' ?>></td>
          <td class="nowrap"><label for="booking-<?= $bid ?>" id="time-<?= $bid ?>"><?= e(fmt_range($b['start_time'], $b['end_time'])) ?></label></td>
          <td><strong id="name-<?= $bid ?>"><?= e($b['name']) ?></strong><?php if ((string) $b['email'] !== ''): ?><br><span class="small"><?= e($b['email']) ?></span><?php endif; ?>
            <?php if ($b['source'] === 'admin'): ?> <span class="badge"><?= e(__('selbst eingetragen')) ?></span><?php endif; ?></td>
          <td class="small nowrap"><?= e(date('d.m.Y H:i', (int) strtotime((string) $b['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($mailable > 0): ?>
    <div class="check">
      <input type="checkbox" id="notify" name="notify" value="1">
      <label for="notify"><?= e(__('Eltern per E-Mail informieren')) ?></label>
    </div>
    <p class="hint"><?= e(__('Bei Einträgen mit Fantasienamen besser nicht anhaken: Sonst gehen Absagen an Adressen, die sich jemand ausgedacht oder von anderen genommen hat.')) ?></p>
    <?php endif; ?>
    <div class="actions">
      <button type="submit" class="button button-danger"><?= e(__('Angehakte Buchungen löschen')) ?></button>
      <a class="button" href="<?= e($back) ?>"><?= e(__('Abbrechen')) ?></a>
    </div>
  </form>
<?php endif; ?>
