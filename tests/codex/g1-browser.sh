#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
umask 077
input=.fixture/g1-browser-input.json
trap 'rm -f "$input"' EXIT
docker compose -p sfmwh-local -f .fixture/compose.yaml exec -T wp \
    php /usr/local/bin/wp --allow-root eval-file /tests/g1-browser-seed.php > "$input"
node tests/g1-browser.mjs
