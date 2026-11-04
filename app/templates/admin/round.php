<?php
/** Overview of a round: days, breaks, slots, bookings. @var array $round @var array $days @var int $conflicts @var string $publicUrl */
use App\Csrf;
$rid = (int) $round['id'];
$csrf = Csrf::adminField();
?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>">Runden</a> › <?= e($round['title']) ?></p>
<div class="page-head">
  <h1><?= e($round['title']) ?></h1>
  <?php if ($showOwner): ?><span class="badge" title="Lehrkraft der Runde">Lehrkraft: <?= e($owner['display_name'] !== '' ? $owner['display_name'] : $owner['username']) ?><?= !$owner['is_active'] ? ' (deaktiviert – Runde öffentlich nicht erreichbar)' : '' ?></span><?php endif; ?>
  <?php if ($round['is_active']): ?><span class="status status-on">aktiv</span><?php else: ?><span class="status status-off"><?= $round['expired_at'] ? 'abgelaufen' : 'inaktiv' ?></span><?php endif; ?>
</div>

<div class="card">
  <p class="small muted">Link für die Eltern</p>
  <div class="copy-row">
    <input type="text" readonly class="link-field" value="<?= e($publicUrl) ?>" aria-label="Link für die Eltern">
    <button type="button" class="button" data-copy="<?= e($publicUrl) ?>">Link kopieren</button>
    <a class="button" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Öffnen</a>
  </div>
  <?php if ((string) ($round['code'] ?? '') !== ''): ?>
    <p>Rundencode: <strong class="pin-display" data-round-code><?= e(App\RoundCode::format($round['code'])) ?></strong>
      <span class="small muted">– Eltern können ihn statt des Links auf <?= e($homeUrl) ?> eingeben.</span></p>
  <?php endif; ?>
  <?php if (App\RoundAccess::required($round)): ?>
    <p class="small"><span class="badge">Nur mit Rundencode</span> Wer den Link öffnet, muss den Rundencode eingeben; über die Startseite reicht der Code allein.</p>
  <?php else: ?>
    <p class="small muted">Ohne Schutz – jeder mit dem Link sieht die freien Termine (Schutz: „Bearbeiten“ → „Nur mit Rundencode“).</p>
  <?php endif; ?>
  <div class="actions">
    <button type="button" class="button" data-copy="<?= e($invitation) ?>">Einladungstext kopieren</button>
    <a class="button" href="mailto:?subject=<?= e(rawurlencode($round['title'])) ?>&amp;body=<?= e(rawurlencode($invitation)) ?>">Als E-Mail öffnen</a>
  </div>
  <details class="small">
    <summary>Einladungstext ansehen</summary>
    <pre class="invitation"><?= e($invitation) ?></pre>
  </details>
  <p class="small muted"><?= ['round' => 'Ein Termin pro Person', 'day' => 'Ein Termin pro Person und Tag'][$round['booking_limit'] ?? 'none'] ?? 'Mehrfachbuchungen erlaubt' ?> · Buchungsschluss <?= (int) $round['booking_deadline_hours'] ?> h vorher · Ändern/Absagen bis <?= (int) $round['cancel_deadline_hours'] ?> h vorher ·
    <?= $round['delete_after_hours'] === null ? 'Runde wird nicht automatisch gelöscht' : 'Runde wird ' . (int) $round['delete_after_hours'] . ' h nach Ablauf gelöscht' ?></p>
  <div class="actions">
    <a class="button" href="<?= e(url('/admin/round/' . $rid . '/edit')) ?>">Bearbeiten</a>
    <a class="button" href="#add-day">+ Tag hinzufügen</a>
    <form method="post" action="<?= e(url('/admin/round/' . $rid . '/toggle')) ?>" class="inline"><?= $csrf ?>
      <button type="submit" class="button"><?= $round['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
    <a class="button" href="<?= e(url('/admin/round/' . $rid . '/print')) ?>" target="_blank" rel="noopener">Druckansicht / PDF</a>
    <a class="button" href="#duplicate" data-open-details="duplicate">Duplizieren …</a>
    <form method="post" action="<?= e(url('/admin/round/' . $rid . '/delete')) ?>" class="inline" data-confirm="Runde „<?= e($round['title']) ?>“ mit allen Tagen und Buchungen endgültig löschen?"><?= $csrf ?>
      <button type="submit" class="button button-danger">Löschen</button></form>
  </div>
  <details id="duplicate">
    <summary>Runde duplizieren</summary>
    <form method="post" action="<?= e(url('/admin/round/' . $rid . '/duplicate')) ?>" class="inline-form" data-once><?= $csrf ?>
      <div>
        <label for="first_date">Erster Tag der Kopie</label>
        <input type="date" id="first_date" name="first_date" aria-describedby="dup-hint">
      </div>
      <button type="submit" class="button">Duplizieren</button>
    </form>
    <p class="hint" id="dup-hint">Übernimmt Tage, Zeiten, Pausen, gesperrte Termine, Einstellungen und Übersetzungen, aber keine Buchungen; die Kopie ist inaktiv. Mit Datum verschieben sich alle Tage um denselben Abstand (z. B. Tage am Di und Do → wieder Di und Do, wenn der erste Tag ein Dienstag ist). Ohne Datum behält die Kopie die bisherigen Daten.</p>
  </details>
</div>

<?php if ($conflicts > 0): ?>
  <div class="alert alert-warning" role="alert"><p><strong><?= $conflicts ?> Konflikt(e):</strong> Gebuchte Termine passen nicht mehr zum Zeitplan (markiert mit ⚠). Bitte umbuchen oder löschen.</p></div>
<?php endif; ?>

<details class="card" id="translations"<?= array_filter($translations, static fn ($t) => !in_array($t['status'], ['auto', 'manual', 'none'], true)) ? ' open' : '' ?>>
  <summary><strong>Übersetzungen von Titel und Beschreibung</strong>
    <?php $states = array_count_values(array_column($translations, 'status')); ?>
    <span class="small muted" data-bt-counter>(<?= count($translations) - ($states['missing'] ?? 0) - ($states['outdated'] ?? 0) ?> von <?= count($translations) ?> aktuell)</span></summary>
  <p class="small">Original: <?= e(App\I18n::name($round['source_lang'])) ?>. Eltern sehen Titel und Beschreibung in ihrer Sprache, auch in E-Mails. Ohne Übersetzung oder wenn sie veraltet ist, erscheint das Original. Titel leeren = diese Sprache bewusst ohne Übersetzung (bleibt so, bis sich das Original ändert); ohne übersetzte Beschreibung erscheint die Original-Beschreibung.</p>
  <?php $at = $autoTranslate; ?>
  <p class="small muted" data-bt-hint>Automatisch übersetzen kann ein <strong>aktuelles Chrome (ab 138) oder Edge (ab 148) am Computer</strong> mit einem Übersetzungsmodell auf dem eigenen Gerät.
    In diesem Browser ist das nicht verfügbar (auf Schulgeräten kann es auch abgeschaltet sein) – bitte unten von Hand eintragen; ohne Übersetzung sehen Eltern das Original.</p>
  <p class="small muted">Bitte <strong>nicht</strong> die Browserfunktion „Seite übersetzen“ verwenden: Sie schickt die ganze Seite samt Buchungen an Google bzw. Microsoft.</p>
  <form method="post" action="<?= e($at['url']) ?>" class="browser-translate" data-browser-translate hidden
        data-source="<?= e($at['source']) ?>" data-hash="<?= e($at['hash']) ?>"
        data-pending="<?= e(implode(',', $at['pending'])) ?>" data-all="<?= e(implode(',', $at['all'])) ?>"
        data-title="<?= e($at['title']) ?>" data-description="<?= e($at['description']) ?>"><?= $csrf ?>
    <div class="actions">
      <?php if ($at['pending']): ?>
      <button type="button" class="button button-primary button-small" data-bt-pending>Im Browser übersetzen (<?= count($at['pending']) ?> <?= count($at['pending']) === 1 ? 'Sprache' : 'Sprachen' ?>)</button>
      <?php endif; ?>
      <button type="button" class="button button-primary button-small" data-bt-next hidden>Nächste Sprache laden</button>
      <button type="button" class="button button-small" data-bt-all data-bt-confirm="Wirklich alle neu übersetzen? Von Hand geänderte werden überschrieben – zum Bestätigen noch einmal klicken">Alle neu übersetzen (im Browser)</button>
    </div>
    <div data-bt-status role="status" aria-live="polite" hidden></div>
  </form>
  <form method="post" action="<?= e(url('/admin/round/' . $rid . '/translations')) ?>" class="form-grid"><?= $csrf ?>
    <?php foreach ($translations as $lang => $t): ?>
    <fieldset class="field pin-fieldset" lang="<?= e($lang) ?>">
      <legend><img src="<?= e(asset('flags/' . $lang . '.svg')) ?>" alt="" class="flag flag-inline"> <?= e(App\I18n::name($lang)) ?>
        <span class="status <?= in_array($t['status'], ['auto', 'manual', 'none'], true) ? 'status-on' : 'status-off' ?>"><?= ['auto' => 'automatisch', 'manual' => 'von Hand', 'none' => 'bewusst ohne Übersetzung (Original)', 'outdated' => 'veraltet – Original geändert', 'missing' => 'fehlt'][$t['status']] ?></span></legend>
      <input type="hidden" name="t_seen[<?= e($lang) ?>]" value="<?= e($t['seen']) ?>">
      <div class="field"><label for="tt-<?= e($lang) ?>">Titel</label><input type="text" id="tt-<?= e($lang) ?>" name="t_title[<?= e($lang) ?>]" maxlength="200" value="<?= e($t['title']) ?>"></div>
      <div class="field"><label for="td-<?= e($lang) ?>">Beschreibung</label><textarea id="td-<?= e($lang) ?>" name="t_description[<?= e($lang) ?>]" rows="3" maxlength="5000"><?= e($t['description']) ?></textarea></div>
      <?php if ($t['status'] === 'outdated'): ?>
      <label class="small"><input type="checkbox" name="t_confirm[<?= e($lang) ?>]" value="1"> Passt weiterhin zum geänderten Original (ohne Textänderung als aktuell übernehmen)</label>
      <?php endif; ?>
    </fieldset>
    <?php endforeach; ?>
    <div class="actions"><button type="submit" class="button button-primary">Übersetzungen speichern</button></div>
  </form>
</details>

<?php if (!$days) { require __DIR__ . '/_add_day.php'; } ?>

<?php foreach ($days as $day): $did = (int) $day['id']; ?>
<section class="card day-card" id="day-<?= $did ?>">
  <div class="page-head">
    <h2><?= e(fmt_date($day['day_date'])) ?></h2>
    <p class="muted"><?= e(fmt_range($day['start_time'], $day['end_time'])) ?> Uhr · <?= (int) $day['slot_minutes'] ?> Min. pro Termin</p>
  </div>
  <div class="actions">
    <a class="button button-small" href="<?= e(url('/admin/day/' . $did . '/edit')) ?>">Tag bearbeiten</a>
    <a class="button button-small" href="<?= e(url('/admin/round/' . $rid . '/print', ['day' => $did])) ?>" target="_blank" rel="noopener">Tag drucken</a>
    <?php $dayBookings = count(array_filter($day['slots'], static fn ($s) => $s['booking_id'] !== null)); ?>
    <form method="post" action="<?= e(url('/admin/day/' . $did . '/delete')) ?>" class="inline-delete" data-confirm="Diesen Tag mit allen Slots<?= $dayBookings ? ' und ' . $dayBookings . ' Buchung(en)' : '' ?> löschen?"><?= $csrf ?>
      <?php if ($dayBookings): ?>
        <label class="small"><input type="checkbox" name="confirm" value="1" required> inkl. <?= $dayBookings ?> Buchung(en)</label>
      <?php endif; ?>
      <button type="submit" class="button button-small button-danger">Tag löschen</button></form>
  </div>

  <h3>Pausen</h3>
  <?php if ($day['breaks']): ?>
  <ul class="break-list">
    <?php foreach ($day['breaks'] as $br): ?>
    <li><?= e(fmt_range($br['start_time'], $br['end_time'])) ?> <?= $br['label'] ? '· ' . e($br['label']) : '' ?>
      <form method="post" action="<?= e(url('/admin/break/' . (int) $br['id'] . '/delete')) ?>" class="inline"><?= $csrf ?>
        <button type="submit" class="button-link">entfernen</button></form></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted small">Keine Pausen.</p><?php endif; ?>
  <form method="post" action="<?= e(url('/admin/day/' . $did . '/break')) ?>" class="inline-form"><?= $csrf ?>
    <label>Von <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="start_time" required></label>
    <label>Bis <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="end_time" required></label>
    <label>Bezeichnung <input type="text" name="label" maxlength="100" placeholder="z. B. Mittagspause"></label>
    <button type="submit" class="button button-small">Pause hinzufügen</button>
  </form>

  <h3>Termine</h3>
  <div class="table-wrap">
  <table class="table slots-table">
    <thead><tr><th scope="col">Zeit</th><th scope="col">Eintrag</th><th scope="col">Gebucht am</th><th scope="col"><span class="visually-hidden">Aktionen</span></th></tr></thead>
    <tbody>
    <?php foreach ($day['slots'] as $s): $sid = (int) $s['id']; ?>
      <tr id="slot-<?= $sid ?>" class="<?= $s['booking_id'] ? 'is-booked' : ($s['is_blocked'] ? 'is-blocked' : 'is-free') ?><?= $s['is_conflict'] ? ' is-conflict' : '' ?>">
        <td class="nowrap"><?= $s['is_conflict'] ? '<span title="Konflikt">⚠ </span>' : '' ?><?= e(fmt_range($s['start_time'], $s['end_time'])) ?></td>
        <td>
          <?php if ($s['booking_id']): ?>
            <strong><?= e($s['name']) ?></strong><?php if ($s['email']): ?><br><span class="small"><?= e($s['email']) ?></span><?php endif; ?>
            <?php if ($s['source'] === 'admin'): ?> <span class="badge">selbst eingetragen</span><?php endif; ?>
          <?php elseif ($s['is_blocked']): ?><span class="muted">gesperrt</span>
          <?php else: ?><span class="muted">frei</span><?php endif; ?>
        </td>
        <td class="small nowrap"><?= $s['booking_id'] ? e(date('d.m.Y H:i', strtotime($s['booked_at']))) : '' ?></td>
        <td class="row-actions">
          <?php if ($s['booking_id']): ?>
            <a class="button button-small" href="<?= e(url('/admin/booking/' . (int) $s['booking_id'] . '/edit')) ?>">Bearbeiten</a>
            <form method="post" action="<?= e(url('/admin/booking/' . (int) $s['booking_id'] . '/delete')) ?>" class="inline" data-confirm="Eintrag von „<?= e($s['name']) ?>“ löschen? Der Slot wird wieder frei."><?= $csrf ?>
              <button type="submit" class="button button-small button-danger">Löschen</button></form>
          <?php else: ?>
            <a class="button button-small" href="<?= e(url('/admin/slot/' . $sid . '/book')) ?>">Eintragen</a>
            <form method="post" action="<?= e(url('/admin/slot/' . $sid . '/block')) ?>" class="inline"><?= $csrf ?>
              <button type="submit" class="button button-small"><?= $s['is_blocked'] ? 'Freigeben' : 'Sperren' ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$day['slots']): ?><tr><td colspan="4" class="muted">Keine Slots – prüfen Sie Zeiten, Slot-Dauer und Pausen.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>

<?php if ($days) { require __DIR__ . '/_add_day.php'; } ?>
