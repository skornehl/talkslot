<?php /** @var array $languages @var string $mailTransport @var string $mailFrom @var string $operatorContact @var ?string $operatorType @var list<string> $operatorHints @var string $privacyTemplate @var list<string> $legalHints @var list<string> $outdatedHints @var array<string, bool> $outdated */ use App\Csrf;
// Behind the current template (LegalTexts::outdated()): with a version marker "changed", otherwise "differs"
$outdated ??= [];
$behind = static fn (string $page): string => isset($outdated[$page])
    ? ' <span class="small muted">– ' . e($outdated[$page] ? __('Vorlage geändert') : __('weicht von der Vorlage ab')) . '</span>' : ''; ?>
<h1><?= e(__('Impressum & Datenschutz')) ?></h1>
<p><a href="<?= e(url('/admin/start')) ?>"><?= e(__('Bereit für den Start? Alle Punkte der Einrichtung auf einen Blick')) ?></a></p>
<?php foreach ($operatorHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?></p></div>
<?php endforeach; ?>
<section class="card">
  <h2><?= e(__('Angaben für Impressum und Datenschutz')) ?></h2>
  <form method="post" action="<?= e(url('/admin/texts/operator')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <?php require __DIR__ . '/_operator_type.php'; ?>
    <div class="field">
      <label for="controller_contact"><?= e(__('Verantwortliche Stelle')) ?></label>
      <textarea id="controller_contact" name="controller_contact" rows="5" maxlength="2000" aria-describedby="cc-hint" placeholder="<?= str_replace("\n", '&#10;', e($operatorType === 'private'
        ? __("Vorname Nachname bzw. Name des Vereins oder Trägers\nVertreten durch: Vorname Nachname (bei Verein oder Träger)\nStraße Hausnummer\nPLZ Ort\nE-Mail: name@example.org")
        : __("Name der Schule\nVertreten durch: Vorname Nachname\nStraße Hausnummer\nPLZ Ort\nE-Mail: name@example.org"))) ?>"><?= e($operatorContact) ?></textarea>
      <p class="hint" id="cc-hint"><?= __h('Die Schule bzw. der Betreiber ist für alle Terminrunden datenschutzrechtlich verantwortlich. Bitte {bold} eintragen (wie im Beispiel) – genau dieser Text erscheint in Impressum und Datenschutzerklärung. Gilt für alle Admins gemeinsam. Ohne diese Angaben ist keine Runde öffentlich erreichbar.', ['bold' => '<strong>' . e(__('vollständigen Namen, Anschrift und E-Mail-Adresse')) . '</strong>']) ?></p>
    </div>
    <div class="actions"><button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button></div>
  </form>
</section>
<section class="card" id="parent-contact">
  <h2><?= e(__('Kontakt für Eltern bei Fragen')) ?></h2>
  <form method="post" action="<?= e(url('/admin/texts/contact')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <div class="field">
      <label for="parent_contact"><?= e(__('Allgemeiner Kontakt (z. B. Sekretariat)')) ?> <span class="muted small"><?= e(__('(optional)')) ?></span></label>
      <input type="text" id="parent_contact" name="parent_contact" maxlength="<?= App\ParentContact::MAX_LENGTH ?>" value="<?= e($parentContact ?? '') ?>" placeholder="<?= e(__('z. B. Sekretariat, Telefon 01234 5678, montags bis freitags 8 bis 12 Uhr')) ?>" aria-describedby="gpc-hint">
      <p class="hint" id="gpc-hint"><?= e(__('Eltern sehen ihn, wenn sie nicht weiterkommen: auf der Seite „Nicht gefunden“ (z. B. bei einem vertippten Link), bei Runden mit Rundencode vor der Eingabe des Codes und bei allen Runden von Lehrkräften, die unter „Mein Profil“ keinen eigenen Kontakt angegeben haben. Er wird nicht übersetzt: bitte kurz und ohne Abkürzungen. Ohne Eintrag verweist die Seite auf das Impressum.')) ?></p>
    </div>
    <div class="actions"><button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button></div>
  </form>
</section>
<?php foreach ($legalHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?> <?= __h('Die Vorlage steht in {file} bzw. {file2}.', ['file' => '<code>app/templates/pages/' . e($privacyTemplate) . '.de.md</code>', 'file2' => '<code>' . e($privacyTemplate) . '.en.md</code>']) ?></p></div>
<?php endforeach; ?>
<?php foreach ($outdatedHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status" data-legal-outdated><p><?= e($h) ?></p></div>
<?php endforeach; ?>
<section class="card">
  <p><?= __h('Diese Texte gelten für die gesamte Plattform. In Impressum und Datenschutzerklärung wird die Stelle {controller} automatisch durch die Angaben oben ersetzt. {hoster} in der Datenschutzerklärung wird durch die Angaben zum Hoster aus der Konfiguration ersetzt.', ['controller' => '<code>[[VERANTWORTLICHE]]</code>', 'hoster' => '<code>[[HOSTER]]</code>']) ?></p>
  <p><?= __h('Impressum und Datenschutzerklärung gibt es als Vorlage für beide Betriebsarten. Ändern Sie oben die Betriebsart, werden unveränderte Vorlagentexte durch die passende Vorlage ersetzt, bearbeitete Texte nie; eine Vorlage laden Sie beim jeweiligen Text über „Vorlage laden“. Angaben in eckigen Klammern wie {example} müssen Sie selbst ersetzen – vorher lässt sich keine Runde aktivieren.', ['example' => '<code>[ZUSTÄNDIGE LANDESDATENSCHUTZBEHÖRDE MIT ADRESSE]</code>']) ?></p>
  <ul class="link-list">
    <?php foreach ($languages as $l): ?>
      <li><a href="<?= e(url('/admin/page/imprint/' . $l)) ?>"><?= e(__('Impressum ({lang})', ['lang' => strtoupper($l)])) ?></a><?= $behind("imprint/$l") ?></li>
      <li><a href="<?= e(url('/admin/page/privacy/' . $l)) ?>"><?= e(__('Datenschutzerklärung ({lang})', ['lang' => strtoupper($l)])) ?></a><?= $behind("privacy/$l") ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<section class="card">
  <h2><?= e(__('E-Mail-Versand')) ?></h2>
  <p class="small"><?= __h('Versand: {transport}, Absender: {from} (Änderung über die Deployment-Konfiguration).', ['transport' => '<strong>' . e($mailTransport) . '</strong>', 'from' => '<strong>' . e($mailFrom !== '' ? $mailFrom : __('nicht konfiguriert')) . '</strong>']) ?></p>
</section>
