<?php
// Errors at the field (expert review P2-2; see README.md in this directory)
echo "Errors at the field (expert review P2-2)\n";
check('field attributes: nothing without error or hint; the hint alone; invalid with the error before the hint',
    field_attrs([], 'name') === ''
    && field_attrs(['Allgemein'], 'name', 'name-hint') === ' aria-describedby="name-hint"'
    && field_attrs(['name' => 'Fehlt'], 'name') === ' aria-invalid="true" aria-describedby="name-error"'
    && field_attrs(['name' => 'Fehlt'], 'name', 'name-hint') === ' aria-invalid="true" aria-describedby="name-error name-hint"'
    && field_attrs(['email' => 'Falsch'], 'name', 'name-hint') === ' aria-describedby="name-hint"');
check('field message: the id field_attrs() names, escaped; nothing without an error of this field',
    field_error(['name' => '<b>Fehlt</b>'], 'name') === '<p class="field-error" id="name-error">&lt;b&gt;Fehlt&lt;/b&gt;</p>'
    && field_error(['email' => 'Falsch', 'Allgemein'], 'name') === '');
foreach (['public/_errors', 'admin/_errors'] as $partial) {
    $html = App\View::capture($partial, ['errors' => ['name' => 'Name <fehlt>', 'Etwas anderes']]);
    check("$partial: an error of a field links to it, another stays plain text, both escaped",
        str_contains($html, '<li><a href="#name">Name &lt;fehlt&gt;</a></li>') && str_contains($html, '<li>Etwas anderes</li>') && substr_count($html, '<a ') === 1);
}
$validated = App\Controller\PublicController::validate(['slot_id' => '', 'name' => 'x', 'email' => 'kaputt'], true);
check('booking: each error under the id of its field', array_keys($validated) === ['slot_id', 'name', 'email']);
check('booking: a link in the name counts for the name field',
    App\Controller\PublicController::validate(['name' => 'www.example.org', 'email' => ''], false) === ['name' => t('err.name_link')]);
