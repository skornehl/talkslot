<?php /** Request for access about one e-mail address (admins, see App\AccessRequest). @var string $email @var ?array $result @var list<string> $errors */ use App\Csrf; ?>
<?php $when = static fn (string $sql): string => fmt_date(substr($sql, 0, 10)) . ', ' . substr($sql, 11, 5); ?>
<h1><?= e(__('Auskunft zu einer E-Mail-Adresse')) ?></h1>
<p><?= e(__('Für ein Auskunftsersuchen nach Art. 15 DSGVO: zeigt, was TalkSlot zu genau dieser Adresse gespeichert hat, über alle Lehrkräfte hinweg. Jede Suche wird im Protokoll vermerkt (wer, wann, wie viele Treffer; die Adresse selbst nicht).')) ?></p>
<?php require __DIR__ . '/_errors.php'; ?>
<form method="post" action="<?= e(url('/admin/access-request')) ?>" class="card form-grid" data-access-request>
  <?= Csrf::adminField() ?>
  <div class="field">
    <label for="email"><?= e(__('E-Mail-Adresse')) ?></label>
    <input type="email" id="email" name="email" required maxlength="254" autocomplete="off" spellcheck="false" autocapitalize="none" value="<?= e($email) ?>" aria-describedby="email-hint">
    <p class="hint" id="email-hint"><?= e(__('Genau die Adresse, um die es geht; Groß- und Kleinschreibung spielen keine Rolle. Teile einer Adresse werden nicht gesucht.')) ?></p>
  </div>
  <div class="check">
    <input type="checkbox" id="purpose" name="purpose" value="1" required>
    <label for="purpose"><?= e(__('Ich bearbeite ein Auskunftsersuchen der Person, der diese Adresse gehört.')) ?></label>
  </div>
  <p><button type="submit" class="button button-primary"><?= e(__('Suchen')) ?></button></p>
</form>
<?php if ($result !== null): ?>
<section aria-labelledby="result-title" data-access-result>
  <h2 id="result-title"><?= e(__('Gespeichert zu {email}', ['email' => $email])) ?></h2>
  <?php if (!$result['bookings'] && !$result['cancellations']): ?>
  <p class="alert" data-access-none><?= e(__('Zu dieser Adresse ist derzeit nichts gespeichert.')) ?></p>
  <?php endif; ?>
  <?php if ($result['bookings']): ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(__('Termin')) ?></th><th scope="col"><?= e(__('Runde und Lehrkraft')) ?></th><th scope="col"><?= e(__('Name')) ?></th>
      <th scope="col"><?= e(__('Sprache')) ?></th><th scope="col"><?= e(__('Eingetragen')) ?></th><th scope="col"><?= e(__('Weitere Angaben')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($result['bookings'] as $b): ?>
      <tr data-access-booking>
        <td class="nowrap"><?= e(fmt_date((string) $b['day_date'])) ?><br><?= e(__('{time} Uhr', ['time' => fmt_range((string) $b['start_time'], (string) $b['end_time'])])) ?><?php if ((string) $b['location'] !== ''): ?><br><span class="small"><?= e((string) $b['location']) ?></span><?php endif; ?></td>
        <td><?= e((string) $b['title']) ?><br><span class="small"><?= e((string) $b['teacher_name']) ?></span></td>
        <td><?= e((string) $b['name']) ?></td>
        <td><?= e(strtoupper((string) $b['lang'])) ?></td>
        <td class="small"><?= e($when((string) $b['created_at'])) ?><br><?= e($b['source'] === 'admin' ? __('von der Lehrkraft') : __('von den Eltern')) ?></td>
        <td class="small">
          <?= e(!empty($b['remind_ok']) ? __('Erinnerung vorgesehen') : __('keine Erinnerung')) ?>
          <?php if (!empty($b['reminded_at'])): ?><br><?= e(__('Erinnerung verschickt: {time}', ['time' => $when((string) $b['reminded_at'])])) ?><?php endif; ?>
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
        'date' => fmt_date((string) ($c['day_date'] ?? '')), 'time' => fmt_range((string) ($c['start_time'] ?? ''), (string) ($c['end_time'] ?? '')),
        'title' => (string) ($c['title'] ?? ''), 'teacher' => (string) ($c['teacher_name'] ?? ''), 'name' => (string) ($c['name'] ?? ''),
        'since' => $when((string) $c['queued_at'])])) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p class="hint"><?= e(__('Buchungen werden am Tag nach dem Termin gelöscht, bei einer Absage sofort; Absage-E-Mails nach dem Versand, spätestens nach 48 Stunden. Nicht enthalten sind verschickte E-Mails (in den Postfächern der Lehrkräfte und beim Mailserver), Sicherungen und die Protokolle des Webhosters: Dazu geben die Lehrkräfte bzw. der Betreiber Auskunft.')) ?></p>
</section>
<?php endif; ?>
