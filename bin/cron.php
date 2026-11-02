<?php
/**
 * Cronjob: löscht Buchungen vergangener Tage, deaktiviert/löscht abgelaufene
 * Runden, entfernt alte Rate-Limit-Einträge und verschickt Zusammenfassungen an Lehrkräfte.
 *
 * Empfohlen: stündlich, z. B. „5 * * * *“
 *   cd /pfad/zu/talkslot-app && php releases/$(cat current)/bin/cron.php   (Deployment mit bin/deploy.sh)
 *   php /pfad/zu/talkslot/bin/cron.php                                    (einfache Installation)
 * Mit -v werden die Anzahlen ausgegeben (keine personenbezogenen Daten).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

if (!App\Installer::isInstalled()) {
    fwrite(STDERR, "Noch nicht installiert – nichts zu tun.\n");
    exit(0);
}
App\Migrations::run();
$stats = App\Cleanup::run() + App\Summaries::run();
if (in_array('-v', $argv, true)) {
    foreach ($stats as $k => $v) {
        echo str_pad($k, 24) . $v . "\n";
    }
}
