<?php
// Account security and security invariants (see README.md in this directory)
echo "Security: permissions of config.php\n";
// Expert review P2-11: config.php holds the database and mail passwords and the secret. Admins are warned
// on the dashboard when every user of the server may read it; owner and group only (600, 640) is fine.
$cfgFile = (string) tempnam(sys_get_temp_dir(), 'tp-config');
$cfgMode = static function (int $perms) use ($cfgFile): ?string {
    chmod($cfgFile, $perms);
    return App\Config::exposedPermissions($cfgFile);
};
check('config.php readable for every user (644, 604, 666): its permissions are reported',
    $cfgMode(0644) === '0644' && $cfgMode(0604) === '0604' && $cfgMode(0666) === '0666');
check('config.php only for the owner or its group (600, 640, 660, 400): no warning',
    $cfgMode(0600) === null && $cfgMode(0640) === null && $cfgMode(0660) === null && $cfgMode(0400) === null);
unlink($cfgFile);
check('missing file: no warning', App\Config::exposedPermissions($cfgFile) === null);
check('without an argument: the config.php of this installation',
    App\Config::exposedPermissions() === App\Config::exposedPermissions(APP_ROOT . '/config/config.php'));

$dashboard = static fn (?string $perms): string => App\View::capture('admin/dashboard', [
    'title' => 'Alle Terminrunden', 'rounds' => [], 'showOwner' => true, 'configPermissions' => $perms,
    'profileHints' => [], 'operatorMissing' => false, 'legalHints' => [],
]);
check('dashboard: warning with the permissions, nothing without exposure',
    str_contains($dashboard('0644'), 'Die Datei config/config.php ist für alle Benutzer des Servers lesbar (Rechte 0644).')
    && !str_contains($dashboard(null), 'config/config.php'));
check('dashboard in English: warning translated',
    str_contains(App\AdminI18n::with('en', static fn () => $dashboard('0666')), 'The file config/config.php can be read by every user of the server (permissions 0666).'));
