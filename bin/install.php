<?php
/**
 * Installation from the command line (alternative to public/install.php):
 *   php bin/install.php <username> [notification-email]
 * The password is prompted for without echo (twice), then the operator type (school or private); piped input
 * gives the three answers one per line, e.g. printf 'pw\npw\nprivate\n' | php bin/install.php sabrina
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
App\Platform::enforce(true); // needs the database: also pdo_mysql

use App\Installer;

if (Installer::isInstalled()) {
    fwrite(STDERR, "Already installed.\n");
    exit(1);
}
$username = $argv[1] ?? '';
$email = $argv[2] ?? '';

// Hide the password while typing; shell_exec is often disabled on shared hosting (calling it would be an error)
$hide = stream_isatty(STDIN) && function_exists('shell_exec');
$ask = static function (string $prompt) use ($hide): string {
    echo $prompt;
    if ($hide) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string) fgets(STDIN), "\r\n");
    if ($hide) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $v;
};
// Before asking for the password: MariaDB from 10.6 on, app/var writable
$errors = Installer::environmentProblems();
if (!$errors) {
    if (stream_isatty(STDIN) && !$hide) {
        echo "(shell_exec is disabled on this server: the password will be visible while you type it)\n";
    }
    $password = $ask('Password: ');
    $password2 = $ask('Repeat password: ');
    // Who runs the platform decides the templates of legal notice and privacy policy
    echo 'Operated by the school as a public body (school) or privately, e.g. by a private person, association or independent provider (private): ';
    $type = trim((string) fgets(STDIN));

    $errors = Installer::validate($username, $password, $password2, $email);
    if (($typeError = Installer::validateType($type)) !== null) {
        $errors[] = $typeError;
    }
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\nUsage: php bin/install.php <username> [email]\n"
        . "Input: password, password again, operator type (school or private), one per line\n");
    exit(1);
}
Installer::install($username, $password, $email, '', $type);
echo "Installation complete.\n";
