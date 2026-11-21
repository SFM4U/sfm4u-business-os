#!/bin/bash
set -e
SRC=/home/claude/wh/sfm4u-wholesale; OUT=/home/claude/wh/receiver-build/out/sfm4u-stock-receiver
VER=$(grep -m1 "Version:" /home/claude/wh/receiver-build/sfm4u-stock-receiver.php | awk '{print $3}')
rm -rf /home/claude/wh/receiver-build/out && mkdir -p "$OUT/src/Members" "$OUT/src/Ops"
cp /home/claude/wh/receiver-build/sfm4u-stock-receiver.php "$OUT/"
cp "$SRC"/src/Members/{Members,CardLink,Tiers,MemberLink}.php "$OUT/src/Members/"
mkdir -p "$OUT/src/Market" && cp "$SRC"/src/Market/{MarketConnect,VendorProfit}.php "$OUT/src/Market/"
cp "$SRC"/src/Ops/{StockFeed,StockFeedAdmin}.php "$OUT/src/Ops/"
cp "$SRC"/COPYING "$OUT/" 2>/dev/null || true
cd /home/claude/wh/receiver-build/out && rm -f "/home/claude/SFM4U-Stock-Receiver-$VER.zip" && zip -qr "/home/claude/SFM4U-Stock-Receiver-$VER.zip" sfm4u-stock-receiver
echo "/home/claude/SFM4U-Stock-Receiver-$VER.zip"
