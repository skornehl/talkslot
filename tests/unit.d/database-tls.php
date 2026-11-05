<?php
// Optional TLS for the database connection (expert review P3-6); the connection itself: tests/smoke.d/database-tls.sh
echo "Database over TLS (db.ssl_ca)\n";
$tlsPlain = App\Db::options([]);
check('db.ssl_ca: without it the connection has the usual options and no TLS',
    $tlsPlain[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION && $tlsPlain[PDO::ATTR_EMULATE_PREPARES] === false
    && App\Db::options(['ssl_ca' => ' ']) === $tlsPlain && App\Db::options(['ssl_ca' => ['/x.pem']]) === $tlsPlain && count($tlsPlain) === 5);
$tlsRelative = static function (string $path): string {
    try {
        App\Db::options(['ssl_ca' => $path]);
        return 'accepted';
    } catch (PDOException $e) {
        return $e->getMessage();
    }
};
// The working directory differs between web requests (public/) and the cron job: a relative path would work in one only
check('db.ssl_ca: a relative path is refused before connecting (a connection error, so it reaches the alert mail)',
    $tlsRelative('db-ca.pem') === 'db.ssl_ca must be an absolute path, the path of the CA certificate of the database server'
    && App\ErrorPage::sanitize($tlsRelative('db-ca.pem')) === $tlsRelative('db-ca.pem')
    && $tlsRelative('../certs/ca.pem') !== 'accepted' && App\ConfigSchema::KEYS['db.ssl_ca'] === ['string', '']);
$tlsCfg = (string) tempnam(sys_get_temp_dir(), 'cfg');
$tlsEnv = ['BASE_URL' => 'https://t.example/', 'APP_SECRET' => str_repeat('s', 40), 'DB_USER' => 'u', 'DB_PASS' => 'p'];
[$tlsRc1] = $run(['bin/build-config.php', $tlsCfg], ['DB_SSL_CA' => '/home/schule/db-ca.pem'] + $tlsEnv);
$tlsWith = (static fn ($f) => require $f)($tlsCfg)['db']['ssl_ca'] ?? null;
[$tlsRc2] = $run(['bin/build-config.php', $tlsCfg], $tlsEnv);
$tlsWithout = (static fn ($f) => require $f)($tlsCfg)['db']['ssl_ca'] ?? null;
[$tlsRc3, , $tlsErr3] = $run(['bin/build-config.php', $tlsCfg], ['DB_SSL_CA' => 'db-ca.pem'] + $tlsEnv);
@unlink($tlsCfg);
check('build-config: DB_SSL_CA becomes db.ssl_ca, unset = no TLS, a relative path stops the build',
    $tlsRc1 === 0 && $tlsWith === '/home/schule/db-ca.pem' && $tlsRc2 === 0 && $tlsWithout === ''
    && $tlsRc3 === 1 && str_contains($tlsErr3, 'DB_SSL_CA must be an absolute path'));
