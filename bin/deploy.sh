#!/usr/bin/env bash
# Atomares Deployment per FTPS (lftp): Jede Version wird vollständig in ein eigenes Verzeichnis
# hochgeladen und erst danach durch Austausch der Zeigerdatei „current“ aktiv geschaltet.
# Besucher sehen also nie eine halb hochgeladene Mischung aus alter und neuer Version.
#
#   bin/deploy.sh upload <Name>          Version aus build/ hochladen (noch nicht aktiv) und prüfen,
#                                         ob alle Dateien angekommen sind
#   bin/deploy.sh activate <Name>        umschalten, feste Dateien im Webverzeichnis aktualisieren,
#                                         Seite prüfen (sonst sofort zurück), alte Versionen löschen
#   bin/deploy.sh switch <Name|previous> auf eine vorhandene Version umschalten (Rollback)
#   bin/deploy.sh discard <Name>         hochgeladene, nie aktivierte Version wieder löschen
#   bin/deploy.sh list                   vorhandene Versionen, die aktive mit *
#
# Umgebung: FTP_URL (z. B. ftp://ftp.example.org), APP_DIR, WEB_DIR (relativ zum FTP-Stammverzeichnis;
# APP_DIR außerhalb des Webroots), FTP_USER und LFTP_PASSWORD, BASE_URL (für die Prüfung), KEEP (aufbewahrte Versionen, Standard 5),
# BUILD_DIR (Standard build). Server-Layout: README, Abschnitt „Deployment“.
set -euo pipefail

die() { echo "::error::$*" >&2; exit 1; }

