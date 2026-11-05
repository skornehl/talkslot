#!/usr/bin/env bash
# PHP warnings, notices, deprecations and fatal errors of the app in its error log (expert review P1-14).
# app/bootstrap.php logs them as "PHP Warning: <message> (<file>:<line>)" and carries on, so they would
# otherwise pass every test unnoticed; tests/smoke.sh and CI call this script after their runs.
#   tests/php-errors.sh [log] [offset]
# log defaults to app/var/php-error.log, offset is the size in bytes before the run (only newer lines count;
# if the log was rotated in between, the rest of the older file .1 counts as well). Prints the lines grouped
# and exits with 1 if there are any. Lines from code outside app/, bin/ and public/ (php -r and temporary
# scripts of the tests, which provoke such errors on purpose) are not counted.
LOG=${1:-$(dirname "$0")/../app/var/php-error.log}
OFFSET=${2:-0}
[ -f "$LOG" ] || exit 0
new_lines() {
  if [ "$(stat -c %s "$LOG")" -lt "$OFFSET" ]; then
    [ -f "$LOG.1" ] && tail -c +"$((OFFSET + 1))" "$LOG.1"
    cat "$LOG"
  else
    tail -c +"$((OFFSET + 1))" "$LOG"
  fi
}
FOUND=$(new_lines | grep -aE '^\[[^]]*\] PHP (Warning|Notice|Deprecated|Fatal error): .* \(/(app|bin|public)/[^():]*:[0-9]+\)$')
[ -z "$FOUND" ] && exit 0
printf '%s\n' "$FOUND" | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | sort -rn
exit 1
