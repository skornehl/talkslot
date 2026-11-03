<?php /** @var array $languages @var string $mailTransport @var string $mailFrom @var string $operatorContact @var list<string> $operatorHints */ use App\Csrf; ?>
<h1>Impressum &amp; Datenschutz</h1>
<?php foreach ($operatorHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?></p></div>
<?php endforeach; ?>
<section class="card">
  <h2>Angaben für Impressum und Datenschutz</h2>
  <form method="post" action="<?= e(url('/admin/texts/operator')) ?>" class="form-grid">
    <?= Csrf::adminField() ?>
    <div class="field">
      <label for="controller_contact">Verantwortliche Stelle</label>
      <textarea id="controller_contact" name="controller_contact" rows="5" maxlength="2000" aria-describedby="cc-hint" placeholder="Name der Schule&#10;Vertreten durch: Vorname Nachname&#10;Straße Hausnummer&#10;PLZ Ort&#10;E-Mail: name@example.org"><?= e($operatorContact) ?></textarea>
      <p class="hint" id="cc-hint">Die Schule bzw. der Betreiber ist für alle Terminrunden datenschutzrechtlich verantwortlich. Bitte <strong>vollständigen Namen, Anschrift und E-Mail-Adresse</strong> eintragen (wie im Beispiel) – genau dieser Text erscheint in Impressum und Datenschutzerklärung. Gilt für alle Admins gemeinsam. Ohne diese Angaben ist keine Runde öffentlich erreichbar.</p>
    </div>
    <div class="actions"><button type="submit" class="button button-primary">Speichern</button></div>
  </form>
</section>
<?php foreach ($legalHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?> Die Vorlage steht in <code>app/templates/pages/privacy.de.md</code> bzw. <code>privacy.en.md</code>.</p></div>
<?php endforeach; ?>
<section class="card">
  <p>Diese Texte gelten für die gesamte Plattform. In Impressum und Datenschutzerklärung wird die Stelle <code>[[VERANTWORTLICHE]]</code> automatisch durch die Angaben oben ersetzt. <code>[[HOSTER]]</code> in der Datenschutzerklärung wird durch die Angaben zum Hoster aus der Konfiguration ersetzt.</p>
  <ul class="link-list">
    <?php foreach ($languages as $l): ?>
      <li><a href="<?= e(url('/admin/page/imprint/' . $l)) ?>">Impressum (<?= e(strtoupper($l)) ?>)</a></li>
      <li><a href="<?= e(url('/admin/page/privacy/' . $l)) ?>">Datenschutzerklärung (<?= e(strtoupper($l)) ?>)</a></li>
    <?php endforeach; ?>
  </ul>
</section>
<section class="card">
  <h2>E-Mail-Versand</h2>
  <p class="small">Versand: <strong><?= e($mailTransport) ?></strong>, Absender: <strong><?= e($mailFrom !== '' ? $mailFrom : 'nicht konfiguriert') ?></strong> (Änderung über die Deployment-Konfiguration).</p>
</section>
