# Security events of an account, for its holder only (expert review P2-9, see README.md in this directory):
# password, link, reset, address, username, admin rights, the sign-in limit; failed attempts since the previous
# sign-in; an alarm to the admins when several accounts reach the limit; deleted after security_events_days.
echo "Security events (for the account holder only)"
SE=se$$
SET=$(curl -s -b "$J" "$B/admin/users" | csrf)
SE_POST() { curl -s -L -b "$J" --data-urlencode "csrf=$SET" "$@"; } # as the first admin
SE_LOGIN() { curl -s -o /dev/null -w '%{http_code}' -c "$1" -b "$1" --data-urlencode "csrf=$(curl -s -c "$1" -b "$1" "$B/admin/login" | csrf)" --data-urlencode "username=$2" --data-urlencode "password=$3" "$B/admin/login"; }
SE_EVENTS() { DBQ "SELECT COALESCE(GROUP_CONCAT(CONCAT(event, ':', by_admin) ORDER BY id), '') FROM tp_security_events WHERE user_id = $1"; }
SEH=$($DC exec -T app php -r 'echo password_hash("Ereignis-Passwort-1", PASSWORD_DEFAULT);')
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at, last_login_at) VALUES ('ereignis-$SE@example.org', '$SEH', 'teacher', 'Frau Ereignis', 'ereignis-$SE@example.org', 1, NOW(), NOW() - INTERVAL 3 DAY)"
SEU=$(DBQ "SELECT id FROM tp_users WHERE username = 'ereignis-$SE@example.org'")

# Failed attempts while signed out: the holder learns the number after signing in (and only then)
SEJ=$(mktemp)
for i in 1 2 3; do SE_LOGIN "$SEJ" "ereignis-$SE@example.org" "falsch-falsch-$i" > /dev/null; done
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'login'"
C=$(SE_LOGIN "$SEJ" "ereignis-$SE@example.org" "Ereignis-Passwort-1")
D=$(curl -s -b "$SEJ" "$B/admin")
check "after signing in: the failed attempts since the previous sign-in, once, with the way out" '[ "$C" = 303 ] && echo "$D" | grep -q "Seit Ihrer vorigen Anmeldung gab es 3 fehlgeschlagene Anmeldeversuche für Ihr Konto." && ! curl -s -b "$SEJ" "$B/admin" | grep -q "fehlgeschlagene Anmeldeversuche für Ihr Konto"'
P=$(curl -s -b "$SEJ" "$B/admin/profile")
check "profile: the previous sign-in and the failed attempts since, no events yet, the note who sees them" 'echo "$P" | grep -q "Ihre vorige Anmeldung: $(date -d "-3 day" +%d.%m.%Y)" && echo "$P" | grep -q "Fehlgeschlagene Anmeldeversuche seitdem: 3." && echo "$P" | grep -q "Keine Sicherheitsereignisse in den letzten 30 Tagen." && echo "$P" | grep -q "Das sehen nur Sie, auch Admins nicht."'

# Single events: own password, e-mail address by an admin, admin rights, reset by an admin, forgotten password
curl -s -o /dev/null -b "$SEJ" --data-urlencode "csrf=$(curl -s -b "$SEJ" "$B/admin/profile" | csrf)" -d "current_password=Ereignis-Passwort-1&new_password=Ereignis-Wolke-Tisch-2&new_password2=Ereignis-Wolke-Tisch-2" "$B/admin/password"
SE_POST -d "display_name=Frau+Ereignis&role=teacher&lang=de&is_active=1&username=ereignis-$SE&email=ereignis-neu-$SE@example.org" --data-urlencode "current_password=$PASS" "$B/admin/users/$SEU/edit" > /dev/null
SE_POST -d "display_name=Frau+Ereignis&role=admin&lang=de&is_active=1&username=ereignis-$SE&email=ereignis-neu-$SE@example.org" --data-urlencode "current_password=$PASS" "$B/admin/users/$SEU/edit" > /dev/null
SE_POST -d "display_name=Frau+Ereignis&role=teacher&lang=de&is_active=1&username=ereignis-$SE&email=ereignis-neu-$SE@example.org" --data-urlencode "current_password=$PASS" "$B/admin/users/$SEU/edit" > /dev/null
SE_POST "$B/admin/users/$SEU/reset-password" > /dev/null
DBQ "UPDATE tp_users SET access_token_hash = NULL, access_token_expires_at = NULL WHERE id = $SEU" # not "recently issued"
curl -s -o /dev/null --data-urlencode "csrf=$(curl -s "$B/admin/forgot" | csrf)" -d "email=ereignis-neu-$SE@example.org" "$B/admin/forgot"
check "events: own password, address and username by an admin, admin rights given and taken, reset by an admin, link requested ($(SE_EVENTS "$SEU"))" '[ "$(SE_EVENTS "$SEU")" = "password_changed:0,email_changed:1,username_changed:1,admin_granted:1,admin_revoked:1,reset_by_admin:1,reset_requested:0" ]'
check "… stored without IP address or device (only the account, the event, whether an admin did it, the time)" '[ "$(DBQ "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"tp_security_events\"")" = "id,user_id,event,by_admin,created_at" ]'
# The reset took the password away: a new one through the link from the mail
SELINK=$($DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode("to:ereignis-neu-" . $argv[1] . "@example.org")), true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $r["messages"][0]["ID"]), true)["Text"];' "$SE" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
SEJ2=$(mktemp)
curl -s -o /dev/null -c "$SEJ2" -b "$SEJ2" --data-urlencode "csrf=$(curl -s -c "$SEJ2" -b "$SEJ2" "$SELINK" | csrf)" -d "new_password=Ereignis-Birke-Ofen-3&new_password2=Ereignis-Birke-Ofen-3" "$SELINK"
SE_LOGIN "$SEJ2" "ereignis-$SE" "Ereignis-Birke-Ofen-3" > /dev/null
P=$(curl -s -b "$SEJ2" "$B/admin/profile")
check "profile: the account's events, newest first, in its language, with \"by an admin\" where one made the change" '[ "$(SE_EVENTS "$SEU" | grep -o "password_set:0")" = "password_set:0" ] && echo "$P" | grep -q "<td>Passwort über einen Link aus einer E-Mail festgelegt</td>" && echo "$P" | grep -q "<td>E-Mail-Adresse geändert (durch einen Admin)</td>" && echo "$P" | grep -q "<td>Admin-Rechte erhalten (durch einen Admin)</td>" && echo "$P" | grep -q "<td>Passwort geändert</td>" && [ "$(echo "$P" | grep -o "<td>[^<]*</td>" | grep -v nowrap | head -1)" = "<td>Passwort über einen Link aus einer E-Mail festgelegt</td>" ]'
check "… nobody else sees them: not on the admin's own profile, nowhere in the users list or the log" '! curl -s -b "$J" "$B/admin/profile" | grep -q "Admin-Rechte erhalten (durch einen Admin)" && ! curl -s -b "$J" "$B/admin/users" | grep -q "Passwort über einen Link" && ! curl -s -b "$J" "$B/admin/log" | grep -q "Passwort über einen Link"'

