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
    read -r product retail wholesale pos <<END
$(wp eval-file /tests/g0-stock-setup.php three)
END
    barrier=$(mktemp -d "${SFM4U_TEST_BARRIERS:-${TMPDIR:-/tmp}}/sfm4u-test.XXXXXX")
    wp eval-file /tests/g0-stock-worker.php "$retail" "$barrier" retail > "$barrier/retail-result" &
    retail_worker=$!
    wp eval-file /tests/g0-stock-worker.php "$wholesale" "$barrier" wholesale > "$barrier/wholesale-result" &
    wholesale_worker=$!
    wp eval-file /tests/g0-stock-worker.php "$pos" "$barrier" pos > "$barrier/pos-result" &
    pos_worker=$!
    count=0
    while [ ! -f "$barrier/retail" ] || [ ! -f "$barrier/wholesale" ] || [ ! -f "$barrier/pos" ]; do
        count=$((count+1))
        [ "$count" -lt 30 ]
        sleep 1
    done
    touch "$barrier/go"
    wait "$retail_worker"
    wait "$wholesale_worker"
    wait "$pos_worker"
    cat "$barrier/retail-result" "$barrier/wholesale-result" "$barrier/pos-result" > "$barrier/results"
    cat "$barrier/results"
    [ "$(grep -c '^RESERVED ' "$barrier/results")" = 1 ]
    [ "$(grep -c '^CONFLICT .*woocommerce_product_not_enough_stock' "$barrier/results")" = 2 ]
    case "$(awk '/^RESERVED/ {print $2}' "$barrier/results")" in
        retail) winner="$retail" ;;
        wholesale) winner="$wholesale" ;;
        pos) winner="$pos" ;;
        *) exit 1 ;;
    esac
    wp eval-file /tests/g0-stock-verify.php "$product" "$winner"
done
