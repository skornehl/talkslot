#!/usr/bin/env bash
# Updates the bundled libraries in app/vendor/ to the versions listed in composer.lock.
#
# TalkSlot does not run Composer; composer.json/composer.lock only exist so that Dependabot can
# report new versions and security advisories. When Dependabot raises the version in composer.lock,
# run this script on the pull request branch and commit the result:
#
#   bin/update-vendor.sh && php tests/unit.php
#
# Needs curl, tar, sha256sum and php. Only the files TalkSlot uses are copied (PHPMailer, SMTP, Exception),
# with their SHA-256 checksums in app/vendor/phpmailer/SHA256SUMS.
set -euo pipefail
cd "$(dirname "$0")/.."

lock_field() { # lock_field <package> <field>
  php -r '
    $lock = json_decode(file_get_contents("composer.lock"), true, flags: JSON_THROW_ON_ERROR);
    foreach ($lock["packages"] as $p) {
        if ($p["name"] === $argv[1]) { echo $argv[2] === "version" ? $p["version"] : $p["source"]["reference"]; exit(0); }
    }
    fwrite(STDERR, "$argv[1] not found in composer.lock\n"); exit(1);
  ' "$1" "$2"
}

version=$(lock_field phpmailer/phpmailer version)   # e.g. v7.1.1
commit=$(lock_field phpmailer/phpmailer reference)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

echo "PHPMailer $version ($commit)"
curl -fsSL "https://github.com/PHPMailer/PHPMailer/archive/$commit.tar.gz" | tar -xz -C "$tmp" --strip-components=1

dest=app/vendor/phpmailer
files=(src/PHPMailer.php src/SMTP.php src/Exception.php LICENSE VERSION)
mkdir -p "$dest/src"
for f in "${files[@]}"; do
  cp "$tmp/$f" "$dest/$f"
done
# Checksums of exactly these files: the unit tests compare them without network access, the CI job "Static
# analysis" runs this script again and expects no change (expert review P1-6)
(cd "$dest" && sha256sum "${files[@]}" > SHA256SUMS)
echo "Updated $dest to $(cat "$dest/VERSION")"
