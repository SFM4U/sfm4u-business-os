#!/bin/sh
set -eu
wp() {
    if [ -n "${SFM4U_TEST_WP:-}" ]; then
        "$SFM4U_TEST_WP" "$@"
    else
        php /usr/local/bin/wp --allow-root "$@"
    fi
}
for run in 1 2 3; do
    read -r product web pos <<END
$(wp eval-file /tests/g0-stock-setup.php)
END
    barrier=$(mktemp -d "${SFM4U_TEST_BARRIERS:-${TMPDIR:-/tmp}}/sfm4u-test.XXXXXX")
    wp eval-file /tests/g0-stock-worker.php "$web" "$barrier" web > "$barrier/web-result" &
    web_worker=$!
    wp eval-file /tests/g0-stock-worker.php "$pos" "$barrier" pos > "$barrier/pos-result" &
    pos_worker=$!
    count=0
    while [ ! -f "$barrier/web" ] || [ ! -f "$barrier/pos" ]; do
        count=$((count+1))
        [ "$count" -lt 30 ]
        sleep 1
    done
    touch "$barrier/go"
    wait "$web_worker"
    wait "$pos_worker"
    cat "$barrier/web-result" "$barrier/pos-result"
    if grep -qx 'RESERVED web' "$barrier/web-result" && grep -q '^CONFLICT pos ' "$barrier/pos-result"; then
        winner="$web"
    elif grep -qx 'RESERVED pos' "$barrier/pos-result" && grep -q '^CONFLICT web ' "$barrier/web-result"; then
        winner="$pos"
    else
        exit 1
    fi
    wp eval-file /tests/g0-stock-verify.php "$product" "$winner"
done
