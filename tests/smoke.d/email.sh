# E-mail addresses with umlauts in the domain (expert review P3-3), see App\Email
echo "E-mail addresses with umlauts in the domain"
em_mails() { $DC exec -T app php -r 'echo json_decode(file_get_contents("http://mail:8025/api/v1/search?query=" . rawurlencode("to:" . $argv[1])), true)["messages_count"] ?? "?";' "$1"; }
EMR=$(mkround Umlaut-Domain none); EMS=$(slugof "$EMR")
EMB=$(book "$EMS" "Familie Umlaut" " Eltern@Müller-Test.de ")
EMT=$(echo "$EMB" | grep -o '/b/[A-Za-z0-9_-]\{43\}' | head -1 | sed 's#/b/##')
check "booking with umlauts in the domain: accepted, stored and mailed in the ASCII form (punycode)" \
  '[[ "$EMB" == 303* ]] && [ "$(DBQ "SELECT email FROM tp_bookings WHERE name = \"Familie Umlaut\"")" = "Eltern@xn--mller-test-9db.de" ] && [ "$(em_mails Eltern@xn--mller-test-9db.de)" = 1 ]'
EMP=$(curl -s "$B/$EMS")
EMX=$(curl -s -w '\n%{http_code}' --data-urlencode "csrf=$(echo "$EMP" | csrf)" --data-urlencode "name=Familie Jürgen" --data-urlencode "email=jürgen@example.org" \
  -d "slot_id=$(echo "$EMP" | grep -o 'value="[0-9]*" required' | head -1 | grep -o '[0-9]*')" "$B/$EMS")
check "umlauts before the @: refused (422), the form shows the address as typed, nothing stored" \
  '[ "$(echo "$EMX" | tail -1)" = 422 ] && echo "$EMX" | has "value=\"jürgen@example.org\"" && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings WHERE name = \"Familie Jürgen\"")" = 0 ]'
EMM=$(curl -s "$B/b/$EMT")
check "confirmation to another address with umlauts in the domain (appointment page): sent as punycode" \
  '[ "$(curl -s -o /dev/null -w "%{http_code}" --data-urlencode "csrf=$(echo "$EMM" | csrf)" --data-urlencode "email=Oma@Bücher-Test.example" -d "action=mail" "$B/b/$EMT")" = 303 ] && [ "$(em_mails Oma@xn--bcher-test-9db.example)" = 1 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$EMR/delete"
check "test round of the e-mail addresses removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $EMR")" = 0 ]'
