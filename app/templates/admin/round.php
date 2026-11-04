<?php
/** Overview of a round: days, breaks, slots, bookings. @var array $round @var array $days @var int $conflicts @var string $publicUrl */
use App\Csrf;
$rid = (int) $round['id'];
$csrf = Csrf::adminField();
?>
<p class="breadcrumb"><a href="<?= e(url('/admin')) ?>"><?= e(__('Runden')) ?></a> › <?= e($round['title']) ?></p>
<div class="page-head">
  <h1><?= e($round['title']) ?></h1>
  <?php if ($showOwner): ?><span class="badge" title="<?= e(__('Lehrkraft der Runde')) ?>"><?= e(__('Lehrkraft: {name}', ['name' => $owner['display_name'] !== '' ? $owner['display_name'] : $owner['username']])) ?><?= !$owner['is_active'] ? ' ' . e(__('(deaktiviert – Runde öffentlich nicht erreichbar)')) : '' ?></span><?php endif; ?>
  <?php if ($round['is_active']): ?><span class="status status-on"><?= e(__('aktiv')) ?></span><?php else: ?><span class="status status-off"><?= e($round['expired_at'] ? __('abgelaufen') : __('inaktiv')) ?></span><?php endif; ?>
</div>

<div class="card">
  <p class="small muted"><?= e(__('Link für die Eltern')) ?></p>
  <div class="copy-row">
    <input type="text" readonly class="link-field" value="<?= e($publicUrl) ?>" aria-label="<?= e(__('Link für die Eltern')) ?>">
    <button type="button" class="button" data-copy="<?= e($publicUrl) ?>"><?= e(__('Link kopieren')) ?></button>
    <a class="button" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= e(__('Öffnen')) ?></a>
  </div>
  <?php if ((string) ($round['code'] ?? '') !== ''): ?>
    <p><?= e(__('Rundencode:')) ?> <strong class="pin-display" data-round-code><?= e(App\RoundCode::format($round['code'])) ?></strong>
      <span class="small muted"><?= e(__('– Eltern können ihn statt des Links auf {url} eingeben.', ['url' => $homeUrl])) ?></span></p>
  <?php endif; ?>
  <?php if (App\RoundAccess::required($round)): ?>
    <p class="small"><span class="badge"><?= e(__('Nur mit Rundencode')) ?></span> <?= e(__('Wer den Link öffnet, muss den Rundencode eingeben; über die Startseite reicht der Code allein.')) ?></p>
  <?php else: ?>
    <p class="small muted"><?= e(__('Ohne Schutz – jeder mit dem Link sieht die freien Termine (Schutz: „Bearbeiten“ → „Nur mit Rundencode“).')) ?></p>
  <?php endif; ?>
  <div class="actions">
    <button type="button" class="button" data-copy="<?= e($invitation) ?>"><?= e(__('Einladungstext kopieren')) ?></button>
    <a class="button" href="mailto:?subject=<?= e(rawurlencode($round['title'])) ?>&amp;body=<?= e(rawurlencode($invitation)) ?>"><?= e(__('Als E-Mail öffnen')) ?></a>
  </div>
  <details class="small">
    <summary><?= e(__('Einladungstext ansehen')) ?></summary>
    <pre class="invitation"><?= e($invitation) ?></pre>
  </details>
  <?php if (trim((string) ($round['location'] ?? '')) !== ''): ?><p><?= __h('Ort: {location}', ['location' => '<strong>' . e(trim((string) $round['location'])) . '</strong>']) ?></p><?php endif; ?>
  <p class="small muted"><?= e(['round' => __('Ein Termin pro Person'), 'day' => __('Ein Termin pro Person und Tag')][$round['booking_limit'] ?? 'none'] ?? __('Mehrfachbuchungen erlaubt')) ?><?= !empty($round['remind_parents']) ? ' · ' . e(__('Erinnerung am Vorabend')) : '' ?> · <?= e(__('Buchungsschluss {hours} h vorher', ['hours' => (int) $round['booking_deadline_hours']])) ?> · <?= e(__('Ändern/Absagen bis {hours} h vorher', ['hours' => (int) $round['cancel_deadline_hours']])) ?> ·
    <?= e($round['delete_after_hours'] === null ? __('Runde wird nicht automatisch gelöscht') : __('Runde wird {hours} h nach Ablauf gelöscht', ['hours' => (int) $round['delete_after_hours']])) ?></p>
  <div class="actions">
    <a class="button" href="<?= e(url('/admin/round/' . $rid . '/edit')) ?>"><?= e(__('Bearbeiten')) ?></a>
    <a class="button" href="#add-day"><?= e(__('+ Tag hinzufügen')) ?></a>
    <form method="post" action="<?= e(url('/admin/round/' . $rid . '/toggle')) ?>" class="inline"><?= $csrf ?>
      <button type="submit" class="button"><?= e($round['is_active'] ? __('Deaktivieren') : __('Aktivieren')) ?></button></form>
    <a class="button" href="<?= e(url('/admin/round/' . $rid . '/print')) ?>" target="_blank" rel="noopener"><?= e(__('Druckansicht / PDF')) ?></a>
    <a class="button" href="#duplicate" data-open-details="duplicate"><?= e(__('Duplizieren …')) ?></a>
    <?php if (App\Auth::isAdmin()): ?>
    <form method="post" action="<?= e(url('/admin/templates/from-round/' . $rid)) ?>" class="inline" data-once><?= $csrf ?>
      <button type="submit" class="button"><?= e(__('Als Vorlage speichern')) ?></button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/round/' . $rid . '/delete')) ?>" class="inline" data-confirm="<?= e(__('Runde „{title}“ mit allen Tagen und Buchungen endgültig löschen?', ['title' => $round['title']])) ?>"><?= $csrf ?>
      <button type="submit" class="button button-danger"><?= e(__('Löschen')) ?></button></form>
  </div>
  <details id="duplicate">
    <summary><?= e(__('Runde duplizieren')) ?></summary>
    <form method="post" action="<?= e(url('/admin/round/' . $rid . '/duplicate')) ?>" class="inline-form" data-once><?= $csrf ?>
      <div>
        <label for="first_date"><?= e(__('Erster Tag der Kopie')) ?></label>
        <input type="date" id="first_date" name="first_date" aria-describedby="dup-hint">
      </div>
      <button type="submit" class="button"><?= e(__('Duplizieren')) ?></button>
    </form>
    <p class="hint" id="dup-hint"><?= e(__('Übernimmt Tage, Zeiten, Pausen, gesperrte Termine, Einstellungen und Übersetzungen, aber keine Buchungen; die Kopie ist inaktiv. Mit Datum verschieben sich alle Tage um denselben Abstand (z. B. Tage am Di und Do → wieder Di und Do, wenn der erste Tag ein Dienstag ist). Ohne Datum behält die Kopie die bisherigen Daten.')) ?></p>
  </details>
