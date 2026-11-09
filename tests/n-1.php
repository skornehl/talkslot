<?php
/**
 * tests/n-1.sh: drives the previous release (argument 1: its base URL, 2: its directory) on the current schema.
 * Forms are read from the pages, so that this works with the markup of any release.
 */
declare(strict_types=1);

require '/srv/app/app/bootstrap.php'; // the current code: only to read the test data
use App\Db;

[, $base, $dir] = $argv;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . "\n";
    $fail += $ok ? 0 : 1;
}
$jar = tempnam(sys_get_temp_dir(), 'jar');
/** @return array{int, string, string} status, body, Location */
$http = static function (string $path, ?array $post = null, array $headers = []) use ($base, $jar): array {
    $c = curl_init($base . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) {
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $raw = (string) curl_exec($c);
    $size = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    preg_match('/^location:\s*(\S+)/mi', substr($raw, 0, $size), $l);
    return [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), substr($raw, $size), (string) ($l[1] ?? '')];
};
/** The fields of the first form that contains the element matched by $xpath, and its action */
$form = static function (string $html, string $xpath): array {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $x = new DOMXPath($doc);
    $node = $x->query("//form[$xpath]")->item(0);
    if (!$node instanceof DOMElement) {
        return ['', []];
    }
    $fields = [];
    foreach ($x->query('.//input', $node) as $input) {
        if ($input instanceof DOMElement && in_array($input->getAttribute('type'), ['hidden', 'text', 'email'], true)) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
    }
    return [$node->getAttribute('action'), $fields];
};

[$code] = $http('/');
check('start page', $code === 200);
[$code, $page] = $http('/admin/login');
[$action, $fields] = $form($page, './/input[@name="password"]');
[$code] = $http('/admin/login', ['username' => 'sabrina', 'password' => 'Sehr-Sicheres-Dev-Pw1'] + $fields);
check('admin: sign in', $code === 303);
$target = Db::one('SELECT r.slug, r.title, s.id AS slot_id FROM slots s JOIN round_days d ON d.id = s.day_id JOIN rounds r ON r.id = d.round_id
    LEFT JOIN bookings b ON b.slot_id = s.id WHERE r.is_active = 1 AND r.code_required = 0 AND s.is_conflict = 0 AND b.id IS NULL AND d.day_date > CURDATE()
    ORDER BY d.day_date DESC, s.start_time DESC LIMIT 1');
[$code, $page] = $http('/admin');
check('admin: the overview lists the rounds of the newer schema', $code === 200 && $target !== null && str_contains($page, htmlspecialchars((string) $target['title'], ENT_QUOTES)));

[$code, $page] = $http('/' . $target['slug']);
[$action, $fields] = $form($page, './/input[@name="name"]');
check('parents: page of the round with the booking form', $code === 200 && $action !== '');
[$code, , $location] = $http($action, ['name' => 'Nora Vorversion', 'slot_id' => $target['slot_id'], 'website' => '', 'privacy' => '1'] + $fields);
$manage = (string) parse_url($location, PHP_URL_PATH);
check('parents: booking', $code === 303 && str_starts_with($manage, '/b/'));
check('booking stored in the newer schema', Db::value('SELECT COUNT(*) FROM bookings WHERE name = ?', ['Nora Vorversion']) === 1);
[$code, $page] = $http($manage);
[$action, $fields] = $form($page, './/input[@name="action" and @value="move"]');
$free = Db::value('SELECT s.id FROM slots s JOIN round_days d ON d.id = s.day_id JOIN round_days o ON o.round_id = d.round_id JOIN slots b ON b.day_id = o.id AND b.id = ?
    LEFT JOIN bookings x ON x.slot_id = s.id WHERE x.id IS NULL AND s.is_conflict = 0 AND s.id <> ? ORDER BY d.day_date DESC, s.start_time LIMIT 1', [$target['slot_id'], $target['slot_id']]);
[$codeMove] = $http($action, ['slot_id' => $free] + $fields);
check('parents: moving', $code === 200 && $codeMove === 303 && (int) Db::value('SELECT slot_id FROM bookings WHERE name = ?', ['Nora Vorversion']) === (int) $free);
[, $page] = $http($manage);
[$action, $fields] = $form($page, './/input[@name="action" and @value="cancel"]');
[$code] = $http($action, ['confirm' => '1'] + $fields);
check('parents: cancelling', in_array($code, [200, 303], true) && Db::value('SELECT COUNT(*) FROM bookings WHERE name = ?', ['Nora Vorversion']) === 0);

exec('php ' . escapeshellarg("$dir/bin/cron.php") . ' 2>&1', $cron, $cronRc);
check('the cron job of the release runs on the newer schema', $cronRc === 0);

$token = hash_hmac('sha256', 'health', (string) App\Config::get('secret', ''));
[$code, $body] = $http('/health', [], ['X-Health-Token: ' . $token]);
check('health check of the release: the newer schema counts as up to date (' . trim($body) . ')', $code === 200 && str_contains($body, 'ok'));

$log = (string) @file_get_contents("$dir/app/var/php-error.log") . (string) @file_get_contents("$dir/server.log");
preg_match_all('/PHP (?:Warning|Notice|Deprecated|Fatal error)[^\n]*/', $log, $m);
preg_match_all('/\[[A-Za-z\\\\]*(?:Exception|Error)\][^\n]*/', $log, $e);
check('no PHP warning or error of the release' . ($m[0] || $e[0] ? ': ' . implode('; ', array_slice(array_unique(array_merge($m[0], $e[0])), 0, 5)) : ''), !$m[0] && !$e[0]);
@unlink($jar);
echo $fail === 0 ? "ALL N-1 TESTS PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
