<?php /** @var array $languages @var string $mailTransport @var string $mailFrom */ ?>
<h1>Impressum &amp; Datenschutz</h1>
<?php foreach ($legalHints ?? [] as $h): ?>
  <div class="alert alert-warning" role="status"><p><?= e($h) ?> Die Vorlage steht in <code>app/templates/pages/privacy.de.md</code> bzw. <code>privacy.en.md</code>.</p></div>
<?php endforeach; ?>
<section class="card">
  <p>Diese Texte gelten für die gesamte Plattform. In Impressum und Datenschutzerklärung wird die Stelle <code>[[VERANTWORTLICHE]]</code> automatisch durch Ihre Angaben aus <a href="<?= e(url('/admin/profile')) ?>">„Mein Profil“</a> ersetzt (als Admin sind Sie für alle Terminrunden verantwortlich). <code>[[HOSTER]]</code> in der Datenschutzerklärung wird durch die Angaben zum Hoster aus der Konfiguration ersetzt.</p>
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
