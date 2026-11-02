<?php
/**
 * Installation über die Kommandozeile (Alternative zu public/install.php):
 *   php bin/install.php <benutzername> [benachrichtigungs-email]
 * Das Passwort wird verdeckt abgefragt.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Installer;

if (Installer::isInstalled()) {
    fwrite(STDERR, "Bereits installiert.\n");
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
$password = $ask('Passwort: ');
$password2 = $ask('Passwort wiederholen: ');

$errors = Installer::validate($username, $password, $password2, $email);
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\nAufruf: php bin/install.php <benutzername> [email]\n");
    exit(1);
}
Installer::install($username, $password, $email);
echo "Installation abgeschlossen.\n";
