#!/bin/bash
# Build the standalone "SFM4U Membership Card" plugin from the Wholesale source (one source of truth).
set -e
SRC=/home/claude/wh/sfm4u-wholesale
OUT=/home/claude/wh/member-card-build/out/sfm4u-member-card
rm -rf "$OUT" && mkdir -p "$OUT/src/Members" "$OUT/assets" "$OUT/languages" "$OUT/docs"
cp /home/claude/wh/member-card-build/sfm4u-member-card.php "$OUT/"
cp "$SRC"/src/Members/{Members,Barcode,BdMapData,BdMap,CardView,CardGuide,CardLink,Receipt,MembersAdmin,MembersFront,Rewards,Tiers,MemberLink}.php "$OUT/src/Members/"
cp "$SRC"/assets/{members.js,members.css,qrcode.js,zxing.min.js,zxing.LICENSE,sfm4u-logo-round.jpg,sfm4u-logo-wide.jpg,sfm4u-logo-watermark.png,sample-person.jpg,sample-signature.png,card-guide.css} "$OUT/assets/"
cp "$SRC"/languages/sfm4u-wholesale-bn_BD.l10n.php "$OUT/languages/"
cp "$SRC"/docs/CARD-SPEC.md "$OUT/docs/"
cp /home/claude/wh/member-card-build/readme.txt "$OUT/" 2>/dev/null || true
VER=$(grep -m1 "Version:" "$OUT/sfm4u-member-card.php" | sed 's/.*Version: *//')
cd /home/claude/wh/member-card-build/out && rm -f "/home/claude/SFM4U-Membership-Card-$VER.zip" && zip -qr "/home/claude/SFM4U-Membership-Card-$VER.zip" sfm4u-member-card
echo "/home/claude/SFM4U-Membership-Card-$VER.zip"
