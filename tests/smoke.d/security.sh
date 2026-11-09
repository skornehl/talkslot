# Account security and security notices (see README.md in this directory)
echo "Security: permissions of config.php"
# Expert review P2-11: config.php holds the database and mail passwords and the secret. Admins see a
# warning on the dashboard while every user of the server can read it. The test setup itself needs a
# world-readable file (the web server runs as another user), so only that case is checked here; owner
# and group only (600, 640) are covered in tests/unit.d/security.php.
CFG="$ROOT/config/config.php"
CFG_KEEP=$(stat -c %a "$CFG")
chmod o+r "$CFG"
CFG_NOTE="Die Konfiguration ist für alle Benutzer des Servers lesbar: config/config.php ($(printf '%04o' "0$(stat -c %a "$CFG")"))."
check "config.php readable for every user: admins see a warning with its path and permissions" 'curl -s -b "$J" "$B/admin" | grep -qF "$CFG_NOTE"'
# Teachers cannot have that fixed: no warning for them
CFG_PW=Rechte-Lehrkraft-123
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, created_at) VALUES ('cfg-teacher@example.org', '$($DC exec -T app php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CFG_PW")', 'teacher', 'Herr Rechte', 'cfg-teacher@example.org', NOW())"
JC=$(mktemp)
curl -s -o /dev/null -c "$JC" -b "$JC" --data-urlencode "csrf=$(curl -s -c "$JC" -b "$JC" "$B/admin/login" | csrf)" --data-urlencode "username=cfg-teacher@example.org" --data-urlencode "password=$CFG_PW" "$B/admin/login"
check "teachers do not see it" 'CFG_D=$(curl -s -b "$JC" "$B/admin"); echo "$CFG_D" | grep -q "Meine Terminrunden" && ! echo "$CFG_D" | grep -qF "config/config.php"'
DBQ "DELETE FROM tp_users WHERE username = 'cfg-teacher@example.org'"
rm -f "$JC"
chmod "$CFG_KEEP" "$CFG"

# Account security (see README.md in this directory)
echo "Account security"
# Expert review P1-5: the password again for admin rights and e-mail addresses, security notices by
# e-mail, at least one active admin under a lock, no password links on the screen, ending all sessions,
# sign-ins only in coarse steps. Accounts of this file carry the run number (Mailpit keeps old mails).
AS=$(date +%s)
AS_COUNT() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo $r["messages_count"] ?? "?";' "$1"; }
AS_TEXT() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo isset($r["messages"][0]["ID"]) ? json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $r["messages"][0]["ID"]), true)["Text"] : "";' "$1"; }
AS_POST() { curl -s -L -b "$J" --data-urlencode "csrf=$T" "$@"; } # as the first admin, follows the redirect
AS_LOGIN() { # cookie jar, username, password
  curl -s -o /dev/null -c "$1" -b "$1" --data-urlencode "csrf=$(curl -s -c "$1" -b "$1" "$B/admin/login" | csrf)" --data-urlencode "username=$2" --data-urlencode "password=$3" "$B/admin/login"
}
AS_CODE() { curl -s -o /dev/null -w '%{http_code}' -b "$1" "$B/admin"; }
NOTE='subject:"Sicherheitshinweis"'
ADM=lehrer@example.org # address of the first admin
AID=$(DBQ "SELECT id FROM tp_users WHERE username = '$USER'")
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
N0=$(AS_COUNT "to:$ADM $NOTE")

