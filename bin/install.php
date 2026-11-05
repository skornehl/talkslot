<?php
/**
 * Installation from the command line (alternative to public/install.php):
 *   php bin/install.php <username> [notification-email]
 * The password is prompted for without echo, then the operator type (school or private).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Installer;

if (Installer::isInstalled()) {
    fwrite(STDERR, "Already installed.\n");
    exit(1);
}
$username = $argv[1] ?? '';
$email = $argv[2] ?? '';

$ask = static function (string $prompt): string {
    echo $prompt;
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $v;
};
$password = $ask('Password: ');
$password2 = $ask('Repeat password: ');
// Who runs the platform decides the templates of legal notice and privacy policy
echo 'Operated by the school as a public body (school) or privately, e.g. by a private person, association or independent provider (private): ';
$type = trim((string) fgets(STDIN));

$errors = Installer::validate($username, $password, $password2, $email);
if (($typeError = Installer::validateType($type)) !== null) {
    $errors[] = $typeError;
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\nUsage: php bin/install.php <username> [email]\n");
    exit(1);
}
Installer::install($username, $password, $email, '', $type);
echo "Installation complete.\n";
