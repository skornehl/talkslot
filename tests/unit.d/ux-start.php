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
    str_contains($rsPage, 'data-check="legal" data-state="todo"') && str_contains($rsPage, '<span aria-hidden="true">○</span> Noch offen:</span> Impressum und Datenschutzerklärung</h2>')
    && str_contains($rsPage, '<li>Die Angaben für Impressum &lt;und&gt; Datenschutz fehlen.</li>') && str_contains($rsPage, '<a href="/admin/texts">Zu den Texten</a>')
    && str_contains($rsPage, '1 von 3 Punkten erledigt.') && str_contains($rsPage, 'Bis Sie die erste Runde aktivieren, steht diese Liste auch oben unter „Alle Runden“.'));
// Dashboard while no round has been public: one checklist instead of the setup warnings it covers
$rsDash = static fn (?array $readiness, array $news = []): string => App\View::capture('admin/dashboard', ['title' => 'x', 'rounds' => [], 'showOwner' => true,
    'configExposed' => ['config/config.php' => '0644'], 'profileHints' => [], 'operatorMissing' => false, 'legalHints' => ['Die Angaben für Impressum und Datenschutz fehlen.'],
    'mailsLate' => ['count' => 2, 'since' => '2026-11-01 10:00:00'], 'unusedAccounts' => 0, 'setupHints' => ['Einstellung x wirkt nicht.'], 'cron' => ['state' => 'never', 'last' => null],
    'readiness' => $readiness, 'whatsNew' => $news]);
$rsSetup = $rsDash($rsItems);
$rsLater = $rsDash(null);
check('dashboard during setup: the checklist with the open points (done ones left out), each with its way to fix it, instead of the setup warnings',
    str_contains($rsSetup, '<div class="alert alert-warning" role="status" data-readiness-summary>') && str_contains($rsSetup, '1 von 3 Punkten erledigt.') && str_contains($rsSetup, '<strong>Noch offen: Impressum und Datenschutzerklärung</strong>')
    && str_contains($rsSetup, '<a href="/admin/texts">Zu den Texten</a></li>') && !str_contains($rsSetup, 'data-mails-late')
    && str_contains($rsSetup, '<li>Die Angaben für Impressum &lt;und&gt; Datenschutz fehlen.</li>') && !str_contains($rsSetup, 'Rechte der Konfigurationsdatei')
    && !str_contains($rsSetup, 'data-setup-hints') && !str_contains($rsSetup, 'ist für alle Benutzer des Servers lesbar') && !str_contains($rsSetup, 'Es ist noch kein vollständiger Lauf des Cronjobs')
    && !str_contains($rsSetup, 'Zu den Texten</a></p></div>'));
check('dashboard once in use: no checklist, the warnings as before',
    !str_contains($rsLater, 'data-readiness-summary') && str_contains($rsLater, 'data-setup-hints') && str_contains($rsLater, 'ist für alle Benutzer des Servers lesbar')
    && str_contains($rsLater, 'Es ist noch kein vollständiger Lauf des Cronjobs') && str_contains($rsLater, 'Die Angaben für Impressum und Datenschutz fehlen.') && str_contains($rsLater, 'data-mails-late'));
$rsCheckOnly = $rsDash([$rsItems[1], $rsItems[2]]);
check('dashboard during setup with nothing left to do: the checklist as information, not as a warning',
    str_contains($rsCheckOnly, '<div class="alert alert-info" role="status" data-readiness-summary>') && str_contains($rsCheckOnly, 'Bitte prüfen: E-Mail-Versand'));
$rsNews = $rsDash(null, [['key' => 'a', 'admin' => false, 'text' => 'Neu: <b>A</b>', 'link' => '/admin/profile'], ['key' => 'b', 'admin' => true, 'text' => 'Neu: B', 'link' => null]]);
check('what\'s new: the notes (escaped) with the page they are about, a button that confirms them; nothing without notes',
    str_contains($rsNews, 'data-whats-new') && str_contains($rsNews, '<strong id="news-title">Neu seit dem letzten Update</strong>')
    && str_contains($rsNews, '<li>Neu: &lt;b&gt;A&lt;/b&gt; <a href="/admin/profile">Ansehen</a></li><li>Neu: B</li>')
    && str_contains($rsNews, '<form method="post" action="/admin/news">') && str_contains($rsNews, 'aria-describedby="news-title">Verstanden</button>')
    && !str_contains($rsLater, 'data-whats-new'));
$rsE = [['key' => '2026-11-05a', 'admin' => true, 'text' => 'A', 'link' => null], ['key' => '2026-11-05b', 'admin' => false, 'text' => 'B', 'link' => null],
    ['key' => '2027-02-01a', 'admin' => false, 'text' => 'C', 'link' => null]];
$rsArr = ['2026-11-05a' => 1000, '2026-11-05b' => 1000, '2027-02-01a' => 5000];
$rsVis = static fn (string $seen, int $created, bool $admin, ?array $arrived = null): string => implode('', array_column(App\WhatsNew::visible($rsE, $seen, $arrived ?? $rsArr, $created, $admin), 'text'));
check('what\'s new: newer than the confirmed note, arrived after the account was created, teachers only theirs',
    $rsVis('', 500, true) === 'ABC' && $rsVis('', 500, false) === 'BC' && $rsVis('2026-11-05b', 500, true) === 'C'
    && $rsVis('', 2000, true) === 'C' && $rsVis('', 6000, true) === '' && $rsVis('2027-02-01a', 500, true) === ''
    && $rsVis('', 500, true, ['2026-11-05a' => 0, '2026-11-05b' => 0]) === 'C');
$rsKeys = array_column(App\WhatsNew::entries(), 'key');
$rsSorted = $rsKeys;
sort($rsSorted, SORT_STRING);
check('what\'s new: keys are dates with a letter, unique, oldest first; every note has a text',
    $rsKeys && $rsKeys === $rsSorted && count(array_unique($rsKeys)) === count($rsKeys) && App\WhatsNew::latest() === end($rsKeys)
    && !array_filter($rsKeys, static fn (string $k): bool => preg_match('/^\d{4}-\d\d-\d\d[a-z]$/', $k) !== 1)
    && !array_filter(App\WhatsNew::entries(), static fn (array $e): bool => trim($e['text']) === '' || !is_bool($e['admin'])));
