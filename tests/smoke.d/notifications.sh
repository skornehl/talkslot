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
# The dialog has no box for an entry whose parents never got a mail: the message says why nobody was told
R=$(curl -s -L -b "$J" -d "csrf=$NT" "$B/admin/booking/$(n_id "Aenderung Unbestaetigt")/delete")
check "conflict deleted, an entry whose parents never got a mail (no box in the dialog): none now either, please inform in person" 'echo "$R" | has "Die Eltern („Aenderung Unbestaetigt“, $(c_addr 3)) haben zu diesem Eintrag nie eine E-Mail erhalten und wurden deshalb nicht benachrichtigt – bitte persönlich informieren." && [ "$(n_mails "$(c_addr 3)")" = 0 ]'

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
# Replies to the newest mail to $1 (subject word $2) go to: JSON list of addresses, null if none
c_replyto() { $DC exec -T app php -r '$r=json_decode(file_get_contents("http://mail:8025/api/v1/search?query='"$(n_query "$@")"'"),true); echo json_encode(array_column($r["messages"][0]["ReplyTo"] ?? [], "Address") ?: null);'; }
check "… without the address of the deleted account for replies (her change notice had it)" '[ "$(c_replyto "$(c_addr 5)" abgesagt)" = null ] && [ "$(c_replyto "$(c_addr 5)" ge%C3%A4ndert)" = "[\"lk-aenderung-$NRUN@example.org\"]" ]'

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

