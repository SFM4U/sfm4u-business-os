# SFM4U Membership Card — layout and sizes

Owner-approved design (2026-11-20, build 0.4.6). Front text is always English; back text is always Bangla. Every card type uses the same design; only the type label changes.

| Part | Real size | Position / notes |
|---|---|---|
| Card (CR80, ISO/IEC 7810 ID-1) | 85.6 × 54 mm | 1012 × 638 px design canvas (≈300 dpi, 11.82 px/mm); printed with @page 85.6×54 mm, scale .3197 |
| Screen preview | 404.8 × 255.2 px | canvas scaled ×0.4 |
| Top / bottom colour bar | full width × 0.8 mm | 10 px, colour #22e3c3 (same on every card type) |
| Logo (round) | 9.1 mm × 9.1 mm | left 52 px, top 34 px (108 px, enlarged in 0.4.8) |
| "SFM4U" text | 3.7 mm high | 44 px Space Grotesk bold; "Membership Card" 21 px |
| Card-type badge | height ≈ 4.1 mm | right 56 px, top 52 px; text MEMBER / PREMIUM MEMBER / BUSINESS MEMBER, 22 px |
| Photo | 19.5 mm × 24.5 mm | left 56 px, top 176 px, 3 px border, 22 px corners |
| Name column | starts 27.9 mm from left, ends 20.0 mm from right | left 330 px; labels 21 px; big name 46 px (max 2 lines); owner 34 px; father/DOB 26 px |
| Bangladesh map (front) | 14.5 mm × 20.0 mm | right 44 px, top 128 px; thin district lines (1.2 px, 50% white), member district green; member photo faint inside the map (40%, grey; only when a real photo is uploaded) |
| Bottom line (front) | — | bottom 44 px: NID left (30 px), "member since" date right (26 px) |
| Back header bar | full width × 6.3 mm | black; "SFM4U · type" left, "কার্ড নং 0000001" right |
| Back details grid | 58.0 mm wide | left 56 px, top 100 px, right 270 px; 25 px text; label column 210 px |
| District map (back) | 14.4 mm × 16.9 mm | right side, top 110 px; flag green #006a4e, red disc #f42a41; district name in Bangla below (26 px) |
| Barcode box | 47.4 mm × 10.2 mm | left 56 px, bottom 112 px; Code128 of the 7-digit member number; number below, 28 px |
| Lost-card note | 25.4 mm wide | right 56 px, bottom 64 px; 18 px |
| Member signature (front) | 19.5 mm × 6.1 mm | left 56 px, top 478 px, under the photo; drawn on phone/tab or uploaded image, stored as small PNG in user meta (not in the public media library), shown in white |
| Data-source line (back) | — | 15 px, under the lost-card note, only when an NID is linked: data taken from the member's NID with consent; not a government ID |

Colours: background #050807, accent #22e3c3, text #f2f5f4, labels #9fb0ab; back background #f4f7f6.
Fonts: Space Grotesk (front, numbers), Hind Siliguri / Noto Sans Bengali (Bangla).
Map data: Bangladesh Bureau of Statistics / OCHA ROAP via geoBoundaries (CC BY 3.0 IGO), simplified by tools/gen-bdmap.py.
Source of truth: src/Members/CardView.php (css()) and src/Members/BdMap.php.

## Changes in build 0.4.7 (2026-11-20)
- Front right: the member's home district as a thin outline only (no fill, no flag design, no name text — owner removed the name in 0.4.8). If no district is known, a thin outline of Bangladesh. Box 180 × 250 px (15.2 × 21.1 mm), right 40 px, top 122 px.
- Back right: Bangladesh map with the member's photo faintly inside (55%, grey) and the home district in green; district name in Bangla below. Map 168 × 232 px (14.2 × 19.6 mm), column right 50 px, top 92 px.
- Back details (Bangla): name, father, mother, address, mobile, email (+ shop and shop address for business cards). Grid left 56 px, top 94 px, right 262 px, 22 px text, label column 150 px, max height 300 px.
- Owner choice: address, mobile and email are printed on the back so a finder can return a lost card. Anyone holding the card can read them.

## Changes in build 0.4.8 (2026-11-20)
- Front: card number shown after "Membership Card" as "No. 0000001" (teal, bold, same 21 px line).
- Front district map: name text removed; outline only.
- 0.4.9: date of birth moved under the father's name (stacked, not side by side).
