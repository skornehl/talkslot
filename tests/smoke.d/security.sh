echo "Sign-in protection and passwords"
# Expert review P0-4: usernames only in plain ASCII, the per-account login limit counted by account,
# known devices exempt from it, an e-mail to the owner instead of a silent lockout, password rules.
# Helpers: the key App\RateLimiter stores for a subject (dev secret), a used-up limit, Mailpit, a login.
S_HM() { php -r 'echo hash_hmac("sha256", "subject|" . $argv[1], str_repeat("d", 64));' "$1"; }
S_FULL() { DBQ "INSERT INTO tp_rate_limits (bucket, key_hash, window_start, hits) VALUES ('$1', '$2', UNIX_TIMESTAMP(), 100000) ON DUPLICATE KEY UPDATE hits = 100000, window_start = UNIX_TIMESTAMP()"; }
S_CLEAR() { DBQ "DELETE FROM tp_rate_limits WHERE bucket IN ('login', 'login_user', 'login_device', 'login_notice')"; }
S_MAILS() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo $r["messages_count"] ?? "?";' "$1"; }
S_MAILTEXT() { $DC exec -T app php -r '$r = json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode($argv[1])), true); echo json_decode(file_get_contents("http://mail:8025/api/v1/message/" . ($r["messages"][0]["ID"] ?? "")), true)["Text"] ?? "";' "$1"; }
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
