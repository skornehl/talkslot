# Requests for access about an e-mail address (expert review P3-7), see App\AccessRequest
echo "Requests for access (Art. 15 GDPR)"
AR_R=$(mkround Auskunft none); AR_S=$(slugof "$AR_R")
AR_N=$(date +%s%N | tail -c 8); AR_MAIL="auskunft-$AR_N@example.org"
book "$AR_S" "Familie Auskunft" "$AR_MAIL" >/dev/null
book "$AR_S" "Familie Grossschreibung" "$(echo "$AR_MAIL" | tr 'a-z' 'A-Z')" >/dev/null
book "$AR_S" "Familie Andere" "andere-$AR_N@example.org" >/dev/null
# A cancellation still waiting in the mail queue keeps the address in its payload
AR_P=$(printf '{"booking_id":0,"round_id":0,"email":"%s","lang":"de","name":"Familie Absage","title":"Alte Runde","teacher_name":"Frau Test","day_date":"2030-01-02","start_time":"10:00:00","end_time":"10:15:00"}' "$AR_MAIL")
DBQ "INSERT INTO tp_mail_outbox (kind, payload, priority, next_attempt_at, created_at) VALUES ('cancelled', '$AR_P', 1, NOW() + INTERVAL 1 DAY, NOW())"
ar_post() { curl -s -w '\n%{http_code}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "email=$1" ${2:+-d "purpose=$2"} "$B/admin/access-request"; }
AR_L0=$(DBQ "SELECT COUNT(*) FROM tp_audit_log")
check "form for admins, reachable from the log" 'curl -s -b "$J" "$B/admin/log" | has "/admin/access-request" && curl -s -b "$J" "$B/admin/access-request" | has "data-access-request"'
AR_X=$(ar_post "$AR_MAIL")
check "without the confirmation: refused (422), nothing shown, nothing logged" '[ "$(echo "$AR_X" | tail -1)" = 422 ] && ! echo "$AR_X" | has "data-access-result" && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log")" = "$AR_L0" ]'
AR_X=$(ar_post "kein-format" 1)
check "not an address: refused (422)" '[ "$(echo "$AR_X" | tail -1)" = 422 ] && ! echo "$AR_X" | has "data-access-result"'
AR_X=$(ar_post "$AR_MAIL" 1)
check "the bookings of this address (any case) and the waiting cancellation, nobody else's" '[ "$(echo "$AR_X" | tail -1)" = 200 ] && [ "$(echo "$AR_X" | grep -c "data-access-booking")" = 2 ] && echo "$AR_X" | has "Familie Auskunft" && echo "$AR_X" | has "Familie Grossschreibung" && ! echo "$AR_X" | has "Familie Andere" && [ "$(echo "$AR_X" | grep -c "data-access-cancellation")" = 1 ] && echo "$AR_X" | has "Familie Absage"'
AR_M=$(DBQ "SELECT message FROM tp_audit_log ORDER BY id DESC LIMIT 1")
check "the search is in the log: with the numbers of hits, without the address" 'echo "$AR_M" | has "Auskunftssuche" && echo "$AR_M" | has "2 Buchung(en), 1 wartende" && ! echo "$AR_M" | has "$AR_MAIL" && [ "$(DBQ "SELECT COUNT(*) FROM tp_audit_log")" = "$((AR_L0 + 1))" ]'
AR_X=$(ar_post "auskunft-$AR_N@example.or" 1)
check "only the exact address: a similar one finds nothing, and says so" 'echo "$AR_X" | has "data-access-none" && ! echo "$AR_X" | has "data-access-booking"'
DBQ "DELETE FROM tp_mail_outbox WHERE kind = 'cancelled' AND payload LIKE '%$AR_MAIL%'"
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$AR_R/delete"
check "test data of the requests for access removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $AR_R")" = 0 ]'
