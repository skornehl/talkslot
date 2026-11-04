<?php
/**
 * Cron job: deletes bookings of past days, deactivates/deletes expired rounds,
 * removes old rate-limit entries, queues summaries to teachers and reminders to parents
 * and sends the due mails of the outbox (limited in number and time, see config mail_queue).
 *
 * Recommended: hourly, e.g. "5 * * * *"
 *   cd /path/to/talkslot-app && php releases/$(cat current)/bin/cron.php   (deployment with bin/deploy.sh)
 *   php /path/to/talkslot/bin/cron.php                                    (simple installation)
 * With -v the counts are printed (no personal data).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

if (!App\Installer::isInstalled()) {
    fwrite(STDERR, "Not installed yet – nothing to do.\n");
    exit(0);
}
App\Migrations::run();
$stats = App\Cleanup::run() + App\Summaries::run() + App\Reminders::run() + App\Outbox::process();
if (in_array('-v', $argv, true)) {
    foreach ($stats as $k => $v) {
        echo str_pad($k, 24) . $v . "\n";
    }
}
