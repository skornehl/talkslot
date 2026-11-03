<?php
/**
 * Generates config/config.php from environment variables (for deployment via
 * GitHub Actions; values come from repository secrets and variables).
 *   php bin/build-config.php <target file>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$target = $argv[1] ?? '';
if ($target === '') {
    fwrite(STDERR, "Usage: php bin/build-config.php <target file>\n");
    exit(1);
}

// Values without leading/trailing whitespace and line breaks (e.g. from copy & paste)
$env = static fn (string $k, string $default = ''): string => ($v = trim((string) getenv($k))) !== '' ? $v : $default;
// Install key only while installation is allowed (variable ALLOW_INSTALL=1)
$allowInstall = $env('ALLOW_INSTALL') === '1';
$required = array_merge(['BASE_URL', 'APP_SECRET', 'DB_USER', 'DB_PASS'], $allowInstall ? ['INSTALL_KEY'] : []);
foreach ($required as $k) {
    if ($env($k) === '') {
        fwrite(STDERR, "Missing variable/secret: $k\n");
        exit(1);
    }
}
// Placeholders from the initial setup must never be deployed
foreach (['APP_SECRET', 'INSTALL_KEY', 'DB_USER', 'DB_PASS', 'DB_NAME', 'SMTP_USER', 'SMTP_PASS'] as $k) {
    if ($env($k) === 'CHANGE_ME') {
        fwrite(STDERR, "Secret $k still contains the placeholder CHANGE_ME\n");
        exit(1);
    }
}
// Check values with a fixed set of options (a typo in SMTP_ENCRYPTION would otherwise mean: password in plain text)
$checks = [
    'MAIL_TRANSPORT' => ['mail', 'smtp', 'none'],
    'SMTP_ENCRYPTION' => ['tls', 'ssl', 'none'],
];
foreach ($checks as $k => $allowed) {
    if ($env($k) !== '' && !in_array($env($k), $allowed, true)) {
        fwrite(STDERR, "$k must be one of: " . implode(', ', $allowed) . "\n");
        exit(1);
    }
}
$prefix = getenv('DB_PREFIX') !== false ? trim((string) getenv('DB_PREFIX')) : 'tp_';
if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,20}$/', $prefix)) {
    fwrite(STDERR, "DB_PREFIX: only letters, digits and _ (at most 20 characters)\n");
    exit(1);
}
if (strlen($env('APP_SECRET')) < 32 || ($allowInstall && strlen($env('INSTALL_KEY')) < 16)) {
    fwrite(STDERR, "APP_SECRET (>= 32 characters) or INSTALL_KEY (>= 16 characters) too short\n");
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
    // Table prefix (variable DB_PREFIX, default tp_; set empty = no prefix)
    'prefix' => $prefix,
];
$config['alert_email'] = $env('ALERT_EMAIL');
$config['app_name'] = $env('APP_NAME', (string) $config['app_name']);
$config['hosting'] = ['de' => $env('HOSTING_DE'), 'en' => $env('HOSTING_EN')];
$config['mail']['transport'] = $env('MAIL_TRANSPORT', 'mail');
$config['mail']['from_address'] = $env('MAIL_FROM');
$config['mail']['from_name'] = $env('MAIL_FROM_NAME', (string) $config['app_name']);
$config['mail']['reply_to'] = $env('MAIL_REPLY_TO');
$config['mail']['smtp'] = [
    'host' => $env('SMTP_HOST'),
    'port' => (int) $env('SMTP_PORT', '587'),
    'encryption' => $env('SMTP_ENCRYPTION', 'tls'),
    'user' => $env('SMTP_USER'),
    'pass' => $env('SMTP_PASS'),
];

$php = "<?php\n// Generated automatically by bin/build-config.php during deployment – do not edit by hand.\nreturn "
    . var_export($config, true) . ";\n";
file_put_contents($target, $php);
chmod($target, 0600);
echo "config written\n";
