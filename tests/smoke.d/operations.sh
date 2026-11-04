# Operations: no silent data loss (see README.md in this directory)
echo "Operations: automatic deletion only for rounds in use"
# Expert review P0-6: the clean-up lets only rounds expire that were in use (active, activated before or
# with an entry by the teacher). A copy that keeps the old dates, or any other draft, stays. The round page
# and the overview say when a round will be deleted. Rounds of this file are called "Betrieb …".
T=$(curl -s -b "$J" "$B/admin" | csrf)
OP_PAST=$(date -d '-3 day' +%F); OP_NEXT=$(date -d '+15 day' +%F)
op_round() { # op_round <title> <active 0/1> <date> → id of the new round (10:00–11:00, two slots)
  curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$1" -d "is_active=$2&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24&day_dates%5B%5D=$3&start_time=10:00&end_time=11:00&slot_minutes=30" "$B/admin/round/new" | grep -o '[0-9]*$'
}
op_entry() { # op_entry <round> <name>: entry by the teacher in the first free slot
  curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "name=$2" -d "lang=de" "$B$(curl -s -b "$J" "$B/admin/round/$1" | grep -o '/admin/slot/[0-9]*/book' | head -1)"
}
op_used() { DBQ "SELECT GROUP_CONCAT(id, ':', was_used ORDER BY id) FROM tp_rounds WHERE id IN ($1)"; }
OPA=$(op_round "Betrieb aktiv" 1 "$OP_PAST")
OPD=$(op_round "Betrieb Entwurf" 0 "$OP_PAST")
OPE=$(op_round "Betrieb Eintrag" 0 "$OP_PAST"); op_entry "$OPE" "Betrieb Eintrag"
OPX=$(op_round "Betrieb deaktiviert" 1 "$OP_NEXT")
OPB=$(op_round "Betrieb gebucht" 0 "$OP_NEXT"); op_entry "$OPB" "Betrieb Buchung"
OPX_PAGE=$(curl -s -b "$J" "$B/admin/round/$OPX")
check "active round: the round page says when it will be deleted (0:00 after the last day + 24 h)" 'echo "$OPX_PAGE" | grep -q "Runde wird am [A-Za-z]*, $(date -d "$OP_NEXT +2 day" +%d.%m.%Y), 00:00 Uhr gelöscht (24 h nach Ablauf)"'
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$OPX/toggle"
DBQ "UPDATE tp_round_days SET day_date = '$OP_PAST' WHERE round_id = $OPX"
OPC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$OPA/duplicate" | grep -o '[0-9]*$')
check "in use: activated (also when deactivated again) or with an entry; not: drafts and the copy of a round" '[ "$(op_used "$OPA,$OPD,$OPE,$OPX,$OPB,$OPC")" = "$OPA:1,$OPD:0,$OPE:1,$OPX:1,$OPB:1,$OPC:0" ] && [ "$(DBQ "SELECT is_active FROM tp_rounds WHERE id=$OPX")" = 0 ]'
check "draft: the round page says it is not deleted automatically" 'curl -s -b "$J" "$B/admin/round/$OPD" | has "Noch nie aktiv: wird nicht automatisch gelöscht"'
$DC exec -T app php -r 'require "/srv/app/app/bootstrap.php"; App\Cleanup::run();'
check "clean-up: rounds in use expire (active, deactivated by hand, with an entry); a draft never used stays, also the copy with the old dates" '[ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id IN ($OPA,$OPX,$OPE) AND expired_at IS NOT NULL AND is_active = 0")" = 3 ] && [ "$(DBQ "SELECT COUNT(*) FROM tp_rounds WHERE id IN ($OPD,$OPC) AND expired_at IS NULL")" = 2 ] && ! curl -s -b "$J" "$B/admin/round/$OPC" | has "Abgelaufen:"'
OP_DEL=$(DBQ "SELECT DATE_FORMAT(expired_at + INTERVAL 24 HOUR, '%d.%m.%Y, %H:%i') FROM tp_rounds WHERE id=$OPA")
OPA_PAGE=$(curl -s -b "$J" "$B/admin/round/$OPA")
check "expired round: notice with the date of deletion and how to keep the round ($OP_DEL)" 'echo "$OPA_PAGE" | grep -q "Abgelaufen: Die Runde wird am [A-Za-z]*, $OP_DEL Uhr automatisch gelöscht, mit allen Tagen und Einstellungen. Zum Behalten einen Tag ab heute hinzufügen" && echo "$OPA_PAGE" | grep -q "Runde wird am [A-Za-z]*, $OP_DEL Uhr gelöscht (24 h nach Ablauf)"'
OP_DASH=$(curl -s -b "$J" "$B/admin")
check "overview: expired round with the date of deletion, nothing for the draft" 'echo "$OP_DASH" | sed -n "/>Betrieb aktiv</,/<\/tr>/p" | grep -q "wird am ${OP_DEL%%,*} gelöscht" && ! echo "$OP_DASH" | sed -n "/>Betrieb Entwurf</,/<\/tr>/p" | grep -q "gelöscht"'