# Inviting an admin: only with the own password; all admins get a notice, the new one the invitation
NEW="display_name=Frau+Sicher&email=as-admin-$AS@example.org&role=admin"
check "inviting an admin without the password: refused, nothing created" 'AS_POST -d "$NEW" "$B/admin/users/new" | has "Zur Bestätigung bitte Ihr Passwort eingeben" && [ -z "$(DBQ "SELECT id FROM tp_users WHERE username = \"as-admin-$AS@example.org\"")" ]'
check "inviting an admin with a wrong password: refused" 'AS_POST -d "$NEW&current_password=falsch-falsch-falsch" "$B/admin/users/new" | has "Zur Bestätigung bitte Ihr Passwort eingeben"'
AS_POST -d "$NEW" --data-urlencode "current_password=$PASS" "$B/admin/users/new" >/dev/null
UZ=$(DBQ "SELECT id FROM tp_users WHERE username = 'as-admin-$AS@example.org'")
check "admin invited with the password: notice to all admins, only the invitation to the new one" '[ -n "$UZ" ] && [ "$(AS_COUNT "to:$ADM $NOTE")" -gt "$N0" ] && AS_TEXT "to:$ADM $NOTE" | has "„Frau Sicher“ (as-admin-$AS@example.org) wurde als Admin eingeladen." && AS_TEXT "to:$ADM $NOTE" | has "Durch: Frau Sabrina Test" && [ "$(AS_COUNT "to:as-admin-$AS@example.org $NOTE")" = 0 ] && [ "$(AS_COUNT "to:as-admin-$AS@example.org subject:Einladung")" = 1 ]'
ZLINK=$(AS_TEXT "to:as-admin-$AS@example.org subject:Einladung" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
JZ=$(mktemp)
curl -s -o /dev/null -c "$JZ" -b "$JZ" --data-urlencode "csrf=$(curl -s -c "$JZ" -b "$JZ" "$ZLINK" | csrf)" -d "new_password=Sicher-Passwort-123&new_password2=Sicher-Passwort-123" "$ZLINK"
check "first password set through the invitation: e-mail to the account holder (set, not changed)" '[ "$(AS_COUNT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde festgelegt\"")" = 1 ] && M=$(AS_TEXT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde festgelegt\"") && echo "$M" | has "für Ihr Konto „as-admin-$AS@example.org“ bei" && echo "$M" | has "wurde soeben das Passwort festgelegt." && ! echo "$M" | has "Andere Anmeldungen"'
AS_LOGIN "$JZ" "as-admin-$AS@example.org" Sicher-Passwort-123
TZ=$(curl -s -b "$JZ" "$B/admin/profile" | csrf)
curl -s -o /dev/null -c "$JZ" -b "$JZ" --data-urlencode "csrf=$TZ" -d "current_password=Sicher-Passwort-123&new_password=Sicher-Passwort-456&new_password2=Sicher-Passwort-456" "$B/admin/password"
check "password changed in the profile: e-mail to the account holder" '[ "$(AS_COUNT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"")" = 1 ] && AS_TEXT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"" | has "Andere Anmeldungen des Kontos wurden dabei beendet." && [ "$(AS_CODE "$JZ")" = 200 ]'
# Without an address (profile, own e-mail removed): changing the password still works, nothing is sent
PZ() { curl -s -L -b "$JZ" --data-urlencode "csrf=$TZ" -d "display_name=Frau+Sicher&summary_evening=1&summary_deadline=1&current_password=Sicher-Passwort-456" "$@" "$B/admin/profile"; }
check "own e-mail address removed: notice to the previous address (no new address to send links to)" 'PZ -d "email=" | has "Profil gespeichert" && M=$(AS_TEXT "to:as-admin-$AS@example.org subject:\"E-Mail-Adresse wurde geändert\"") && echo "$M" | has "(keine Adresse mehr hinterlegt)" && ! echo "$M" | has "an die neue Adresse"'
curl -s -o /dev/null -c "$JZ" -b "$JZ" --data-urlencode "csrf=$TZ" -d "current_password=Sicher-Passwort-456&new_password=Sicher-Passwort-123&new_password2=Sicher-Passwort-123" "$B/admin/password"
check "password changed without an address: no mail, still changed" '[ "$(AS_COUNT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"")" = 1 ] && PZ -d "email=as-admin-$AS@example.org" | has "Zum Ändern der E-Mail-Adresse bitte"'
curl -s -o /dev/null -b "$JZ" --data-urlencode "csrf=$TZ" --data-urlencode "display_name=Frau Sicher" -d "summary_evening=1&summary_deadline=1&email=as-admin-$AS@example.org&current_password=Sicher-Passwort-123" "$B/admin/profile"

# A teacher: name, activation and username without the password; role and e-mail address only with it
AS_POST -d "display_name=Herr+Sicher&email=as-lehrer-$AS@example.org&role=teacher" "$B/admin/users/new" >/dev/null
UT=$(DBQ "SELECT id FROM tp_users WHERE username = 'as-lehrer-$AS@example.org'")
TE="display_name=Herr+Sicher&role=teacher&lang=de"
check "teacher: name and deactivation without the password" 'AS_POST -d "$TE&username=as-lehrer-$AS@example.org&email=as-lehrer-$AS@example.org" "$B/admin/users/$UT/edit" | has "Benutzer gespeichert." && [ "$(DBQ "SELECT is_active FROM tp_users WHERE id = $UT")" = 0 ]'
AS_POST -d "$TE&is_active=1&username=as-lehrer-$AS&email=as-lehrer-$AS@example.org" "$B/admin/users/$UT/edit" >/dev/null
check "username changed by an admin: notice to the account" '[ "$(DBQ "SELECT username FROM tp_users WHERE id = $UT")" = "as-lehrer-$AS" ] && AS_TEXT "to:as-lehrer-$AS@example.org subject:\"Benutzername wurde geändert\"" | has "– Benutzername jetzt: as-lehrer-$AS"'
check "e-mail address of another account without the password: refused" 'AS_POST -d "$TE&is_active=1&username=as-lehrer-$AS&email=as-neu-$AS@example.org" "$B/admin/users/$UT/edit" | has "Zur Bestätigung bitte Ihr Passwort eingeben" && [ "$(DBQ "SELECT email FROM tp_users WHERE id = $UT")" = "as-lehrer-$AS@example.org" ]'
AS_POST -d "$TE&is_active=1&username=as-lehrer-$AS&email=as-neu-$AS@example.org" --data-urlencode "current_password=$PASS" "$B/admin/users/$UT/edit" >/dev/null
check "e-mail address changed with the password: notice to the previous address" '[ "$(DBQ "SELECT email FROM tp_users WHERE id = $UT")" = "as-neu-$AS@example.org" ] && M=$(AS_TEXT "to:as-lehrer-$AS@example.org subject:\"E-Mail-Adresse wurde geändert\"") && echo "$M" | has "hat Frau Sabrina Test (Admin) Folgendes geändert:" && echo "$M" | has "– E-Mail-Adresse jetzt: as-neu-$AS@example.org" && echo "$M" | has "gehen ab jetzt an die neue Adresse"'
check "own e-mail address in the account form: only with the password (as in the profile)" 'AS_POST -d "display_name=Frau+Sabrina+Test&username=$USER&email=as-anders-$AS@example.org&lang=de" "$B/admin/users/$AID/edit" | has "Zur Bestätigung bitte Ihr Passwort eingeben" && [ "$(DBQ "SELECT email FROM tp_users WHERE id = $AID")" = "$ADM" ]'
AS_POST -d "display_name=Frau+Sabrina+Test&username=$USER-$AS&email=$ADM&lang=de" "$B/admin/users/$AID/edit" >/dev/null
check "own username changed in the account form (no password needed): notice to the own address" '[ "$(DBQ "SELECT username FROM tp_users WHERE id = $AID")" = "$USER-$AS" ] && M=$(AS_TEXT "to:$ADM subject:\"Benutzername wurde geändert\"") && echo "$M" | has "wurde Folgendes geändert:" && echo "$M" | has "– Benutzername jetzt: $USER-$AS" && ! echo "$M" | has "(Admin)"'
AS_POST -d "display_name=Frau+Sabrina+Test&username=$USER&email=$ADM&lang=de" "$B/admin/users/$AID/edit" >/dev/null
AS_POST -d "$TE&is_active=1&username=as-lehrer-$AS&email=as-neu-$AS@example.org&role=admin" --data-urlencode "current_password=$PASS" "$B/admin/users/$UT/edit" >/dev/null
check "teacher made an admin (with the password): notice to all admins and to the account" '[ "$(DBQ "SELECT role FROM tp_users WHERE id = $UT")" = admin ] && AS_TEXT "to:$ADM $NOTE" | has "„Herr Sicher“ (as-lehrer-$AS) hat Admin-Rechte erhalten." && AS_TEXT "to:as-admin-$AS@example.org $NOTE" | has "hat Admin-Rechte erhalten" && AS_TEXT "to:as-neu-$AS@example.org $NOTE" | has "hat Admin-Rechte erhalten"'
AS_POST -d "$TE&is_active=1&username=as-lehrer-$AS&email=as-neu-$AS@example.org" --data-urlencode "current_password=$PASS" "$B/admin/users/$UT/edit" >/dev/null
check "admin rights taken away: notice, also to the account" '[ "$(DBQ "SELECT role FROM tp_users WHERE id = $UT")" = teacher ] && AS_TEXT "to:as-neu-$AS@example.org $NOTE" | has "hat keine Admin-Rechte mehr."'
# A reset by an admin says that the previous password no longer works
AS_POST "$B/admin/users/$UZ/reset-password" >/dev/null
check "password reset by an admin: the mail says so, the link is not shown" 'M=$(AS_TEXT "to:as-admin-$AS@example.org subject:\"Passwort festlegen\"") && echo "$M" | has "hat Frau Sabrina Test (Admin) das Passwort zurückgesetzt. Ihr bisheriges Passwort gilt nicht mehr." && echo "$M" | has "War das nicht abgesprochen" && [ "$(AS_CODE "$JZ")" = 303 ]'
ZLINK=$(AS_TEXT "to:as-admin-$AS@example.org subject:\"Passwort festlegen\"" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
curl -s -o /dev/null -c "$JZ" -b "$JZ" --data-urlencode "csrf=$(curl -s -c "$JZ" -b "$JZ" "$ZLINK" | csrf)" -d "new_password=Sicher-Passwort-123&new_password2=Sicher-Passwort-123" "$ZLINK"
AS_LOGIN "$JZ" "as-admin-$AS@example.org" Sicher-Passwort-123
# If the mail cannot be sent, the admin does not see the link either (it would open the account to them).
# Sending fails here because the sender address is missing in the configuration of this one request.
# (The code comes on standard input, not with -r, so that coverage measurement includes it.)
LF=$($DC exec -T app php -- /srv/app "$AID" "$UT" 2>&1 <<'PHP'
<?php
[, $root, $admin, $teacher] = $argv;
require $root . '/app/bootstrap.php';
$c = require $root . '/config/config.php';
$c['mail']['from_address'] = '';
App\Config::load($c);
session_save_path(sys_get_temp_dir());
session_start();
$u = App\Db::one('SELECT * FROM users WHERE id = ?', [(int) $admin]);
$_SESSION = ['uid' => (int) $u['id'], 'epoch' => (int) $u['session_epoch'], 'global_epoch' => (int) App\Settings::get('global_session_epoch', '0'),
    'started' => time(), 'last' => time(), 'csrf' => 't'];
$_POST = ['csrf' => 't'];
register_shutdown_function(static function (): void {
    echo json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    session_destroy();
});
(new App\Controller\AdminController())->dispatch('/users/' . (int) $teacher . '/reset-password', 'POST');
PHP
)
check "mail not sent: a hint, but no password link on the screen" 'echo "$LF" | has "konnte nicht an as-neu-$AS@example.org gesendet werden. Bitte prüfen Sie den Mailversand" && ! echo "$LF" | grep -q "/admin/invite/"'

# Two admins demote (or delete) each other at the same moment: the rows of the active admins are locked,
# the second change waits for the first and is refused, at least one admin remains. The "other admin"
# here is a transaction that demotes the first admin and holds the lock for 3 seconds.
AZ="display_name=Frau+Sicher&username=as-admin-$AS@example.org&email=as-admin-$AS@example.org&lang=de"
( DBQ "START TRANSACTION; UPDATE tp_users SET role = 'teacher' WHERE id = $AID; SELECT SLEEP(3); COMMIT" >/dev/null ) &
sleep 1
R=$(AS_POST -d "$AZ&role=teacher&is_active=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/edit")
wait
DBQ "UPDATE tp_users SET role = 'admin' WHERE id = $AID"
check "demoting each other at the same moment: the second one waits and is refused" 'echo "$R" | has "Es muss mindestens ein aktives Admin-Konto geben." && [ "$(DBQ "SELECT role FROM tp_users WHERE id = $UZ")" = admin ]'
# (the redirect is followed only after the first admin is active again: otherwise its session would end)
( DBQ "START TRANSACTION; UPDATE tp_users SET is_active = 0 WHERE id = $AID; SELECT SLEEP(3); COMMIT" >/dev/null ) &
sleep 1
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "confirm=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/delete"
wait
DBQ "UPDATE tp_users SET is_active = 1 WHERE id = $AID"
R=$(curl -s -b "$J" "$B/admin/users")
check "deleting each other at the same moment: the second one waits and is refused" 'echo "$R" | has "Es muss mindestens ein aktives Admin-Konto geben." && [ -n "$(DBQ "SELECT id FROM tp_users WHERE id = $UZ")" ]'

# Ending all sessions: only with the password; every other session ends, the own one stays
JT=$(mktemp); AS_LOGIN "$JT" "$USER" "$PASS"
check "all sessions: without the password refused" 'AS_POST -d "current_password=" "$B/admin/users/end-sessions" | has "Zum Beenden aller Sitzungen bitte Ihr Passwort eingeben." && [ "$(AS_CODE "$JZ")" = 200 ]'
R=$(curl -s -L -c "$J" -b "$J" --data-urlencode "csrf=$T" --data-urlencode "current_password=$PASS" "$B/admin/users/end-sessions")
check "all sessions ended: other admin and other device logged out, the own session stays" 'echo "$R" | has "Alle Sitzungen beendet:" && [ "$(AS_CODE "$JZ")" = 303 ] && [ "$(AS_CODE "$JT")" = 303 ] && [ "$(AS_CODE "$J")" = 200 ]'
check "all sessions ended: notice to all admins, entry in the log" 'AS_TEXT "to:as-admin-$AS@example.org $NOTE" | has "Alle Sitzungen wurden beendet" && curl -s -b "$J" "$B/admin/log" | has "Alle Sitzungen beendet"'
AS_LOGIN "$JZ" "as-admin-$AS@example.org" Sicher-Passwort-123
check "signing in again after that works" '[ "$(AS_CODE "$JZ")" = 200 ]'
rm -f "$JT"
T=$(curl -s -b "$J" "$B/admin/users" | csrf)

# Deactivating, reactivating and deleting an admin: only with the password, notices to all admins
check "admin deactivated only with the password" 'AS_POST -d "$AZ&role=admin" "$B/admin/users/$UZ/edit" | has "Zur Bestätigung bitte" && [ "$(DBQ "SELECT is_active FROM tp_users WHERE id = $UZ")" = 1 ]'
AS_POST -d "$AZ&role=admin" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/edit" >/dev/null
check "admin deactivated: notice, session of the account ended" 'AS_TEXT "to:$ADM $NOTE" | has "Das Admin-Konto „Frau Sicher“ (as-admin-$AS@example.org) wurde deaktiviert." && AS_TEXT "to:as-admin-$AS@example.org $NOTE" | has "wurde deaktiviert" && [ "$(AS_CODE "$JZ")" = 303 ]'
# The reactivated account is an admin and the account concerned: one mail, in its language (English here)
DBQ "UPDATE tp_users SET lang = 'en' WHERE id = $UZ"
C1=$(AS_COUNT "to:as-admin-$AS@example.org")
AS_POST -d "$AZ&role=admin&is_active=1&lang=en" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/edit" >/dev/null
check "admin reactivated: notice to all admins, to the account once and in its language" 'AS_TEXT "to:$ADM $NOTE" | has "wurde wieder aktiviert." && [ "$(AS_COUNT "to:as-admin-$AS@example.org")" = $((C1 + 1)) ] && AS_TEXT "to:as-admin-$AS@example.org subject:\"Security notice\"" | has "The admin account “Frau Sicher” (as-admin-$AS@example.org) has been reactivated."'
check "deleting an admin without the password: refused" 'AS_POST -d "confirm=1" "$B/admin/users/$UZ/delete" | has "Zum Löschen eines Admin-Kontos bitte Ihr Passwort eingeben." && [ -n "$(DBQ "SELECT id FROM tp_users WHERE id = $UZ")" ] && curl -s -b "$J" "$B/admin/users/$UZ/edit" | has "id=\"delete_password\""'
AS_POST -d "confirm=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$UZ/delete" >/dev/null
check "admin deleted with the password: notice to all admins and to the deleted account" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE id = $UZ")" ] && AS_TEXT "to:$ADM $NOTE" | has "Das Admin-Konto „Frau Sicher“ (as-admin-$AS@example.org) wurde gelöscht." && AS_TEXT "to:as-admin-$AS@example.org subject:\"Security notice\"" | has "has been deleted."'

# Wrong passwords are limited per account (5 per 15 minutes), then even the right one is refused
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'reauth'"
for i in 1 2 3 4 5; do AS_POST -d "current_password=falsch-$i" "$B/admin/users/end-sessions" >/dev/null; done
check "confirming the password: after 5 wrong ones even the right one is refused for a while" 'AS_POST --data-urlencode "current_password=$PASS" "$B/admin/users/end-sessions" | has "Zu viele falsche Passwörter. Bitte versuchen Sie es in 15 Minuten erneut oder melden Sie sich ab und wieder an."'
# Changing the own password counts in the same counter: a taken-over session cannot guess the password there
# without a limit and then use it where it is asked for. The counter belongs to the sign-in, so it cannot
# lock the account holder out either: another session of the same account has its own. (The new password
# is too short on purpose: with the right current one, only that message comes and nothing changes.)
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'reauth'"
JP=$(mktemp); AS_LOGIN "$JP" "$USER" "$PASS"
TP=$(curl -s -b "$JP" "$B/admin/profile" | csrf)
AS_PW() { curl -s -L -b "$1" --data-urlencode "csrf=$2" --data-urlencode "current_password=$3" -d "new_password=zu-kurz&new_password2=zu-kurz" "$B/admin/password"; }
check "changing the password with a wrong current one: refused" 'AS_PW "$JP" "$TP" falsch-1 | has "Das aktuelle Passwort ist falsch."'
for i in 2 3 4 5; do AS_PW "$JP" "$TP" "falsch-$i" >/dev/null; done
check "changing the password: after 5 wrong current ones even the right one is refused" 'AS_PW "$JP" "$TP" "$PASS" | has "Zu viele falsche Passwörter." && ! AS_PW "$JP" "$TP" "$PASS" | has "mindestens 12 Zeichen"'
check "... and confirming a change in the same session as well (one counter)" 'curl -s -L -b "$JP" --data-urlencode "csrf=$TP" --data-urlencode "current_password=$PASS" "$B/admin/users/end-sessions" | has "Zu viele falsche Passwörter." && [ "$(AS_CODE "$J")" = 200 ]'
check "another sign-in of the account is not locked out: its own counter" 'AS_PW "$J" "$T" "$PASS" | has "Das Passwort muss mindestens 12 Zeichen lang sein."'
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'reauth'"
for i in 1 2 3 4 5; do AS_POST -d "current_password=falsch-$i" "$B/admin/users/end-sessions" >/dev/null; done
check "wrong passwords when confirming count for changing the password too" 'AS_PW "$J" "$T" "$PASS" | has "Zu viele falsche Passwörter." && AS_PW "$JP" "$TP" "$PASS" | has "mindestens 12 Zeichen"'
rm -f "$JP"
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'reauth'"

# Sign-ins only in coarse steps; the overview points to accounts unused for over 12 months
DBQ "UPDATE tp_users SET last_login_at = NOW() - INTERVAL 2 YEAR, created_at = NOW() - INTERVAL 3 YEAR WHERE id = $UT"
UL=$(curl -s -b "$J" "$B/admin/users")
check "users list: never / within / more than 12 months, no times" 'echo "$UL" | has "<td class=\"small\"><strong>vor über 12 Monaten</strong></td>" && echo "$UL" | has "<td class=\"small\">in den letzten 12 Monaten</td>" && ! echo "$UL" | sed "s/<[^>]*>//g" | grep -qE "[0-9]{2}\.[0-9]{2}\.[0-9]{4} [0-9]{2}:[0-9]{2}"'
OLD=$(DBQ "SELECT COUNT(*) FROM tp_users WHERE is_active = 1 AND COALESCE(last_login_at, created_at) < NOW() - INTERVAL 12 MONTH")
check "overview: number of accounts unused for over 12 months ($OLD)" 'curl -s -b "$J" "$B/admin" | has "Konten, die seit über 12 Monaten nicht genutzt wurden: $OLD."'
AS_POST -d "confirm=1" "$B/admin/users/$UT/delete" >/dev/null
check "cleaned up: the accounts of this test are gone, one admin" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE username LIKE \"as-%-$AS%\"")" ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_users WHERE role = \"admin\" AND is_active = 1")" = 1 ]'
rm -f "$JZ"
T=$(curl -s -b "$J" "$B/admin" | csrf)

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
    $path = substr($r[1], 2, str_ends_with($r[1], "\\z#") ? -3 : -2); // without #^ and $# or \z#
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

