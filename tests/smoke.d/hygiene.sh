# Code hygiene (expert review P3-4): routes end with \z, so a line break after the path finds no page
echo "Code hygiene"
HY_R=$(mkround Zeilenumbruch none); HY_SLUG=$(slugof "$HY_R")
hy_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
check "a line break after the path (%0A) → 404: admin round page, round page; without it 200" \
  '[ "$(hy_code -b "$J" "$B/admin/round/$HY_R%0A")" = 404 ] && [ "$(hy_code -b "$J" "$B/admin/round/$HY_R")" = 200 ] && [ "$(hy_code "$B/$HY_SLUG%0A")" = 404 ] && [ "$(hy_code "$B/$HY_SLUG")" = 200 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$HY_R/delete"
check "test round of the code hygiene removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $HY_R")" = 0 ]'
# Users list: an invitation counts as open until it expires (decided in the query, see AdminController::users())
HY_MAIL=hy-einladung-$(date +%s%N | tail -c 8)@example.org
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Einladung Test" --data-urlencode "email=$HY_MAIL" -d "role=teacher" "$B/admin/users/new"
hy_state() { curl -s -b "$J" "$B/admin/users" | grep -F -A10 "$HY_MAIL" | grep -m1 -o 'Einladung [a-z]*'; }
DBQ "UPDATE tp_users SET access_token_expires_at = NOW() + INTERVAL 1 DAY WHERE email = '$HY_MAIL'"
HY_OPEN=$(hy_state)
DBQ "UPDATE tp_users SET access_token_expires_at = '2020-01-01 00:00:00' WHERE email = '$HY_MAIL'"
check "users list: a running invitation is open, an expired one is shown as expired" '[ "$HY_OPEN" = "Einladung offen" ] && [ "$(hy_state)" = "Einladung abgelaufen" ]' || printf '      %s / %s\n' "$HY_OPEN" "$(hy_state)"
HY_U=$(DBQ "SELECT id FROM tp_users WHERE email = '$HY_MAIL'")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "confirm=1" --data-urlencode "current_password=$PASS" "$B/admin/users/$HY_U/delete"
check "test account of the code hygiene removed" '[ -z "$(DBQ "SELECT id FROM tp_users WHERE email = \"$HY_MAIL\"")" ]'
