<?php
// Abuse protection of the public pages (expert review P1-7). The limits, the warning and deleting several
// bookings at once need the database: tests/smoke.d/abuse.sh
echo "Abuse protection of the public pages\n";

$abAddress = [App\Abuse::class, 'address'];
check('mails per address: counted in lower case, trimmed, without a "+tag" in the local part (one mailbox)',
    $abAddress(' Anna+Schule@Example.ORG ') === 'anna@example.org' && $abAddress('anna@example.org') === 'anna@example.org'
    && $abAddress('a+b+c@x.example') === 'a@x.example' && $abAddress('Ärger@müller.example') === 'ärger@müller.example');
check('… a "+" at the start, in the domain or an address without "@" stay as they are (in lower case)',
    $abAddress('+anna@example.org') === '+anna@example.org' && $abAddress('anna@ex+ample.org') === 'anna@ex+ample.org'
    && $abAddress('Kein-At') === 'kein-at');
check('limits of the public pages: generous per round and network, a few mails per address, the warning after a burst',
    App\ConfigSchema::KEYS['rate_limits.round'] === ['limit', [App\Abuse::ROUND_LIMIT, 3600]] && App\Abuse::ROUND_LIMIT >= 15
    && App\ConfigSchema::KEYS['rate_limits.recipient'] === ['limit', [3, 3600]] && App\ConfigSchema::KEYS['rate_limits.recipient_day'] === ['limit', [10, 86400]]
    && App\ConfigSchema::KEYS['rate_limits.burst'] === ['limit', [5, 300]] && App\ConfigSchema::KEYS['mail_alert_hourly'][1] === 100);

// Deployment variable ROUND_BOOKING_LIMIT (bin/build-config.php): the limit per round and network
$abCfgFile = (string) tempnam(sys_get_temp_dir(), 'cfg');
$abEnv = ['BASE_URL' => 'https://t.example/', 'APP_SECRET' => str_repeat('s', 40), 'INSTALL_KEY' => str_repeat('k', 20), 'DB_USER' => 'u', 'DB_PASS' => 'p',
    'DB_PREFIX' => 'x_', 'ALERT_EMAIL' => 'betrieb@example.org', 'MAIL_FROM' => 'f@example.org'];
$abBuilt = static function (string $value) use ($run, $abCfgFile, $abEnv): ?array {
    [$rc] = $run(['bin/build-config.php', $abCfgFile], ['ROUND_BOOKING_LIMIT' => $value] + $abEnv);
    return $rc === 0 ? (static fn ($f) => require $f)($abCfgFile)['rate_limits']['round'] : null;
};
[$abRc, , $abErr] = $run(['bin/build-config.php', $abCfgFile], ['ROUND_BOOKING_LIMIT' => 'viele'] + $abEnv);
check('build-config: ROUND_BOOKING_LIMIT per hour taken over, empty → default of the sample, not a whole number of at least 1 → abort',
    $abBuilt('25') === [25, 3600] && $abBuilt('') === [App\Abuse::ROUND_LIMIT, 3600] && $abBuilt('0') === null
    && $abRc === 1 && str_contains($abErr, 'ROUND_BOOKING_LIMIT must be a whole number of at least 1'));
@unlink($abCfgFile);
