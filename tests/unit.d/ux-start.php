<?php
// Usability: "Bereit für den Start?" and "Was ist neu" for admins (see README.md in this directory)
echo "Ready to start, what's new (expert review P2-13)\n";
$rsItems = [
    ['key' => 'legal', 'state' => App\Readiness::TODO, 'title' => 'Impressum und Datenschutzerklärung', 'text' => 'Bis das erledigt ist, lassen sich keine Runden aktivieren.',
        'details' => ['Die Angaben für Impressum <und> Datenschutz fehlen.'], 'link' => '/admin/texts', 'linkText' => 'Zu den Texten'],
    ['key' => 'mail', 'state' => App\Readiness::CHECK, 'title' => 'E-Mail-Versand', 'text' => 'Versand über SMTP.', 'details' => [], 'link' => null, 'linkText' => ''],
    ['key' => 'rights', 'state' => App\Readiness::DONE, 'title' => 'Rechte der Konfigurationsdatei', 'text' => 'Nur für den Webserver lesbar.', 'details' => [], 'link' => null, 'linkText' => ''],
];
check('checklist: points done counted, every state named in words (German, English)',
    App\Readiness::done($rsItems) === 1 && App\Readiness::stateLabel(App\Readiness::TODO) === 'Noch offen' && App\Readiness::stateLabel(App\Readiness::CHECK) === 'Bitte prüfen'
    && App\AdminI18n::with('en', static fn () => App\Readiness::stateLabel(App\Readiness::DONE)) === 'Done');
$rsPage = App\View::capture('admin/readiness', ['title' => 'Bereit für den Start?', 'items' => $rsItems, 'started' => false]);
check('checklist page: state in words in each heading (not colour alone), details escaped, the way to fix it',
    str_contains($rsPage, 'data-check="legal" data-state="todo"') && str_contains($rsPage, '<span aria-hidden="true">✗</span> Noch offen:</span> Impressum und Datenschutzerklärung</h2>')
    && str_contains($rsPage, '<li>Die Angaben für Impressum &lt;und&gt; Datenschutz fehlen.</li>') && str_contains($rsPage, '<a href="/admin/texts">Zu den Texten</a>')
    && str_contains($rsPage, '1 von 3 Punkten erledigt.') && str_contains($rsPage, 'fasst die Übersicht der Runden diese Punkte zusammen'));
// Dashboard while no round has been public: one checklist instead of the setup warnings it covers
$rsDash = static fn (?array $readiness, array $news = []): string => App\View::capture('admin/dashboard', ['title' => 'x', 'rounds' => [], 'showOwner' => true,
    'configExposed' => ['config/config.php' => '0644'], 'profileHints' => [], 'operatorMissing' => false, 'legalHints' => ['Die Angaben für Impressum und Datenschutz fehlen.'],
    'mailsLate' => null, 'unusedAccounts' => 0, 'setupHints' => ['Einstellung x wirkt nicht.'], 'cron' => ['state' => 'never', 'last' => null],
    'readiness' => $readiness, 'whatsNew' => $news]);
$rsSetup = $rsDash($rsItems);
$rsLater = $rsDash(null);
check('dashboard during setup: the checklist with the open points (done ones left out) instead of the setup warnings',
    str_contains($rsSetup, 'data-readiness-summary') && str_contains($rsSetup, '1 von 3 Punkten erledigt.') && str_contains($rsSetup, '<strong>Noch offen: Impressum und Datenschutzerklärung</strong>')
    && str_contains($rsSetup, '<li>Die Angaben für Impressum &lt;und&gt; Datenschutz fehlen.</li>') && !str_contains($rsSetup, 'Rechte der Konfigurationsdatei')
    && !str_contains($rsSetup, 'data-setup-hints') && !str_contains($rsSetup, 'ist für alle Benutzer des Servers lesbar') && !str_contains($rsSetup, 'Es ist noch kein vollständiger Lauf des Cronjobs')
    && !str_contains($rsSetup, 'Zu den Texten</a></p></div>'));
check('dashboard once in use: no checklist, the warnings as before',
    !str_contains($rsLater, 'data-readiness-summary') && str_contains($rsLater, 'data-setup-hints') && str_contains($rsLater, 'ist für alle Benutzer des Servers lesbar')
    && str_contains($rsLater, 'Es ist noch kein vollständiger Lauf des Cronjobs') && str_contains($rsLater, 'Die Angaben für Impressum und Datenschutz fehlen.'));
$rsNews = $rsDash(null, ['Neu: <b>A</b>', 'Neu: B']);
check('what\'s new: the notes (escaped) with a button that confirms them; nothing without notes',
    str_contains($rsNews, 'data-whats-new') && str_contains($rsNews, '<li>Neu: &lt;b&gt;A&lt;/b&gt;</li><li>Neu: B</li>') && str_contains($rsNews, '<form method="post" action="/admin/news">')
    && !str_contains($rsLater, 'data-whats-new'));
$rsKeys = array_column(App\WhatsNew::entries(), 'key');
$rsSorted = $rsKeys;
sort($rsSorted, SORT_STRING);
check('what\'s new: keys are dates with a letter, unique, oldest first; every note has a text',
    $rsKeys && $rsKeys === $rsSorted && count(array_unique($rsKeys)) === count($rsKeys) && App\WhatsNew::latest() === end($rsKeys)
    && !array_filter($rsKeys, static fn (string $k): bool => preg_match('/^\d{4}-\d\d-\d\d[a-z]$/', $k) !== 1)
    && !array_filter(App\WhatsNew::entries(), static fn (array $e): bool => trim($e['text']) === '' || !is_bool($e['admin'])));
