# Security invariants (see README.md in this directory)
echo "Security invariants"
# Expert review P2-8: the role is part of the route table, no GET route changes data, GET /b/<token> has no
# side effects, every booking path locks the day, security headers on 404 and error pages, and form posts
# from other sites are refused. Everything created here is removed again at the end.
T=$(curl -s -b "$J" "$B/admin" | csrf)
SI_R=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" -d "title=Invarianten&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+9 day' +%F)&start_time=14:00&end_time=16:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
SI_S=$(slugof "$SI_R")
SI_TOKEN=$(book "$SI_S" "Ida Invariant" "ida-invariant@example.org" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | cut -c4-)
SI_DAY=$(DBQ "SELECT id FROM tp_round_days WHERE round_id = $SI_R")
SI_BID=$(DBQ "SELECT b.id FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id WHERE s.day_id = $SI_DAY")
SI_SLOT=$(DBQ "SELECT s.id FROM tp_slots s LEFT JOIN tp_bookings b ON b.slot_id = s.id WHERE s.day_id = $SI_DAY AND b.id IS NULL ORDER BY s.start_time LIMIT 1")
curl -s -o /dev/null -b "$J" -d "csrf=$T&start_time=15:00&end_time=15:15&label=Pause" "$B/admin/day/$SI_DAY/break"
SI_BREAK=$(DBQ "SELECT id FROM tp_day_breaks WHERE day_id = $SI_DAY")
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/from-round/$SI_R"
SI_TID=$(DBQ "SELECT MAX(id) FROM tp_round_templates")
SI_UID=$(DBQ "SELECT id FROM tp_users WHERE username = '$USER'")
SI_PW=Invarianten-Lehrkraft-1
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, created_at) VALUES ('si-teacher@example.org', '$($DC exec -T app php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$SI_PW")', 'teacher', 'Herr Invariant', 'si-teacher@example.org', NOW())"
SI_TEACHER=$(DBQ "SELECT id FROM tp_users WHERE username = 'si-teacher@example.org'")
JT=$(mktemp)
curl -s -o /dev/null -c "$JT" -b "$JT" --data-urlencode "csrf=$(curl -s -c "$JT" -b "$JT" "$B/admin/login" | csrf)" --data-urlencode "username=si-teacher@example.org" --data-urlencode "password=$SI_PW" "$B/admin/login"
TT=$(curl -s -b "$JT" "$B/admin" | csrf)
check "test data: round, booking, break, template and a teacher account" '[ -n "$SI_R" ] && [ ${#SI_TOKEN} = 43 ] && [ -n "$SI_BID" ] && [ -n "$SI_SLOT" ] && [ -n "$SI_BREAK" ] && [ -n "$SI_TID" ] && [ -n "$TT" ]'

# SI_ROUTES <GET|POST> <users id>: every route of AdminController::ROUTES with that method as "role status path",
# the placeholders filled with the IDs of this test. A route with a placeholder this test does not know
# yet is reported as "unresolved", so that new routes cannot slip past the checks below.
read -r -d '' SI_ROUTES_PHP <<'PHP'
require "/srv/app/app/bootstrap.php";
$ids = ["round" => $argv[2], "day" => $argv[3], "break" => $argv[4], "slot" => $argv[5], "booking" => $argv[6], "users" => $argv[7], "templates" => $argv[8]];
foreach ((new ReflectionClassConstant(App\Controller\AdminController::class, "ROUTES"))->getValue() as $r) {
    if ($r[0] !== $argv[1]) {
        continue;
    }
    $path = substr($r[1], 2, -2); // without #^ and $#
    $path = str_replace("(\\d+)", $ids[explode("/", $path)[1]] ?? "?", $path);
    $path = (string) preg_replace("/\\(([a-z]+)(\\|[a-z]+)+\\)/", "$1", $path); // (imprint|privacy) → imprint
    $path = str_replace("([a-z]{2})", "de", $path);
    echo $r[3] ?? "none", " ", preg_match($r[1], $path) ? "ok" : "unresolved", " ", rtrim($path, "/"), "\n";
}
PHP
SI_ROUTES() { $DC exec -T app php -r "$SI_ROUTES_PHP" "$1" "$SI_R" "$SI_DAY" "$SI_BREAK" "$SI_SLOT" "$SI_BID" "$2" "$SI_TID"; }

