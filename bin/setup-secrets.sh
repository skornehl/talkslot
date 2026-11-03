#!/usr/bin/env bash
# Stores all GitHub secrets/variables for the deployment.
# Passwords are prompted for without echo and are neither displayed nor stored; values are passed
# to gh via stdin (not as an argument, otherwise they would briefly show up in the process list).
# Usage (in the repo directory, after "gh auth login"): bin/setup-secrets.sh
set -euo pipefail

ask() { # ask <NAME> <description> [hidden]
  local v
  # Line break after hidden input goes to stderr so it does not end up in the value
  if [ "${3:-}" = hidden ]; then read -rsp "$2: " v; echo >&2; else read -rp "$2: " v; fi
  printf '%s' "$v"
}

echo "== Server"
gh variable set BASE_URL --body "$(ask x 'Site URL, e.g. https://termine.example.org')"
gh variable set FTP_HOST --body "$(ask x 'FTP server, e.g. ftp.example.org')"
gh variable set WEB_DIR --body "$(ask x 'Web directory (document root) relative to the FTP root, e.g. public_html')"
gh variable set APP_DIR --body "$(ask x 'App directory outside the web root, e.g. talkslot-app')"
gh variable set DB_HOST --body "$(ask x 'Database server, e.g. localhost')"

echo "== FTP"
printf '%s' "$(ask x 'FTP user')" | gh secret set FTP_USERNAME
printf '%s' "$(ask x 'FTP password' hidden)" | gh secret set FTP_PASSWORD

echo "== Database"
DBU=$(ask x 'DB user')
printf '%s' "$DBU" | gh secret set DB_USER
printf '%s' "$(ask x 'DB password' hidden)" | gh secret set DB_PASS
DBN=$(ask x "DB name [Enter = $DBU]")
printf '%s' "${DBN:-$DBU}" | gh secret set DB_NAME

echo "== Keys (generated randomly)"
printf '%s' "$(openssl rand -hex 32)" | gh secret set APP_SECRET
KEY=$(openssl rand -hex 12)
printf '%s' "$KEY" | gh secret set INSTALL_KEY
gh variable set ALLOW_INSTALL --body 1
echo "   INSTALL_KEY for install.php (please write it down): $KEY"
echo "   After installation: gh variable delete ALLOW_INSTALL (the installer is then no longer shipped)"

echo "== Privacy policy"
HD=$(ask x 'Web host for the privacy policy (German), e.g. "Beispiel GmbH, Weg 1, 12345 Stadt, als Auftragsverarbeiter nach Art. 28 DSGVO. Die Server stehen in Deutschland."')
[ -n "$HD" ] && gh variable set HOSTING_DE --body "$HD"
HE=$(ask x 'Web host for the privacy policy (English)')
[ -n "$HE" ] && gh variable set HOSTING_EN --body "$HE"

echo "== Email"
FROM=$(ask x 'Sender address (Enter = later)')
[ -n "$FROM" ] && gh variable set MAIL_FROM --body "$FROM"

echo "Done. Start the deployment with: gh workflow run Deploy"
