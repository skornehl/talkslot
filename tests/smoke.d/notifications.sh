# Mails to parents and teachers: mail queue (outbox), reminders per booking (see README.md in this directory)
echo "Mail queue (outbox) and reminders"
NT=$(curl -s -b "$J" "$B/admin" | csrf)
NRUN=$(date +%s)   # Mailpit keeps the mails of earlier runs
n_addr() { echo "outbox-$1-$NRUN@example.org"; }
# n_mails <address> [subject word] → number of mails; n_text <address> [subject word] → text of the newest one
n_query() { echo "to:$1${2:+%20subject:$2}"; }
n_mails() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query='"$(n_query "$@")"'"),true); echo $r["messages_count"] ?? 0;'; }
n_text() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query='"$(n_query "$@")"'"),true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/".($r["messages"][0]["ID"] ?? "x")),true)["Text"] ?? "";'; }
n_id() { DBQ "SELECT id FROM tp_bookings WHERE name='$1'"; }
# A round for tomorrow that reminds, six slots
NR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Outbox+Test&remind_parents_field=1&remind_parents=1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+1 day' +%F)&start_time=09:00&end_time=12:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
NRS=$(slugof "$NR")
NP=$(curl -s "$B/$NRS")
check "booking form is sent only once (data-once): a second click cannot end on \"taken\" instead of the link" '[ -n "$NRS" ] && echo "$NP" | has "id=\"booking-form\" novalidate data-once>"'
N1=$(book "$NRS" "Outbox Eltern" "$(n_addr 1)"); N1L=${N1#* }; N1L=${N1L%%\?*}
N1M=$(curl -s "$N1L")
check "appointment page: moving, cancelling and the confirmation mail are sent only once, the calendar file as often as wanted" '[ "$(echo "$N1M" | grep -c "<form method=\"post\" action=\"[^\"]*\" data-once>")" = 3 ] && echo "$N1M" | has "class=\"inline\" data-calendar>"'
check "parents booked: confirmation sent at once, booking may be reminded (round reminds), confirmation noted" '[[ "$N1" == 303* ]] && [[ "$N1" == *"mail=1"* ]] && [ "$(n_mails "$(n_addr 1)")" = 1 ] && [ "$(DBQ "SELECT CONCAT(remind_ok, notified_at IS NOT NULL, mail_failed_at IS NULL) FROM tp_bookings WHERE name=\"Outbox Eltern\"")" = 111 ]'
# Entries by the teacher, without a mail: no reminder until the parents got a confirmation
NSL=($(curl -s -b "$J" "$B/admin/round/$NR" | grep -o '/admin/slot/[0-9]*/book'))
N2P=$(curl -s -L -b "$J" --data-urlencode "csrf=$NT" -d "name=Outbox+Eintrag&email=$(n_addr 2)&lang=de" "$B${NSL[0]}")
N2L=$(echo "$N2P" | grep -o 'http[^ <"]*/b/[A-Za-z0-9_-]\{43\}' | head -1)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Outbox+Ohne+Mail&email=$(n_addr 3)&lang=de" "$B${NSL[1]}"
check "teacher's entry without a mail: not reminded, nothing noted" '[ -n "$N2L" ] && [ "$(DBQ "SELECT CONCAT(remind_ok, notified_at IS NULL) FROM tp_bookings WHERE name IN (\"Outbox Eintrag\", \"Outbox Ohne Mail\") ORDER BY name")" = "$(printf "01\n01")" ]'
N4=$(book "$NRS" "Outbox Nachtrag" "$(n_addr 4)")

# The mail server refuses (no sender address): confirmations and change notices go to the outbox, and so
# does the confirmation of a parent who books right then (in-process, script on stdin for the coverage run)
NQ=$($DC exec -T app php -- /srv/app "$NRS" "$(n_addr 5)" 2>&1 <<'PHP'
<?php
[, $root, $slug, $email] = $argv;
require "$root/app/bootstrap.php";
$cfg = require "$root/config/config.php";
App\Outbox::process(); // what is due anyway, so that only the mails of this test fail below
App\Config::load(["mail" => ["from_address" => ""] + $cfg["mail"], "csrf_min_age_seconds" => 0] + $cfg);
App\I18n::set("de");
$id = static fn (string $name): int => (int) App\Db::value("SELECT id FROM bookings WHERE name = ?", [$name]);
$booking = static fn (string $name): array => (array) App\BookingService::findById($id($name));
$out = App\Notifier::sendOrQueue($booking("Outbox Eltern"), "changed", null) . "|"
    . App\Notifier::sendOrQueue($booking("Outbox Eintrag"), "booked", str_repeat("A", 43)) . "|"
    . App\Notifier::sendOrQueue($booking("Outbox Eintrag"), "changed", null) . "|"
    . var_export(App\Notifier::sendOrQueue(["email" => ""] + $booking("Outbox Eintrag"), "changed", null), true);
$round = App\Db::one("SELECT id FROM rounds WHERE slug = ?", [$slug]);
$slot = App\Db::value("SELECT s.id FROM slots s JOIN round_days d ON d.id = s.day_id LEFT JOIN bookings b ON b.slot_id = s.id
    WHERE d.round_id = ? AND b.id IS NULL ORDER BY s.start_time LIMIT 1", [$round["id"]]);
$_SERVER["REMOTE_ADDR"] = "198.51.100.40"; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REQUEST_URI"] = "/$slug";
$_POST = ["csrf" => App\Csrf::publicToken("book:" . $round["id"]), "slot_id" => (string) $slot, "name" => "Outbox Warteschlange",
    "email" => $email, "privacy" => "1", "website" => ""];
// Printed at the end: the controller sends headers (redirect), so no output before it
register_shutdown_function(static function () use ($id, $out): void {
    echo $out, "|", http_response_code(), "|", App\Db::value("SELECT CONCAT(kind, ':', attempts) FROM mail_outbox WHERE booking_id = ?", [$id("Outbox Warteschlange")]);
    App\Db::run("DELETE FROM rate_limits WHERE bucket IN (?, ?)", ["attempt", "booking"]);
});
(new App\Controller\PublicController())->dispatch("/$slug", "POST");
PHP
)
NQ_ROWS=$(DBQ "SELECT GROUP_CONCAT(CONCAT(b.name, ':', o.kind, ':', o.attempts, ':', o.next_attempt_at > NOW()) ORDER BY b.name SEPARATOR ',') FROM tp_mail_outbox o JOIN tp_bookings b ON b.id = o.booking_id WHERE b.name LIKE 'Outbox %'")
check "mail refused: queued for a retry in some minutes (a change notice waits behind the confirmation), the booking page goes on ($NQ)" '[ "$NQ" = "queued|queued|queued|NULL|303|booked:1" ] && [ "$NQ_ROWS" = "Outbox Eintrag:booked:1:1,Outbox Eltern:changed:1:1,Outbox Warteschlange:booked:1:1" ]'
check "the outbox holds references only: no address, text or link" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_mail_outbox\" AND COLUMN_NAME NOT IN (\"id\",\"kind\",\"booking_id\",\"user_id\",\"ref\",\"priority\",\"attempts\",\"next_attempt_at\",\"created_at\")")" = 0 ]'
NA=$(curl -s -b "$J" "$B/admin/round/$NR")
check "booking list: \"E-Mail nicht zugestellt – neuer Versuch folgt\" at the three entries" '[ "$(echo "$NA" | grep -c "data-mail-failed")" = 3 ] && [ "$(echo "$NA" | grep -c "E-Mail nicht zugestellt – neuer Versuch folgt</span>")" = 3 ]'
check "appointment page after booking with the mail queued: says it follows" 'curl -s "$N1L?done=booked&mail=queued" | has "<p data-mail-queued>Die Bestätigung konnte gerade nicht gesendet werden." && ! curl -s "$N1L?done=booked&mail=1" | has "data-mail-queued" && curl -s "$N1L?done=changed&mail=queued&lang=en" | has "It will be sent to your e-mail address automatically"'

# Retry while the server still refuses: the pause grows
NF=$($DC exec -T app php -- /srv/app 2>&1 <<'PHP'
<?php
[, $root] = $argv;
require "$root/app/bootstrap.php";
$cfg = require "$root/config/config.php";
App\Outbox::process();
App\Db::run("UPDATE mail_outbox SET next_attempt_at = NOW() WHERE booking_id IN (SELECT id FROM bookings WHERE name LIKE 'Outbox %')");
App\Config::load(["mail" => ["from_address" => ""] + $cfg["mail"]] + $cfg);
echo json_encode(App\Outbox::process());
PHP
)
check "failed again: retried later, after a longer pause ($NF)" '[ "$NF" = "{\"mails_sent\":0,\"mails_failed\":3,\"mails_dropped\":0}" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox o JOIN tp_bookings b ON b.id = o.booking_id WHERE b.name LIKE \"Outbox %\" AND o.attempts = 2 AND o.next_attempt_at > NOW() + INTERVAL 10 MINUTE")" = 3 ]'

# The mail server works again: the cron job sends them, the confirmation with a new link of its own
DBQ "UPDATE tp_mail_outbox SET next_attempt_at = NOW() WHERE booking_id IN (SELECT id FROM tp_bookings WHERE name LIKE 'Outbox %')"
NO=$($DC exec -T app php /srv/app/bin/cron.php -v)
N2T=$(n_text "$(n_addr 2)" Terminbest%C3%A4tigung); N2ML=$(echo "$N2T" | grep -o 'http[^ ]*/b/[A-Za-z0-9_-]\{43\}' | head -1)
N5T=$(n_text "$(n_addr 5)" Terminbest%C3%A4tigung); N5ML=$(echo "$N5T" | grep -o 'http[^ ]*/b/[A-Za-z0-9_-]\{43\}' | head -1)
check "cron job: the queued mails go out (one connection), nothing left, marks gone" 'echo "$NO" | grep -qE "mails_sent +[3-9]" && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox o JOIN tp_bookings b ON b.id = o.booking_id WHERE b.name LIKE \"Outbox %\"")" = 0 ] && ! curl -s -b "$J" "$B/admin/round/$NR" | has "data-mail-failed"'
check "change notice from the outbox: current details, the link stays the same" '[ "$(n_mails "$(n_addr 1)")" = 2 ] && n_text "$(n_addr 1)" ge%C3%A4ndert | has "Ihr bisheriger persönlicher Link bleibt gültig."'
check "confirmation from the outbox: a new link that works, the one shown before still works" '[ -n "$N2ML" ] && [ "${N2ML##*/}" != "${N2L##*/}" ] && curl -s "$N2ML" | has "<dd>Outbox Eintrag</dd>" && curl -s "$N2L" | has "<dd>Outbox Eintrag</dd>" && [ "$(DBQ "SELECT mail_token_hash FROM tp_bookings WHERE name=\"Outbox Eintrag\"")" = "$(printf %s "${N2ML##*/}" | sha256sum | cut -d" " -f1)" ]'
check "… also for the parent who booked while the server refused, without a reminder right behind it" '[ -n "$N5ML" ] && curl -s "$N5ML" | has "<dd>Outbox Warteschlange</dd>" && [ "$(n_mails "$(n_addr 5)")" = 1 ] && echo "$NO" | grep -qE "reminders_queued +1" && echo "$NO" | grep -qE "mails_dropped +1"'
check "teacher's entry: after the confirmation it may be reminded, confirmation noted" '[ "$(DBQ "SELECT CONCAT(remind_ok, notified_at IS NOT NULL, mail_failed_at IS NULL) FROM tp_bookings WHERE name=\"Outbox Eintrag\"")" = 111 ]'

# Reminders the evening before: per booking, filtered by the last confirmation (not by any edit)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Outbox+Nachtrag+Neu&email=$(n_addr 4)&lang=de" "$B/admin/booking/$(n_id "Outbox Nachtrag")/edit"
DBQ "UPDATE tp_bookings SET notified_at = notified_at - INTERVAL 2 DAY WHERE name LIKE 'Outbox %'"
NO=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "reminders queued and sent by the cron job" 'echo "$NO" | grep -qE "reminders_queued +3" && echo "$NO" | grep -qE "mails_sent +[3-9]"'
check "reminder: also after the teacher corrected the name the evening before (no mail), and for an entry whose parents got a confirmation" '[ "$(n_mails "$(n_addr 4)" Erinnerung)" = 1 ] && n_text "$(n_addr 4)" Erinnerung | has "Name: Outbox Nachtrag Neu" && [ "$(n_mails "$(n_addr 2)" Erinnerung)" = 1 ] && [ "$(n_mails "$(n_addr 1)" Erinnerung)" = 1 ]'
check "no reminder for an entry by the teacher whose parents never got a mail" '[ "$(n_mails "$(n_addr 3)" Erinnerung)" = 0 ] && [ "$(DBQ "SELECT reminded_at IS NULL FROM tp_bookings WHERE name=\"Outbox Ohne Mail\"")" = 1 ]'

# Order, limits and the fallback after a page request (in-process, script on stdin for the coverage run)
NK=$($DC exec -T app php -- /srv/app 2>&1 <<'PHP'
<?php
[, $root] = $argv;
require "$root/app/bootstrap.php";
$cfg = require "$root/config/config.php";
$broken = ["mail" => ["from_address" => ""] + $cfg["mail"]] + $cfg;
$id = static fn (string $name): int => (int) App\Db::value("SELECT id FROM bookings WHERE name = ?", [$name]);
$kinds = static fn (int $booking): string => (string) App\Db::value("SELECT GROUP_CONCAT(kind ORDER BY kind) FROM mail_outbox WHERE booking_id = ?", [$booking]);
App\Outbox::process();
$out = [];
// A reminder that failed and is no longer due (the round stopped reminding) is dropped
App\Db::run("UPDATE bookings SET reminded_at = NULL WHERE id = ?", [$id("Outbox Eltern")]);
App\Reminders::run();
$queued = $kinds($id("Outbox Eltern"));
App\Config::load($broken);
$failed = App\Outbox::process()["mails_failed"];
App\Config::load($cfg);
App\Db::run("UPDATE rounds SET remind_parents = 0 WHERE title = 'Outbox Test'");
App\Db::run("UPDATE mail_outbox SET next_attempt_at = NOW()");
$out[] = "$queued/$failed/" . App\Outbox::process()["mails_dropped"] . "/" . App\Db::value("SELECT mail_failed_at IS NOT NULL FROM bookings WHERE id = ?", [$id("Outbox Eltern")]);
App\Db::run("UPDATE rounds SET remind_parents = 1 WHERE title = 'Outbox Test'");
// Hourly limit used up: reminders wait for the next hour, single mails go out first anyway
App\Config::load(["mail_queue" => ["hourly_limit" => 1] + $cfg["mail_queue"]] + $cfg);
App\Settings::set("mail_hour", App\Time::now()->format("Y-m-d H") . "|1");
App\Outbox::queueBooking($id("Outbox Warteschlange"), "reminder");
App\Outbox::queueBooking($id("Outbox Eintrag"), "changed");
$out[] = App\Outbox::process()["mails_sent"] . "/" . $kinds($id("Outbox Warteschlange")) . "/" . (int) (App\Settings::get("mail_hour") === App\Time::now()->format("Y-m-d H") . "|2");
App\Db::run("DELETE FROM mail_outbox WHERE booking_id = ?", [$id("Outbox Warteschlange")]);
App\Config::load($cfg);
// Only one run at a time: while another connection holds the lock, a run sends nothing
$db = $cfg["db"];
$other = new PDO("mysql:host={$db["host"]};port={$db["port"]};dbname={$db["name"]}", $db["user"], $db["pass"]);
$other->query("SELECT GET_LOCK(" . $other->quote(App\Db::table("outbox")) . ", 0)");
App\Outbox::queueBooking($id("Outbox Nachtrag Neu"), "changed");
$out[] = json_encode(App\Outbox::process());
$other = null;
// After a page request: only once the response has gone out (PHP-FPM), and not when turned off
$out[] = App\Outbox::afterResponse(static fn (): bool => false) . "/" . (App\Outbox::due() ? "due" : "-");
App\Config::load(["mail_queue" => ["after_response" => false] + $cfg["mail_queue"]] + $cfg);
$out[] = App\Outbox::afterResponse(static fn (): bool => true);
App\Config::load($cfg);
$out[] = App\Outbox::afterResponse(static fn (): bool => true) . "/" . (App\Outbox::due() ? "due" : "-");
// A kind this version does not know (queued by a newer one before a rollback) is dropped
App\Db::run("INSERT INTO mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('unbekannt', ?, 1, NOW(), NOW())", [$id("Outbox Eintrag")]);
$out[] = App\Outbox::process()["mails_dropped"];
// Summaries from the outbox: only while they are still right
$uid = (int) App\Db::value("SELECT user_id FROM rounds WHERE title = 'Outbox Test'");
$day = (int) App\Db::value("SELECT d.id FROM round_days d JOIN rounds r ON r.id = d.round_id WHERE r.title = 'Outbox Test'");
$out[] = implode(",", array_map(static fn ($r) => var_export($r, true), [
    App\Summaries::deliver("summary_evening", $uid, App\Time::today()),
    App\Summaries::deliver("summary_deadline", $uid, "day-0"),
    App\Summaries::deliver("summary_deadline", 0, "day-$day"),
    App\Summaries::deliver("summary_evening", $uid, App\Time::now()->modify("+1 day")->format("Y-m-d")),
    App\Summaries::deliver("summary_deadline", $uid, "day-$day"),
]));
echo implode("|", $out);
PHP
)
check "outbox: a reminder no longer due is dropped; the hourly limit holds back reminders, not single mails; one run at a time; after a page request only once the response is out; unknown kinds dropped; summaries only while right ($NK)" \
  '[ "$NK" = "reminder/1/1/1|1/reminder/1|{\"mails_sent\":0,\"mails_failed\":0,\"mails_dropped\":0}|0/due|0|1/-|1|NULL,NULL,NULL,true,true" ]'

# Given up after 48 hours (also when no attempt was made, e.g. without a cron job)
DBQ "INSERT INTO tp_mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('changed', $(n_id "Outbox Ohne Mail"), 1, NOW() - INTERVAL 49 HOUR, NOW() - INTERVAL 49 HOUR)"
NO=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "after 48 hours: given up, the entry shows \"E-Mail nicht zugestellt\" without a retry" 'echo "$NO" | grep -qE "mails_expired +1" && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE booking_id = $(n_id "Outbox Ohne Mail")")" = 0 ] && curl -s -b "$J" "$B/admin/round/$NR" | grep -q "data-mail-failed title=\"Es folgt kein weiterer Versuch[^\"]*\">E-Mail nicht zugestellt</span>"'
DBQ "INSERT INTO tp_mail_outbox (kind, booking_id, priority, attempts, next_attempt_at, created_at) VALUES ('changed', $(n_id "Outbox Eintrag"), 1, 3, NOW() + INTERVAL 1 HOUR, NOW() - INTERVAL 3 HOUR)"
check "admins see mails waiting for more than two hours (cron job or mail server)" 'curl -s -b "$J" "$B/admin" | has "1 E-Mail(s) warten seit über 2 Stunden auf den Versand"'
DBQ "DELETE FROM tp_mail_outbox WHERE booking_id = $(n_id "Outbox Eintrag")"
check "… and no notice without them" '! curl -s -b "$J" "$B/admin" | has "data-mails-late"'
check "web cron: reports the mails it sent" 'curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron" | has "\"mails_sent\":"'

# Migration 19 on a database of the version before: parents' bookings remind as their round does, entries by
# the teacher only after a new confirmation; the last confirmation counts as sent at the last change
DBQ "DROP TABLE tp_mail_outbox; ALTER TABLE tp_bookings DROP INDEX uq_bookings_mail_token, DROP COLUMN mail_token_hash, DROP COLUMN remind_ok, DROP COLUMN notified_at, DROP COLUMN mail_failed_at; UPDATE tp_settings SET value = '$((LATEST - 1))' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration $LATEST: outbox and booking columns created, bookings carried over" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_bookings\" AND COLUMN_NAME IN (\"remind_ok\",\"notified_at\",\"mail_failed_at\",\"mail_token_hash\")")" = 4 ] && [ "$(DBQ "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_mail_outbox\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && [ "$(DBQ "SELECT GROUP_CONCAT(CONCAT(remind_ok, notified_at = updated_at) ORDER BY name) FROM tp_bookings WHERE name IN (\"Outbox Eltern\", \"Outbox Eintrag\")")" = "01,11" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$N2L")" = 200 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1" "$B/admin/round/$NR/delete"
