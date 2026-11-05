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
check "the outbox holds references only: no address, text or link (a payload only for cancellations, see below)" '[ "$(DBQ "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_mail_outbox\"")" = "id,kind,booking_id,user_id,ref,payload,priority,attempts,next_attempt_at,created_at" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE payload IS NOT NULL")" = 0 ]'
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
DBQ "UPDATE tp_bookings SET created_at = created_at - INTERVAL 2 DAY, updated_at = updated_at - INTERVAL 2 DAY, notified_at = notified_at - INTERVAL 2 DAY WHERE name LIKE 'Outbox %'"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Outbox+Nachtrag+Neu&email=$(n_addr 4)&lang=de" "$B/admin/booking/$(n_id "Outbox Nachtrag")/edit"
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
$due = static fn (): string => App\Outbox::due() ? "due" : "-";
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
// Hourly limit used up: reminders wait for the next hour (a page request does not start a run for them),
// single mails go out first anyway
App\Config::load(["mail_queue" => ["hourly_limit" => 1] + $cfg["mail_queue"]] + $cfg);
App\Settings::set("mail_hour", App\Time::now()->format("Y-m-d H") . "|1");
App\Outbox::queueBooking($id("Outbox Warteschlange"), "reminder");
App\Outbox::queueBooking($id("Outbox Eintrag"), "changed");
$sent = App\Outbox::process()["mails_sent"];
$heldBack = $due();
App\Config::load($cfg);
$out[] = "$sent/" . $kinds($id("Outbox Warteschlange")) . "/" . (int) (App\Settings::get("mail_hour") === App\Time::now()->format("Y-m-d H") . "|2") . "/$heldBack/" . $due();
App\Db::run("DELETE FROM mail_outbox WHERE booking_id = ?", [$id("Outbox Warteschlange")]);
// At most that many mails per run, the rest waits for the next one
App\Outbox::queueBooking($id("Outbox Eltern"), "changed");
App\Outbox::queueBooking($id("Outbox Nachtrag Neu"), "changed");
$out[] = App\Outbox::process(1)["mails_sent"] . "/" . App\Db::value("SELECT COUNT(*) FROM mail_outbox WHERE booking_id IN (?, ?)", [$id("Outbox Eltern"), $id("Outbox Nachtrag Neu")]);
App\Outbox::process();
// Only one run at a time: while another connection holds the lock, a run sends nothing (the cron job waits
// for it a little); the same installation in another database on the server does not block it
$db = $cfg["db"];
$other = new PDO("mysql:host={$db["host"]};port={$db["port"]};dbname={$db["name"]}", $db["user"], $db["pass"]);
$lock = "SHA1(CONCAT(DATABASE(), '.', " . $other->quote(App\Db::table("outbox")) . "))";
$other->query("SELECT GET_LOCK($lock, 0)");
App\Outbox::queueBooking($id("Outbox Nachtrag Neu"), "changed");
$started = microtime(true);
$out[] = json_encode(App\Outbox::process()) . "/" . App\Outbox::process(null, null, 1)["mails_sent"] . "/" . (int) (microtime(true) - $started >= 1);
$other->query("SELECT RELEASE_LOCK($lock)");
$other->query("USE information_schema");
$other->query("SELECT GET_LOCK($lock, 0)");
$out[] = App\Outbox::process()["mails_sent"];
$other = null;
App\Outbox::queueBooking($id("Outbox Nachtrag Neu"), "changed");
// After a page request: only once the response has gone out (PHP-FPM), and not when turned off
$out[] = App\Outbox::afterResponse(static fn (): bool => false) . "/" . (App\Outbox::due() ? "due" : "-");
App\Config::load(["mail_queue" => ["after_response" => false] + $cfg["mail_queue"]] + $cfg);
$out[] = App\Outbox::afterResponse(static fn (): bool => true);
App\Config::load($cfg);
session_save_path(sys_get_temp_dir()); // an admin's session is closed first: the next page does not wait
session_start();
$out[] = App\Outbox::afterResponse(static fn (): bool => true) . "/" . (App\Outbox::due() ? "due" : "-") . "/" . (session_status() === PHP_SESSION_NONE ? "closed" : "open");
// Dropped: a kind this version does not know (queued by a newer one before a rollback), a booking whose
// address was removed in the meantime
App\Db::run("INSERT INTO mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('unbekannt', ?, 1, NOW(), NOW())", [$id("Outbox Eintrag")]);
$address = App\Db::value("SELECT email FROM bookings WHERE id = ?", [$id("Outbox Ohne Mail")]);
App\Db::run("UPDATE bookings SET email = NULL WHERE id = ?", [$id("Outbox Ohne Mail")]);
App\Outbox::queueBooking($id("Outbox Ohne Mail"), "changed");
$out[] = json_encode(App\Outbox::process());
App\Db::run("UPDATE bookings SET email = ? WHERE id = ?", [$address, $id("Outbox Ohne Mail")]);
// A change notice waiting behind a confirmation of the same run: the confirmation has the current details,
// so the notice is not sent as well (removed when the confirmation went out, skipped though already read)
App\Outbox::queueBooking($id("Outbox Eltern"), "booked");
$now = App\Time::nowSql();
App\Db::run("INSERT INTO mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('changed', ?, 1, ?, ?)", [$id("Outbox Eltern"), $now, $now]);
App\Db::run("UPDATE mail_outbox SET next_attempt_at = ? WHERE booking_id = ?", [$now, $id("Outbox Eltern")]);
$out[] = json_encode(App\Outbox::process()) . "/" . $kinds($id("Outbox Eltern"));
// Too old to be sent (the next clean-up gives it up): no run after a page request for it
App\Db::run("INSERT INTO mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('changed', ?, 1, ?, ?)",
    [$id("Outbox Eintrag"), $now, App\Time::now()->modify("-49 hours")->format("Y-m-d H:i:s")]);
