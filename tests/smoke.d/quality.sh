echo "Integration tests in a throwaway schema (tests/integration.php, expert review P1-14)"
# The services directly against the database, in tables of their own (prefix it<hex>_) that are dropped again.
# Only this run's prefix is checked: tables a killed earlier run left behind do not fail it (dev/reset.sh drops them).
IT=$(dcx exec -T app php /srv/app/tests/integration.php 2>&1); IT_RC=$?
IT_PREFIX=$(printf '%s\n' "$IT" | sed -n 's/^Throwaway schema \(it[0-9a-f]\{8\}_\)$/\1/p')
printf '%s\n' "$IT" | sed 's/^/  /'
check "integration tests passed, no table of theirs left ($IT_PREFIX)" '[ "$IT_RC" = 0 ] && echo "$IT" | grep -q "^ALL INTEGRATION TESTS PASSED$" && [ -n "$IT_PREFIX" ] && [ -z "$(dcx exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SHOW TABLES LIKE '"'"'${IT_PREFIX%_}\_%'"'"'")" ]'
