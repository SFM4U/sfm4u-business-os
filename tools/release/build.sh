#!/bin/bash
# Build installable ZIPs + SHA-256 from this repo (no upload, no live change).
# Usage: tools/release/build.sh            -> dist/SFM4U-BusinessOS-sfm4u-wholesale-<ver>.zip, dist/SFM4U-Stock-Receiver-<ver>.zip, dist/SHA256SUMS
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"; OUT="$REPO/dist"; TMP="$(mktemp -d)"
rm -rf "$OUT" && mkdir -p "$OUT"
VER=$(grep -m1 "Version:" "$REPO/wh/sfm4u-wholesale/sfm4u-wholesale.php" | awk '{print $3}')
CONST=$(grep -m1 -oE "SFMWH_VERSION', '[^']+'" "$REPO/wh/sfm4u-wholesale/sfm4u-wholesale.php" | sed "s/.*'\(.*\)'/\1/" || true)
if [ -n "$CONST" ] && [ "$CONST" != "$VER" ]; then echo "Version header $VER != constant $CONST"; exit 1; fi
cp -r "$REPO/wh/sfm4u-wholesale" "$TMP/"
(cd "$TMP" && find sfm4u-wholesale -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null && TZ=UTC zip -qrX "$OUT/SFM4U-BusinessOS-sfm4u-wholesale-$VER.zip" sfm4u-wholesale)
# AlgiBazar Connector (receiver) from the same source files
R="$TMP/sfm4u-stock-receiver"; RV=$(grep -m1 "Version:" "$REPO/wh/receiver-build/sfm4u-stock-receiver.php" | awk '{print $3}')
mkdir -p "$R/src/Members" "$R/src/Market" "$R/src/Ops"
cp "$REPO/wh/receiver-build/sfm4u-stock-receiver.php" "$R/"
cp "$REPO"/wh/sfm4u-wholesale/src/Members/{Members,CardLink,Tiers,MemberLink}.php "$R/src/Members/"
cp "$REPO"/wh/sfm4u-wholesale/src/Market/{MarketConnect,VendorProfit}.php "$R/src/Market/"
cp "$REPO"/wh/sfm4u-wholesale/src/Ops/{StockFeed,StockFeedAdmin}.php "$R/src/Ops/"
cp "$REPO/wh/sfm4u-wholesale/COPYING" "$R/" 2>/dev/null || true
(cd "$TMP" && TZ=UTC zip -qrX "$OUT/SFM4U-Stock-Receiver-$RV.zip" sfm4u-stock-receiver)
(cd "$OUT" && sha256sum *.zip > SHA256SUMS && cat SHA256SUMS)
echo "source commit: $(git -C "$REPO" rev-parse HEAD)$(git -C "$REPO" diff --quiet || echo ' (+uncommitted changes!)')"
rm -rf "$TMP"
