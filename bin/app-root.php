<?php
/**
 * Template for app_root.php in the server's web directory (bin/build.sh fills in the path to the
 * app directory, bin/deploy.sh uploads it). index.php and install.php use it to find where the
 * code of the active version lives:
 *
 *   <app dir>/current             name of the active version (one line) – the "switch"
 *   <app dir>/releases/<name>/    app/, bin/, config/ of that version
 *   <app dir>/app/var/            sessions, logs etc. (shared by all versions)
 *
 * Switching happens only by replacing "current" after a version has been uploaded completely.
 * If the pointer is briefly missing or unusable, we wait a few tenths of a second and fall back
 * to the newest complete version if necessary.
 */
declare(strict_types=1);

return (static function (string $base): string {
    $base = realpath($base) ?: $base;
    $usable = static fn (string $name): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) === 1
        && is_file($base . '/releases/' . $name . '/app/bootstrap.php');

    for ($try = 0; $try < 5; $try++) {
        if ($try > 0) {
            usleep(100_000);
            clearstatcache();
        }
        $name = trim((string) @file_get_contents($base . '/current'));
        if ($usable($name)) {
            return $base . '/releases/' . $name;
        }
    }
    $names = array_values(array_filter(array_map('basename', glob($base . '/releases/*', GLOB_ONLYDIR) ?: []), $usable));
    if ($names !== []) {
        rsort($names, SORT_STRING); // names start with date and time
        return $base . '/releases/' . $names[0];
    }
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Temporarily unavailable.\n";
    exit;
})(__DIR__ . '/../talkslot-app'); // path relative to the web directory (bin/build.sh: APP_DIR, WEB_DIR)