echo "Operations: migration 19"
OPS=$(op_round "Betrieb Sync" 1 "$OP_NEXT")
OP_USED=$(DBQ "SELECT GROUP_CONCAT(id) FROM tp_rounds WHERE was_used = 1")
DBQ "ALTER TABLE tp_rounds DROP COLUMN was_used; UPDATE tp_settings SET value = '18' WHERE name = 'schema_version'"
curl -s -o /dev/null -b "$J" "$B/admin"
check "migration 19: active, expired or booked rounds count as used, drafts not" '[ "$(DBQ "SELECT value FROM tp_settings WHERE name=\"schema_version\"")" = "$(grep -o "LATEST = [0-9]*" "$ROOT/app/src/Migrations.php" | grep -o "[0-9]*$")" ] && [ "$(op_used "$OPA,$OPD,$OPB,$OPC,$OPS")" = "$OPA:1,$OPD:0,$OPB:1,$OPC:0,$OPS:1" ]'
DBQ "UPDATE tp_rounds SET was_used = 1 WHERE id IN ($OP_USED)"
curl -s -o /dev/null -b "$J" -d "csrf=$T" "$B/admin/round/$OPC/toggle"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Betrieb+Entwurf&is_active=0&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24" "$B/admin/round/$OPD/edit"
OP_STILL=$(op_used "$OPD")
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" -d "title=Betrieb+Entwurf&is_active=1&booking_deadline_hours=0&cancel_deadline_hours=0&delete_after_hours=24" "$B/admin/round/$OPD/edit"
check "a draft activated by the button or in the form is in use from then on (saved inactive: still a draft)" '[ "$OP_STILL" = "$OPD:0" ] && [ "$(op_used "$OPD,$OPC")" = "$OPD:1,$OPC:1" ]'

echo "Operations: deleting a round"
# Expert review P0-6: with bookings only with the box ticked, checked on the server (the dialog needs
# JavaScript); the button in its own section after the days, deactivating offered instead
OPB_PAGE=$(curl -s -b "$J" "$B/admin/round/$OPB")
check "own section after the days, the action row only links to it" '[ "$(echo "$OPB_PAGE" | grep -c "action=\"/admin/round/$OPB/delete\"")" = 1 ] && [ "$(echo "$OPB_PAGE" | grep -n "id=\"delete-round\"" | cut -d: -f1)" -gt "$(echo "$OPB_PAGE" | grep -n "id=\"day-[0-9]*\"" | tail -1 | cut -d: -f1)" ] && echo "$OPB_PAGE" | grep -q "<a class=\"button\" href=\"#delete-round\">Löschen …</a>"'
check "with bookings the box \"inkl. 1 Buchung(en)\" is required; deactivating instead only for an active round" 'echo "$OPB_PAGE" | sed -n "/id=\"delete-round\"/,/<\/section>/p" | grep -q "name=\"confirm\" value=\"1\" required> inkl. 1 Buchung(en)" && ! echo "$OPB_PAGE" | has "Stattdessen deaktivieren" && echo "$OPX_PAGE" | sed -n "/id=\"delete-round\"/,/<\/section>/p" | grep -q "action=\"/admin/round/$OPX/toggle\""'
OP_LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J" -d "csrf=$T" "$B/admin/round/$OPB/delete")
check "without the box (no JavaScript): refused with the number of bookings, round and booking kept; with the box deleted" '[[ "$OP_LOC" == */admin/round/$OPB#delete-round ]] && curl -s -b "$J" "$B/admin/round/$OPB" | has "In dieser Runde gibt es 1 Buchung(en). Bitte das Löschen ausdrücklich bestätigen." && [ "$(DBQ "SELECT COUNT(*) FROM tp_bookings b JOIN tp_slots s ON s.id = b.slot_id JOIN tp_round_days d ON d.id = s.day_id WHERE d.round_id = $OPB")" = 1 ] && curl -s -L -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$OPB/delete" | has "Runde mit allen Tagen und Buchungen gelöscht" && [ -z "$(DBQ "SELECT id FROM tp_rounds WHERE id=$OPB")" ]'

echo "Operations: slot sync never deletes a booked slot"
# Expert review P0-6: sync reads the slots from a snapshot; a booking that arrives in the meantime must not
# go with a slot that no longer fits (ON DELETE CASCADE). Simulated with a second connection.
OPS_OUT=$($DC exec -T app php -r '
  require "/srv/app/app/bootstrap.php";
  $day = (int) App\Db::value("SELECT id FROM round_days WHERE round_id = ?", [(int) $argv[1]]);
  $slot = (int) App\Db::value("SELECT id FROM slots WHERE day_id = ? AND start_time = ?", [$day, "10:30:00"]);
  App\Db::run("UPDATE round_days SET end_time = ? WHERE id = ?", ["10:30:00", $day]); // no room for 10:30 any more
  App\Db::pdo()->beginTransaction();
  App\Db::value("SELECT COUNT(*) FROM bookings"); // the snapshot sync reads its slots from starts here
  $c = App\Config::get("db");
  $other = new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $c["host"], (int) ($c["port"] ?? 3306), $c["name"]), $c["user"], $c["pass"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $other->prepare(App\Db::prefix("INSERT INTO bookings (slot_id, name, token_hash, lang, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())"))
    ->execute([$slot, "Betrieb Wettlauf", hash("sha256", random_bytes(16)), "de", "parent"]);
  $conflicts = App\SlotGenerator::sync($day);
  App\Db::pdo()->commit();
  echo $conflicts, "|", App\Db::value("SELECT COUNT(*) FROM bookings WHERE slot_id = ?", [$slot]), "|", (int) App\Db::value("SELECT is_conflict FROM slots WHERE id = ?", [$slot]);
' "$OPS")
check "booked while the schedule is synced: slot kept as a conflict, booking not deleted ($OPS_OUT)" '[ "$OPS_OUT" = "1|1|1" ] && curl -s -b "$J" "$B/admin/round/$OPS" | has "1 Konflikt(e):"'

for r in $OPA $OPD $OPE $OPX $OPC $OPS; do curl -s -o /dev/null -b "$J" -d "csrf=$T&confirm=1" "$B/admin/round/$r/delete"; done
