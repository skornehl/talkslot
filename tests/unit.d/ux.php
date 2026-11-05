<?php
// Usability: ways on for parents and guidance for admins (see README.md in this directory)
echo "Ways on for parents (expert review P2-1)\n";
check('contact for parents: one line, control characters and runs of spaces collapsed, trimmed',
    App\ParentContact::clean("  Sekretariat,\r\n\tTel.\u{200B}01234  5678 ") === 'Sekretariat, Tel. 01234 5678'
    && App\ParentContact::clean(" \n ") === '');
$langs = array_map(static fn (string $f): string => basename($f, '.php'), glob(APP_ROOT . '/app/lang/*.php') ?: []);
$contactTexts = array_map(static fn (string $l): string => (string) ((require APP_ROOT . "/app/lang/$l.php")['contact'] ?? ''), $langs);
check('contact for parents: the sentence names the contact in every parent language (' . count($langs) . ')',
    count($langs) === 6 && !array_filter($contactTexts, static fn (string $t): bool => !str_contains($t, '{contact}')));
$nf = static fn (array $vars): string => App\View::capture('public/notfound', $vars + ['title' => 'Nicht gefunden', 'text' => 'weg']);
$nfCode = $nf(['codeForm' => true, 'contact' => 'Sekretariat <Raum 1>']);
$nfRate = $nf(['codeForm' => false, 'contact' => '']);
check('not found: the code input of the start page and the contact (escaped); otherwise the way to the start page, no contact',
    str_contains($nfCode, '<form method="post" action="/" class="card pin-form" data-code-form>') && str_contains($nfCode, 'name="code"')
    && !str_contains($nfCode, 'aria-invalid') && str_contains($nfCode, '<p class="contact" data-contact>Bei Fragen: Sekretariat &lt;Raum 1&gt;</p>')
    && !str_contains($nfRate, 'data-code-form') && str_contains($nfRate, '<a class="button" href="/">Zur Startseite</a>') && !str_contains($nfRate, 'data-contact')
    && !str_contains($nf([]), 'data-code-form'));
$home = App\View::capture('public/home', ['errors' => ['Code falsch'], 'code' => 'K7MP']);
check('start page: the same code input, marked invalid after an error and filled in',
    str_contains($home, 'data-code-form') && str_contains($home, 'value="K7MP" aria-describedby="code-hint" aria-invalid="true" autofocus'));