$out[] = $due();
App\Db::run("DELETE FROM mail_outbox WHERE booking_id = ?", [$id("Outbox Eintrag")]);
// A reminder for a booking moved to another day in the meantime is not sent (the right one is queued then)
$booking = ["remind_parents" => 1, "remind_ok" => 1, "notified_at" => null] + (array) App\BookingService::findById($id("Outbox Eltern"));
$out[] = var_export(App\Reminders::deliver(["day_date" => App\Time::today()] + $booking), true) . ","
    . var_export(App\Reminders::deliver(["day_date" => App\Time::now()->modify("+1 day")->format("Y-m-d")] + $booking), true);
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
check "outbox: a reminder no longer due is dropped; the hourly limit holds back reminders, not single mails (and starts no run for them); a limit per run; one run at a time per database (the cron job waits a little); after a page request only once the response is out; obsolete mails dropped, also a change notice made obsolete in the same run; nothing due that is too old; no reminder for another day; summaries only while right ($NK)" \
  '[ "$NK" = "reminder/1/1/1|1/reminder/1/-/due|1/1|{\"mails_sent\":0,\"mails_failed\":0,\"mails_dropped\":0}/0/1|1|0/due|0|1/-/closed|{\"mails_sent\":0,\"mails_failed\":0,\"mails_dropped\":2}|{\"mails_sent\":1,\"mails_failed\":0,\"mails_dropped\":1}/|-|NULL,true|NULL,NULL,NULL,true,true" ]'

# Given up after 48 hours (also when no attempt was made, e.g. without a cron job)
DBQ "INSERT INTO tp_mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('changed', $(n_id "Outbox Ohne Mail"), 1, NOW() - INTERVAL 49 HOUR, NOW() - INTERVAL 49 HOUR)"
NO=$($DC exec -T app php /srv/app/bin/cron.php -v)
NG=$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE booking_id = $(n_id "Outbox Ohne Mail")")
DBQ "INSERT INTO tp_mail_outbox (kind, booking_id, priority, next_attempt_at, created_at) VALUES ('reminder', $(n_id "Outbox Ohne Mail"), 3, NOW() + INTERVAL 1 HOUR, NOW())"
check "after 48 hours: given up, the entry shows \"E-Mail nicht zugestellt\" without a retry (also with another mail queued since)" 'echo "$NO" | grep -qE "mails_expired +1" && [ "$NG" = 0 ] && curl -s -b "$J" "$B/admin/round/$NR" | grep -q "data-mail-failed title=\"Es folgt kein weiterer Versuch[^\"]*\">E-Mail nicht zugestellt</span>"'
DBQ "DELETE FROM tp_mail_outbox WHERE booking_id = $(n_id "Outbox Ohne Mail")"
DBQ "INSERT INTO tp_mail_outbox (kind, booking_id, priority, attempts, next_attempt_at, created_at) VALUES ('changed', $(n_id "Outbox Eintrag"), 1, 3, NOW() + INTERVAL 1 HOUR, NOW() - INTERVAL 3 HOUR)"
check "admins see mails waiting for more than two hours (cron job or mail server)" 'curl -s -b "$J" "$B/admin" | has "1 E-Mail(s) warten seit über 2 Stunden auf den Versand"'
DBQ "DELETE FROM tp_mail_outbox WHERE booking_id = $(n_id "Outbox Eintrag")"
check "… and no notice without them" '! curl -s -b "$J" "$B/admin" | has "data-mails-late"'
check "web cron: reports the mails it sent" 'curl -s -X POST -H "X-Cron-Token: $CTOK" "$B/cron" | has "\"mails_sent\":"'

# The reminder turned on later (DS-12): bookings made while the round did not remind get none, neither the
# parents' own one nor a teacher's entry whose parents got a confirmation then; one made afterwards does
NL=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Outbox+Spaet&remind_parents_field=1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+1 day' +%F)&start_time=09:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
NLS=$(slugof "$NL")
NL1=$(book "$NLS" "Outbox Spaet Eltern" "$(n_addr 6)")
NLSL=($(curl -s -b "$J" "$B/admin/round/$NL" | grep -o '/admin/slot/[0-9]*/book'))
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Outbox+Spaet+Eintrag&email=$(n_addr 7)&lang=de&send_mail=1" "$B${NLSL[0]}"
check "round without the reminder: parents' booking and teacher's entry confirmed by mail, neither may be reminded" '[[ "$NL1" == 303* ]] && [ "$(n_mails "$(n_addr 6)")" = 1 ] && [ "$(n_mails "$(n_addr 7)")" = 1 ] && [ "$(DBQ "SELECT GROUP_CONCAT(CONCAT(remind_ok, notified_at IS NOT NULL) ORDER BY name) FROM tp_bookings WHERE name IN (\"Outbox Spaet Eltern\", \"Outbox Spaet Eintrag\")")" = "01,01" ]'
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "title=Outbox+Spaet&remind_parents_field=1&remind_parents=1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0" "$B/admin/round/$NL/edit"
NL2=$(book "$NLS" "Outbox Spaet Neu" "$(n_addr 8)")
DBQ "UPDATE tp_bookings SET created_at = created_at - INTERVAL 2 DAY, updated_at = updated_at - INTERVAL 2 DAY, notified_at = notified_at - INTERVAL 2 DAY WHERE name LIKE 'Outbox Spaet %'"
NO=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "reminder turned on afterwards: the parents who booked before get none, the ones who booked after it do" '[ "$(DBQ "SELECT remind_parents FROM tp_rounds WHERE id = $NL")" = 1 ] && [[ "$NL2" == 303* ]] && [ "$(n_mails "$(n_addr 8)" Erinnerung)" = 1 ] && [ "$(n_mails "$(n_addr 6)" Erinnerung)" = 0 ] && [ "$(DBQ "SELECT GROUP_CONCAT(CONCAT(remind_ok, reminded_at IS NULL) ORDER BY name) FROM tp_bookings WHERE name IN (\"Outbox Spaet Eltern\", \"Outbox Spaet Neu\")")" = "01,10" ]'
check "… nor the teacher's entry confirmed while the round did not remind" '[ "$(n_mails "$(n_addr 7)" Erinnerung)" = 0 ] && [ "$(DBQ "SELECT CONCAT(remind_ok, reminded_at IS NULL) FROM tp_bookings WHERE name = \"Outbox Spaet Eintrag\"")" = "01" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1" "$B/admin/round/$NL/delete"

