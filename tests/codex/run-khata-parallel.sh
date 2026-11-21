#!/bin/sh
set -eu

wp() {
    if [ -n "${SFM4U_TEST_WP:-}" ]; then
        "$SFM4U_TEST_WP" "$@"
    else
        php /usr/local/bin/wp --allow-root "$@"
    fi
}

fixture="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)/counter-khata-parallel.php"
barrier="$(mktemp -d /fixture-out/khata-test.XXXXXX)"
workers=''
cleanup() {
    for pid in $workers; do
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    done
    rm -f "$barrier"/seed.json "$barrier"/ready-0 "$barrier"/ready-1 "$barrier"/go "$barrier"/result-0 "$barrier"/result-1
    rmdir "$barrier"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

wp eval-file "$fixture" seed "$barrier"
for index in 0 1; do
    wp eval-file "$fixture" worker "$barrier" "$index" &
    workers="$workers $!"
done

attempt=0
while [ ! -f "$barrier/ready-0" ] || [ ! -f "$barrier/ready-1" ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 25 ]; then
        printf '%s\n' 'Timed out waiting for both khata workers.' >&2
        exit 1
    fi
    sleep 1
done
touch "$barrier/go"
failed=0
for pid in $workers; do
    wait "$pid" || failed=1
done
workers=''
[ "$failed" -eq 0 ]
wp eval-file "$fixture" verify "$barrier"