</div>

<?php if ($conflicts > 0): ?>
  <div class="alert alert-warning" role="alert"><p><strong><?= e(__('{count} Konflikt(e):', ['count' => $conflicts])) ?></strong> <?= e(__('Gebuchte Termine passen nicht mehr zum Zeitplan (markiert mit ⚠). Bitte umbuchen oder löschen.')) ?></p></div>
<?php endif; ?>

<details class="card" id="translations"<?= array_filter($translations, static fn ($t) => !in_array($t['status'], ['auto', 'manual', 'none'], true)) ? ' open' : '' ?>>
  <summary><strong><?= e(__('Übersetzungen von Titel und Beschreibung')) ?></strong>
    <?php $states = array_count_values(array_column($translations, 'status')); ?>
    <span class="small muted" data-bt-counter><?= e(__('({ok} von {all} aktuell)', ['ok' => count($translations) - ($states['missing'] ?? 0) - ($states['outdated'] ?? 0), 'all' => count($translations)])) ?></span></summary>
  <p class="small"><?= e(__('Original: {lang}. Eltern sehen Titel und Beschreibung in ihrer Sprache, auch in E-Mails. Ohne Übersetzung oder wenn sie veraltet ist, erscheint das Original. Titel leeren = diese Sprache bewusst ohne Übersetzung (bleibt so, bis sich das Original ändert); ohne übersetzte Beschreibung erscheint die Original-Beschreibung.', ['lang' => App\I18n::name($round['source_lang'])])) ?></p>
  <?php $at = $autoTranslate; ?>
  <p class="small muted" data-bt-hint><?= __h('Automatisch übersetzen kann ein {browsers} mit einem Übersetzungsmodell auf dem eigenen Gerät.', ['browsers' => '<strong>' . e(__('aktuelles Chrome (ab 138) oder Edge (ab 148) am Computer')) . '</strong>']) . "\n" ?>
    <?= e(__('In diesem Browser ist das nicht verfügbar (auf Schulgeräten kann es auch abgeschaltet sein) – bitte unten von Hand eintragen; ohne Übersetzung sehen Eltern das Original.')) ?></p>
  <p class="small muted"><?= __h('Bitte {not} die Browserfunktion „Seite übersetzen“ verwenden: Sie schickt die ganze Seite samt Buchungen an Google bzw. Microsoft.', ['not' => '<strong>' . e(__('nicht')) . '</strong>']) ?></p>
  <form method="post" action="<?= e($at['url']) ?>" class="browser-translate" data-browser-translate hidden
        data-source="<?= e($at['source']) ?>" data-hash="<?= e($at['hash']) ?>"
        data-pending="<?= e(implode(',', $at['pending'])) ?>" data-all="<?= e(implode(',', $at['all'])) ?>"
        data-title="<?= e($at['title']) ?>" data-description="<?= e($at['description']) ?>"><?= $csrf ?>
    <div class="actions">
      <?php if ($at['pending']): ?>
      <button type="button" class="button button-primary button-small" data-bt-pending><?= e(count($at['pending']) === 1 ? __('Im Browser übersetzen ({count} Sprache)', ['count' => count($at['pending'])]) : __('Im Browser übersetzen ({count} Sprachen)', ['count' => count($at['pending'])])) ?></button>
      <?php endif; ?>
      <button type="button" class="button button-primary button-small" data-bt-next hidden><?= e(__('Nächste Sprache laden')) ?></button>
      <button type="button" class="button button-small" data-bt-all data-bt-confirm="<?= e(__('Wirklich alle neu übersetzen? Von Hand geänderte werden überschrieben – zum Bestätigen noch einmal klicken')) ?>"><?= e(__('Alle neu übersetzen (im Browser)')) ?></button>
    </div>
    <div data-bt-status role="status" aria-live="polite" hidden></div>
  </form>
  <form method="post" action="<?= e(url('/admin/round/' . $rid . '/translations')) ?>" class="form-grid"><?= $csrf ?>
    <?php foreach ($translations as $lang => $t): ?>
    <fieldset class="field pin-fieldset" lang="<?= e($lang) ?>">
      <legend><img src="<?= e(asset('flags/' . $lang . '.svg')) ?>" alt="" class="flag flag-inline"> <?= e(App\I18n::name($lang)) ?>
        <span class="status <?= in_array($t['status'], ['auto', 'manual', 'none'], true) ? 'status-on' : 'status-off' ?>"><?= e(['auto' => __('automatisch'), 'manual' => __('von Hand'), 'none' => __('bewusst ohne Übersetzung (Original)'), 'outdated' => __('veraltet – Original geändert'), 'missing' => __('fehlt')][$t['status']]) ?></span></legend>
      <input type="hidden" name="t_seen[<?= e($lang) ?>]" value="<?= e($t['seen']) ?>">
      <div class="field"><label for="tt-<?= e($lang) ?>"><?= e(__('Titel')) ?></label><input type="text" id="tt-<?= e($lang) ?>" name="t_title[<?= e($lang) ?>]" maxlength="200" value="<?= e($t['title']) ?>"></div>
      <div class="field"><label for="td-<?= e($lang) ?>"><?= e(__('Beschreibung')) ?></label><textarea id="td-<?= e($lang) ?>" name="t_description[<?= e($lang) ?>]" rows="3" maxlength="5000"><?= e($t['description']) ?></textarea></div>
      <?php if ($t['status'] === 'outdated'): ?>
      <label class="small"><input type="checkbox" name="t_confirm[<?= e($lang) ?>]" value="1"> <?= e(__('Passt weiterhin zum geänderten Original (ohne Textänderung als aktuell übernehmen)')) ?></label>
      <?php endif; ?>
    </fieldset>
    <?php endforeach; ?>
    <div class="actions"><button type="submit" class="button button-primary"><?= e(__('Übersetzungen speichern')) ?></button></div>
  </form>
