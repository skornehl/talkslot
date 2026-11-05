<?php
/**
 * Cron job: deletes bookings of past days, deactivates/deletes expired rounds,
 * removes old rate-limit entries, queues summaries to teachers and reminders to parents
 * and sends the due mails of the outbox (limited in number and time, see config mail_queue).
 *
 * Recommended: hourly, e.g. "5 * * * *"
 *   cd /path/to/talkslot-app && php releases/$(cat current)/bin/cron.php   (deployment with bin/deploy.sh)
 *   php /path/to/talkslot/bin/cron.php                                    (simple installation)
 * With -v the counts are printed (no personal data), and whether the database server is supported (db_server).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
App\Platform::enforce(true); // needs the database: also pdo_mysql

if (!App\Installer::isInstalled()) {
    fwrite(STDERR, "Not installed yet – nothing to do.\n");
    exit(0);
}
App\Migrations::run();
// No named arguments in this file: the "php" of a cron job is often an older version than the web server's,
// which must get the message of App\Platform instead of a syntax error
$stats = ['db_server' => App\Health::serverStatus()] + App\Cleanup::run() + App\Summaries::run() + App\Reminders::run() + App\Outbox::process(null, null, App\Outbox::CRON_WAIT);
if (in_array('-v', $argv, true)) {
    foreach ($stats as $k => $v) {
        echo str_pad($k, 24) . $v . "\n";
    }
}
