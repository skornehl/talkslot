#!/usr/bin/env bash
# Fills the local development instance with demo data (used for the screenshots in docs/):
# admin profile, three rounds and a few bookings. Run after dev/reset.sh.
# Usage: dev/demo.sh [base_url]   (default http://localhost:8080; admin as in dev/reset.sh)
set -euo pipefail
B=${1:-http://localhost:8080}
USER=sabrina
PASS=Sehr-Sicheres-Dev-Pw1
J=$(mktemp)
trap 'rm -f "$J"' EXIT
csrf() { grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
day() { date -d "+$1 day" +%F; }

T=$(curl -s -c "$J" -b "$J" "$B/admin/login" | csrf)
curl -s -o /dev/null -c "$J" -b "$J" -d "csrf=$T&username=$USER" --data-urlencode "password=$PASS" "$B/admin/login"
T=$(curl -s -b "$J" "$B/admin" | csrf)
[ -n "$T" ] || { echo "login failed" >&2; exit 1; }

# Admin profile, and the school's details for imprint and privacy policy (required before rounds go public)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "display_name=Frau Sabrina Berger" \
  -d "email=lehrer@example.org&summary_evening=1&summary_deadline=1" "$B/admin/profile"
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" \
  --data-urlencode $'controller_contact=Gesamtschule am See\nVertreten durch die Schulleitung\nSeeweg 12\n12345 Seestadt\nE-Mail: sekretariat@example.org' \
  "$B/admin/texts/operator"

round() { # round <title> <description> <active 0/1> <minutes> <start> <end> <day offsets…>
  local title=$1 desc=$2 active=$3 minutes=$4 start=$5 end=$6 days='' d
  shift 6
  for d in "$@"; do days+="&day_dates%5B%5D=$(day "$d")"; done
  curl -s -o /dev/null -w '%{redirect_url}' -b "$J" --data-urlencode "csrf=$T" --data-urlencode "title=$title" \
    --data-urlencode "description=$desc" \
    -d "random_suffix=1&is_active=$active&booking_limit=round&booking_deadline_hours=24&cancel_deadline_hours=12&delete_after_hours=24$days&start_time=$start&end_time=$end&slot_minutes=$minutes&break_start%5B%5D=16:30&break_end%5B%5D=16:45&break_label%5B%5D=Pause" \
    "$B/admin/round/new" | grep -o '[0-9]*$'
}
R1=$(round 'Elternsprechtag Klasse 7b' $'Liebe Eltern,\n\nbitte wählen Sie einen Termin für das Gespräch über die Halbjahreszeugnisse. Die Gespräche finden in Raum A 112 statt.' 1 15 15:00 18:00 7 8)
round 'Sprechstunde Mathematik' 'Offene Sprechstunde für Fragen zum Unterricht.' 1 10 13:00 14:00 10 > /dev/null
round 'Elternabend Klasse 5a' 'Wird noch geplant.' 0 20 18:00 20:00 21 > /dev/null

# English translation of the first round (parents with English selected see it, also in mails)
curl -s -o /dev/null -b "$J" --data-urlencode "csrf=$T" --data-urlencode "t_title[en]=Parent-teacher conference, class 7b" \
  --data-urlencode $'t_description[en]=Dear parents,\n\nplease choose an appointment to talk about the mid-year reports. The meetings take place in room A 112.' \
  "$B/admin/round/$R1/translations"

# Bookings in the first round, made through the public booking form like parents would
SLUG=$(curl -s -b "$J" "$B/admin/round/$R1" | grep -o 'data-copy="[^"]*"' | head -1 | sed 's#.*/##;s/"$//')
book() { # book <slot index> <name> [email]
  local page csrf slot
  page=$(curl -s "$B/$SLUG")
  csrf=$(echo "$page" | csrf)
  slot=$(echo "$page" | grep -o 'name="slot_id" id="slot-[0-9]*" value="[0-9]*"' | sed -n "$1p" | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
  curl -s -o /dev/null --data-urlencode "csrf=$csrf" --data-urlencode "name=$2" --data-urlencode "email=${3:-}" -d "slot_id=$slot&privacy=1" "$B/$SLUG"
}
book 1 'Familie Yılmaz (Elif)' elif.yilmaz@example.org
book 2 'Lukas Schmidt'
book 3 'Anna Kowalska' anna.k@example.org
book 5 'Mia Petrenko'
book 8 'Jonas Becker' jonas.becker@example.org
book 11 'Emma Wagner'
book 14 'Noah Fischer' n.fischer@example.org
echo "demo data ok: $B/$SLUG"
