<?php /** Request for access about one e-mail address (admins, see App\AccessRequest). @var string $email @var ?array $result @var list<string> $errors */ use App\Csrf; ?>
<?php $when = static fn (string $sql): string => __('{date}, {time} Uhr', ['date' => fmt_date(substr($sql, 0, 10)), 'time' => substr($sql, 11, 5)]); ?>
<h1><?= e(__('Auskunft zu einer E-Mail-Adresse')) ?></h1>
<p><?= e(__('Wenn Eltern wissen möchten, was über sie gespeichert ist (Art. 15 DSGVO): Diese Seite findet die Buchungen und wartenden Absage-E-Mails mit genau dieser Adresse, über alle Lehrkräfte hinweg. Jede Suche wird im Protokoll vermerkt (wer, wann, wie viele Treffer; die Adresse selbst nicht).')) ?></p>
<?php require __DIR__ . '/_errors.php'; ?>
<form method="post" action="<?= e(url('/admin/access-request')) ?>" class="card form-grid" data-access-request novalidate>
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="email"><?= e(__('E-Mail-Adresse')) ?></label>
    <input type="email" id="email" name="email" required maxlength="254" autocomplete="off" spellcheck="false" autocapitalize="none" value="<?= e($email) ?>" aria-describedby="email-hint">
    <p class="hint" id="email-hint"><?= e(__('Genau die Adresse, um die es geht; Groß- und Kleinschreibung spielen keine Rolle. Teile einer Adresse werden nicht gesucht.')) ?></p>
  </div>
  <div class="check">
    <input type="checkbox" id="purpose" name="purpose" value="1" required>
    <label for="purpose"><?= e(__('Ich bearbeite eine Auskunftsanfrage der Person, der diese Adresse gehört.')) ?></label>
  </div>
  <p><button type="submit" class="button button-primary"><?= e(__('Suchen')) ?></button></p>
</form>
<?php if ($result !== null): ?>
<section aria-labelledby="result-title" data-access-result>
  <h2 id="result-title"><?= e(__('Ergebnis für {email}', ['email' => $email])) ?></h2>
  <?php if (!$result['bookings'] && !$result['cancellations']): ?>
  <p class="alert" data-access-none><?= e(__('In TalkSlot gibt es derzeit keine Buchung und keine wartende Absage-E-Mail mit dieser Adresse.')) ?></p>
  <?php endif; ?>
  <?php if ($result['bookings']): ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(__('Termin')) ?></th><th scope="col"><?= e(__('Runde und Lehrkraft')) ?></th><th scope="col"><?= e(__('Name')) ?></th>
      <th scope="col"><?= e(__('Sprache')) ?></th><th scope="col"><?= e(__('Gebucht am')) ?></th><th scope="col"><?= e(__('Erinnerung und E-Mails')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($result['bookings'] as $b): ?>
      <tr data-access-booking>
        <td><span class="nowrap"><?= e(fmt_date((string) $b['day_date'])) ?></span><br><span class="nowrap"><?= e(__('{time} Uhr', ['time' => fmt_range((string) $b['start_time'], (string) $b['end_time'])])) ?></span><?php if ((string) $b['location'] !== ''): ?><br><span class="small"><?= e((string) $b['location']) ?></span><?php endif; ?></td>
        <td><?= e((string) $b['title']) ?><br><span class="small"><?= e((string) $b['teacher_name']) ?></span></td>
        <td><?= e((string) $b['name']) ?><?php if ($b['interpreter_lang'] !== null): ?><br><span class="small" data-interpreter><?= e(__('Dolmetschung gewünscht: {language}', ['language' => $b['interpreter_lang']])) ?></span><?php endif; ?></td>
        <td><?= e(App\I18n::name((string) $b['lang'])) ?></td>
        <td class="small"><?= e($when((string) $b['created_at'])) ?><br><?= e($b['source'] === 'admin' ? __('Adresse von der Schule') : __('Adresse von den Eltern')) ?></td>
        <td class="small">
          <?php if (!empty($b['reminded_at'])): ?><?= e(__('Erinnerung verschickt: {time}', ['time' => $when((string) $b['reminded_at'])])) ?>
          <?php elseif (!empty($b['remind_ok']) && !empty($b['remind_parents'])): ?><?= e(__('Erinnerung vorgesehen')) ?>
          <?php else: ?><?= e(__('Keine Erinnerung')) ?><?php endif; ?>
          <?php if (!empty($b['notified_at'])): ?><br><?= e(__('Letzte Bestätigung oder Änderungsmitteilung: {time}', ['time' => $when((string) $b['notified_at'])])) ?><?php endif; ?>
          <?php if (!empty($b['mail_failed_at'])): ?><br><?= e(__('E-Mail nicht zugestellt: {time}', ['time' => $when((string) $b['mail_failed_at'])])) ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if ($result['cancellations']): ?>
  <h3><?= e(__('Absage-E-Mails, die noch auf den Versand warten')) ?></h3>
  <ul>
    <?php foreach ($result['cancellations'] as $c): ?>
    <li data-access-cancellation><?= e(__('{date}, {time} Uhr, {title} ({teacher}), Name {name}: wartet seit {since}', [
        'date' => fmt_date((string) $c['day_date']), 'time' => fmt_range((string) $c['start_time'], (string) $c['end_time']),
        'title' => (string) $c['title'], 'teacher' => (string) $c['teacher_name'], 'name' => (string) $c['name'],
        'since' => $when((string) $c['queued_at'])])) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p><?= e(__('Nicht enthalten sind Buchungen ohne oder mit anderer E-Mail-Adresse, bereits verschickte E-Mails (in den Postfächern der Lehrkräfte und beim Mailserver), Sicherungen und die Zugriffslogs des Webhosters. Fragen Sie dazu die Lehrkräfte bzw. den Webhoster.')) ?></p>
  <p class="hint"><?= e(__('Buchungen werden am Tag nach dem Termin gelöscht, bei einer Absage sofort; Absage-E-Mails nach dem Versand, spätestens nach 48 Stunden.')) ?></p>
</section>
<?php endif; ?>
