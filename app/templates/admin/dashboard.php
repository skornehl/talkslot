<?php /** @var array $rounds @var list<string> $profileHints @var list<string> $legalHints @var bool $showOwner @var bool $operatorMissing @var bool $typeMissing @var bool $textsOpen @var int $unusedAccounts @var ?array $mailsLate @var array{state: string, last: ?int}|null $cron @var list<string> $setupHints @var ?string $summaryNotice @var int $foreignAddresses */ ?>
<?php if ($mailsLate): ?>
  <div class="alert alert-warning" role="status" data-mails-late><p><?= e(__('{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand (die älteste seit {since}). Erinnerungen, Zusammenfassungen und Bestätigungen, die nicht sofort zugestellt werden konnten, verschickt der Cron-Job: Bitte prüfen Sie, ob er regelmäßig läuft und ob der Mailversand funktioniert. Nach 48 Stunden werden sie verworfen.', ['count' => $mailsLate['count'], 'hours' => App\Outbox::lateHours(), 'since' => date('d.m.Y H:i', (int) strtotime($mailsLate['since']))])) ?></p></div>
<?php endif; ?>
<?php foreach ($legalHints as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?> <a href="<?= e(url('/admin/texts')) ?>"><?= e(__('Zu den Texten')) ?></a></p></div>
<?php endforeach; ?>
<?php if ($unusedAccounts): ?>
  <div class="alert alert-info" role="status"><p><?= e(__('Konten, die seit über 12 Monaten nicht genutzt wurden: {count}. Bitte prüfen Sie, ob sie noch gebraucht werden, und deaktivieren Sie die Konten ausgeschiedener Lehrkräfte.', ['count' => $unusedAccounts])) ?> <a href="<?= e(url('/admin/users')) ?>"><?= e(__('Zu den Benutzern')) ?></a></p></div>
<?php endif; ?>
<?php if ($operatorMissing && !App\Auth::isAdmin()): ?>
  <div class="alert alert-warning" role="status"><p><?= e(__('Es sind noch keine Angaben für Impressum und Datenschutz hinterlegt (Aufgabe der Admins). Bis dahin sind keine Terminrunden öffentlich erreichbar.')) ?></p></div>
<?php elseif ($typeMissing ?? false): ?>
  <div class="alert alert-warning" role="status"><p><?= e(__('Die Admins müssen noch wählen, ob die Terminvergabe von der Schule oder privat betrieben wird. Bis dahin lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar.')) ?></p></div>
<?php elseif ($textsOpen ?? false): ?>
  <div class="alert alert-warning" role="status"><p><?= e(__('Impressum bzw. Datenschutzerklärung enthalten noch offene Platzhalter (Aufgabe der Admins). Bis sie ausgefüllt sind, lassen sich keine Runden aktivieren; bereits aktive Runden bleiben erreichbar.')) ?></p></div>
<?php endif; ?>
<?php if ($cron ?? null): $cronGuide = App\Version::PROJECT_URL . (App\AdminI18n::lang() === 'de' ? 'de/' : '') . 'guide/operations/#cron-monitoring'; ?>
  <div class="alert alert-warning" role="status"><p><?= e($cron['state'] === 'late'
      ? __('Der Cronjob ist seit dem {time} Uhr nicht mehr vollständig gelaufen. Ohne ihn werden Buchungen vergangener Tage verspätet gelöscht und Zusammenfassungen und Erinnerungen verspätet oder gar nicht verschickt. Bitte prüfen Sie den Cronjob auf dem Server bzw. den GitHub-Workflow „Cron“: GitHub schaltet geplante Workflows in öffentlichen Repositorys nach 60 Tagen ohne Aktivität ab, unter „Actions“ lässt er sich wieder einschalten.', ['time' => date('d.m.Y H:i', (int) $cron['last'])])
      : __('Es ist noch kein vollständiger Lauf des Cronjobs verzeichnet. Bitte richten Sie ihn ein (stündlich bin/cron.php oder der Web-Cron, z. B. über den GitHub-Workflow „Cron“), damit Buchungen pünktlich gelöscht und Zusammenfassungen und Erinnerungen rechtzeitig verschickt werden; ist er schon eingerichtet, zeigt „php bin/cron.php -v“, woran er scheitert. Wer bewusst ohne Cronjob arbeitet, schaltet diesen Hinweis mit cron_alert_hours = 0 in der Konfiguration ab.')) ?> <a href="<?= e($cronGuide) ?>"><?= e(__('Zur Anleitung')) ?></a></p></div>
<?php endif; ?>
<?php if ($configExposed): ?>
  <div class="alert alert-warning" role="status"><p><?= e(__('Die Konfiguration ist für alle Benutzer des Servers lesbar: {files}. Sie enthält die Passwörter für Datenbank und Mailversand sowie den geheimen Schlüssel. Bitte setzen Sie die Rechte auf 600 (oder 640), z. B. im FTP-Programm, oder geben Sie diesen Hinweis an die technische Betreuung weiter.', ['files' => implode(', ', array_map(static fn (string $file, string $mode): string => "$file ($mode)", array_keys($configExposed), $configExposed))])) ?></p></div>
<?php endif; ?>
<?php if ($setupHints ?? []): ?>
  <div class="alert alert-warning" role="status" data-setup-hints>
    <p><strong><?= e(__('Die Einrichtung des Servers braucht Aufmerksamkeit (Aufgabe der technischen Betreuung, die Einstellungen stehen in config/config.php):')) ?></strong></p>
    <ul><?php foreach ($setupHints as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($summaryNotice ?? null): ?>
  <div class="alert alert-info" role="status" data-summary-notice>
    <p><?= e($summaryNotice) ?> <a href="<?= e(url('/admin/profile')) ?>"><?= e(__('Zum Profil')) ?></a></p>
    <form method="post" action="<?= e(url('/admin/profile/summaries')) ?>"><?= App\Csrf::adminField() ?><button type="submit" class="button button-small"><?= e(__('Verstanden')) ?></button></form>
  </div>
<?php endif; ?>
<?php if ($foreignAddresses ?? 0): ?>
  <div class="alert alert-warning" role="status" data-foreign-addresses><p><?= e(__('Aktive Konten mit einer Adresse außerhalb von „allowed_account_domains“: {count}. An diese Adressen gehen keine Zusammenfassungen und keine Antworten der Eltern. Bitte ändern Sie die Adressen.', ['count' => $foreignAddresses])) ?> <a href="<?= e(url('/admin/users')) ?>"><?= e(__('Zu den Benutzern')) ?></a></p></div>
<?php endif; ?>
<?php if ($profileHints): ?>
  <div class="alert alert-warning" role="status">
    <p><strong><?= __h('Bitte ergänzen Sie {link}:', ['link' => '<a href="' . e(url('/admin/profile')) . '">' . e(__('Ihr Profil')) . '</a>']) ?></strong></p>
    <ul><?php foreach ($profileHints as $h): ?><li><?= e($h) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<div class="page-head">
  <h1><?= e($title) ?></h1>
  <a class="button button-primary" href="<?= e(url('/admin/round/new')) ?>"><?= e(__('+ Neue Runde')) ?></a>
</div>

<?php if (!$rounds): ?>
  <p class="alert alert-info"><?= e(__('Noch keine Runden angelegt. Starten Sie mit „Neue Runde“.')) ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead>
    <tr><th scope="col"><?= e(__('Runde')) ?></th><?php if ($showOwner): ?><th scope="col"><?= e(__('Lehrkraft')) ?></th><?php endif; ?><th scope="col"><?= e(__('Zeitraum')) ?></th><th scope="col"><?= e(__('Buchungen')) ?></th><th scope="col"><?= e(__('Status')) ?></th><th scope="col"><?= e(__('Link')) ?></th></tr>
  </thead>
  <tbody>
  <?php foreach ($rounds as $r): $link = abs_url('/' . $r['slug']); ?>
    <tr>
      <td><a href="<?= e(url('/admin/round/' . (int) $r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a><?= App\RoundAccess::required($r) ? ' <span class="badge" title="' . e(__('nur mit Rundencode erreichbar')) . '">' . e(__('Code')) . '</span>' : '' ?></td>
      <?php if ($showOwner): ?><td><?= e($r['owner_name']) ?><?= !$r['owner_active'] ? ' <span class="badge">' . e(__('deaktiviert')) . '</span>' : '' ?></td><?php endif; ?>
      <td><?= $r['first_day'] ? e(date('d.m.Y', strtotime($r['first_day']))) . ($r['last_day'] !== $r['first_day'] ? ' – ' . e(date('d.m.Y', strtotime($r['last_day']))) : '') : '<span class="muted">' . e(__('keine Tage')) . '</span>' ?></td>
      <td><?= (int) $r['booking_count'] ?> / <?= (int) $r['slot_count'] ?></td>
      <td>
        <?php if ($r['is_active']): ?><span class="status status-on"><?= e(__('aktiv')) ?></span>
        <?php elseif ($r['expired_at']): ?><span class="status status-off"><?= e(__('abgelaufen')) ?></span><?php if ($r['deletion_at']): ?><br><span class="small muted"><?= e(__('wird am {date} gelöscht', ['date' => $r['deletion_at']->format('d.m.Y')])) ?></span><?php endif; ?>
        <?php else: ?><span class="status status-off"><?= e(__('inaktiv')) ?></span><?php endif; ?>
      </td>
      <td><button type="button" class="button button-small" data-copy="<?= e($link) ?>" aria-label="<?= e(__('Link kopieren') . ': ' . $r['title']) ?>"><?= e(__('Link kopieren')) ?></button></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
