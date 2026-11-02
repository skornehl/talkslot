<?php
/**
 * Erzeugt config/config.php aus Umgebungsvariablen (für das Deployment per
 * GitHub Actions; Werte stammen aus Repository-Secrets bzw. -Variablen).
 *   php bin/build-config.php <zieldatei>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$target = $argv[1] ?? '';
if ($target === '') {
    fwrite(STDERR, "Aufruf: php bin/build-config.php <zieldatei>\n");
    exit(1);
}

// Werte ohne führende/abschließende Leerzeichen und Zeilenumbrüche (z. B. aus Copy & Paste)
$env = static fn (string $k, string $default = ''): string => ($v = trim((string) getenv($k))) !== '' ? $v : $default;
// Installationsschlüssel nur, solange installiert werden darf (Variable ALLOW_INSTALL=1)
$allowInstall = $env('ALLOW_INSTALL') === '1';
$required = array_merge(['BASE_URL', 'APP_SECRET', 'DB_USER', 'DB_PASS'], $allowInstall ? ['INSTALL_KEY'] : []);
foreach ($required as $k) {
    if ($env($k) === '') {
        fwrite(STDERR, "Fehlende Variable/Secret: $k\n");
        exit(1);
    }
}
// Platzhalter aus der Ersteinrichtung dürfen nie deployt werden
foreach (['APP_SECRET', 'INSTALL_KEY', 'DB_USER', 'DB_PASS', 'DB_NAME', 'SMTP_USER', 'SMTP_PASS'] as $k) {
    if ($env($k) === 'CHANGE_ME') {
        fwrite(STDERR, "Secret $k enthält noch den Platzhalter CHANGE_ME\n");
        exit(1);
    }
}
// Werte mit festem Vorrat prüfen (Tippfehler bei SMTP_ENCRYPTION hieße sonst: Passwort im Klartext)
$checks = [
    'MAIL_TRANSPORT' => ['mail', 'smtp', 'none'],
    'SMTP_ENCRYPTION' => ['tls', 'ssl', 'none'],
];
foreach ($checks as $k => $allowed) {
    if ($env($k) !== '' && !in_array($env($k), $allowed, true)) {
        fwrite(STDERR, "$k muss einer dieser Werte sein: " . implode(', ', $allowed) . "\n");
        exit(1);
    }
}
$prefix = getenv('DB_PREFIX') !== false ? trim((string) getenv('DB_PREFIX')) : 'tp_';
if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,20}$/', $prefix)) {
    fwrite(STDERR, "DB_PREFIX: nur Buchstaben, Ziffern und _ (höchstens 20 Zeichen)\n");
    exit(1);
}
if (strlen($env('APP_SECRET')) < 32 || ($allowInstall && strlen($env('INSTALL_KEY')) < 16)) {
    fwrite(STDERR, "APP_SECRET (>= 32 Zeichen) oder INSTALL_KEY (>= 16 Zeichen) zu kurz\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config/config.sample.php';
$config['base_url'] = rtrim($env('BASE_URL'), '/');
$config['secret'] = $env('APP_SECRET');
$config['install_key'] = $allowInstall ? $env('INSTALL_KEY') : '';
$config['db'] = [
    'host' => $env('DB_HOST', 'localhost'),
    'port' => (int) $env('DB_PORT', '3306'),
    'name' => $env('DB_NAME', $env('DB_USER')),
    'user' => $env('DB_USER'),
    'pass' => $env('DB_PASS'),
    // Tabellen-Präfix (Variable DB_PREFIX, Standard tp_; leer setzen = kein Präfix)
    'prefix' => $prefix,
];
$config['alert_email'] = $env('ALERT_EMAIL');
$config['mail']['transport'] = $env('MAIL_TRANSPORT', 'mail');
$config['mail']['from_address'] = $env('MAIL_FROM');
$config['mail']['from_name'] = $env('MAIL_FROM_NAME', 'TalkSlot');
$config['mail']['reply_to'] = $env('MAIL_REPLY_TO');
$config['mail']['smtp'] = [
    'host' => $env('SMTP_HOST'),
    'port' => (int) $env('SMTP_PORT', '587'),
    'encryption' => $env('SMTP_ENCRYPTION', 'tls'),
    'user' => $env('SMTP_USER'),
    'pass' => $env('SMTP_PASS'),
];

$php = "<?php\n// Automatisch erzeugt durch bin/build-config.php beim Deployment – nicht von Hand ändern.\nreturn "
    . var_export($config, true) . ";\n";
file_put_contents($target, $php);
chmod($target, 0600);
echo "config geschrieben\n";
