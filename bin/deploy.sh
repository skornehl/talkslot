#!/usr/bin/env bash
# Atomic deployment via FTPS (lftp): every version is uploaded completely into its own directory
# and only then made active by replacing the pointer file "current".
# Visitors therefore never see a half-uploaded mix of the old and the new version.
#
#   bin/deploy.sh upload <name>          upload a version from build/ (not active yet), verify that
#                                         all files have arrived, restrict access to its config.php
#   bin/deploy.sh activate <name>        switch, update the fixed files in the web directory,
#                                         check the site (otherwise switch back at once), delete old versions
#   bin/deploy.sh switch <name|previous> switch to an existing version (rollback)
#   bin/deploy.sh discard <name>         delete an uploaded version that was never activated
#   bin/deploy.sh prune                  delete all versions except the active one (after changing
#                                         secrets: older versions keep the old ones in their config.php)
#   bin/deploy.sh list                   existing versions, the active one marked with *
#
# Environment: FTP_URL (e.g. ftp://ftp.example.org), APP_DIR, WEB_DIR (relative to the FTP root;
# APP_DIR outside the web root), FTP_USER and LFTP_PASSWORD, BASE_URL (for the check), KEEP (versions kept, default 5),
# BUILD_DIR (default build), CONFIG_MODE (permissions of config.php after the upload, default 600;
# 640 if PHP reads it through the group, keep = leave them to the server). Server layout: README, section "Deployment".
set -euo pipefail

die() { echo "::error::$*" >&2; exit 1; }

