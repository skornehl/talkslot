#!/usr/bin/env bash
# Hinterlegt alle GitHub-Secrets/-Variablen für das Deployment.
# Passwörter werden verdeckt abgefragt und nicht angezeigt oder gespeichert; Werte gehen per stdin
# an gh (nicht als Argument, sonst stünden sie kurz in der Prozessliste).
# Aufruf (im Repo-Verzeichnis, nach "gh auth login"): bin/setup-secrets.sh
set -euo pipefail

ask() { # ask <NAME> <Beschreibung> [verdeckt]
  local v
  # Zeilenumbruch nach verdeckter Eingabe auf stderr, damit er nicht im Wert landet
  if [ "${3:-}" = hidden ]; then read -rsp "$2: " v; echo >&2; else read -rp "$2: " v; fi
  printf '%s' "$v"
}

echo "== Server"
gh variable set BASE_URL --body "$(ask x 'Adresse der Seite, z. B. https://termine.example.org')"
gh variable set FTP_HOST --body "$(ask x 'FTP-Server, z. B. ftp.example.org')"
gh variable set WEB_DIR --body "$(ask x 'Webverzeichnis (Document-Root) relativ zum FTP-Stamm, z. B. public_html')"
gh variable set APP_DIR --body "$(ask x 'App-Verzeichnis außerhalb des Webroots, z. B. talkslot-app')"
gh variable set DB_HOST --body "$(ask x 'Datenbank-Server, z. B. localhost')"

echo "== FTP"
printf '%s' "$(ask x 'FTP-Benutzer')" | gh secret set FTP_USERNAME
printf '%s' "$(ask x 'FTP-Passwort' hidden)" | gh secret set FTP_PASSWORD

echo "== Datenbank"
DBU=$(ask x 'DB-Benutzer')
printf '%s' "$DBU" | gh secret set DB_USER
printf '%s' "$(ask x 'DB-Passwort' hidden)" | gh secret set DB_PASS
DBN=$(ask x "DB-Name [Enter = $DBU]")
printf '%s' "${DBN:-$DBU}" | gh secret set DB_NAME

echo "== Schlüssel (werden zufällig erzeugt)"
printf '%s' "$(openssl rand -hex 32)" | gh secret set APP_SECRET
KEY=$(openssl rand -hex 12)
printf '%s' "$KEY" | gh secret set INSTALL_KEY
gh variable set ALLOW_INSTALL --body 1
echo "   INSTALL_KEY für install.php (bitte notieren): $KEY"
echo "   Nach der Installation: gh variable delete ALLOW_INSTALL (Installer wird dann nicht mehr ausgeliefert)"

echo "== Datenschutzerklärung"
HD=$(ask x 'Hoster für die Datenschutzerklärung (deutsch), z. B. "Beispiel GmbH, Weg 1, 12345 Stadt, als Auftragsverarbeiter nach Art. 28 DSGVO. Die Server stehen in Deutschland."')
[ -n "$HD" ] && gh variable set HOSTING_DE --body "$HD"
HE=$(ask x 'Web host for the privacy policy (English)')
[ -n "$HE" ] && gh variable set HOSTING_EN --body "$HE"

echo "== E-Mail"
FROM=$(ask x 'Absenderadresse (Enter = später)')
[ -n "$FROM" ] && gh variable set MAIL_FROM --body "$FROM"

echo "Fertig. Deployment starten mit: gh workflow run Deploy"
