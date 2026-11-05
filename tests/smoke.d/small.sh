# Small improvements from the expert review (P3), one section per topic
echo "Database over TLS (db.ssl_ca)"
# The app's own connection (in-process, script on stdin for the coverage run): with a CA that did not sign the
# database server's certificate it is refused (the dev server has a self-signed certificate, MariaDB 10.6 no
# TLS at all), without db.ssl_ca it connects as always. A server with a certificate of the CA for its host name
# needs its own setup (see the pull request of P3-6).
SM_TLS=$($DC exec -T app php -- /srv/app 2>&1 <<'PHP'
<?php
[, $root] = $argv;
require "$root/app/bootstrap.php";
$cfg = require "$root/config/config.php";
$try = static function (string $ca) use ($root, $cfg): string {
    // A fresh process for each attempt: App\Db keeps its connection (and a failed one) for the request
    $code = '<?php require $argv[1] . "/app/bootstrap.php"; $c = require $argv[1] . "/config/config.php";'
        . ' App\Config::load(["db" => ["ssl_ca" => $argv[2]] + $c["db"]] + $c);'
        . ' try { echo App\Db::value("SELECT 1") === 1 ? "connected" : "?"; } catch (PDOException $e) { echo "refused: ", $e->getMessage(); }';
    $p = proc_open([PHP_BINARY, '--', $root, $ca], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    proc_close($p);
    return trim((string) $out);
};
echo $try(''), "\n", $try('/etc/ssl/certs/ca-certificates.crt'), "\n";
PHP
)
check "db.ssl_ca: without it the app connects as before" '[ "$(echo "$SM_TLS" | sed -n 1p)" = connected ]'
check "db.ssl_ca: a CA that did not sign the server's certificate → no connection" \
  'echo "$SM_TLS" | sed -n 2p | grep -q "^refused: .*SSL"' || printf '      %s\n' "$SM_TLS"
