#!/usr/bin/env bash
# The previous release against the schema of the current code (expert review P2-5): what "Switch version" does
# after an update. It switches the code, not the database, so the older code must work with the newer schema.
#   dev/reset.sh && dev/demo.sh && tests/n-1.sh <release tag>
# The release runs with php -S inside the app container on the database of the Docker environment; tests/n-1.php
# signs in, books, moves and cancels, asks the health check and reads the error log of that release.
set -uo pipefail
cd "$(dirname "$0")/.."
TAG=${1:?usage: tests/n-1.sh <release tag>}
DC="docker compose -f dev/compose.yml"
DIR=/tmp/talkslot-n1
PORT=8094
echo "Previous release $TAG on the schema of this version"
git archive "$TAG" app bin public config | $DC exec -T app sh -c "rm -rf $DIR && mkdir -p $DIR && tar -x -C $DIR && mkdir -p $DIR/app/var" || { echo "  ✗ release $TAG cannot be unpacked"; exit 1; }
$DC exec -T app php -r '$c = require "/srv/app/config/config.php"; $c["base_url"] = $argv[1]; $c["force_https"] = false; $c["debug"] = false; file_put_contents($argv[2], "<?php\nreturn " . var_export($c, true) . ";\n");' "http://127.0.0.1:$PORT" "$DIR/config/config.php"
$DC exec -T -w "$DIR/public" app sh -c "php -S 127.0.0.1:$PORT -t $DIR/public $DIR/public/index.php > $DIR/server.log 2>&1 & S=\$!; i=0; until curl -s -o /dev/null http://127.0.0.1:$PORT/ || [ \$i -ge 50 ]; do i=\$((i + 1)); sleep 0.2; done; php /srv/app/tests/n-1.php http://127.0.0.1:$PORT $DIR; rc=\$?; kill \$S; exit \$rc"
