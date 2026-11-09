# "My appointments on this device" (expert review P3-2): the page only brings the empty, hidden box, app.js fills it
# from the browser's own entries (tests/browser.mjs); a round's code prompt leaves out that round
echo "My appointments on this device"
MA_H=$(curl -s "$B/")
check "start page: the box is there, hidden, without any data from the server" 'echo "$MA_H" | has "id=\"my-appointments\" hidden" && echo "$MA_H" | has "data-my-appointments>" && ! echo "$MA_H" | has "data-except-round" && echo "$MA_H" | has "Meine Termine auf diesem Gerät"'
MA_R=$(mkround Mein-Termin none); MA_S=$(slugof "$MA_R")
DBQ "UPDATE tp_rounds SET code_required = 1 WHERE id = $MA_R"
check "code prompt of a round: the box leaves out that round" 'curl -s "$B/$MA_S" | has "data-except-round=\"$MA_R\""'
curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$MA_R/delete"
check "test data of my appointments removed" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id = $MA_R")" = 0 ]'