FTP_URL=${FTP_URL:?FTP_URL missing, e.g. ftp://ftp.example.org}
APP_DIR=${APP_DIR:?APP_DIR missing}
WEB_DIR=${WEB_DIR:?WEB_DIR missing}
APP_DIR=${APP_DIR%/}
WEB_DIR=${WEB_DIR%/}
KEEP=${KEEP:-5}
BUILD_DIR=${BUILD_DIR:-build}
CONFIG_MODE=${CONFIG_MODE:-600}
NAME_RX='^[A-Za-z0-9][A-Za-z0-9._-]*$'
SAFE_RX='^[A-Za-z0-9._/:@-]+$'
for v in "$FTP_URL" "$APP_DIR" "$WEB_DIR" "$BUILD_DIR"; do [[ $v =~ $SAFE_RX ]] || die "Invalid characters in: $v"; done
[[ $KEEP =~ ^[0-9]+$ ]] && [ "$KEEP" -ge 2 ] || die "KEEP must be at least 2"
[[ $CONFIG_MODE =~ ^([0-7]{3,4}|keep)$ ]] || die "CONFIG_MODE must be an octal mode like 600 or 640, or keep"
FTP_USER=$(printf '%s' "${FTP_USER:-}" | tr -d '\r\n')
[[ $FTP_USER != *"'"* ]] || die "Invalid FTP user name"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# Run lftp commands; aborts at the first failing command (exit code ≠ 0)
ftp() {
  local login=''
  [ -n "$FTP_USER" ] && login="-u '$FTP_USER' --env-password"
  lftp -c "set cmd:fail-exit yes; set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true;
    set net:max-retries 5; set net:reconnect-interval-base 5; set net:timeout 30;
    open $login $FTP_URL; $1"
}

# Replace a file without it ever being half-written: upload as .new, then rename.
# If the upload itself fails, everything stays as it was. If the server does not overwrite on
# rename, the target is briefly deleted (app_root.php waits for a missing pointer).
put_atomic() {
  local src=$1 dst=$2
  ftp "put $src -o $dst.new"
  ftp "mv $dst.new $dst" 2> /dev/null || ftp "rm -f $dst; mv $dst.new $dst"
}

releases() { # existing versions, oldest first
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

# After switching, checks that the site responds, really serves the new version
# (asset path in the HTML) and that its CSS is reachable
check() {
  local name=$1 base=${BASE_URL:-} html i
  base=${base%/}
  if [ -z "$base" ]; then echo "::warning::BASE_URL missing – check skipped"; return 0; fi
  for i in 1 2 3 4 5 6; do
    if html=$(curl -fsS -m 30 "$base/admin/login") && grep -qF "/v/$name/css/app.css" <<< "$html" \
      && curl -fsS -m 30 -o /dev/null "$base/v/$name/css/app.css" \
      && curl -fsS -m 30 -o /dev/null "$base/"; then
      echo "Check ok: $base serves $name"
      return 0
    fi
    sleep 5
  done
  return 1
}

# Delete old versions (the newest KEEP and the active one stay)
cleanup() {
  local active=$1 all n cmds=''
  all=$(releases)
  for n in $(printf '%s\n' "$all" | head -n -"$KEEP"); do
    [ "$n" = "$active" ] && continue
    echo "Deleting old version $n"
    cmds+="rm -r -f $APP_DIR/releases/$n; rm -r -f $WEB_DIR/v/$n; "
  done
  # Cleanup is no reason to fail an otherwise successful deployment
  [ -z "$cmds" ] || ftp "$cmds" || echo "::warning::Cleanup incomplete – will be retried on the next deployment"
}

# Delete every version except the active one, e.g. after changing secrets (database or SMTP password,
# APP_SECRET): older versions still contain the old values in their config.php. No rollback afterwards.
prune() {
  local active all n cmds=''
  active=$(current)
  all=$(releases)
  [ -n "$active" ] && grep -qxF "$active" <<< "$all" || die "Active version ${active:-?} not found on the server – nothing deleted"
  for n in $all; do
    [ "$n" = "$active" ] && continue
    echo "Deleting version $n"
    cmds+="rm -r -f $APP_DIR/releases/$n; rm -r -f $WEB_DIR/v/$n; "
  done
  if [ -n "$cmds" ]; then ftp "$cmds"; fi
  echo "Only the active version is left: $active"
}

# Delete a version (never the active one). Afterwards app_root.php can no longer pick it as a fallback either.
discard() {
  local name=$1
  [ "$name" != "$(current)" ] || die "$name is active and will not be deleted"
  ftp "rm -r -f $APP_DIR/releases/$name; rm -r -f $WEB_DIR/v/$name"
  echo "Deleted: $name"
}

# Switch back to the previous version and delete the failed one (on the very first deployment there
# is no previous one: the site then stays without a version until a working one has been uploaded)
revert() {
  local prev=$1 failed=$2
  if [ -n "$prev" ]; then
    set_current "$prev"
    echo "::error::Check failed – switched back to $prev"
  else
    ftp "rm -f $APP_DIR/current"
    echo "::error::Check failed – first deployment, no previous version"
  fi
  discard "$failed" || true
}

cmd=${1:-}
name=${2:-}
case "$cmd" in
  upload)
    [[ $name =~ $NAME_RX ]] || die "Version name missing or invalid"
    [ -d "$BUILD_DIR/app/releases/$name" ] && [ -d "$BUILD_DIR/web/v/$name" ] || die "No build for $name in $BUILD_DIR"
    ftp "mirror -R --no-perms --parallel=2 --verbose=1 $BUILD_DIR/app/releases/$name $APP_DIR/releases/$name;
      mirror -R --no-perms --verbose=1 $BUILD_DIR/app/app/var $APP_DIR/app/var;
      mirror -R --no-perms --parallel=2 --verbose=1 $BUILD_DIR/web/v/$name $WEB_DIR/v/$name;
      put $BUILD_DIR/web/v/.htaccess -o $WEB_DIR/v/.htaccess"
    # Completeness: all files of the build must be on the server
    (cd "$BUILD_DIR/app/releases/$name" && find . -type f | sed 's#^\./#app/#') > "$TMP/local"
    (cd "$BUILD_DIR/web/v/$name" && find . -type f | sed 's#^\./#web/#') >> "$TMP/local"
    { ftp "find $APP_DIR/releases/$name" | grep -v '/$' | sed "s#^$APP_DIR/releases/$name/#app/#"
      ftp "find $WEB_DIR/v/$name" | grep -v '/$' | sed "s#^$WEB_DIR/v/$name/#web/#"; } > "$TMP/remote"
    missing=$(comm -23 <(LC_ALL=C sort "$TMP/local") <(LC_ALL=C sort "$TMP/remote"))
    [ -z "$missing" ] || die "Files missing on the server: $(head -5 <<< "$missing" | tr '\n' ' ')"
    # config.php holds the database and SMTP passwords and APP_SECRET; mirror --no-perms leaves its
    # permissions to the FTP server (often 644, readable for every user of a shared server)
    if [ "$CONFIG_MODE" != keep ]; then
      ftp "chmod $CONFIG_MODE $APP_DIR/releases/$name/config/config.php" && echo "config.php: permissions $CONFIG_MODE" \
        || echo "::warning::Could not set the permissions of config.php to $CONFIG_MODE (no chmod on this FTP server?). Please check them; the admin area warns if every user of the server can read the file."
    fi
    echo "Uploaded and complete: $name ($(wc -l < "$TMP/local") files) – not active yet"
    ;;

  activate)
    [[ $name =~ $NAME_RX ]] || die "Version name missing or invalid"
    all=$(releases)
    grep -qxF "$name" <<< "$all" || die "Version $name is not on the server"
    prev=$(current)
    set_current "$name"
    echo "Switched: ${prev:-(none)} → $name"
    # Fixed files in the web directory: first app_root.php (atomically), then index.php, .htaccess etc.
    # (rarely change; on the first deployment index.php already needs app_root.php)
    put_atomic "$BUILD_DIR/web/app_root.php" "$WEB_DIR/app_root.php"
    ftp "mirror -R --no-perms --verbose=1 -x '^v/' -x '^app_root\\.php\$' $BUILD_DIR/web $WEB_DIR"
    if ! check "$name"; then
      revert "$prev" "$name"
      [ "$CONFIG_MODE" = keep ] || echo "If the new version failed with HTTP 500, PHP may run as a different user than FTP and cannot read config.php with permissions $CONFIG_MODE: then set CONFIG_MODE to 640 or keep (see the deployment guide)."
      exit 1
    fi
    cleanup "$name"
    ;;

  switch)
    [ -n "$name" ] || die "Target missing: version name or previous"
    all=$(releases)
    active=$(current)
    if [ "$name" = previous ]; then
      name=$(printf '%s\n' "$all" | awk -v a="$active" '$0 < a' | tail -n 1)
      [ -n "$name" ] || die "No version older than ${active:-?} available"
    fi
    [[ $name =~ $NAME_RX ]] && grep -qxF "$name" <<< "$all" || die "Version $name is not on the server. Available: $(tr '\n' ' ' <<< "$all")"
    [ "$name" != "$active" ] || { echo "$name is already active"; exit 0; }
    set_current "$name"
    echo "Switched: ${active:-?} → $name"
    if ! check "$name"; then
      [ -n "$active" ] && set_current "$active"
      die "Check failed – switched back to ${active:-?}"
    fi
    ;;

  discard)
    [[ $name =~ $NAME_RX ]] || die "Version name missing or invalid"
    discard "$name"
    ;;

  prune)
    prune
    ;;

  list)
    active=$(current)
    releases | while read -r n; do
      [ "$n" = "$active" ] && echo "* $n (active)" || echo "  $n"
    done
    ;;

  *)
    sed -n '2,/^set -euo pipefail$/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//'
    exit 2
    ;;
esac
