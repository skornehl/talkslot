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
check "password set through the link: e-mail to the account holder" '[ "$(AS_COUNT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"")" = 1 ] && AS_TEXT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"" | has "für Ihr Konto „as-admin-$AS@example.org“"'
AS_LOGIN "$JZ" "as-admin-$AS@example.org" Sicher-Passwort-123
TZ=$(curl -s -b "$JZ" "$B/admin/profile" | csrf)
curl -s -o /dev/null -c "$JZ" -b "$JZ" --data-urlencode "csrf=$TZ" -d "current_password=Sicher-Passwort-123&new_password=Sicher-Passwort-456&new_password2=Sicher-Passwort-456" "$B/admin/password"
check "password changed in the profile: e-mail to the account holder" '[ "$(AS_COUNT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"")" = 2 ] && [ "$(AS_CODE "$JZ")" = 200 ]'
# Without an address (profile, own e-mail removed): changing the password still works, nothing is sent
PZ() { curl -s -L -b "$JZ" --data-urlencode "csrf=$TZ" -d "display_name=Frau+Sicher&summary_evening=1&summary_deadline=1&current_password=Sicher-Passwort-456" "$@" "$B/admin/profile"; }
check "own e-mail address removed: notice to the previous address" 'PZ -d "email=" | has "Profil gespeichert" && AS_TEXT "to:as-admin-$AS@example.org subject:\"E-Mail-Adresse wurde geändert\"" | has "(keine Adresse mehr hinterlegt)"'
curl -s -o /dev/null -c "$JZ" -b "$JZ" --data-urlencode "csrf=$TZ" -d "current_password=Sicher-Passwort-456&new_password=Sicher-Passwort-123&new_password2=Sicher-Passwort-123" "$B/admin/password"
check "password changed without an address: no mail, still changed" '[ "$(AS_COUNT "to:as-admin-$AS@example.org subject:\"Ihr Passwort wurde geändert\"")" = 2 ] && PZ -d "email=as-admin-$AS@example.org" | has "Zum Ändern der E-Mail-Adresse bitte"'
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
check "confirming the password: after 5 wrong ones even the right one is refused for a while" 'AS_POST --data-urlencode "current_password=$PASS" "$B/admin/users/end-sessions" | has "Zu viele falsche Passwörter. Bitte versuchen Sie es in 15 Minuten erneut."'
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
