<?php /** @var array $languages @var string $mailTransport @var string $mailFrom @var string $operatorContact @var list<string> $operatorHints */ use App\Csrf; ?>
<h1><?= e(__('Impressum & Datenschutz')) ?></h1>
<?php foreach ($operatorHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?></p></div>
<?php endforeach; ?>
<section class="card">
  <h2><?= e(__('Angaben für Impressum und Datenschutz')) ?></h2>
  <form method="post" action="<?= e(url('/admin/texts/operator')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <div class="field">
      <label for="controller_contact"><?= e(__('Verantwortliche Stelle')) ?></label>
      <textarea id="controller_contact" name="controller_contact" rows="5" maxlength="2000" aria-describedby="cc-hint" placeholder="<?= str_replace("\n", '&#10;', e(__("Name der Schule\nVertreten durch: Vorname Nachname\nStraße Hausnummer\nPLZ Ort\nE-Mail: name@example.org"))) ?>"><?= e($operatorContact) ?></textarea>
      <p class="hint" id="cc-hint"><?= __h('Die Schule bzw. der Betreiber ist für alle Terminrunden datenschutzrechtlich verantwortlich. Bitte {bold} eintragen (wie im Beispiel) – genau dieser Text erscheint in Impressum und Datenschutzerklärung. Gilt für alle Admins gemeinsam. Ohne diese Angaben ist keine Runde öffentlich erreichbar.', ['bold' => '<strong>' . e(__('vollständigen Namen, Anschrift und E-Mail-Adresse')) . '</strong>']) ?></p>
    </div>
    <div class="actions"><button type="submit" class="button button-primary"><?= e(__('Speichern')) ?></button></div>
  </form>
</section>
<?php foreach ($legalHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?> <?= __h('Die Vorlage steht in {file} bzw. {file2}.', ['file' => '<code>app/templates/pages/privacy.de.md</code>', 'file2' => '<code>privacy.en.md</code>']) ?></p></div>
<?php endforeach; ?>
<section class="card">
  <p><?= __h('Diese Texte gelten für die gesamte Plattform. In Impressum und Datenschutzerklärung wird die Stelle {controller} automatisch durch die Angaben oben ersetzt. {hoster} in der Datenschutzerklärung wird durch die Angaben zum Hoster aus der Konfiguration ersetzt.', ['controller' => '<code>[[VERANTWORTLICHE]]</code>', 'hoster' => '<code>[[HOSTER]]</code>']) ?></p>
  <ul class="link-list">
    <?php foreach ($languages as $l): ?>
      <li><a href="<?= e(url('/admin/page/imprint/' . $l)) ?>"><?= e(__('Impressum ({lang})', ['lang' => strtoupper($l)])) ?></a></li>
      <li><a href="<?= e(url('/admin/page/privacy/' . $l)) ?>"><?= e(__('Datenschutzerklärung ({lang})', ['lang' => strtoupper($l)])) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<section class="card">
  <h2><?= e(__('E-Mail-Versand')) ?></h2>
  <p class="small"><?= __h('Versand: {transport}, Absender: {from} (Änderung über die Deployment-Konfiguration).', ['transport' => '<strong>' . e($mailTransport) . '</strong>', 'from' => '<strong>' . e($mailFrom !== '' ? $mailFrom : __('nicht konfiguriert')) . '</strong>']) ?></p>
</section>
