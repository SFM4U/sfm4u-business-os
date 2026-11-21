#!/bin/sh
set -eu
wp() {
    if [ -n "${SFM4U_TEST_WP:-}" ]; then
        "$SFM4U_TEST_WP" "$@"
    else
        php /usr/local/bin/wp --allow-root "$@"
    fi
}
fault=${1:-process}
seed=$(wp eval-file /tests/g2-authorized-recovery.php seed)
# The fixture seed emits only IDs and request keys.
set -- $seed
barrier=$(mktemp -d "${SFM4U_TEST_BARRIERS:-${TMPDIR:-/tmp}}/sfm4u-test.XXXXXX")
interrupt() {
    phase="$1"; mode="$2"; shift 2
    (
        if [ -n "${SFM4U_TEST_WP:-}" ]; then
            exec "$SFM4U_TEST_WP" eval-file /tests/g2-authorized-recovery.php "$mode" "$@" "$barrier"
        fi
        exec php /usr/local/bin/wp --allow-root eval-file /tests/g2-authorized-recovery.php "$mode" "$@" "$barrier"
    ) &
    worker=$!
    count=0
    while [ ! -s "$barrier/$phase" ]; do
        if ! kill -0 "$worker" 2>/dev/null; then wait "$worker"; exit 1; fi
        count=$((count+1))
        if [ "$count" -ge 45 ]; then kill "$worker"; exit 1; fi
        sleep 1
    done
    kill -KILL "$worker"
    if wait "$worker"; then exit 1; fi
}
if [ "$fault" = socket ]; then
    wp eval-file /tests/g2-authorized-recovery.php lose-socket "$@" "$barrier"
else
    interrupt pre lose-before "$@"
fi
wp eval-file /tests/g2-authorized-recovery.php retry-before "$@" "$barrier"
interrupt post lose-after "$@"
wp eval-file /tests/g2-authorized-recovery.php recover-and-credit "$@" "$barrier"
