echo "Integration tests in a throwaway schema (tests/integration.php, expert review P1-14)"
# The services directly against the database, in tables of their own (prefix it<hex>_) that are dropped again
IT=$(dcx exec -T app php /srv/app/tests/integration.php 2>&1); IT_RC=$?
printf '%s\n' "$IT" | sed 's/^/  /'
check "integration tests passed, no table of theirs left (only tp_ tables of the installation)" '[ "$IT_RC" = 0 ] && echo "$IT" | grep -q "^ALL INTEGRATION TESTS PASSED$" && [ "$(dcx exec -T db mariadb -uapp -papp-dev-only talkslot -Ne "SHOW TABLES" | grep -vc "^tp_")" = 0 ]'