# Role: teachers get 404 for every route the table marks as admin-only (POST with a valid token, so that
# the role check is what refuses it; their own account ID, so that nothing else would be hit)
SI_OPEN=""; SI_N=0
for SI_M in GET POST; do
  while read -r role status path; do
    [ "$role" = teacher ] && continue
    SI_N=$((SI_N + 1))
    [ "$status" = ok ] || { SI_OPEN="$SI_OPEN $SI_M:$path:$status"; continue; }
    if [ "$SI_M" = GET ]; then code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JT" "$B/admin$path")
    else code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JT" -d "csrf=$TT&confirm=1" "$B/admin$path"); fi
    [ "$code" = 404 ] || SI_OPEN="$SI_OPEN $SI_M:$path:$code"
  done < <(SI_ROUTES "$SI_M" "$SI_TEACHER")
done
check "role from the route table: teachers get 404 for all $SI_N admin routes$SI_OPEN" '[ -z "$SI_OPEN" ] && [ "$SI_N" -ge 18 ]'

# No GET route changes data: every GET route of the table as admin, once with typical form fields in the
# query string, and the public pages. Fingerprint of all tables except the rate-limit counters (counting
# requests is their purpose); the clean-up on requests is not due during the check.
SI_TABLES=$(DBQ "SELECT GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'tp\\_%' AND TABLE_NAME <> 'tp_rate_limits'")
SI_SUM() { DBQ "CHECKSUM TABLE $SI_TABLES" | md5sum; }
SI_MAILS() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/messages?limit=1"), true)["total"] ?? "?";'; }
DBQ "INSERT INTO tp_settings (name, value) VALUES ('last_cleanup', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value = UNIX_TIMESTAMP()"
SI_SUM0=$(SI_SUM); SI_MAIL0=$(SI_MAILS)
SI_BAD=""; SI_N=0
SI_FIELDS="confirm=1&action=cancel&csrf=$T&slot_id=$SI_SLOT&move_to=$SI_SLOT&is_active=0&role=admin"
while read -r role status path; do
  SI_N=$((SI_N + 1))
  [ "$status" = ok ] || { SI_BAD="$SI_BAD $path:$status"; continue; }
  for q in "" "?$SI_FIELDS"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" "$B/admin$path$q")
    [ "$code" = 200 ] || SI_BAD="$SI_BAD $path$q:$code"
  done
done < <(SI_ROUTES GET "$SI_UID")
SI_CODE=$(DBQ "SELECT code FROM tp_rounds WHERE id = $SI_R")
for u in "/" "/?code=$SI_CODE" "/?code=ZZZZ-ZZZZ" "/impressum" "/datenschutz?r=$SI_S" "/$SI_S" "/$SI_S?lang=en&$SI_FIELDS" \
  "/b/$SI_TOKEN" "/admin/login" "/admin/forgot" "/admin/invite/$(printf 'A%.0s' $(seq 43))" "/cron" "/gibt-es-nicht"; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$B$u")
  [[ "$code" =~ ^(200|303|404|422)$ ]] || SI_BAD="$SI_BAD $u:$code"
done
check "no GET route changes data: $SI_N admin GET routes and the public pages, no mail sent$SI_BAD" '[ -z "$SI_BAD" ] && [ "$SI_N" -ge 15 ] && [ "$(SI_SUM)" = "$SI_SUM0" ] && [ "$(SI_MAILS)" = "$SI_MAIL0" ]'

# GET /b/<token>: links in mails are opened by scanners and previews; even with the fields of the cancel
# and move forms in the query string (and as HEAD) the booking stays as it is and nobody gets a mail
SI_ROW() { DBQ "SELECT CONCAT_WS('|', slot_id, name, email, lang, updated_at, IFNULL(reminded_at, '-')) FROM tp_bookings WHERE id = $SI_BID"; }
SI_ROW0=$(SI_ROW); SI_SUM0=$(SI_SUM); SI_MAIL0=$(SI_MAILS)
MC=$(curl -s "$B/b/$SI_TOKEN" | csrf)
for q in "" "?done=booked&mail=1&remember=1" "?action=cancel&confirm=1&csrf=$MC" "?action=move&slot_id=$SI_SLOT&csrf=$MC"; do
  curl -s -o /dev/null "$B/b/$SI_TOKEN$q"; curl -s -o /dev/null -I "$B/b/$SI_TOKEN$q"
done
check "GET /b/<token> without side effects (also with cancel/move fields and as HEAD)" '[ -n "$SI_ROW0" ] && [ "$(SI_ROW)" = "$SI_ROW0" ] && [ "$(SI_SUM)" = "$SI_SUM0" ] && [ "$(SI_MAILS)" = "$SI_MAIL0" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$B/b/$SI_TOKEN")" = 200 ]'

