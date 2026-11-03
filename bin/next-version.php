<?php
/**
 * Prints the version for the current commit (during deployment, working directory = repository):
 * if HEAD is already a release, its tag; otherwise the next version according to App\Version::next().
 *   php bin/next-version.php   → e.g. v1.3.0
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
// Re-run on an already released commit: same version
foreach (explode("\n", $git("tag --points-at HEAD --list 'v[0-9]*' --sort=-v:refname")) as $tag) {
    if (preg_match('/^v\d+\.\d+\.\d+$/', $tag)) {
        echo $tag, "\n";
        exit(0);
    }
}
$last = $git("describe --tags --abbrev=0 --match 'v[0-9]*.[0-9]*.[0-9]*'");
$log = $last !== '' ? $git('log ' . escapeshellarg($last . '..HEAD') . ' --format=%B%x00') : '';
echo App\Version::next($last, array_values(array_filter(array_map('trim', explode("\0", $log))))), "\n";