# Sign-in limit of several accounts within an hour: the holders get their notice, the admins one alarm a day
S_HM2() { $DC exec -T app php -r 'echo hash_hmac("sha256", "subject|" . $argv[1], str_repeat("d", 64));' "$1"; }
SE_IDS=""
for n in 1 2 3 4; do
  DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, is_active, created_at) VALUES ('limit$n-$SE@example.org', '$SEH', 'teacher', 'Limit $n', 'limit$n-$SE@example.org', 1, NOW())"
  id=$(DBQ "SELECT id FROM tp_users WHERE username = 'limit$n-$SE@example.org'"); SE_IDS="$SE_IDS $id"
  DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('login_user', '$(S_HM2 "login:#$id")', UNIX_TIMESTAMP(), 100000)"
done
DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('login', 'login_alarm', 'login_alarm_sent')"
SEJ3=$(mktemp)
for n in 1 2 3 4; do SE_LOGIN "$SEJ3" "limit$n-$SE@example.org" "irgendwas-falsch-$n" > /dev/null; done
SE_ALARM() { DBQ "SELECT COUNT(*) FROM tp_mail_outbox WHERE kind = 'login_alarm'"; }
SE_ADMINS=$(DBQ "SELECT COUNT(*) FROM tp_users WHERE role = 'admin' AND is_active = 1")
A1=$(SE_ALARM)
check "sign-in limit reached: an event for each account; more than 3 accounts within an hour: one alarm to each admin (queued)" '[ "$(DBQ "SELECT COUNT(*) FROM tp_security_events WHERE event = \"login_limit\" AND user_id IN ($(echo $SE_IDS | tr " " ","))")" = 4 ] && [ "$A1" = "$SE_ADMINS" ]'
$DC exec -T app php /srv/app/bin/cron.php > /dev/null
AT2=$($DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode("to:lehrer@example.org subject:\"viele fehlgeschlagene Anmeldungen\"")), true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $r["messages"][0]["ID"]), true)["Text"];')
check "… the alarm names the number, not the accounts, and goes out once a day" 'echo "$AT2" | grep -q "bei mehr als 3 Konten das Limit pro Konto erreicht" && ! echo "$AT2" | grep -q "limit[0-9]-$SE" && [ "$(SE_ALARM)" = 0 ] && DBQ "UPDATE tp_rate_limits SET hits = 0 WHERE bucket = \"login_notice\"" && SE_LOGIN "$SEJ3" "limit1-$SE@example.org" "nochmal-falsch-9" > /dev/null && [ "$(SE_ALARM)" = 0 ]'

# Retention: deleted after security_events_days (default 30), with the account
DBQ "UPDATE tp_security_events SET created_at = NOW() - INTERVAL 31 DAY WHERE user_id = $SEU AND event = 'password_changed'"
SC=$($DC exec -T app php /srv/app/bin/cron.php -v)
check "clean-up: events older than 30 days deleted, the others kept" 'echo "$SC" | grep -qE "security_events_deleted +[1-9]" && [ -z "$(SE_EVENTS "$SEU" | grep -o password_changed)" ] && [ -n "$(SE_EVENTS "$SEU")" ]'
DBQ "DELETE FROM tp_users WHERE id IN ($SEU$(echo $SE_IDS | sed 's/^/,/; s/ /,/g')); DELETE FROM tp_rate_limits WHERE bucket IN ('login', 'login_user', 'login_notice', 'login_alarm', 'login_alarm_sent')"
check "… and with the account" '[ "$(DBQ "SELECT COUNT(*) FROM tp_security_events WHERE user_id = $SEU")" = 0 ]'
rm -f "$SEJ" "$SEJ2" "$SEJ3"