# Every booking path locks the day: a second connection holds the lock on the day (as SlotGenerator::sync()
# does while it recalculates the slots). Booking and moving, by parents and by the teacher, must wait for
# it (lock wait timeout, reported as "taken") and go through once it is released.
read -r -d '' SI_LOCK_PHP <<'PHP'
require "/srv/app/app/bootstrap.php";
use App\BookingService;
use App\Db;
$round = Db::one("SELECT * FROM rounds WHERE id = ?", [(int) $argv[1]]);
$dayId = (int) $argv[2];
$holder = Db::pdo();
(new ReflectionProperty(Db::class, "pdo"))->setValue(null, null); // the booking gets its own connection
Db::run("SET SESSION innodb_lock_wait_timeout = 1");
$free = static fn (): int => (int) Db::value("SELECT s.id FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id WHERE s.day_id = ? AND b.id IS NULL AND s.is_blocked = 0 AND s.is_conflict = 0 ORDER BY s.start_time LIMIT 1", [$dayId]);
$attempt = static function (callable $fn, bool $dayLocked) use ($holder, $dayId): string {
    if ($dayLocked) {
        $holder->beginTransaction();
        $holder->prepare(Db::prefix("SELECT id FROM round_days WHERE id = ? FOR UPDATE"))->execute([$dayId]);
    }
    try {
        $fn();
        return "booked";
    } catch (App\BookingException $e) {
        return $e->getMessage();
    } finally {
        if ($dayLocked) {
            $holder->rollBack();
        }
    }
};
[$moving] = BookingService::book($round, $free(), "Sperre Umbuchen", null, "de");
$n = 0;
$paths = [
    "parents book" => static function () use ($round, $free, &$n): void { BookingService::book($round, $free(), "Sperre Eltern " . ++$n, null, "de"); },
    "teacher books" => static function () use ($round, $free): void { BookingService::adminBook($round, $free(), "Sperre Lehrkraft", null, "de"); },
    "parents move" => static function () use ($moving, $free): void { BookingService::move(BookingService::findById($moving), $free(), false); },
    "teacher moves" => static function () use ($moving, $free): void { BookingService::move(BookingService::findById($moving), $free(), true); },
];
foreach ($paths as $name => $fn) {
    echo $name, ": ", $attempt($fn, true), "/", $attempt($fn, false), "\n";
}
PHP
SI_LOCK=$($DC exec -T app php -r "$SI_LOCK_PHP" "$SI_R" "$SI_DAY" 2>&1)
check "every booking path waits for the lock on the day ($(echo $SI_LOCK))" '[ "$(echo "$SI_LOCK" | grep -c ": taken/booked$")" = 4 ]'

# Security headers on every answer, exactly once: 404 pages of both areas, the 404 for teachers on admin
# routes, the refused cross-site post, Apache's own 403 for internal files and normal pages for comparison.
# (500 pages: tests/unit.d/security.php)
SI_HDR() { # expected status, curl arguments → ok or what is missing
  local h; h=$(curl -s -o /dev/null -D - "${@:2}" | tr -d '\r')
  echo "$h" | head -1 | grep -q " $1" || { echo "status $(echo "$h" | head -1)"; return; }
  for p in "^content-security-policy: default-src 'self';.*frame-ancestors 'none'" '^x-frame-options: DENY$' '^x-content-type-options: nosniff$' \
    '^referrer-policy: no-referrer$' '^permissions-policy: camera=()'; do
    [ "$(echo "$h" | grep -ci -- "$p")" = 1 ] || { echo "$p: $(echo "$h" | grep -ci -- "$p")×"; return; }
  done
  echo ok
}
SI_BAD=""
while read -r exp args; do
  r=$(eval "SI_HDR $exp $args")
  [ "$r" = ok ] || SI_BAD="$SI_BAD [$args: $r]"
done <<EOF
404 "\$B/gibt-es-nicht-hier"
404 "\$B/a/b/c"
404 "\$B/b/$(printf 'A%.0s' $(seq 43))"
404 "\$B/cron"
404 -b "\$J" "\$B/admin/gibt-es-nicht"
404 -b "\$JT" "\$B/admin/users"
403 -H "Sec-Fetch-Site: cross-site" -d "name=x" "\$B/\$SI_S"
403 -H "Sec-Fetch-Site: cross-site" -d "username=x" "\$B/admin/login"
403 "\$B/.env"
403 "\$B/config.php.bak"
200 "\$B/"
200 -b "\$J" "\$B/admin"
200 "\$B/robots.txt"
EOF
check "security headers exactly once on 404, 403 and normal pages$SI_BAD" '[ -z "$SI_BAD" ]'

