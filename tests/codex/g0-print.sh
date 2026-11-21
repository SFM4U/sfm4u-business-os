#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
chrome='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
[ -x "$chrome" ]
for paper in 80mm a4; do
    docker compose -p sfmwh-local -f .fixture/compose.yaml exec -T wp \
        php /usr/local/bin/wp --allow-root eval-file /tests/g0-receipt.php "$paper" > ".fixture/g0-receipt-$paper.html"
    "$chrome" --headless --disable-background-networking --no-pdf-header-footer \
        --user-data-dir="$PWD/.fixture/g0-chrome-$paper" --print-to-pdf="$PWD/.fixture/g0-receipt-$paper.pdf" \
        "file://$PWD/.fixture/g0-receipt-$paper.html"
    swift tests/verify-receipt.swift ".fixture/g0-receipt-$paper.pdf" "$paper"
done
