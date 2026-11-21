#!/bin/sh
set -eu
wp() {
    if [ -n "${SFM4U_TEST_WP:-}" ]; then
        "$SFM4U_TEST_WP" "$@"
    else
        php /usr/local/bin/wp --allow-root "$@"
    fi
}
seed=$(wp eval-file /tests/g2-pos-concurrent.php seed)
# The fixture seed emits only IDs and request keys.
set -- $seed
merchant="$1"
shift
barrier=$(mktemp -d "${SFM4U_TEST_BARRIERS:-${TMPDIR:-/tmp}}/sfm4u-test.XXXXXX")
pids=''
worker=0
for product in "$@" "$1"; do
 worker=$((worker + 1))
 (
  attempt=1
  while [ "$attempt" -le 6 ]; do
   result=$(wp eval-file /tests/g2-pos-concurrent.php sale "$merchant" "$product" "$barrier" "$worker")
   if [ "$result" != RETRY ]; then
    printf '%s\n' "$result" > "$barrier/result-$worker"
    exit 0
   fi
   printf 'Retrying product %s with the original request key\n' "$product" >&2
   attempt=$((attempt + 1))
   sleep 1
  done
  exit 1
 ) &
 pids="$pids $!"
done
for worker in 1 2 3 4 5; do
 count=0
 while [ ! -f "$barrier/$worker" ]; do
  count=$((count+1))
  [ "$count" -lt 30 ]
  sleep 1
 done
done
touch "$barrier/go"
for pid in $pids; do wait "$pid"; done
test -s "$barrier/result-1"
cmp "$barrier/result-1" "$barrier/result-5"
echo "PASS overlapping same-key workers received the same receipt"
wp eval-file /tests/g2-pos-concurrent.php verify "$merchant" "$@"
