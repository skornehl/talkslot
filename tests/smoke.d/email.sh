# E-mail addresses with umlauts in the domain (expert review P3-3), see App\Email
echo "E-mail addresses with umlauts in the domain"
em_mails() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode("to:" . $argv[1])), true)["messages_count"] ?? "?";' "$1"; }
EMR=$(mkround Umlaut-Domain none); EMS=$(slugof "$EMR")
EMN=$(date +%s%N | tail -c 8) # Mailpit keeps the mails of earlier runs: addresses of this run only
EMB=$(book "$EMS" "Familie Umlaut" " Eltern-$EMN@Müller-Test.de ")
EMT=$(echo "$EMB" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | head -1 | sed 's#/b/##')
check "booking with umlauts in the domain: accepted, stored and mailed in the ASCII form (punycode)" \
  '[[ "$EMB" == 303* ]] && [ "$(DBQ "SELECT email FROM tp_bookings WHERE name = \"Familie Umlaut\"")" = "Eltern-$EMN@xn--mller-test-9db.de" ] && [ "$(em_mails "Eltern-$EMN@xn--mller-test-9db.de")" = 1 ]'
EMP=$(curl -s "$B/$EMS")
EMX=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(echo "$EMP" | csrf)" --data-urlencode "name=Familie Jürgen" --data-urlencode "email=jürgen@example.org" \
  -d "slot_id=$(echo "$EMP" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')" "$B/$EMS")
check "umlauts before the @: refused (422), the form shows the address as typed, nothing stored" \
  '[ "$(echo "$EMX" | tail -1)" = 422 ] && echo "$EMX" | has "value=\"jürgen@example.org\"" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name = \"Familie Jürgen\"")" = 0 ]'
EMM=$(curl -s "$B/b/$EMT")
check "confirmation to another address with umlauts in the domain (appointment page): sent as punycode" \
  '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$(echo "$EMM" | csrf)" --data-urlencode "email=Oma-$EMN@Bücher-Test.example" -d "action=mail" "$B/b/$EMT")" = 303 ] && [ "$(em_mails "Oma-$EMN@xn--bcher-test-9db.example")" = 1 ]'
# Entered by the teacher: stored as punycode as well
EMSL=$(DBQ "SELECT s.id FROM tp_slots s JOIN tp_round_days d ON d.id = s.day_id LEFT JOIN tp_bookings b ON b.slot_id = s.id WHERE d.round_id = $EMR AND b.id IS NULL ORDER BY s.id LIMIT 1")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "name=Familie Eintrag" --data-urlencode "email=Eintrag@Müller-Test.de" -d "lang=de" "$B/admin/slot/$EMSL/book"
check "teacher's entry with umlauts in the domain: stored as punycode" '[ "$(DBQ "SELECT email FROM tp_bookings WHERE name = \"Familie Eintrag\"")" = "Eintrag@xn--mller-test-9db.de" ]'
# Accounts: address and username as punycode; signing in and "forgot password" also take the address with umlauts
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Frau Umlaut" --data-urlencode "email=Lehrerin@Grundschule-Münster.de" -d "role=teacher" "$B/admin/users/new"
EMUID=$(DBQ "SELECT id FROM tp_users WHERE username = 'lehrerin@xn--grundschule-mnster-y6b.de' AND email = 'lehrerin@xn--grundschule-mnster-y6b.de'")
check "user form: address with umlauts in the domain, address and username stored as punycode" '[ -n "$EMUID" ]'
EMH=$($DC exec -T app php -r 'echo password_hash("Umlaut-Domain-Passwort-7", PASSWORD_DEFAULT);')
DBQ "UPDATE tp_users SET password_hash = '$EMH', access_token_hash = NULL, access_token_expires_at = NULL WHERE id = ${EMUID:-0}"
EMJ=$(mktemp); EMT2=$(curl -s -c "$EMJ" -b "$EMJ" "$B/admin/login" | csrf)
check "sign in with the address with umlauts (the username is its punycode form)" \
  '[ "$(curl -s -o /dev/null -w "%{http_code}" -c "$EMJ" -b "$EMJ" --data-urlencode "csrf=$EMT2" --data-urlencode "username=Lehrerin@Grundschule-Münster.de" --data-urlencode "password=Umlaut-Domain-Passwort-7" "$B/admin/login")" = 303 ]'
rm -f "$EMJ"; EMJ=$(mktemp); EMT3=$(curl -s -c "$EMJ" -b "$EMJ" "$B/admin/forgot" | csrf)
curl -s -o /dev/null -c "$EMJ" -b "$EMJ" --data-urlencode "csrf=$EMT3" --data-urlencode "email=lehrerin@grundschule-münster.de" "$B/admin/forgot"
check "forgot password with the address with umlauts: the link goes to its punycode form" '[ "$(em_mails lehrerin@xn--grundschule-mnster-y6b.de)" -ge 1 ] && [ -n "$(DBQ "SELECT access_token_hash FROM tp_users WHERE id = ${EMUID:-0}")" ]'
# Text outside ASCII reaches the query only here: compared with the ASCII column username it would be an error (1267)
DBQ "DELETE FROM tp_rate_limits WHERE bucket = 'forgot'"
EM_ASCII=$(curl -s -o /dev/null -w '%{http_code}' -b "$EMJ" -c "$EMJ" --data-urlencode "csrf=$(curl -s -c "$EMJ" -b "$EMJ" "$B/admin/forgot" | csrf)" -d "email=niemand-ascii@example.org" "$B/admin/forgot")
EM_UMLAUT=$(curl -s -o /dev/null -w '%{http_code}' -b "$EMJ" -c "$EMJ" --data-urlencode "csrf=$(curl -s -c "$EMJ" -b "$EMJ" "$B/admin/forgot" | csrf)" --data-urlencode "email=müller@example.org" "$B/admin/forgot")
check "forgot password with an address outside ASCII: the same answer as for an unknown address, not an error ($EM_ASCII, $EM_UMLAUT)" '[ "$EM_ASCII" = 200 ] && [ "$EM_UMLAUT" = "$EM_ASCII" ]'
rm -f "$EMJ"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "confirm=1" --data-urlencode "current_password=$PASS" "$B/admin/users/${EMUID:-0}/delete"
check "test account of the e-mail addresses removed" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE id = ${EMUID:-0}")" ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$EMR/delete"
check "test round of the e-mail addresses removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $EMR")" = 0 ]'