# Migration 20 on a database of the version before: parents' bookings remind as their round does, entries by
# the teacher only after a new confirmation; the last confirmation counts as sent at the last change
DBQ "DROP TABLE tp_mail_outbox; ALTER TABLE tp_bookings DROP INDEX uq_bookings_mail_token, DROP COLUMN mail_token_hash, DROP COLUMN remind_ok, DROP COLUMN notified_at, DROP COLUMN mail_failed_at; UPDATE tp_settings SET value = '19' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 20: outbox and booking columns created, bookings carried over" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_bookings\" AND COLUMN_NAME IN (\"remind_ok\",\"notified_at\",\"mail_failed_at\",\"mail_token_hash\")")" = 4 ] && [ "$(DBQ "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_mail_outbox\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ] && [ "$(DBQ "SELECT GROUP_CONCAT(CONCAT(remind_ok, notified_at = updated_at) ORDER BY name) FROM tp_bookings WHERE name IN (\"Outbox Eltern\", \"Outbox Eintrag\")")" = "01,11" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$N2L")" = 200 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1" "$B/admin/round/$NR/delete"

# Changes by the teacher that concern booked parents (ScheduleService): cancellations and change notices in the
# parents' language, only with the box "Eltern per E-Mail informieren" and only to parents who know the booking
# (booked themselves, or got a mail about the teacher's entry); the others are named in the message
echo "Parents told about changes by the teacher"
NT=$(curl -s -b "$J" "$B/admin" | csrf)
c_addr() { echo "aenderung-$1-$NRUN@example.org"; }
# Newest mail to $1 (subject word $2): 1 if a part says method=CANCEL, then the content of its first attachment
c_ics() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query='"$(n_query "$@")"'"),true); $id=$r["messages"][0]["ID"] ?? "x"; $a=json_decode((string) @file_get_contents("http://mail:8025/api/v1/message/$id"),true)["Attachments"][0] ?? null; echo (int) str_contains((string) @file_get_contents("http://mail:8025/api/v1/message/$id/raw"), "method=CANCEL"), "\n", $a ? file_get_contents("http://mail:8025/api/v1/message/$id/part/" . $a["PartID"]) : "";'; }
# What waits in the outbox: change notices of the bookings of this test, cancellations ('' = nothing)
c_outbox() { DBQ "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', b.name, o.kind, o.priority) ORDER BY o.id), '') FROM tp_mail_outbox o JOIN tp_bookings b ON b.id = o.booking_id WHERE b.name LIKE 'Aenderung %'"; }
c_cancelled() { DBQ "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', JSON_VALUE(payload, '$.name'), JSON_VALUE(payload, '$.lang'), priority, attempts) ORDER BY id), '') FROM tp_mail_outbox WHERE kind = 'cancelled'"; }
c_day() { curl -s -L -b "$J" -d "csrf=$NT&day_date=$2&start_time=09:00&end_time=10:00&slot_minutes=${4:-15}${5:-}" --data-urlencode "location=$3" "$B/admin/day/$1/edit"; }
CD1=$(date -d '+20 day' +%F); CD2=$(date -d '+21 day' +%F); CD3=$(date -d '+22 day' +%F)
CR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Aenderung+Test&location=Raum+1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$CD1&day_dates%5B%5D=$CD2&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
CRS=$(slugof "$CR")
CSL=($(curl -s -b "$J" "$B/admin/round/$CR" | grep -o '/admin/slot/[0-9]*/book'))
CDAYS=($(curl -s -b "$J" "$B/admin/round/$CR" | grep -o 'id="day-[0-9]*"' | grep -o '[0-9]\+'))
# Day 1: parents with an address, an English entry with a confirmation, an entry without one, one without an
# address; day 2: three parents
book "$CRS" "Aenderung Eltern" "$(c_addr 1)" >/dev/null
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Aenderung+Eintrag&email=$(c_addr 2)&lang=en&send_mail=1" "$B${CSL[1]}"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Aenderung+Unbestaetigt&email=$(c_addr 3)&lang=de" "$B${CSL[2]}"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$NT" -d "name=Aenderung+Ohne+Mail&lang=de" "$B${CSL[3]}"
for n in "Tag Zwei:5" "Einzeln:6" "Abgelehnt:7"; do book "$CRS" "Aenderung ${n%:*}" "$(c_addr "${n#*:}")" >/dev/null; done
check "set-up: four entries on the first day, three on the second" '[ -n "$CRS" ] && [ "${#CDAYS[@]}" = 2 ] && [ "$(DBQ "SELECT GROUP_CONCAT(d.day_date ORDER BY s.start_time, d.day_date) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $CR")" = "$CD1,$CD2,$CD1,$CD2,$CD1,$CD2,$CD1" ]'

# A single entry deleted with the box: the cancellation goes out at once
C6=$(n_id "Aenderung Einzeln")
R=$(curl -s -L -b "$J" -d "csrf=$NT&notify=1" "$B/admin/booking/$C6/delete")
CT=$(n_text "$(c_addr 6)" abgesagt)
CI=$(c_ics "$(c_addr 6)" abgesagt)
check "entry deleted with the box: cancellation at once, in the parents' language, with the round page to book again (no link of the booking)" 'echo "$R" | has "Eintrag gelöscht – der Slot ist wieder frei. Absage per E-Mail an die Eltern gesendet." && echo "$CT" | has "leider wurde Ihr Termin von der Lehrkraft abgesagt" && echo "$CT" | has "Name: Aenderung Einzeln" && echo "$CT" | has "Ort: Raum 1" && echo "$CT" | has "$B/$CRS?lang=de" && ! echo "$CT" | has "/b/"' \
  || echo "$CT" | head -12
check "… with a calendar file that cancels the entry in the parents' calendar (same UID, METHOD:CANCEL, round and teacher)" '[ "$(echo "$CI" | head -1)" = 1 ] && echo "$CI" | has "METHOD:CANCEL" && echo "$CI" | has "STATUS:CANCELLED" && echo "$CI" | has "UID:appointment-$C6@" && echo "$CI" | has "SUMMARY:Aenderung Test – Frau Sabrina Test"'
check "… logged without a name of the parents" '[ "$(DBQ "SELECT message LIKE \"%: Buchung % gelöscht, Eltern per E-Mail informiert\" AND message NOT LIKE \"%Einzeln%\" FROM tp_audit_log ORDER BY id DESC LIMIT 1")" = 1 ]'
# The mail server refuses it (an address it cannot take): the cancellation waits in the outbox
DBQ "UPDATE tp_bookings SET email = 'kaputt@@example.org' WHERE name = 'Aenderung Abgelehnt'"
R=$(curl -s -L -b "$J" -d "csrf=$NT&notify=1" "$B/admin/booking/$(n_id "Aenderung Abgelehnt")/delete")
check "mail refused: the cancellation waits in the outbox as a single mail, retried later, without a booking to refer to" 'echo "$R" | has "Die Absage per E-Mail konnte gerade nicht gesendet werden. Sie wird automatisch erneut versucht." && [ "$(c_cancelled)" = "Aenderung Abgelehnt:de:1:1" ] && [ "$(DBQ "SELECT CONCAT(booking_id IS NULL, user_id IS NULL, next_attempt_at > NOW()) FROM tp_mail_outbox WHERE kind = \"cancelled\"")" = 111 ]'
DBQ "UPDATE tp_mail_outbox SET payload = JSON_REPLACE(payload, '$.email', '$(c_addr 7)'), next_attempt_at = NOW() WHERE kind = 'cancelled'"

# New location of day 2, new date of day 1, with the box: change notices from the outbox (bulk mails)
R=$(c_day "${CDAYS[1]}" "$CD2" "Raum 7" 15 "&notify=1")
check "new location of a day with the box: the parents get the new details from the outbox as a bulk mail" 'echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten per E-Mail in ihrer Sprache." && [ "$(c_outbox)" = "Aenderung Tag Zwei:changed:2" ]'
R=$(c_day "${CDAYS[0]}" "$CD3" "" 15 "&notify=1")
CW=$(date -d "$CD3" +%d.%m.%Y)
check "new date with the box: two parents by mail, the entry without a confirmation and the one without an address named for the teacher" 'echo "$R" | has "Die Eltern von 2 Buchung(en) erhalten die neuen Termindaten" && echo "$R" | has "Keine E-Mail an die Eltern von 2 Buchung(en) (keine Adresse hinterlegt oder Eintrag nie per E-Mail bestätigt) – bitte persönlich informieren: Aenderung Unbestaetigt, $(c_addr 3) ($CW 09:30); Aenderung Ohne Mail ($CW 09:45)." && [ "$(c_outbox)" = "Aenderung Tag Zwei:changed:2,Aenderung Eltern:changed:2,Aenderung Eintrag:changed:2" ]'
check "… logged with the number, without names" '[ "$(DBQ "SELECT message LIKE \"%bearbeitet (jetzt %), Eltern von 2 Buchung(en) per E-Mail informiert\" FROM tp_audit_log ORDER BY id DESC LIMIT 1")" = 1 ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "cron job: the change notices go out with the new details (English for the English entry), and the refused cancellation" 'n_text "$(c_addr 5)" ge%C3%A4ndert | has "Ort: Raum 7" && n_text "$(c_addr 1)" ge%C3%A4ndert | has ", $CW, 09:00" && n_text "$(c_addr 2)" changed | has "New appointment: " && [ "$(n_mails "$(c_addr 3)")" = 0 ] && [ "$(n_mails "$(c_addr 7)" abgesagt)" = 1 ] && [ -z "$(c_outbox)" ] && [ -z "$(c_cancelled)" ]'

# Longer slots: the booking that keeps its start gets the new end time; the others become conflicts, their
# parents hear of it when the teacher moves or deletes them
R=$(c_day "${CDAYS[0]}" "$CD3" "" 20 "&notify=1")
check "longer slots: a change notice for the new end time, none for the three conflicts" 'echo "$R" | has "Achtung: 3 gebuchte(r) Termin(e) passen nicht mehr zum Zeitplan" && echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten" && [ "$(c_outbox)" = "Aenderung Eltern:changed:2" ]'
R=$(curl -s -L -b "$J" -d "csrf=$NT&notify=1" "$B/admin/booking/$(n_id "Aenderung Unbestaetigt")/delete")
check "conflict deleted with the box, an entry whose parents never got a mail: none now either, please inform in person" 'echo "$R" | has "Die Eltern („Aenderung Unbestaetigt“, $(c_addr 3)) haben zu diesem Eintrag nie eine E-Mail erhalten und wurden deshalb nicht benachrichtigt – bitte persönlich informieren." && [ "$(n_mails "$(c_addr 3)")" = 0 ]'

# Day deleted with the box: cancellations with what the mails need; the change notice goes with its booking
R=$(curl -s -L -b "$J" -d "csrf=$NT&confirm=1&notify=1" "$B/admin/day/${CDAYS[0]}/delete")
check "day deleted with the box: cancellations queued as bulk mails in the parents' language, the one without an address named" 'echo "$R" | has "Die Eltern von 2 Buchung(en) erhalten eine Absage per E-Mail in ihrer Sprache." && echo "$R" | has "bitte persönlich informieren: Aenderung Ohne Mail ($CW 09:45)." && [ "$(c_cancelled)" = "Aenderung Eltern:de:2:0,Aenderung Eintrag:en:2:0" ]'
check "… a cancellation keeps what its mail needs, no link, token or description" '[ "$(DBQ "SELECT GROUP_CONCAT(DISTINCT JSON_KEYS(payload)) FROM tp_mail_outbox WHERE kind = \"cancelled\"")" = "[\"booking_id\", \"round_id\", \"email\", \"lang\", \"name\", \"title\", \"auto_translated\", \"source_lang\", \"teacher_name\", \"teacher_email\", \"day_date\", \"start_time\", \"end_time\", \"location\", \"created_at\"]" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE kind = \"cancelled\" AND (payload LIKE \"%/b/%\" OR payload LIKE \"%hash%\")")" = 0 ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "cron job: the cancellations go out in the parents' language with the round page; the change notice of a deleted booking does not" 'n_text "$(c_addr 2)" cancelled | has "unfortunately the teacher has cancelled your appointment" && n_text "$(c_addr 2)" cancelled | has "$B/$CRS?lang=en" && n_text "$(c_addr 1)" abgesagt | has "Abgesagter Termin: " && [ "$(n_mails "$(c_addr 1)" ge%C3%A4ndert)" = 1 ] && [ -z "$(c_cancelled)" ]'

# Another teacher takes the round over, then her account is deleted, both with the box
curl -s -o /dev/null -b "$J" -d "csrf=$NT&display_name=Frau+Aenderung&email=lk-aenderung-$NRUN@example.org&role=teacher" "$B/admin/users/new"
CU=$(DBQ "SELECT id FROM tp_users WHERE username = 'lk-aenderung-$NRUN@example.org'")
R=$(curl -s -L -b "$J" --data-urlencode "csrf=$NT" -d "title=Aenderung+Test&user_id=$CU&booking_deadline_hours=0&cancel_deadline_hours=0&notify=1" "$B/admin/round/$CR/edit")
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "round handed to another teacher with the box: the parents get the change notice with her name" 'echo "$R" | has "Die Runde gehört jetzt Frau Aenderung." && echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten" && [ "$(n_mails "$(c_addr 5)" ge%C3%A4ndert)" = 2 ] && n_text "$(c_addr 5)" ge%C3%A4ndert | has "Gespräch mit: Frau Aenderung"'
UF=$(curl -s -b "$J" "$B/admin/users/$CU/edit" | tr '\n' '~')
check "account with a booking: the delete dialog names the cancellation, the box is ticked" 'echo "$UF" | has "mit allen Runden und Buchungen endgültig löschen?~~Die Eltern von 1 Buchung(en) erhalten eine Absage per E-Mail.\" data-confirm-off=\"" && echo "$UF" | has "<input type=\"checkbox\" name=\"notify\" value=\"1\" checked> Eltern per E-Mail informieren"'
R=$(curl -s -L -b "$J" -d "csrf=$NT&confirm=1&notify=1" "$B/admin/users/$CU/delete")
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "account deleted with the box: the parents get the cancellation, without a page to book again (the round is gone)" 'echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten eine Absage per E-Mail in ihrer Sprache." && n_text "$(c_addr 5)" abgesagt | has "Gespräch mit: Frau Aenderung" && ! n_text "$(c_addr 5)" abgesagt | has "/$CRS" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id = $CR")" ]'

# A round: the box after an error, a new location, the parents' own cancellation, then the round deleted
CR2=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Aenderung+Runde&location=Raum+1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$CD1&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
CR2S=$(slugof "$CR2")
book "$CR2S" "Aenderung Runde Eltern" "$(c_addr 8)" >/dev/null
CSELF=$(book "$CR2S" "Aenderung Selbst" "$(c_addr 9)"); CSELF=${CSELF#* }; CSELF=${CSELF%%\?*}
c_round() { curl -s -L -b "$J" --data-urlencode "csrf=$NT" -d "booking_deadline_hours=0&cancel_deadline_hours=0&is_active=1$1" "${@:2}" "$B/admin/round/$CR2/edit"; }
check "round form after an error: the box stays as it was sent" 'c_round "&title=" | has "name=\"notify\" value=\"1\" aria-describedby=\"notify-hint\">" && c_round "&title=&notify=1" | has "name=\"notify\" value=\"1\" checked aria-describedby=\"notify-hint\">"'
curl -s -o /dev/null --data-urlencode "csrf=$(curl -s "$CSELF" | csrf)" -d "action=cancel&confirm=1" "$CSELF"
CI=$(c_ics "$(c_addr 9)" abgesagt)
check "parents cancel themselves: the mail carries the calendar file that cancels the appointment" '[ -n "$CSELF" ] && n_text "$(c_addr 9)" abgesagt | has "Ihr Termin wurde abgesagt" && [ "$(echo "$CI" | head -1)" = 1 ] && echo "$CI" | has "METHOD:CANCEL" && echo "$CI" | has "SUMMARY:Aenderung Runde – Frau Sabrina Test"'
R=$(c_round "&title=Aenderung+Runde&notify=1" --data-urlencode "location=Aula")
check "new location of the round with the box: a change notice for its bookings" 'echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten" && [ "$(c_outbox)" = "Aenderung Runde Eltern:changed:2" ]'
R=$(curl -s -L -b "$J" -d "csrf=$NT&confirm=1&notify=1" "$B/admin/round/$CR2/delete")
check "round deleted with the box: a cancellation instead of the change notice, logged with the number" 'echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten eine Absage per E-Mail in ihrer Sprache." && [ "$(c_cancelled)" = "Aenderung Runde Eltern:de:2:0" ] && [ "$(DBQ "SELECT message FROM tp_audit_log ORDER BY id DESC LIMIT 1")" = "Runde „Aenderung Runde“ (Frau Sabrina Test): gelöscht (mit 1 Buchung(en)), Eltern von 1 Buchung(en) per E-Mail informiert" ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "cron job: the cancellation names the new location, no change notice, no page to book again" 'n_text "$(c_addr 8)" abgesagt | has "Ort: Aula" && ! n_text "$(c_addr 8)" abgesagt | has "/$CR2S" && [ "$(n_mails "$(c_addr 8)" ge%C3%A4ndert)" = 0 ]'

# Bulk mails and the hourly limit; cancellations no longer needed (in-process, script on stdin for the coverage run)
CK=$($DC exec -T app php -- /srv/app "$(c_addr 10)" "$(c_addr 11)" 2>&1 <<'PHP'
<?php
[, $root, $bulk, $single] = $argv;
require "$root/app/bootstrap.php";
$cfg = require "$root/config/config.php";
App\Outbox::process();
$data = ['booking_id' => 0, 'round_id' => 0, 'lang' => 'de', 'name' => 'Aenderung Warteschlange', 'title' => 'Aenderung', 'auto_translated' => false,
    'source_lang' => 'de', 'teacher_name' => 'Frau Test', 'teacher_email' => '', 'day_date' => App\Time::now()->modify('+2 days')->format('Y-m-d'),
    'start_time' => '09:00:00', 'end_time' => '09:15:00', 'location' => '', 'created_at' => App\Time::nowSql()];
$out = [];
// Hourly limit used up: a bulk cancellation waits for the next hour (no run after a page request for it), a single one goes out
App\Config::load(['mail_queue' => ['hourly_limit' => 1] + $cfg['mail_queue']] + $cfg);
App\Settings::set('mail_hour', App\Time::now()->format('Y-m-d H') . '|1');
App\Outbox::queueCancellation(['email' => $bulk] + $data, false, true);
App\Outbox::queueCancellation(['email' => $single] + $data);
$out[] = App\Outbox::process()['mails_sent'] . '/' . (App\Outbox::due() ? 'due' : '-') . '/' . App\Db::value("SELECT COUNT(*) FROM mail_outbox WHERE kind = 'cancelled'");
App\Config::load($cfg);
$out[] = App\Outbox::process()['mails_sent'];
// Not sent: the appointment has begun meanwhile, or the row lacks what the mail needs
App\Outbox::queueCancellation(['email' => $single, 'day_date' => App\Time::now()->modify('-1 day')->format('Y-m-d')] + $data);
App\Db::run("INSERT INTO mail_outbox (kind, payload, priority, next_attempt_at, created_at) VALUES ('cancelled', '{}', 1, ?, ?)", [App\Time::nowSql(), App\Time::nowSql()]);
$out[] = json_encode(App\Outbox::process());
echo implode('|', $out);
PHP
)
check "outbox: the hourly limit holds back bulk cancellations, not single ones; an appointment that has begun or a row without its data is dropped ($CK)" '[ "$CK" = "1/-/1|1|{\"mails_sent\":0,\"mails_failed\":0,\"mails_dropped\":2}" ] && [ "$(n_mails "$(c_addr 10)" abgesagt)" = 1 ] && [ "$(n_mails "$(c_addr 11)" abgesagt)" = 1 ] && [ -z "$(c_cancelled)" ]'

# Migration 21 on a database of the version before: the outbox gets the column for cancellations
DBQ "ALTER TABLE tp_mail_outbox DROP COLUMN payload; UPDATE tp_settings SET value = '20' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 21: the outbox gets the column for cancellations" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_mail_outbox\" AND COLUMN_NAME=\"payload\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'

# Expert review P1-4: summaries without names unless the school allows them, the reply address for parents,
# allowed_account_domains, no admin session without the cookie and the way back to the page asked for
echo "Summaries without names, reply address, return after signing in"
ST=$(curl -s -b "$J" "$B/admin" | csrf)
SRUN=$(date +%s)
s_addr() { echo "summary-$1-$SRUN@example.org"; }
s_files() { $DC exec -T app php -r 'echo count(glob("/srv/app/app/var/sessions/sess_*"));'; }
# s_ics <address> [subject word] → the calendar file of the newest mail ('' without one)
s_ics() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query='"$(n_query "$@")"'"),true); $id=$r["messages"][0]["ID"] ?? "x"; $a=json_decode(file_get_contents("http://mail:8025/api/v1/message/$id"),true)["Attachments"] ?? []; echo $a ? file_get_contents("http://mail:8025/api/v1/message/$id/part/".$a[0]["PartID"]) : "";'; }
SU=$(DBQ "SELECT id FROM tp_users WHERE username = '$USER'")
S_BEFORE=$(DBQ "SELECT CONCAT('email = ', QUOTE(email), ', reply_to = ', QUOTE(reply_to), ', summary_evening = ', summary_evening, ', summary_deadline = ', summary_deadline, ', summary_names = ', QUOTE(summary_names)) FROM tp_users WHERE id = $SU")
SNAME=$(DBQ "SELECT display_name FROM tp_users WHERE id = $SU")
DBQ "UPDATE tp_users SET email = '$(s_addr konto)', reply_to = NULL, summary_evening = 1, summary_deadline = 1 WHERE id = $SU"
# A round for tomorrow: the same name and address twice, parents without an address, an entry by the teacher
SR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$ST" -d "title=Zusammenfassung+Test&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+1 day' +%F)&start_time=09:00&end_time=11:30&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$')
SRS=$(slugof "$SR")
book "$SRS" "Anna Muster" "$(s_addr eltern)" >/dev/null
book "$SRS" "anna muster" "$(s_addr eltern)" >/dev/null
book "$SRS" "Ben Ohne Adresse" "" >/dev/null
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$ST" -d "name=Eintrag+Lehrkraft&lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$SR" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
check "parents' mail: replies go to the account's address while it has no reply address" \
  '$DC exec -T app php -r "echo file_get_contents(\"http://mail:8025/api/v1/search?query=to:$(s_addr eltern)\");" | has "\"ReplyTo\":[{\"Name\":\"\",\"Address\":\"$(s_addr konto)\"}]"'

# The summaries from the outbox (in-process, script on stdin for the coverage run); each one to its own address
SM=$($DC exec -T app php -- /srv/app "$SU" "$(date -d '+1 day' +%F)" "$(DBQ "SELECT id FROM tp_round_days WHERE round_id = $SR")" "$(s_addr)" <<'PHP'
<?php
[, $root, $uid, $date, $day, $addr] = $argv;
require "$root/app/bootstrap.php";
$cfg = require "$root/config/config.php";
$to = static fn (string $n) => App\Db::run('UPDATE users SET email = ? WHERE id = ?', [str_replace('summary--', "summary-$n-", $addr), $uid]);
$names = static fn (?int $v) => App\Db::run('UPDATE users SET summary_names = ? WHERE id = ?', [$v, $uid]);
$evening = static fn (): string => var_export(App\Summaries::deliver('summary_evening', (int) $uid, $date), true);
$out = [];
$to('ohne'); $names(1);                       // the account wants names, the school does not allow them
$out[] = $evening();
App\Config::load(['summary_names' => true] + $cfg);
$to('namen');
$out[] = $evening();                          // allowed and wanted: names and the calendar file
$to('alt'); $names(null);
$out[] = $evening();                          // account from before: as before
$to('frist'); $names(0);
$out[] = var_export(App\Summaries::deliver('summary_deadline', (int) $uid, "day-$day"), true);
App\Config::load(['allowed_account_domains' => ['schule.example.org']] + $cfg);
$to('fremd');
$out[] = $evening();                          // an address outside the allowed domains gets none
App\Config::load($cfg);
echo implode('|', $out);
PHP
)
SM1=$(n_text "$(s_addr ohne)" Morgen)
check "summary by default: the number, what stands out, the link; no name, no address, no calendar file ($SM)" \
  '[ "$SM" = "true|true|true|true|NULL" ] && echo "$SM1" | has "Zusammenfassung Test – " && echo "$SM1" | has "  4 Termine zwischen 09:00 und 11:00" && echo "$SM1" | has "  Auffällig: 2× gleicher Name, 2× gleiche E-Mail-Adresse, 1× ohne E-Mail-Adresse" && echo "$SM1" | has "Übersicht und Druckansicht: $B/admin/round/$SR" && echo "$SM1" | has "Diese Zusammenfassung enthält keine Namen" && ! echo "$SM1" | grep -qi "anna\|ben ohne\|eintrag\|@example.org" && [ -z "$(s_ics "$(s_addr ohne)" Morgen)" ]'
SM2=$(n_text "$(s_addr namen)" Morgen); SICS=$(s_ics "$(s_addr namen)" Morgen)
check "summary where the school allows names: times and names, the calendar file without names" \
  'echo "$SM2" | has "  09:00–09:30  Anna Muster" && echo "$SM2" | has "  09:30–10:00  anna muster" && echo "$SM2" | has "spätestens zum Ende des Schuljahres" && echo "$SICS" | has "SUMMARY:Zusammenfassung Test" && [ "$(echo "$SICS" | grep -c "^BEGIN:VEVENT")" -ge 4 ] && ! echo "$SICS" | grep -qi "anna\|ben\|eintrag\|@example.org"'
check "summary: an account from before keeps the names where they are allowed; after the deadline without them" \
  'n_text "$(s_addr alt)" Morgen | has "  09:00–09:30  Anna Muster" && n_text "$(s_addr frist)" Buchungsschluss | has "  4 Termine zwischen 09:00 und 11:00" && ! n_text "$(s_addr frist)" Buchungsschluss | has "Anna" && [ "$(n_mails "$(s_addr fremd)")" = 0 ]'
DBQ "UPDATE tp_users SET email = '$(s_addr konto)' WHERE id = $SU"

# Notice on the dashboard for an account from before, until it confirms it
DBQ "UPDATE tp_users SET summary_names = NULL WHERE id = $SU"
SD=$(curl -s -b "$J" "$B/admin")
curl -s -o /dev/null -b "$J" -d "csrf=$ST" "$B/admin/profile/summaries"
check "dashboard: accounts from before are told that names are gone (admins: how to allow them), until they confirm; the choice stays" \
  'echo "$SD" | has "data-summary-notice>" && echo "$SD" | has "Neu: Ihre Zusammenfassungen per E-Mail enthalten keine Namen und keine Kalenderdatei mehr" && echo "$SD" | has "„summary_names“ in config/config.php" && [ "$(DBQ "SELECT summary_names FROM tp_users WHERE id = $SU")" = 1 ] && ! curl -s -b "$J" "$B/admin" | has "data-summary-notice"'
SPF() { curl -s -L -b "$J" --data-urlencode "csrf=$ST" --data-urlencode "display_name=$SNAME" --data-urlencode "email=$(s_addr konto)" -d "summary_evening=1&summary_deadline=1" "$@" "$B/admin/profile"; }
DBQ "UPDATE tp_users SET summary_names = NULL WHERE id = $SU"
check "profile: without the school's permission no choice of names, saving keeps it as it was" \
  '! curl -s -b "$J" "$B/admin/profile" | has "name=\"summary_names\"" && SPF -d "summary_names=0" | has "Profil gespeichert." && [ "$(DBQ "SELECT COALESCE(summary_names, \"NULL\") FROM tp_users WHERE id = $SU")" = NULL ]'

# Reply address for parents: only with the password, then the Reply-To of the parents' mails
check "reply address: invalid one refused, changing it needs the password" \
  'SPF --data-urlencode "reply_to=kein at" | has "Die Antwortadresse für Eltern ist ungültig." && SPF --data-urlencode "reply_to=$(s_addr antwort)" | has "Zum Ändern der Antwortadresse für Eltern bitte Ihr aktuelles Passwort eingeben." && [ "$(DBQ "SELECT COALESCE(reply_to, \"-\") FROM tp_users WHERE id = $SU")" = - ]'
SPF --data-urlencode "reply_to=$(s_addr antwort)" --data-urlencode "current_password=$PASS" >/dev/null
book "$SRS" "Antwort Test" "$(s_addr eltern2)" >/dev/null
check "reply address saved; the parents' mails name it as Reply-To instead of the account's address" \
  '[ "$(DBQ "SELECT reply_to FROM tp_users WHERE id = $SU")" = "$(s_addr antwort)" ] && curl -s -b "$J" "$B/admin/profile" | has "value=\"$(s_addr antwort)\"" && $DC exec -T app php -r "echo file_get_contents(\"http://mail:8025/api/v1/search?query=to:$(s_addr eltern2)\");" | has "\"ReplyTo\":[{\"Name\":\"\",\"Address\":\"$(s_addr antwort)\"}]"'

# The school allows names and only addresses at its domain (config.php changed for a moment, see operations.sh)
ALERTS '$f = "/srv/app/config/config.php"; copy($f, "$f.p14"); $c = require $f; $c["summary_names"] = true; $c["allowed_account_domains"] = ["schule.example.org"];
  file_put_contents($f, "<?php return " . var_export($c, true) . ";");'
sleep 3
SPN=$(curl -s -b "$J" "$B/admin/profile"); SDN=$(curl -s -b "$J" "$B/admin")
S_OFF=$(SPF --data-urlencode "reply_to=$(s_addr antwort)" | has "Profil gespeichert." && DBQ "SELECT summary_names FROM tp_users WHERE id = $SU")
S_ON=$(SPF --data-urlencode "reply_to=$(s_addr antwort)" -d "summary_names=1" | has "Profil gespeichert." && DBQ "SELECT summary_names FROM tp_users WHERE id = $SU")
S_MAIL=$(SPF --data-urlencode "reply_to=$(s_addr antwort)" --data-urlencode "email=privat-$SRUN@gmx.example" --data-urlencode "current_password=$PASS")
S_REPLY=$(SPF --data-urlencode "reply_to=privat-$SRUN@gmx.example" --data-urlencode "current_password=$PASS")
S_USER=$(curl -s -b "$J" --data-urlencode "csrf=$ST" -d "display_name=Fremd&email=fremd-$SRUN@gmx.example&role=teacher" "$B/admin/users/new")
ALERTS '$f = "/srv/app/config/config.php"; file_put_contents($f, file_get_contents("$f.p14")) && unlink("$f.p14");'
sleep 3
check "names allowed: the choice in the profile (as before for an account from before), saved both ways" \
  'echo "$SPN" | has "<input type=\"checkbox\" id=\"summary_names\" name=\"summary_names\" value=\"1\" checked>" && [ "$S_OFF" = 0 ] && [ "$S_ON" = 1 ]'
check "allowed domains: a new address elsewhere is refused (account, reply address, new account); an address from before can stay, with hints" \
  'echo "$S_MAIL" | has "Bitte eine Adresse bei schule.example.org angeben" && echo "$S_REPLY" | has "Antwortadresse für Eltern: Bitte eine Adresse bei schule.example.org angeben" && echo "$S_USER" | has "Bitte eine Adresse bei schule.example.org angeben" && [ -z "$(DBQ "SELECT id FROM tp_users WHERE email = \"fremd-$SRUN@gmx.example\"")" ] && [ "$(DBQ "SELECT email FROM tp_users WHERE id = $SU")" = "$(s_addr konto)" ] && echo "$SPN" | has "Die Schule erlaubt nur Adressen bei schule.example.org." && echo "$SDN" | has "data-foreign-addresses>" && echo "$SDN" | has "Ihre E-Mail-Adresse ist keine dienstliche Adresse bei schule.example.org"'

# No session without the cookie; a link from another site (webmailer) opens the page in a signed-in browser
SF0=$(s_files)
SH=$(curl -s -D - -o /dev/null "$B/admin/round/$SR?day=1")
SH2=$(curl -s -D - -o /dev/null -d "csrf=x" "$B/admin/round/$SR/toggle")
check "without the cookie: to the login page with the page asked for; no session, no cookie (GET and POST)" \
  'echo "$SH" | head -1 | grep -q " 303" && echo "$SH" | grep -qi "^location: /admin/login?next=%2Fadmin%2Fround%2F$SR%3Fday%3D1" && echo "$SH2" | grep -qi "^location: /admin/login.$" && ! echo "$SH$SH2" | grep -qi "^set-cookie" && [ "$(s_files)" -le "$SF0" ] && curl -s -D - -o /dev/null "$B/admin" | grep -qi "^location: /admin/login.$"'
SX=$(curl -s -D - -H "Sec-Fetch-Site: cross-site" "$B/admin/round/$SR?day=1")
check "link from another site without the cookie (SameSite=Strict): interim page that opens it again from this site, no session" \
  'echo "$SX" | head -1 | grep -q " 200" && echo "$SX" | has "<meta http-equiv=\"refresh\" content=\"0; url=/admin/round/$SR?day=1\">" && echo "$SX" | has "<a href=\"/admin/round/$SR?day=1\">Weiter</a>" && ! echo "$SX" | grep -qi "^set-cookie" && [ "$(s_files)" -le "$SF0" ] && curl -s -H "Sec-Fetch-Site: cross-site" "$B/admin" | has "content=\"0; url=/admin\">"'
check "… and with the cookie (opened again from this site) the page itself" \
  'curl -s -b "$J" -H "Sec-Fetch-Site: cross-site" "$B/admin/round/$SR" | has "<h1>Zusammenfassung Test</h1>"'
SH=$(curl -s -D - -o /dev/null -b "tp_admin=$(printf 'a%.0s' {1..40})" "$B/admin/round/$SR")
check "unknown session cookie: ended, to the login page with the page asked for, no session left" \
  'echo "$SH" | grep -qi "^location: /admin/login?next=%2Fadmin%2Fround%2F$SR" && echo "$SH" | grep -i "^set-cookie: tp_admin=deleted" | grep -qi "path=/admin" && [ "$(s_files)" -le "$SF0" ]'
# Signing in leads back there: only to a page of the admin area
JN=$(mktemp)
SL=$(curl -s -c "$JN" -b "$JN" "$B/admin/login?next=%2Fadmin%2Fround%2F$SR%3Fday%3D1")
SLOC=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JN" -b "$JN" --data-urlencode "csrf=$(echo "$SL" | csrf)" --data-urlencode "username=$USER" --data-urlencode "password=$PASS" --data-urlencode "next=/admin/round/$SR?day=1" "$B/admin/login")
check "signing in: back to the page asked for (with its query); signed in already, the login page leads there too" \
  'echo "$SL" | has "<input type=\"hidden\" name=\"next\" value=\"/admin/round/$SR?day=1\">" && [ "$SLOC" = "$B/admin/round/$SR?day=1" ] && [ "$(curl -s -o /dev/null -w "%{redirect_url}" -b "$JN" "$B/admin/login?next=%2Fadmin%2Fround%2F$SR")" = "$B/admin/round/$SR" ]'
curl -s -o /dev/null -b "$JN" -d "csrf=$(curl -s -b "$JN" "$B/admin" | csrf)" "$B/admin/logout"
SL=$(curl -s -c "$JN" -b "$JN" "$B/admin/login?next=%2F%2Fevil.example%2Fadmin")
SLOC=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JN" -b "$JN" --data-urlencode "csrf=$(echo "$SL" | csrf)" --data-urlencode "username=$USER" --data-urlencode "password=$PASS" --data-urlencode "next=https://evil.example/admin/round/1" "$B/admin/login")
check "signing in: another site or an odd path as the target leads to the overview" '! echo "$SL" | has "name=\"next\"" && [ "$SLOC" = "$B/admin" ]'
curl -s -o /dev/null -b "$JN" -d "csrf=$(curl -s -b "$JN" "$B/admin" | csrf)" "$B/admin/logout"
rm -f "$JN"

# Migration 22 on a database of the version before: accounts from before NULL (as before), new ones 0, a
# repeated run changes nothing
S_IDS=$(DBQ "SELECT GROUP_CONCAT(CONCAT(id, ':', COALESCE(summary_names, 'NULL'))) FROM tp_users")
DBQ "ALTER TABLE tp_users DROP COLUMN summary_names, DROP COLUMN reply_to; UPDATE tp_settings SET value = '21' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
DBQ "INSERT INTO tp_users (username, password_hash, display_name, created_at) VALUES ('migration-22-$SRUN', 'x', 'Neu', NOW()); UPDATE tp_settings SET value = '21' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 22: reply address and choice of names; accounts from before NULL, new ones 0, a repeated run keeps them" \
  '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_users\" AND COLUMN_NAME IN (\"summary_names\",\"reply_to\")")" = 2 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_users WHERE summary_names IS NOT NULL AND username <> \"migration-22-$SRUN\"")" = 0 ] && [ "$(DBQ "SELECT summary_names FROM tp_users WHERE username = \"migration-22-$SRUN\"")" = 0 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'
DBQ "DELETE FROM tp_users WHERE username = 'migration-22-$SRUN'"
for s_pair in ${S_IDS//,/ }; do DBQ "UPDATE tp_users SET summary_names = ${s_pair#*:} WHERE id = ${s_pair%%:*}"; done
curl -s -o /dev/null -b "$J" -d "csrf=$ST&confirm=1" "$B/admin/round/$SR/delete"
DBQ "UPDATE tp_users SET $S_BEFORE WHERE id = $SU"
