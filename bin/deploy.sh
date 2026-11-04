#!/usr/bin/env bash
# Atomic deployment via FTPS (lftp): every version is uploaded completely into its own directory
# and only then made active by replacing the pointer file "current".
# Visitors therefore never see a half-uploaded mix of the old and the new version.
#
#   bin/deploy.sh upload <name>          upload a version from build/ (not active yet), restrict access to
#                                         its config.php, verify that all files have arrived
#   bin/deploy.sh activate <name>        switch, update the fixed files in the web directory, check the site
#                                         (otherwise switch back at once), delete old versions, restrict
#                                         access to the config.php of the remaining ones
#   bin/deploy.sh switch <name|previous> switch to an existing version (rollback)
#   bin/deploy.sh discard <name>         delete an uploaded version that was never activated
#   bin/deploy.sh prune                  delete all versions except the active one (after changing
#                                         secrets: older versions keep the old ones in their config.php)
#   bin/deploy.sh list                   existing versions, the active one marked with *
#
# Environment: FTP_URL (e.g. ftp://ftp.example.org), APP_DIR, WEB_DIR (relative to the FTP root;
# APP_DIR outside the web root), FTP_USER and LFTP_PASSWORD, BASE_URL (for the check), APP_SECRET (optional:
# with it the check also asks the health check about database and migrations; needs openssl), KEEP (versions kept, default 5),
# BUILD_DIR (default build), CONFIG_MODE (permissions of config.php after the upload, default 600;
# 640 if PHP reads it through the group, keep = leave them to the server; never readable for others).
# Server layout: README, section "Deployment".
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
# The owner (PHP) must read it, others never: 644 or 666 would silently undo the protection (keep is the
# deliberate exception, the admin area then warns if needed)
[[ $CONFIG_MODE =~ ^(0?[4-7][0-7]0|keep)$ ]] || die "CONFIG_MODE must be 600, 640 (owner may read, others may not) or keep"
FTP_USER=$(printf '%s' "${FTP_USER:-}" | tr -d '\r\n')
# For the health check (like build-config.php: without line breaks and surrounding spaces)
SECRET=$(printf '%s' "${APP_SECRET:-}" | tr -d '\r\n' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
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

# Set CONFIG_MODE on the config.php of the given versions. It holds the database and SMTP passwords and
# APP_SECRET; mirror --no-perms leaves its permissions to the FTP server (often 644, readable for every user
# of a shared server). One lftp call: chmod goes on after a file it cannot change, and a failure only warns.
protect_config() {
  local n files=''
  [ "$CONFIG_MODE" != keep ] && [ $# -gt 0 ] || return 0
  for n in "$@"; do files+=" $APP_DIR/releases/$n/config/config.php"; done
  if ftp "chmod $CONFIG_MODE$files"; then
    echo "config.php: permissions $CONFIG_MODE in $*"
  else
    echo "::warning::Could not set the permissions of config.php to $CONFIG_MODE in $* (no chmod on this FTP server?). Please check them in $APP_DIR/releases/<version>/config/config.php; the admin area warns while every user of the server can read one of them."
  fi
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

# Health check of the active version (POST /health, App\Health): database reachable, MariaDB in the minimum
# version, every migration applied. The key is derived from APP_SECRET like the web cron's and sent in a
# header (not in the URL, so it never reaches an access log; read by curl from a file, so it is not on its
# command line either). Prints the status word, or http_<code> (404: this version has no health check, or
# its config.php has another APP_SECRET).
health() {
  local base=$1 code
  printf 'X-Health-Token: %s\n' "$(printf health | openssl dgst -sha256 -hmac "$SECRET" | sed 's/.*= //')" > "$TMP/health.h"
  code=$(curl -sS -m 60 -o "$TMP/health" -w '%{http_code}' -d '' -H @"$TMP/health.h" "$base/health") || code=000
  rm -f "$TMP/health.h"
  case "$code" in
    200 | 503) sed -n 's/^{"status":"\([a-z_]*\)"}$/\1/p' "$TMP/health" | grep . || echo "http_$code" ;;
    *) echo "http_$code" ;;
  esac
}

# What a failed health check means, for the log of the run
health_hint() {
  case "$1" in
    db_unreachable) echo "the version cannot connect to the database (check DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS)" ;;
    db_unsupported) echo "the database is not MariaDB 10.6 or newer (MySQL is not supported)" ;;
    migration_failed) echo "a database migration failed (details in the alert mail and in app/var/php-error.log)" ;;
    schema_outdated) echo "the database migrations did not finish during the check (another request may still be running them)" ;;
    http_404) echo "the new version does not answer the health check: is APP_SECRET the one its config.php was built with?" ;;
    *) echo "unexpected answer of the health check ($1)" ;;
  esac
}

