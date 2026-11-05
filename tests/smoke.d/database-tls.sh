# Optional TLS for the database connection (expert review P3-6), see App\Db::options()
echo "Database over TLS (db.ssl_ca)"
# The app's own connection (in-process, script on stdin for the coverage run), each attempt in a fresh process (App\Db
# keeps its connection, and a failed one, for the request): without db.ssl_ca as always; with a CA that did not sign the
# server's certificate it is refused (the dev server has a self-signed one: "Cannot connect to MySQL using SSL";
# MariaDB 10.6 offers no TLS at all: "MySQL server has gone away"), with a hint naming the setting that survives
# ErrorPage::sanitize() (alert mail, log). A server with a certificate of the CA for its host name needs its own
# setup; the pull request of P3-6 describes that test.
SM_TLS=$($DC exec -T app php -- /srv/app 2>&1 <<'PHP'
<?php
[, $root] = $argv;
require "$root/app/bootstrap.php";
// The TLS attributes: those of Pdo\Mysql on PHP 8.4+, without a deprecation (PDO::MYSQL_ATTR_* since 8.5)
$deprecated = [];
set_error_handler(static function (int $no, string $msg) use (&$deprecated): bool { $deprecated[] = $msg; return true; }, E_DEPRECATED | E_USER_DEPRECATED);
$o = App\Db::options(['ssl_ca' => '/etc/ssl/db-ca.pem']);
restore_error_handler();
echo ($o[Pdo\Mysql::ATTR_SSL_CA] ?? null) === '/etc/ssl/db-ca.pem' && ($o[Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? null) === true
    && $deprecated === [] ? 'attributes ok' : 'attributes wrong: ' . implode(' ', $deprecated), "\n";
$try = static function (string $ca) use ($root): string {
    $code = '<?php require $argv[1] . "/app/bootstrap.php"; $c = require $argv[1] . "/config/config.php";'
        . ' App\Config::load(["db" => ["ssl_ca" => $argv[2]] + $c["db"]] + $c);'
        . ' try { echo App\Db::value("SELECT 1") === 1 ? "connected" : "?"; } catch (PDOException $e) { echo "refused: ", App\ErrorPage::sanitize($e->getMessage()); }'
        . ' try { App\Db::value("SELECT 1"); } catch (PDOException $e) { echo App\Db::isUnavailable() ? " (unavailable)" : ""; }';
    $p = proc_open([PHP_BINARY, '--', $root, $ca], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    proc_close($p);
    return trim((string) $out);
};
echo $try(''), "\n", $try('/etc/ssl/certs/ca-certificates.crt'), "\n", $try('certs/db-ca.pem'), "\n";
PHP
)
check "db.ssl_ca: the TLS attributes of PHP 8.4+ (no deprecated PDO::MYSQL_ATTR_*)" '[ "$(echo "$SM_TLS" | sed -n 1p)" = "attributes ok" ]'
check "db.ssl_ca: without it the app connects as before" '[ "$(echo "$SM_TLS" | sed -n 2p)" = connected ]'
check "db.ssl_ca: a CA that did not sign the server's certificate → no connection, the error (as in the alert mail) names the setting" \
  'echo "$SM_TLS" | sed -n 3p | grep -qE "^refused: SQLSTATE\[HY000\] \[(2002|2006)\] .* – db\.ssl_ca is set: .* and that the server offers TLS \(unavailable\)$"'
check "db.ssl_ca: a relative path → no connection, error says why" \
  '[ "$(echo "$SM_TLS" | sed -n 4p)" = "refused: db.ssl_ca must be an absolute path, the path of the CA certificate of the database server (unavailable)" ]' \
  || printf '      %s\n' "$SM_TLS"
