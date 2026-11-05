<?php
// Local development configuration (copied to config/config.php). No real credentials!
$c = require __DIR__ . '/../config/config.sample.php';
$c['base_url'] = 'http://localhost:8080';
$c['force_https'] = false;
$c['debug'] = true;
$c['secret'] = str_repeat('d', 64);
$c['install_key'] = 'dev-install-key-123';
$c['db'] = ['host' => 'db', 'port' => 3306, 'name' => 'talkslot', 'user' => 'app', 'pass' => 'app-dev-only', 'prefix' => 'tp_'];
$c['mail']['transport'] = 'smtp';
$c['mail']['from_address'] = 'termine@example.org';
$c['alert_email'] = 'betrieb@example.org'; // error reports end up in Mailpit
$c['mail']['smtp'] = ['host' => 'mail', 'port' => 1025, 'encryption' => 'none', 'user' => '', 'pass' => ''];
$c['rate_limits'] = ['attempt' => [1000, 3600], 'booking' => [1000, 3600], 'manage' => [1000, 3600], 'login' => [10, 900]];
$c['csrf_min_age_seconds'] = 0;
$c['summary_evening_hour'] = 0; // tests: daily summary at any time
$c['cron_alert_hours'] = 3; // tests: the cron monitoring with hours that are quick to simulate
return $c;
