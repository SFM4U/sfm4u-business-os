# SFM4U member card design (Skill 228)

Makes a front + back PNG of an SFM4U member card from one JSON file. This is a design and print
preview only. The card that counts is the one the account system issues (`src/Members/CardView.php`);
the member number, level and services must be copied from the account system, never invented.

```bash
python3 make_card.py example-card.json OUT_DIR
```

Needs Pillow, Node + Playwright (Chromium), PHP CLI (barcode from `src/Members/Barcode.php`, same as
the real card) and npm once (downloads the Noto Sans Bengali font into OUT_DIR/fonts).

## JSON fields
| Field | Meaning |
|---|---|
| member_no | 7 digits from the account system (required) |
| name_en / name_bn | name as on the account (EN required) |
| title | gold badge, e.g. CEO (staff/owner only, admin decides) |
| card_label / card_label_bn | e.g. MEMBER CARD / SFM4U সদস্য কার্ড |
| level_en / level_bn | Member, Premium, Business, Business Black |
| role_en / role_bn, countries_en | short lines |
| flags | list of BD, US |
| services | algibazar, goldenclub, aiagent, realestate (only enrolled ones) |
| phones | only numbers the member asked to print |
| photo, photo_crop | member's own portrait (consent); crop box [left, top, right, bottom] optional |
| logos | list of {path, round} |

The script refuses `nid`, `brn`, `passport`, `dob` and `address` fields: they are never printed
(Owner rules, Skill 227). Keep real members' JSON and photos outside the repository.