echo "Security: form posts from other sites"
# Browsers mark a form that another site submits with "Sec-Fetch-Site: cross-site"; such posts are refused
# before anything is processed. Same-origin posts carry "Origin: null" (Referrer-Policy no-referrer) and
# pass, as do clients without the header (all other checks of this test).
XS="Sec-Fetch-Site: cross-site"
P=$(curl -s "$B/$SI_S"); PC=$(echo "$P" | csrf); SI_FREE=$(echo "$P" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')
SI_NB=$(DBQ "SELECT COUNT(*) FROM tp_bookings")
X=$(curl -s -w '\n%{http_code}' -H "$XS" -H "Origin: https://evil.example" --data-urlencode "csrf=$PC" -d "slot_id=$SI_FREE&name=Fremde+Seite&privacy=1" "$B/$SI_S")
check "booking posted from another site: 403 with a notice, nothing booked" '[ "$(echo "$X" | tail -1)" = 403 ] && echo "$X" | has "<h1>Anfrage abgelehnt</h1>" && echo "$X" | has "von einer anderen Website aus abgeschickt" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings")" = "$SI_NB" ]'
check "the notice in the parents' language" 'curl -s -H "$XS" -d "lang=uk&name=x" "$B/$SI_S" | has "<h1>Запит відхилено</h1>"'
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Sec-Fetch-Site: same-origin" -H "Origin: null" --data-urlencode "csrf=$PC" -d "slot_id=$SI_FREE&name=Gleiche+Seite&privacy=1" "$B/$SI_S")
check "same-origin booking with 'Origin: null' goes through" '[ "$code" = 303 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings")" = "$((SI_NB + 1))" ]'
MC=$(curl -s "$B/b/$SI_TOKEN" | csrf)
code=$(curl -s -o /dev/null -w '%{http_code}' -H "$XS" --data-urlencode "csrf=$MC" -d "action=cancel&confirm=1" "$B/b/$SI_TOKEN")
check "cancelling posted from another site: 403, booking stays" '[ "$code" = 403 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE id = $SI_BID")" = 1 ]'
JX=$(mktemp)
LT=$(curl -s -c "$JX" -b "$JX" "$B/admin/login" | csrf)
X=$(curl -s -D - -c "$JX" -b "$JX" -H "$XS" -H "Origin: null" --data-urlencode "csrf=$LT" --data-urlencode "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")
check "login posted from another site (login CSRF): 403, no session, not signed in" 'echo "$X" | head -1 | grep -q " 403" && ! echo "$X" | grep -qi "^set-cookie" && echo "$X" | has "<h1>Anfrage abgelehnt</h1>" && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JX" "$B/admin")" = 303 ]'
check "admin notice in English for an English browser" 'curl -s -H "$XS" -H "Accept-Language: en-GB,en;q=0.9" -d "username=x" "$B/admin/login" | has "<h1>Request refused</h1>"'
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JX" -c "$JX" -H "Sec-Fetch-Site: same-origin" -H "Origin: null" --data-urlencode "csrf=$LT" --data-urlencode "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")
check "same-origin login with 'Origin: null' works" '[ "$code" = 303 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JX" "$B/admin")" = 200 ]'
curl -s -o /dev/null -b "$JX" -d "csrf=$(curl -s -b "$JX" "$B/admin" | csrf)" "$B/admin/logout"; rm -f "$JX"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J" -H "$XS" -d "csrf=$T" "$B/admin/round/$SI_R/toggle")
check "admin action from another site, even with session and token: 403, round unchanged" '[ "$code" = 403 ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id = $SI_R")" = 1 ]'
check "links from other sites still open (GET with Sec-Fetch-Site: cross-site)" '[ "$(curl -s -o /dev/null -w "%{http_code}" -H "$XS" "$B/$SI_S")" = 200 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -H "$XS" "$B/b/$SI_TOKEN")" = 200 ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -H "$XS" "$B/admin/login")" = 200 ]'

# Clean-up: teacher account, template, round (with its bookings)
curl -s -o /dev/null -b "$JT" -d "csrf=$TT" "$B/admin/logout"; rm -f "$JT"
DBQ "DELETE FROM tp_users WHERE id = $SI_TEACHER"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/templates/$SI_TID/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$SI_R/delete"
check "security invariants: test data removed" '[ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $SI_R")" ] && [ -z "$(DBQ "SELECT id FROM tp_round_templates WHERE id = $SI_TID")" ] && [ -z "$(DBQ "SELECT id FROM tp_users WHERE id = $SI_TEACHER")" ]'
