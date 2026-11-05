<?php /** Choice of the operator type (installer, "Texte"). @var ?string $operatorType */ use App\Operator;
$typeHints = [
    'school' => __('Die Schule ist als öffentliche Stelle Verantwortliche im Sinne der DSGVO. Vorlagen: Rechtsgrundlage Art. 6 Abs. 1 lit. e DSGVO in Verbindung mit dem Schulgesetz des Landes, Datenschutzbeauftragte der Schule, Impressum der Schule. Betreibt jemand anderes den Server für die Schule, braucht die Schule mit ihm eine Vereinbarung nach Art. 28 DSGVO.'),
    'private' => __('Der Betreiber (Privatperson, Verein oder freier Träger) ist Verantwortlicher im Sinne der DSGVO; Lehrkräfte nutzen sein Angebot. Vorlagen: Rechtsgrundlagen Art. 6 Abs. 1 lit. b und f DSGVO, Impressum eines privaten, nicht kommerziellen Angebots; ein Verein oder freier Träger ergänzt dort die vertretungsberechtigte Person und gegebenenfalls den Registereintrag. Schulen können für ihre Lehrkräfte eigene Vorgaben haben.'),
]; ?>
<fieldset class="field">
  <legend><?= e(__('Betriebsart')) ?></legend>
  <?php foreach (Operator::TYPES as $t): ?>
  <div class="check">
    <input type="radio" id="operator_type_<?= e($t) ?>" name="operator_type" value="<?= e($t) ?>" required aria-describedby="operator_type_<?= e($t) ?>_hint"<?= ($operatorType ?? null) === $t ? ' checked' : '' ?>>
    <label for="operator_type_<?= e($t) ?>"><?= e(Operator::typeLabel($t)) ?></label>
  </div>
  <p class="hint hint-indent" id="operator_type_<?= e($t) ?>_hint"><?= e($typeHints[$t]) ?></p>
  <?php endforeach; ?>
</fieldset>
