# Code hygiene (expert review P3-4): routes end with \z, so a line break after the path finds no page
echo "Code hygiene"
HY_R=$(mkround Zeilenumbruch none); HY_SLUG=$(slugof "$HY_R")
hy_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
check "a line break after the path (%0A) → 404: admin round page, round page; without it 200" \
  '[ "$(hy_code -b "$J" "$B/admin/round/$HY_R%0A")" = 404 ] && [ "$(hy_code -b "$J" "$B/admin/round/$HY_R")" = 200 ] && [ "$(hy_code "$B/$HY_SLUG%0A")" = 404 ] && [ "$(hy_code "$B/$HY_SLUG")" = 200 ]'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$HY_R/delete"
check "test round of the code hygiene removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $HY_R")" = 0 ]'