# Review of the change notices (P1-3): a new address the teacher enters gets no mail after a change until one
# from the entry reached it; change notices are limited per account and hour; a conflict that fits again is
# told if its details changed meanwhile; a change notice updates the appointment in the parents' calendar
echo "Change notices: new addresses, limit per account, conflicts, calendar"
NT=$(curl -s -b "$J" "$B/admin" | csrf)
r_addr() { echo "review-$1-$NRUN@example.org"; }
r_outbox() { DBQ "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', b.name, o.kind) ORDER BY o.id), '') FROM tp_mail_outbox o JOIN tp_bookings b ON b.id = o.booking_id WHERE b.name LIKE 'Review %'"; }
# r_day <date> <end> <location> [notify, default ticked]: saves the day of the review round
r_day() { curl -s -L -b "$J" -d "csrf=$NT&day_date=$1&start_time=09:00&end_time=$2&slot_minutes=15${4-&notify=1}" --data-urlencode "location=$3" "$B/admin/day/$RDAY/edit"; }
r_seq() { c_ics "$@" | grep -o 'SEQUENCE:[0-9]*' | grep -o '[0-9]*$'; }
# Key of the limit per account in tp_rate_limits (App\RateLimiter, secret of dev/config.dev.php)
r_hm() { php -r 'echo hash_hmac("sha256", "subject|" . $argv[1], str_repeat("d", 64));' "$1"; }
RAID=$(DBQ "SELECT id FROM tp_users WHERE username = '$USER'")
RD1=$(date -d '+25 day' +%F); RD2=$(date -d '+26 day' +%F); RW=$(date -d "$RD2" +%d.%m.%Y)
RR=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Review+Test&location=Raum+1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$RD1&start_time=09:00&end_time=10:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
RRS=$(slugof "$RR")
RDAY=$(curl -s -b "$J" "$B/admin/round/$RR" | grep -o 'id="day-[0-9]*"' | grep -o '[0-9]\+' | head -1)
# 09:00 parents whose address the teacher replaces, 09:45 the later conflict, 09:15 and 09:30 two more parents
book "$RRS" "Review Adresse" "$(r_addr 1)" >/dev/null
book "$RRS" "Review Konflikt" "$(r_addr 3)" 3 >/dev/null
for n in "Eins:6" "Zwei:7"; do book "$RRS" "Review Limit ${n%:*}" "$(r_addr "${n#*:}")" >/dev/null; done
check "set-up: four bookings by parents" '[ -n "$RRS" ] && [ "$(DBQ "SELECT GROUP_CONCAT(CONCAT(b.name, \"@\", TIME_FORMAT(s.start_time, \"%H:%i\"), \":\", b.source) ORDER BY s.start_time) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id WHERE s.day_id = $RDAY")" = "Review Adresse@09:00:parent,Review Limit Eins@09:15:parent,Review Limit Zwei@09:30:parent,Review Konflikt@09:45:parent" ]'

# The teacher replaces the address of the parents without a mail: the new one counts as unknown
R=$(curl -s -L -b "$J" --data-urlencode "csrf=$NT" -d "name=Review+Adresse&email=$(r_addr 2)&lang=de" "$B/admin/booking/$(n_id "Review Adresse")/edit")
check "new address without a mail: the message says that it gets no mails after changes yet; an entry of the teacher now, no reminder" 'echo "$R" | has "Neue E-Mail-Adresse ohne E-Mail gespeichert: Die Eltern erhalten dort Änderungen, Absagen und Erinnerungen erst, wenn Sie den Eintrag mit „Änderung per E-Mail mitteilen“ speichern." && [ "$(DBQ "SELECT CONCAT(source, \":\", notified_at IS NULL, \":\", remind_ok) FROM tp_bookings WHERE name = \"Review Adresse\"")" = "admin:1:0" ]'
check "edit form: a new address gets mails after changes only after the one sent from here" 'curl -s -b "$J" "$B/admin/booking/$(n_id "Review Limit Eins")/edit" | has "An eine neue Adresse gehen Änderungen, Absagen und Erinnerungen erst nach dieser E-Mail."'
check "day form after an error: the box stays as it was sent" 'r_day "kaputt" 10:00 "" "" | has "name=\"notify\" value=\"1\">" && r_day "kaputt" 10:00 "" | has "name=\"notify\" value=\"1\" checked>"'
# The bookings were made an hour ago (calendar file and "changed since the last mail" go by the second)
DBQ "UPDATE tp_bookings SET created_at = created_at - INTERVAL 1 HOUR, updated_at = updated_at - INTERVAL 1 HOUR, notified_at = notified_at - INTERVAL 1 HOUR WHERE name LIKE 'Review %'"

# New date and an earlier end: three parents with a new date, the one at 09:45 becomes a conflict
R=$(r_day "$RD2" 09:45 "")
check "new date: no change notice to the replaced address, the teacher is told to inform them in person" 'echo "$R" | has "Die Eltern von 2 Buchung(en) erhalten die neuen Termindaten" && echo "$R" | has "bitte persönlich informieren: Review Adresse, $(r_addr 2) ($RW 09:00)." && [ "$(r_outbox)" = "Review Limit Eins:changed,Review Limit Zwei:changed" ]' \
  || echo "$R" | grep -o 'class="alert[^>]*>[^<]*' | head -5
check "… the conflict with the new date is named: its parents do not know it yet" 'echo "$R" | has "Achtung: 1 gebuchte(r) Termin(e) passen nicht mehr zum Zeitplan und sind als Konflikt markiert. Bitte umbuchen oder löschen. Bei 1 davon haben sich zugleich Datum, Ort oder Lehrkraft geändert; die Eltern kennen die neuen Daten noch nicht. Die Eltern erfahren davon"'
check "… every booking with a new date counts as changed, the conflict too" '[ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name LIKE \"Review %\" AND updated_at > created_at + INTERVAL 30 MINUTE")" = 4 ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "change notice: its calendar file updates the appointment (higher SEQUENCE than the confirmation, new date)" '[ "$(r_seq "$(r_addr 6)" Terminbest%C3%A4tigung)" = 0 ] && [ "$(r_seq "$(r_addr 6)" ge%C3%A4ndert)" -gt 0 ] && c_ics "$(r_addr 6)" ge%C3%A4ndert | has "DTSTART:${RD2//-/}T"'
check "… nothing to the replaced address" '[ "$(n_mails "$(r_addr 2)")" = 0 ]'

# The end back at 10:00: the conflict fits again with the new date, its parents get it now
R=$(r_day "$RD2" 10:00 "")
check "conflict fits again after its date changed: a change notice with the current details" 'echo "$R" | has "Tag gespeichert. Slots wurden aktualisiert." && echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten" && [ "$(r_outbox)" = "Review Konflikt:changed" ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "… sent with the new date" 'n_text "$(r_addr 3)" ge%C3%A4ndert | has ", $RW, 09:45"'
# A break makes it a conflict again and goes: nothing changed since that mail, so no mail
curl -s -o /dev/null -b "$J" -d "csrf=$NT&start_time=09:45&end_time=10:00" "$B/admin/day/$RDAY/break"
R=$(curl -s -L -b "$J" -d "csrf=$NT" "$B/admin/break/$(DBQ "SELECT id FROM tp_day_breaks WHERE day_id = $RDAY")/delete")
check "break added and removed: the conflict fits again unchanged, no mail" 'echo "$R" | has "Pause entfernt. Slots wurden aktualisiert." && ! echo "$R" | has "erhalten die neuen Termindaten" && [ -z "$(r_outbox)" ] && [ "$(DBQ "SELECT is_conflict FROM tp_slots WHERE day_id = $RDAY AND start_time = \"09:45:00\"")" = 0 ]'

# Limit per account: one change notice left this hour
DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('teacher_bulk', '$(r_hm "user:$RAID")', UNIX_TIMESTAMP(), 199) ON DUPLICATE KEY UPDATE hits = 199, window_start = UNIX_TIMESTAMP()"
r_hits() { DBQ "SELECT hits FROM tp_rate_limits WHERE bucket = 'teacher_bulk' AND key_hash = '$(r_hm "user:$RAID")'"; }
R=$(r_day "$RD2" 10:00 "Raum A")
check "limit per account: one change notice queued, the parents above the limit named for the teacher" 'echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten" && echo "$R" | has "Keine E-Mail an die Eltern von 2 Buchung(en): Ihr Konto hat die Höchstzahl von Änderungsmitteilungen je Stunde erreicht. Bitte persönlich informieren oder später den Eintrag mit „Änderung per E-Mail mitteilen“ speichern: Review Limit Zwei, $(r_addr 7) ($RW 09:30); Review Konflikt, $(r_addr 3) ($RW 09:45)." && [ "$(r_outbox)" = "Review Limit Eins:changed" ] && [ "$(r_hits)" = 200 ]'
R=$(r_day "$RD2" 10:00 "Raum B")
check "… changed again before it went out: the waiting notice gets the new details, nothing more counted" 'echo "$R" | has "Die Eltern von 1 Buchung(en) erhalten die neuen Termindaten" && echo "$R" | has "Keine E-Mail an die Eltern von 2 Buchung(en): Ihr Konto hat die Höchstzahl" && [ "$(r_outbox)" = "Review Limit Eins:changed" ] && [ "$(r_hits)" = 200 ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
R=$(r_day "$RD2" 10:00 "Raum C")
check "… once sent, repeated changes stay limited: nothing queued, all named" 'n_text "$(r_addr 6)" ge%C3%A4ndert | has "Ort: Raum B" && [ "$(n_mails "$(r_addr 6)" ge%C3%A4ndert)" = 2 ] && echo "$R" | has "Keine E-Mail an die Eltern von 3 Buchung(en): Ihr Konto hat die Höchstzahl" && ! echo "$R" | has "erhalten die neuen Termindaten" && [ -z "$(r_outbox)" ]'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'teacher_bulk'"

# A conflict gets the location of the round: named for the teacher
r_day "$RD2" 09:45 "" >/dev/null
R=$(curl -s -L -b "$J" --data-urlencode "csrf=$NT" -d "title=Review+Test&booking_deadline_hours=0&cancel_deadline_hours=0&is_active=1&notify=1" --data-urlencode "location=Aula" "$B/admin/round/$RR/edit")
check "new location of the round for a conflict: the warning says its parents do not know it yet" 'echo "$R" | has "Achtung: 1 gebuchte(r) Termin(e) sind als Konflikt markiert und haben jetzt einen anderen Ort oder eine andere Lehrkraft; die Eltern kennen die neuen Daten noch nicht. Die Eltern erfahren davon" && echo "$R" | has "Die Eltern von 2 Buchung(en) erhalten die neuen Termindaten"'

# Deleted with the box: no cancellation to the replaced address either
R=$(curl -s -L -b "$J" -d "csrf=$NT&notify=1" "$B/admin/booking/$(n_id "Review Adresse")/delete")
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
check "replaced address, booking deleted: no cancellation, the teacher is told to inform in person" 'echo "$R" | has "Die Eltern („Review Adresse“, $(r_addr 2)) haben zu diesem Eintrag nie eine E-Mail erhalten" && [ "$(n_mails "$(r_addr 2)")" = 0 ] && [ "$(n_mails "$(r_addr 1)" abgesagt)" = 0 ]'
# Deleted meanwhile (by the parents, or in another tab): no second cancellation (in-process, script on stdin for the coverage run)
RS=$($DC exec -T app php -- /srv/app "$(n_id "Review Limit Zwei")" 2>&1 <<'PHP'
<?php
[, $root, $id] = $argv;
require "$root/app/bootstrap.php";
$b = App\BookingService::findById((int) $id);
$first = App\BookingService::delete($b);
echo var_export($first, true), '|', var_export(App\ScheduleService::deleteBooking($b, true), true), '|', App\Db::value("SELECT COUNT(*) FROM mail_outbox WHERE kind = 'cancelled'");
PHP
)
check "booking deleted meanwhile: the teacher's deletion sends no second cancellation ($RS)" '[ "$RS" = "true|NULL|0" ] && [ "$(n_mails "$(r_addr 7)" abgesagt)" = 0 ]'

# Appointments that are over concern nobody: one the change moves into the past, and one over before the change
RP=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Review+Vorbei&location=Raum+1&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+3 day' +%F)&start_time=00:00&end_time=00:15&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
book "$(slugof "$RP")" "Review Vorbei" "$(r_addr 9)" >/dev/null
RPD=$(curl -s -b "$J" "$B/admin/round/$RP" | grep -o 'id="day-[0-9]*"' | grep -o '[0-9]\+' | head -1)
r_waits() { DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE booking_id = $(n_id "Review Vorbei")"; }
R=$(curl -s -L -b "$J" -d "csrf=$NT&day_date=$(date +%F)&start_time=00:00&end_time=00:15&slot_minutes=15&notify=1&location=" "$B/admin/day/$RPD/edit")
check "day moved to a time that is over (today 00:00): no change notice for it" 'echo "$R" | has "Tag gespeichert. Slots wurden aktualisiert." && ! echo "$R" | has "erhalten die neuen Termindaten" && ! echo "$R" | has "persönlich informieren" && [ "$(r_waits)" = 0 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id WHERE b.name = \"Review Vorbei\" AND s.day_id = $RPD")" = 1 ]'
R=$(curl -s -L -b "$J" --data-urlencode "csrf=$NT" -d "title=Review+Vorbei&booking_deadline_hours=0&cancel_deadline_hours=0&is_active=1&notify=1" --data-urlencode "location=Aula" "$B/admin/round/$RP/edit")
check "… nor after a new location of the round, which concerns only appointments still ahead" 'echo "$R" | has "Runde gespeichert." && ! echo "$R" | has "erhalten die neuen Termindaten" && ! echo "$R" | has "persönlich informieren" && [ "$(r_waits)" = 0 ] && [ "$(DBQ "SELECT location FROM tp_rounds WHERE id = $RP")" = Aula ]'
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1" "$B/admin/round/$RP/delete"

# Cancellations of appointments within 24 hours go out as single mails, not after the bulk mails
RT=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$NT" -d "title=Review+Morgen&booking_limit=none&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&day_dates%5B%5D=$(date -d '+1 day' +%F)&start_time=00:00&end_time=01:00&slot_minutes=15" "$B/admin/round/new" | grep -o '[0-9]*$')
book "$(slugof "$RT")" "Review Morgen" "$(r_addr 8)" >/dev/null
RTD=$(curl -s -b "$J" "$B/admin/round/$RT" | grep -o 'id="day-[0-9]*"' | grep -o '[0-9]\+' | head -1)
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1&notify=1" "$B/admin/day/$RTD/delete"
check "day deleted with an appointment in the next 24 hours: its cancellation is not a bulk mail" '[ "$(c_cancelled)" = "Review Morgen:de:1:0" ]'
$DC exec -T app php /srv/app/bin/cron.php >/dev/null
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1" "$B/admin/round/$RT/delete"
curl -s -o /dev/null -b "$J" -d "csrf=$NT&confirm=1" "$B/admin/round/$RR/delete"
check "clean-up: the rounds of the review are gone" '[ "$(n_mails "$(r_addr 8)" abgesagt)" = 1 ] && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id IN (${RT:-0}, ${RR:-0}, ${RP:-0})")" ] && [ -z "$(c_cancelled)" ]'

# Migration 21 on a database of the version before: the outbox gets the column for cancellations
DBQ "ALTER TABLE tp_mail_outbox DROP COLUMN payload; UPDATE tp_settings SET value = '20' WHERE name = 'schema_version'"
curl -s -o /dev/null "$B/"
check "migration 21: the outbox gets the column for cancellations" '[ "$(DBQ "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\"tp_mail_outbox\" AND COLUMN_NAME=\"payload\"")" = 1 ] && [ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$LATEST" ]'
