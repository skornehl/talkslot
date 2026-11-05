<?php
// Small improvements from the expert review (P3), one section per topic
echo "Database over TLS (db.ssl_ca)\n";
$tlsOk = ['secret' => str_repeat('s', 32)] + (require dirname(__DIR__, 2) . '/config/config.sample.php');
$tlsErr = static fn (mixed $ca): string => implode(' ', array_column(App\ConfigSchema::errors(['db' => ['ssl_ca' => $ca] + $tlsOk['db']] + $tlsOk), 'en'));
$tlsCa = (string) tempnam(sys_get_temp_dir(), 'ca');
check('db.ssl_ca: empty (default) or a readable file is fine; a missing file stops the app with a clear message, as does another type',
    $tlsErr('') === '' && $tlsErr($tlsCa) === '' && $tlsErr(" $tlsCa ") === '' && App\ConfigSchema::errors($tlsOk) === []
    && $tlsErr('/nicht/da.pem') === 'Configuration error: "db.ssl_ca" must be the path of a readable file, the CA certificate of the database server ("/nicht/da.pem").'
    && str_contains($tlsErr(['/x.pem']), '(array)')
    && str_starts_with(App\ConfigSchema::errors(['db' => ['ssl_ca' => '/nicht/da.pem']] + $tlsOk)[0]['de'], 'Fehler in der Konfiguration: „db.ssl_ca“'));
@unlink($tlsCa);
$tlsPlain = App\Db::options([]);
check('db.ssl_ca: without it the connection has the usual options and no TLS',
    $tlsPlain[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION && $tlsPlain[PDO::ATTR_EMULATE_PREPARES] === false
    && App\Db::options(['ssl_ca' => ' ']) === $tlsPlain && count($tlsPlain) === 5);
// The MySQL attributes exist only with pdo_mysql (the app container, not the plain PHP images of the lint job)
if (extension_loaded('pdo_mysql')) {
    $tlsOn = App\Db::options(['ssl_ca' => ' /etc/ssl/db-ca.pem ']);
    $tlsAttr = static fn (string $n): int => constant(class_exists(Pdo\Mysql::class) ? "Pdo\\Mysql::ATTR_$n" : "PDO::MYSQL_ATTR_$n");
    check('db.ssl_ca: TLS with the CA file, the server certificate is verified',
        $tlsOn[$tlsAttr('SSL_CA')] === '/etc/ssl/db-ca.pem' && $tlsOn[$tlsAttr('SSL_VERIFY_SERVER_CERT')] === true
        && array_diff_key($tlsOn, $tlsPlain) !== [] && count($tlsOn) === 7);
}
$tlsCfg = (string) tempnam(sys_get_temp_dir(), 'cfg');
$tlsEnv = ['BASE_URL' => 'https://t.example/', 'APP_SECRET' => str_repeat('s', 40), 'DB_USER' => 'u', 'DB_PASS' => 'p'];
[$tlsRc1] = $run(['bin/build-config.php', $tlsCfg], ['DB_SSL_CA' => '/etc/ssl/certs/db-ca.pem'] + $tlsEnv);
$tlsWith = (static fn ($f) => require $f)($tlsCfg)['db']['ssl_ca'] ?? null;
[$tlsRc2] = $run(['bin/build-config.php', $tlsCfg], $tlsEnv);
$tlsWithout = (static fn ($f) => require $f)($tlsCfg)['db']['ssl_ca'] ?? null;
@unlink($tlsCfg);
check('build-config: DB_SSL_CA becomes db.ssl_ca, unset = no TLS',
    $tlsRc1 === 0 && $tlsWith === '/etc/ssl/certs/db-ca.pem' && $tlsRc2 === 0 && $tlsWithout === '');