</details>

<?php if (!$days) { require __DIR__ . '/_add_day.php'; } ?>

<?php foreach ($days as $day): $did = (int) $day['id']; ?>
<section class="card day-card" id="day-<?= $did ?>">
  <div class="page-head">
    <h2><?= e(fmt_date($day['day_date'])) ?></h2>
    <p class="muted"><?= e(__('{time} Uhr', ['time' => fmt_range($day['start_time'], $day['end_time'])])) ?> · <?= e(__('{minutes} Min. pro Termin', ['minutes' => (int) $day['slot_minutes']])) ?><?php if (trim((string) ($day['location'] ?? '')) !== ''): ?> · <?= __h('Ort: {location}', ['location' => '<strong>' . e(trim((string) $day['location'])) . '</strong>']) ?><?php endif; ?></p>
  </div>
  <div class="actions">
    <a class="button button-small" href="<?= e(url('/admin/day/' . $did . '/edit')) ?>"><?= e(__('Tag bearbeiten')) ?></a>
    <a class="button button-small" href="<?= e(url('/admin/round/' . $rid . '/print', ['day' => $did])) ?>" target="_blank" rel="noopener"><?= e(__('Tag drucken')) ?></a>
    <?php $dayBookings = count(array_filter($day['slots'], static fn ($s) => $s['booking_id'] !== null)); ?>
    <form method="post" action="<?= e(url('/admin/day/' . $did . '/delete')) ?>" class="inline-delete" data-confirm="<?= e($dayBookings ? __('Diesen Tag mit allen Slots und {count} Buchung(en) löschen?', ['count' => $dayBookings]) : __('Diesen Tag mit allen Slots löschen?')) ?>"><?= $csrf ?>
      <?php if ($dayBookings): ?>
        <label class="small"><input type="checkbox" name="confirm" value="1" required> <?= e(__('inkl. {count} Buchung(en)', ['count' => $dayBookings])) ?></label>
      <?php endif; ?>
      <button type="submit" class="button button-small button-danger"><?= e(__('Tag löschen')) ?></button></form>
  </div>

  <h3><?= e(__('Pausen')) ?></h3>
  <?php if ($day['breaks']): ?>
  <ul class="break-list">
    <?php foreach ($day['breaks'] as $br): ?>
    <li><?= e(fmt_range($br['start_time'], $br['end_time'])) ?> <?= $br['label'] ? '· ' . e($br['label']) : '' ?>
      <form method="post" action="<?= e(url('/admin/break/' . (int) $br['id'] . '/delete')) ?>" class="inline"><?= $csrf ?>
        <button type="submit" class="button-link"><?= e(__('entfernen')) ?></button></form></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted small"><?= e(__('Keine Pausen.')) ?></p><?php endif; ?>
  <form method="post" action="<?= e(url('/admin/day/' . $did . '/break')) ?>" class="inline-form"><?= $csrf ?>
    <label><?= e(__('Von')) ?> <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="start_time" required></label>
    <label><?= e(__('Bis')) ?> <input type="text" inputmode="numeric" pattern="[0-9]{1,2}([:.][0-9]{2})?|[0-9]{3,4}" maxlength="5" placeholder="HH:MM" autocomplete="off" class="time-input" data-time name="end_time" required></label>
    <label><?= e(__('Bezeichnung')) ?> <input type="text" name="label" maxlength="100" placeholder="<?= e(__('z. B. Mittagspause')) ?>"></label>
    <button type="submit" class="button button-small"><?= e(__('Pause hinzufügen')) ?></button>
  </form>

  <h3><?= e(__('Termine')) ?></h3>
  <div class="table-wrap">
  <table class="table slots-table">
    <thead><tr><th scope="col"><?= e(__('Zeit')) ?></th><th scope="col"><?= e(__('Eintrag')) ?></th><th scope="col"><?= e(__('Gebucht am')) ?></th><th scope="col"><span class="visually-hidden"><?= e(__('Aktionen')) ?></span></th></tr></thead>
    <tbody>
    <?php foreach ($day['slots'] as $s): $sid = (int) $s['id']; ?>
      <tr id="slot-<?= $sid ?>" class="<?= $s['booking_id'] ? 'is-booked' : ($s['is_blocked'] ? 'is-blocked' : 'is-free') ?><?= $s['is_conflict'] ? ' is-conflict' : '' ?>">
        <td class="nowrap"><?= $s['is_conflict'] ? '<span title="' . e(__('Konflikt')) . '">⚠ </span>' : '' ?><?= e(fmt_range($s['start_time'], $s['end_time'])) ?></td>
        <td>
          <?php if ($s['booking_id']): ?>
            <strong><?= e($s['name']) ?></strong><?php if ($s['email']): ?><br><span class="small"><?= e($s['email']) ?></span><?php endif; ?>
            <?php if ($s['source'] === 'admin'): ?> <span class="badge"><?= e(__('selbst eingetragen')) ?></span><?php endif; ?>
          <?php elseif ($s['is_blocked']): ?><span class="muted"><?= e(__('gesperrt')) ?></span>
          <?php else: ?><span class="muted"><?= e(__('frei')) ?></span><?php endif; ?>
        </td>
        <td class="small nowrap"><?= $s['booking_id'] ? e(date('d.m.Y H:i', strtotime($s['booked_at']))) : '' ?></td>
        <td class="row-actions">
          <?php if ($s['booking_id']): ?>
            <a class="button button-small" href="<?= e(url('/admin/booking/' . (int) $s['booking_id'] . '/edit')) ?>"><?= e(__('Bearbeiten')) ?></a>
            <form method="post" action="<?= e(url('/admin/booking/' . (int) $s['booking_id'] . '/delete')) ?>" class="inline" data-confirm="<?= e(__('Eintrag von „{name}“ löschen? Der Slot wird wieder frei.', ['name' => $s['name']])) ?>"><?= $csrf ?>
              <button type="submit" class="button button-small button-danger"><?= e(__('Löschen')) ?></button></form>
          <?php else: ?>
            <a class="button button-small" href="<?= e(url('/admin/slot/' . $sid . '/book')) ?>"><?= e(__('Eintragen')) ?></a>
            <form method="post" action="<?= e(url('/admin/slot/' . $sid . '/block')) ?>" class="inline"><?= $csrf ?>
              <button type="submit" class="button button-small"><?= e($s['is_blocked'] ? __('Freigeben') : __('Sperren')) ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$day['slots']): ?><tr><td colspan="4" class="muted"><?= e(__('Keine Slots – prüfen Sie Zeiten, Slot-Dauer und Pausen.')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>

<?php if ($days) { require __DIR__ . '/_add_day.php'; } ?>
