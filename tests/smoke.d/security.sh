# Account security and security notices (see README.md in this directory)
echo "Security: permissions of config.php"
# Expert review P2-11: config.php holds the database and mail passwords and the secret. Admins see a
# warning on the dashboard while every user of the server can read it. The test setup itself needs a
# world-readable file (the web server runs as another user), so only that case is checked here; owner
# and group only (600, 640) are covered in tests/unit.d/security.php.
CFG="$ROOT/config/config.php"
CFG_KEEP=$(stat -c %a "$CFG")
chmod o+r "$CFG"
CFG_NOTE="Die Konfiguration ist für alle Benutzer des Servers lesbar: config/config.php ($(printf '%04o' "0$(stat -c %a "$CFG")"))."
check "config.php readable for every user: admins see a warning with its path and permissions" 'curl -s -b "$J" "$B/admin" | grep -qF "$CFG_NOTE"'
# Teachers cannot have that fixed: no warning for them
CFG_PW=Rechte-Lehrkraft-123
DBQ "INSERT INTO tp_users (username, password_hash, role, display_name, email, created_at) VALUES ('cfg-teacher@example.org', '$($DC exec -T app php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$CFG_PW")', 'teacher', 'Herr Rechte', 'cfg-teacher@example.org', NOW())"
JC=$(mktemp)
curl -s -o /dev/null -c "$JC" -b "$JC" --data-urlencode "csrf=$(curl -s -c "$JC" -b "$JC" "$B/admin/login" | csrf)" --data-urlencode "username=cfg-teacher@example.org" --data-urlencode "password=$CFG_PW" "$B/admin/login"
check "teachers do not see it" 'CFG_D=$(curl -s -b "$JC" "$B/admin"); echo "$CFG_D" | grep -q "Meine Terminrunden" && ! echo "$CFG_D" | grep -qF "config/config.php"'
DBQ "DELETE FROM tp_users WHERE username = 'cfg-teacher@example.org'"
rm -f "$JC"
chmod "$CFG_KEEP" "$CFG"
