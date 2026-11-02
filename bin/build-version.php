<?php
/**
 * Schreibt die Versionsinfo für das Deployment (gelesen von App\Version):
 *   VERSION="$(git describe --tags --always)" COMMIT="$GITHUB_SHA" php bin/build-version.php <zieldatei>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$target = $argv[1] ?? '';
$version = trim((string) getenv('VERSION'));
$commit = trim((string) getenv('COMMIT'));
if ($target === '' || $version === '' || !preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
    fwrite(STDERR, "Aufruf: VERSION=… COMMIT=<sha> php bin/build-version.php <zieldatei>\n");
    exit(1);
}
$info = [
    'version' => substr($version, 0, 100),
    'commit' => $commit,
    'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
file_put_contents($target, "<?php\n// Vom Deployment erzeugt (bin/build-version.php)\nreturn " . var_export($info, true) . ";\n");
echo "Version: {$info['version']} ({$info['commit']})\n";