echo "Sign-in protection and passwords"
# Expert review P0-4: usernames only in plain ASCII, the per-account login limit counted by account,
# known devices exempt from it, an e-mail to the owner instead of a silent lockout, password rules.
# Helpers: the key App\RateLimiter stores for a subject (dev secret), a used-up limit, Mailpit, a login.
S_HM() { php -r 'echo hash_hmac("sha256", "subject|" . $argv[1], str_repeat("d", 64));' "$1"; }
S_FULL() { DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('$1', '$2', UNIX_TIMESTAMP(), 100000) ON DUPLICATE KEY UPDATE hits = 100000, window_start = UNIX_TIMESTAMP()"; }
S_CLEAR() { DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('login', 'login_user', 'login_device', 'login_notice')"; }
S_MAILS() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo $r["messages_count"] ?? "?";' "$1"; }
S_MAILTEXT() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo isset($r["messages"][0]["ID"]) ? json_decode(file_get_contents("http://mail:8025/api/v1/message/" . $r["messages"][0]["ID"]), true)["Text"] : "";' "$1"; }
S_LOGIN() { # cookie jar, username, password → HTTP status
  local t; t=$(curl -s -c "$1" -b "$1" "$B/admin/login" | csrf)
  curl -s -o /dev/null -w '%{http_code}' -c "$1" -b "$1" --data-urlencode "csrf=$t" --data-urlencode "username=$2" --data-urlencode "password=$3" "$B/admin/login"
}
S_DEVICE() { grep 'tp_device_' "$1" > "$2"; } # copy only the known-device cookie into a fresh jar (no session)
S_NONCE() { awk -F'\t' '/tp_device_/ { split($7, v, "."); print v[1] }' "$1"; }
AID=$(DBQ "SELECT id FROM tp_users WHERE username = '$USER'")
S_CLEAR

# DBA-1: the collation finds "sabrina" for "SÁBRİNA" or "sabri<zero-width space>na", which used to sign in
# with the right password while every spelling had its own per-account counter
SJ1=$(mktemp)
C1=$(S_LOGIN "$SJ1" "SÁBRİNA" "$PASS"); C2=$(S_LOGIN "$SJ1" $'sabri\xe2\x80\x8bna' "$PASS")
check "username with accents or a zero-width space: no sign-in even with the right password" '[ "$C1 $C2" = "401 401" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" -b "$SJ1" "$B/admin")" = 303 ]'
check "… the attempts count for the IP address, not for an account" '[ "$(DBQ "SELECT COALESCE(SUM(hits), 0) FROM tp_rate_limits WHERE bucket = \"login\"")" = 2 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rate_limits WHERE bucket = \"login_user\"")" = 0 ]'
S_CLEAR
S_LOGIN "$SJ1" "SABRINA" "falsch-falsch-1" >/dev/null; S_LOGIN "$SJ1" "Niemand-$RUN@Example.org" "falsch-falsch-2" >/dev/null
check "per-account limit counted by account ID (any spelling), unknown names in lower case" '[ "$(DBQ "SELECT hits FROM tp_rate_limits WHERE bucket = \"login_user\" AND key_hash = \"$(S_HM "login:#$AID")\"")" = 1 ] && [ "$(DBQ "SELECT hits FROM tp_rate_limits WHERE bucket = \"login_user\" AND key_hash = \"$(S_HM "login:niemand-$RUN@example.org")\"")" = 1 ]'

# SEC-2: strangers who use up the per-account limit cannot lock the owner out of known devices
S_CLEAR
SJ2=$(mktemp)
SH=$(curl -s -D - -o /dev/null -c "$SJ2" -b "$SJ2" --data-urlencode "csrf=$(curl -s -c "$SJ2" -b "$SJ2" "$B/admin/login" | csrf)" --data-urlencode "username=$USER" --data-urlencode "password=$PASS" "$B/admin/login")
check "sign-in sets a known-device cookie: only for /admin/login, HttpOnly, SameSite=Strict, for a year" 'echo "$SH" | grep -i "^set-cookie: tp_device_[0-9a-f]\{16\}=" | grep -i "max-age=3153[0-9]\{4\};" | grep -i "path=/admin/login;" | grep -i "httponly" | grep -qi "samesite=strict"'
S_FULL login_user "$(S_HM "login:#$AID")"
SJ3=$(mktemp); S_DEVICE "$SJ2" "$SJ3"; SJ4=$(mktemp)
C1=$(S_LOGIN "$SJ4" "$USER" "$PASS"); C2=$(S_LOGIN "$SJ3" "$USER" "$PASS")
check "account limit used up: unknown device refused, known device signs in" '[ "$C1 $C2" = "401 303" ]'
SJ5=$(mktemp); S_DEVICE "$SJ3" "$SJ5"
check "… and gets the cookie renewed (new random number)" '[ -n "$(S_NONCE "$SJ5")" ] && [ "$(S_NONCE "$SJ5")" != "$(S_NONCE "$SJ2")" ]'
awk -F'\t' 'BEGIN { OFS = "\t" } /tp_device_/ { split($7, v, "."); $7 = v[1] "." v[2] "." sprintf("%064d", 0) } { print }' "$SJ5" > "$SJ4"
check "forged known-device cookie does not help" '[ "$(S_LOGIN "$SJ4" "$USER" "$PASS")" = 401 ]'
S_FULL login_device "$(S_HM "device:$(S_NONCE "$SJ5")")"
check "a known device with too many failed attempts of its own (stolen cookie) is refused as well" '[ "$(S_LOGIN "$SJ5" "$USER" "$PASS")" = 401 ]'

# New teacher account: password rules on the invitation page, the link makes the browser a known
# device, an e-mail to the owner when the account limit is used up
S_CLEAR
T=$(curl -s -b "$J" "$B/admin/users" | csrf)
SU="geraet-$RUN@example.org"
curl -s -o /dev/null -b "$J" -d "csrf=$T&display_name=Frau+Geraet&email=$SU&role=teacher" "$B/admin/users/new"
SUID=$(DBQ "SELECT id FROM tp_users WHERE username = '$SU'")
SLINK=$(S_MAILTEXT "to:$SU" | grep -o 'http[^ ]*/admin/invite/[A-Za-z0-9_-]\{43\}' | head -1)
SJ6=$(mktemp); ST=$(curl -s -c "$SJ6" -b "$SJ6" "$SLINK" | csrf)
S_SET() { curl -s -c "$SJ6" -b "$SJ6" --data-urlencode "csrf=$ST" --data-urlencode "new_password=$1" --data-urlencode "new_password2=$1" "$SLINK"; }
check "password rules on the invitation page: no username or e-mail address in it, nothing common, no pattern" '[ -n "$SLINK" ] && S_SET "Geraet-$RUN-Kaffee" | has "weder den Benutzernamen" && S_SET "Sonnenschein2024" | has "zu leicht zu erraten" && S_SET "1234-5678-9012" | has "zu leicht zu erraten"'
SH=$(curl -s -D - -o /dev/null -c "$SJ6" -b "$SJ6" --data-urlencode "csrf=$ST" -d "new_password=Gartenzaun-Wolke-Tisch-5&new_password2=Gartenzaun-Wolke-Tisch-5" "$SLINK")
check "setting the password through the e-mailed link makes this browser a known device" 'echo "$SH" | grep -qi "^location: .*/admin/login" && echo "$SH" | grep -i "^set-cookie: tp_device_[0-9a-f]\{16\}=" | grep -qi "path=/admin/login;"'
S_FULL login_user "$(S_HM "login:#$SUID")"
N0=$(S_MAILS "to:$SU")
SJ7=$(mktemp)
C1=$(S_LOGIN "$SJ7" "$SU" "Gartenzaun-Wolke-Tisch-5"); C2=$(S_LOGIN "$SJ7" "$SU" "Gartenzaun-Wolke-Tisch-5")
check "account limit used up: the owner gets one e-mail (not one per attempt)" '[ "$C1 $C2" = "401 401" ] && [ "$(S_MAILS "to:$SU")" = "$((N0 + 1))" ]'
SM=$(S_MAILTEXT "to:$SU subject:Anmeldeversuche")
check "… which explains the lockout and the way out" 'echo "$SM" | has "Hallo Frau Geraet," && echo "$SM" | has "(mehr als 20)" && echo "$SM" | has "bis zu 60 Minuten" && echo "$SM" | has "„Passwort vergessen?“"'
SJ8=$(mktemp); S_DEVICE "$SJ6" "$SJ8"
check "… and the browser that set the password through the link signs in anyway" '[ "$(S_LOGIN "$SJ8" "$SU" "Gartenzaun-Wolke-Tisch-5")" = 303 ]'

# Changing the own password follows the same rules
T=$(curl -s -b "$J" "$B/admin" | csrf)
check "own password change: username in the new password rejected" 'PW "$PASS" "Sabrina-Kaffee-Wolke-9" "Sabrina-Kaffee-Wolke-9" | has "weder den Benutzernamen"'

T=$(curl -s -b "$J" "$B/admin/users" | csrf)
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/users/$SUID/delete"
check "cleaned up: test account deleted" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE username = \"$SU\"")" ]'
S_CLEAR
rm -f "$SJ1" "$SJ2" "$SJ3" "$SJ4" "$SJ5" "$SJ6" "$SJ7" "$SJ8"
