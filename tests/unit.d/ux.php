<?php
// Usability: ways on for parents and guidance for admins (see README.md in this directory)
echo "Ways on for parents (expert review P2-1)\n";
check('contact for parents: one line, control characters and runs of spaces collapsed, trimmed',
    App\ParentContact::clean("  Sekretariat,\r\n\tTel.\u{0007}01234  5678\u{2028}(Raum 1) ") === 'Sekretariat, Tel. 01234 5678 (Raum 1)'
    && App\ParentContact::clean("Büro 👩\u{200D}🏫") === "Büro 👩\u{200D}🏫"
    && App\ParentContact::clean(" \n ") === '');
$langs = array_map(static fn (string $f): string => basename($f, '.php'), glob(APP_ROOT . '/app/lang/*.php') ?: []);
$contactTexts = array_map(static fn (string $l): string => (string) ((require APP_ROOT . "/app/lang/$l.php")['contact'] ?? ''), $langs);
check('contact for parents: the sentence names the contact in every parent language (' . count($langs) . ')',
    count($langs) === 6 && !array_filter($contactTexts, static fn (string $t): bool => !str_contains($t, '{contact}')));
$nf = static fn (array $vars): string => App\View::capture('public/notfound', $vars + ['title' => 'Nicht gefunden', 'text' => 'weg']);
$nfCode = $nf(['codeForm' => true, 'contact' => 'Sekretariat <Raum 1>']);
$nfRate = $nf(['codeForm' => false, 'contact' => '']);
check('not found: the code input of the start page and the contact (escaped); otherwise the way to the start page and, without a contact, to the imprint',
    str_contains($nfCode, '<form method="post" action="/" class="card pin-form" data-code-form>') && str_contains($nfCode, 'name="code"')
    && !str_contains($nfCode, 'aria-invalid') && str_contains($nfCode, '<p class="contact" data-contact>Bei Fragen: Sekretariat &lt;Raum 1&gt;</p>')
    && !str_contains($nfRate, 'data-code-form') && str_contains($nfRate, '<a class="button" href="/">Zur Startseite</a>') && !str_contains($nfRate, 'data-contact>')
    && str_contains($nfRate, '<p class="contact" data-contact-none>Bei Fragen wenden Sie sich bitte an die Schule bzw. den Betreiber dieser Seite (siehe <a href="/impressum">Impressum</a>).</p>')
    && !str_contains($nf([]), 'data-code-form'));
// The contact is not translated: on a page in another language it is marked with the default language (WCAG 3.1.2)
App\I18n::set('tr');
$nfTr = $nf(['codeForm' => false, 'contact' => 'Sekretariat']);
App\I18n::set('de');
$roundContact = static fn (array $vars): string => App\View::capture('public/_contact', $vars);
check('contact: marked with the default language on a page in another language; nothing without a contact outside a dead end',
    str_contains($nfTr, '<p class="contact" data-contact>Sorularınız için: <span lang="de">Sekretariat</span></p>')
    && !str_contains($nf(['contact' => 'Sekretariat']), '<span lang=') && trim($roundContact(['contact' => ''])) === '');
$home = App\View::capture('public/home', ['errors' => ['Code falsch'], 'code' => 'K7MP']);
check('start page: the same code input, marked invalid after an error and filled in',
    str_contains($home, 'data-code-form') && str_contains($home, 'value="K7MP" aria-describedby="code-hint" aria-invalid="true" autofocus'));
