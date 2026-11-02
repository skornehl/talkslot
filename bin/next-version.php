<?php
/**
 * Gibt die Version für den aktuellen Commit aus (im Deployment, Arbeitsverzeichnis = Repository):
 * Ist HEAD schon ein Release, dessen Tag, sonst die nächste Version nach App\Version::next().
 *   php bin/next-version.php   → z. B. v1.3.0
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/src/Version.php';

$git = static function (string $args): string {
    $out = shell_exec('git ' . $args . ' 2>/dev/null');
    return is_string($out) ? trim($out) : '';
};
// Erneuter Lauf auf einem bereits veröffentlichten Stand: dieselbe Version
foreach (explode("\n", $git("tag --points-at HEAD --list 'v[0-9]*' --sort=-v:refname")) as $tag) {
    if (preg_match('/^v\d+\.\d+\.\d+$/', $tag)) {
        echo $tag, "\n";
        exit(0);
    }
}
$last = $git("describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*'");
$log = $last !== '' ? $git('log ' . escapeshellarg($last . '..HEAD') . ' --format=%B%x00') : '';
echo App\Version::next($last, array_values(array_filter(array_map('trim', explode("\0", $log))))), "\n";
