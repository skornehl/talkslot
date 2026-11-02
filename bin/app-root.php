<?php
/**
 * Vorlage für app_root.php im Webverzeichnis des Servers (bin/build.sh trägt den Pfad zum
 * App-Verzeichnis ein, bin/deploy.sh lädt sie hoch). index.php und install.php fragen damit, wo der
 * Code der aktiven Version liegt:
 *
 *   <App-Verzeichnis>/current             Name der aktiven Version (eine Zeile) – der „Schalter“
 *   <App-Verzeichnis>/releases/<Name>/    app/, bin/, config/ dieser Version
 *   <App-Verzeichnis>/app/var/            Sessions, Logs usw. (für alle Versionen gemeinsam)
 *
 * Umgeschaltet wird nur durch Austausch von „current“, nachdem eine Version vollständig hochgeladen
 * ist. Fehlt der Zeiger kurz oder ist er unbrauchbar, wird ein paar Zehntelsekunden gewartet und
 * notfalls die neueste vollständige Version genommen.
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
        rsort($names, SORT_STRING); // Namen beginnen mit Datum und Uhrzeit
        return $base . '/releases/' . $names[0];
    }
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Vorübergehend nicht verfügbar.\n";
    exit;
})(__DIR__ . '/../talkslot-app'); // Pfad relativ zum Webverzeichnis (bin/build.sh: APP_DIR, WEB_DIR)
