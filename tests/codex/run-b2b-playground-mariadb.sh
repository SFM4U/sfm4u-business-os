#!/bin/sh
set -eu

if [ "$#" -ne 4 ]; then
    echo 'Usage: run-b2b-playground-mariadb.sh CLI WORDPRESS_DIRECTORY PLUGIN_ZIP_DIRECTORY EVIDENCE_DIRECTORY' >&2
    exit 2
fi

cli=$1
site=$(cd "$2" && pwd)
archives=$(cd "$3" && pwd)
out=$(cd "$4" && pwd)
root=$(cd "$(dirname "$0")/.." && pwd)

if [ -e "$out/mysql-scenarios.txt" ]; then
    echo 'Use an evidence directory without an existing mysql-scenarios.txt.' >&2
    exit 2
fi

node "$root/tests/playground-mariadb-proxy.cjs" &
proxy_pid=$!
trap 'kill "$proxy_pid" 2>/dev/null || true' EXIT
trap 'exit 1' HUP INT TERM
# CLI startup and mounting take longer than binding the local listener.
sleep 1
kill -0 "$proxy_pid"
"$cli" run-blueprint --site-url=http://127.0.0.1:18943 \
    --wordpress-install-mode=do-not-attempt-installing --skip-sqlite-setup \
    --blueprint="$root/tests/b2b-playground-mariadb-blueprint.json" \
    --mount-before-install="$site:/wordpress" \
    --mount="$archives:/archives" --mount="$root/modules:/modules" \
    --mount="$root/tests:/tests" --mount="$root/plugin:/source" \
    --mount="$out:/fixture-out"
rg -q '^HPOS integration passed$' "$out/mysql-scenarios.txt"
rg -q '^Settlement and dashboard integration passed$' "$out/mysql-scenarios.txt"