# After switching, checks that the site responds, really serves the new version (asset path in the HTML),
# that its CSS is reachable and, with APP_SECRET, that it works with the database (health check).
# strict: the version must have the health check (activate: it was built from this code). Otherwise
# (switch) an older version without it, or one with an older APP_SECRET, gets the page checks only.
check() {
  local name=$1 strict=${2:-} base=${BASE_URL:-} html i
  CHECK_STATUS='' # empty: the pages failed; otherwise the answer of the health check
  base=${base%/}
  if [ -z "$base" ]; then echo "::warning::BASE_URL missing – check skipped"; return 0; fi
  if [ -z "$SECRET" ]; then
    echo "::warning::APP_SECRET missing – database and migrations are not checked, only the pages"
  elif ! command -v openssl > /dev/null; then
    echo "::warning::openssl missing – database and migrations are not checked, only the pages"; SECRET=''
  fi
  for i in 1 2 3 4 5 6; do
    if html=$(curl -fsS -m 30 "$base/admin/login") && grep -qF "/v/$name/css/app.css" <<< "$html" \
      && curl -fsS -m 30 -o /dev/null "$base/v/$name/css/app.css" \
      && curl -fsS -m 30 -o /dev/null "$base/"; then
      CHECK_STATUS=ok
      [ -z "$SECRET" ] || CHECK_STATUS=$(health "$base")
      case "$CHECK_STATUS" in
        ok | not_installed)
          echo "Check ok: $base serves $name$([ -z "$SECRET" ] || echo " (database: $CHECK_STATUS)")"
          return 0 ;;
        http_404)
          if [ -z "$strict" ]; then
            echo "::warning::$name has no health check (older version) or another APP_SECRET – only the pages were checked"
            echo "Check ok: $base serves $name"
            return 0
          fi
          break ;; # the same answer on every try
      esac
    fi
    sleep 5
  done
  echo "::error::Check of $name failed: $(if [ -n "$CHECK_STATUS" ]; then health_hint "$CHECK_STATUS"; else echo "login page, start page or CSS of this version not served"; fi)"
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
    # The code first and right after it the permissions of config.php, so that others can read it as briefly
    # as possible (FTP cannot set them before the upload)
    ftp "mirror -R --no-perms --parallel=2 --verbose=1 $BUILD_DIR/app/releases/$name $APP_DIR/releases/$name"
    protect_config "$name"
    ftp "mirror -R --no-perms --verbose=1 $BUILD_DIR/app/app/var $APP_DIR/app/var;
      mirror -R --no-perms --parallel=2 --verbose=1 $BUILD_DIR/web/v/$name $WEB_DIR/v/$name;
      put $BUILD_DIR/web/v/.htaccess -o $WEB_DIR/v/.htaccess"
    # Completeness: all files of the build must be on the server
    (cd "$BUILD_DIR/app/releases/$name" && find . -type f | sed 's#^\./#app/#') > "$TMP/local"
    (cd "$BUILD_DIR/web/v/$name" && find . -type f | sed 's#^\./#web/#') >> "$TMP/local"
    { ftp "find $APP_DIR/releases/$name" | grep -v '/$' | sed "s#^$APP_DIR/releases/$name/#app/#"
      ftp "find $WEB_DIR/v/$name" | grep -v '/$' | sed "s#^$WEB_DIR/v/$name/#web/#"; } > "$TMP/remote"
    missing=$(comm -23 <(LC_ALL=C sort "$TMP/local") <(LC_ALL=C sort "$TMP/remote"))
    [ -z "$missing" ] || die "Files missing on the server: $(head -5 <<< "$missing" | tr '\n' ' ')"
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
    if ! check "$name" strict; then
      revert "$prev" "$name"
      [ "$CONFIG_MODE" = keep ] || [ -n "$CHECK_STATUS" ] || echo "If the new version failed with HTTP 500, PHP may run as a different user than FTP and cannot read config.php with permissions $CONFIG_MODE: then set CONFIG_MODE to 640 or keep (see the deployment guide)."
      exit 1
    fi
    cleanup "$name"
    # The older versions have the same secrets in their config.php (also those uploaded before CONFIG_MODE
    # existed). Only after the check: PHP has then read the new one with CONFIG_MODE, so a rollback still works.
    [ -z "${BASE_URL:-}" ] || protect_config $(releases)
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