FTP_URL=${FTP_URL:?FTP_URL fehlt, z. B. ftp://ftp.example.org}
APP_DIR=${APP_DIR:?APP_DIR fehlt}
WEB_DIR=${WEB_DIR:?WEB_DIR fehlt}
APP_DIR=${APP_DIR%/}
WEB_DIR=${WEB_DIR%/}
KEEP=${KEEP:-5}
BUILD_DIR=${BUILD_DIR:-build}
NAME_RX='^[A-Za-z0-9][A-Za-z0-9._-]*$'
SAFE_RX='^[A-Za-z0-9._/:@-]+$'
for v in "$FTP_URL" "$APP_DIR" "$WEB_DIR" "$BUILD_DIR"; do [[ $v =~ $SAFE_RX ]] || die "Unzulässige Zeichen in: $v"; done
[[ $KEEP =~ ^[0-9]+$ ]] && [ "$KEEP" -ge 2 ] || die "KEEP muss mindestens 2 sein"
FTP_USER=$(printf '%s' "${FTP_USER:-}" | tr -d '\r\n')
[[ $FTP_USER != *"'"* ]] || die "Unzulässiger FTP-Benutzername"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# lftp-Befehle ausführen; bricht beim ersten fehlgeschlagenen Befehl ab (Exit-Code ≠ 0)
ftp() {
  local login=''
  [ -n "$FTP_USER" ] && login="-u '$FTP_USER' --env-password"
  lftp -c "set cmd:fail-exit yes; set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true;
    set net:max-retries 5; set net:reconnect-interval-base 5; set net:timeout 30;
    open $login $FTP_URL; $1"
}

# Datei ersetzen, ohne dass sie zwischendurch halb geschrieben ist: hochladen als .new, dann umbenennen.
# Scheitert schon das Hochladen, bleibt alles, wie es war. Überschreibt der Server beim Umbenennen
# nicht, wird das Ziel kurz gelöscht (app_root.php wartet auf einen fehlenden Zeiger).
put_atomic() {
  local src=$1 dst=$2
  ftp "put $src -o $dst.new"
  ftp "mv $dst.new $dst" 2> /dev/null || ftp "rm -f $dst; mv $dst.new $dst"
}

releases() { # vorhandene Versionen, älteste zuerst
  ftp "cls -1 $APP_DIR/releases/" 2>/dev/null | sed -e 's#/$##' -e 's#.*/##' | grep -E "$NAME_RX" | LC_ALL=C sort || true
}

current() {
  rm -f "$TMP/current"
  ftp "get $APP_DIR/current -o $TMP/current" > /dev/null 2>&1 || true
  [ -f "$TMP/current" ] && tr -d ' \r\n' < "$TMP/current" || true
}

set_current() {
  printf '%s\n' "$1" > "$TMP/pointer"
  put_atomic "$TMP/pointer" "$APP_DIR/current"
}

# Prüft nach dem Umschalten, dass die Seite antwortet, wirklich die neue Version ausliefert
# (Asset-Pfad im HTML) und deren CSS erreichbar ist
check() {
  local name=$1 base=${BASE_URL:-} html i
  base=${base%/}
  if [ -z "$base" ]; then echo "::warning::BASE_URL fehlt – Prüfung übersprungen"; return 0; fi
  for i in 1 2 3 4 5 6; do
    if html=$(curl -fsS -m 30 "$base/admin/login") && grep -qF "/v/$name/css/app.css" <<< "$html" \
      && curl -fsS -m 30 -o /dev/null "$base/v/$name/css/app.css" \
      && curl -fsS -m 30 -o /dev/null "$base/"; then
      echo "Prüfung ok: $base liefert $name aus"
      return 0
    fi
    sleep 5
  done
  return 1
}

# Alte Versionen löschen (die neuesten KEEP und die aktive bleiben)
cleanup() {
  local active=$1 all n cmds=''
  all=$(releases)
  for n in $(printf '%s\n' "$all" | head -n -"$KEEP"); do
    [ "$n" = "$active" ] && continue
    echo "Lösche alte Version $n"
    cmds+="rm -r -f $APP_DIR/releases/$n; rm -r -f $WEB_DIR/v/$n; "
  done
  # Aufräumen ist kein Grund, ein erfolgreiches Deployment scheitern zu lassen
  [ -z "$cmds" ] || ftp "$cmds" || echo "::warning::Aufräumen unvollständig – wird beim nächsten Deployment wiederholt"
}

# Version löschen (nie die aktive). Danach kann app_root.php sie auch als Notlösung nicht mehr wählen.
discard() {
  local name=$1
  [ "$name" != "$(current)" ] || die "$name ist aktiv und wird nicht gelöscht"
  ftp "rm -r -f $APP_DIR/releases/$name; rm -r -f $WEB_DIR/v/$name"
  echo "Gelöscht: $name"
}

# Zurück auf die vorherige Version und die gescheiterte löschen (beim allerersten Deployment gibt es
# keine vorherige: dann bleibt die Seite ohne Version, bis eine funktionierende hochgeladen ist)
revert() {
  local prev=$1 failed=$2
  if [ -n "$prev" ]; then
    set_current "$prev"
    echo "::error::Prüfung fehlgeschlagen – zurückgeschaltet auf $prev"
  else
    ftp "rm -f $APP_DIR/current"
    echo "::error::Prüfung fehlgeschlagen – erstes Deployment, keine vorherige Version"
  fi
  discard "$failed" || true
}

cmd=${1:-}
name=${2:-}
case "$cmd" in
  upload)
    [[ $name =~ $NAME_RX ]] || die "Versionsname fehlt oder ist ungültig"
    [ -d "$BUILD_DIR/app/releases/$name" ] && [ -d "$BUILD_DIR/web/v/$name" ] || die "Kein Build für $name in $BUILD_DIR"
    ftp "mirror -R --no-perms --parallel=2 --verbose=1 $BUILD_DIR/app/releases/$name $APP_DIR/releases/$name;
      mirror -R --no-perms --verbose=1 $BUILD_DIR/app/app/var $APP_DIR/app/var;
      mirror -R --no-perms --parallel=2 --verbose=1 $BUILD_DIR/web/v/$name $WEB_DIR/v/$name;
      put $BUILD_DIR/web/v/.htaccess -o $WEB_DIR/v/.htaccess"
    # Vollständigkeit: alle Dateien des Builds müssen auf dem Server liegen
    (cd "$BUILD_DIR/app/releases/$name" && find . -type f | sed 's#^\./#app/#') > "$TMP/local"
    (cd "$BUILD_DIR/web/v/$name" && find . -type f | sed 's#^\./#web/#') >> "$TMP/local"
    { ftp "find $APP_DIR/releases/$name" | grep -v '/$' | sed "s#^$APP_DIR/releases/$name/#app/#"
      ftp "find $WEB_DIR/v/$name" | grep -v '/$' | sed "s#^$WEB_DIR/v/$name/#web/#"; } > "$TMP/remote"
    missing=$(comm -23 <(LC_ALL=C sort "$TMP/local") <(LC_ALL=C sort "$TMP/remote"))
    [ -z "$missing" ] || die "Auf dem Server fehlen Dateien: $(head -5 <<< "$missing" | tr '\n' ' ')"
    echo "Hochgeladen und vollständig: $name ($(wc -l < "$TMP/local") Dateien) – noch nicht aktiv"
    ;;

  activate)
    [[ $name =~ $NAME_RX ]] || die "Versionsname fehlt oder ist ungültig"
    all=$(releases)
    grep -qxF "$name" <<< "$all" || die "Version $name liegt nicht auf dem Server"
    prev=$(current)
    set_current "$name"
    echo "Umgeschaltet: ${prev:-(keine)} → $name"
    # Feste Dateien im Webverzeichnis: zuerst app_root.php (atomar), dann index.php, .htaccess usw.
    # (ändern sich selten; beim ersten Deployment braucht index.php die app_root.php schon)
    put_atomic "$BUILD_DIR/web/app_root.php" "$WEB_DIR/app_root.php"
    ftp "mirror -R --no-perms --verbose=1 -x '^v/' -x '^app_root\\.php\$' $BUILD_DIR/web $WEB_DIR"
    if ! check "$name"; then
      revert "$prev" "$name"
      exit 1
    fi
    cleanup "$name"
    ;;

  switch)
    [ -n "$name" ] || die "Ziel fehlt: Versionsname oder previous"
    all=$(releases)
    active=$(current)
    if [ "$name" = previous ]; then
      name=$(printf '%s\n' "$all" | awk -v a="$active" '$0 < a' | tail -n 1)
      [ -n "$name" ] || die "Keine ältere Version als ${active:-?} vorhanden"
    fi
    [[ $name =~ $NAME_RX ]] && grep -qxF "$name" <<< "$all" || die "Version $name liegt nicht auf dem Server. Vorhanden: $(tr '\n' ' ' <<< "$all")"
    [ "$name" != "$active" ] || { echo "$name ist bereits aktiv"; exit 0; }
    set_current "$name"
    echo "Umgeschaltet: ${active:-?} → $name"
    if ! check "$name"; then
      [ -n "$active" ] && set_current "$active"
      die "Prüfung fehlgeschlagen – zurückgeschaltet auf ${active:-?}"
    fi
    ;;

  discard)
    [[ $name =~ $NAME_RX ]] || die "Versionsname fehlt oder ist ungültig"
    discard "$name"
    ;;

  list)
    active=$(current)
    releases | while read -r n; do
      [ "$n" = "$active" ] && echo "* $n (aktiv)" || echo "  $n"
    done
    ;;

  *)
    sed -n '2,16p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
    ;;
esac
