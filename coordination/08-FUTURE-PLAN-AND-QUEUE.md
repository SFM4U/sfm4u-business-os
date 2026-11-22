# 08 — FUTURE PLAN AND WORK QUEUE (no AI sits idle)

Owner: MD SHALA UDDIN. Written by CLAUDE on 2026-11-22 (night). Read with "06 — AI WORK BOARD" and "05 — WORK RULES".

## Rule: nobody waits
1. When your task is done, blocked or locked, take the NEXT unlocked task from the queue below, top to bottom. Do not stop and do not ask the Owner.
2. Use the lock rules from the 06 board (LOCK-<TASK>.txt is the authority).
3. When you are blocked, write BLOCKED, keep your work in your module log, and take the next task at once.
4. Never edit another AI's module folder. Never change a live site. Live work is CLAUDE's job, after review.
5. One task = one module folder `sfm4u-module-<slug>`, a ZIP in "02 Ready modules", and tests (pass N fail 0) on real WordPress + WooCommerce with synthetic data.
6. Every module follows the module contract: its own dashboard, settings and help in Bangla + English, joins through `sfm4u_bos_modules`, and uses the SFM4U look.

## Answers to the current BLOCKED items (CLAUDE decision, 2026-11-22)
- **A07 Notifications** (blocked on transports): build it now with an adapter design.
  - Channels that work today: email (wp_mail) and an on-site inbox (bell plus a list).
  - SMS, WhatsApp and push are adapter classes behind the interface `SFM4U\Notify\Transport` (`send(array $msg): bool`). They show "not configured" until K01 SMS / K02 push / M04 WhatsApp give a real transport.
  - Order-to-shop mapping: use `apply_filters('sfm4u_shop_for_product', (int) get_post_field('post_author', $productId), $productId)`. B01 will hook this filter later.
  - Other modules send through one call: `do_action('sfm4u_notify', $event, $userId, $data)`.
- **A03 Licence and updates** (blocked on the release contract): wait for CL-2 (the update server by CLAUDE). Until then, build only the client side against this contract:
  - `GET https://marketplace.sfm4u.com/wp-json/sfm4u-releases/v1/check?site=<host>&items=slug:version,...`
  - The answer is JSON (final, CL-2 built on branch `cl2-update-server`): `{updates: [{slug, version, sha256, size, requires_core, package_url (signed, 10 min)}], new: [{slug, code, name, version}], checked}`.
  - The client is already built in core 0.9.24-dev (`src/UpdateClient.php`); A03 now only adds licence/plan rules on top of it.
  - The site key is sent in the header `X-SFM4U-Site-Key`. Never store it in code.
- **B03 Vendor panel** (blocked on the app repo): web panel parts are done. The app screen moves to M01 (REST API and app). Mark B03 REVIEW with the web panel only.

## The queue (take the first unlocked READY item; codes match "01 — MODULE CATALOG")
### Wave 1: finish replacing Dokan (marketplace core)
1. G01 Delivery zones (CODEX-2), B04 Verification (CODEX-3), B01+B02 Stores and registration (CODEX-5), E01 Orders (CODEX-6), B07+B08+B09 Money (CODEX-7). These are assigned; do not take them unless they are stale for 6 h.
2. B10 Reverse withdrawal (needs B07–B09 data, so build it against their filters)
3. E02 Invoices and packing slips
4. E09 Refunds
5. E08 Returns and warranty
6. D05 Coupons (shop coupons plus admin coupons)
7. C10 Product Q&A
8. C11 Wishlist and compare
9. C03 Bulk edit
10. J06 Customer groups
11. J04 Referral
12. D08 Dynamic price rules
13. D09 VAT and tax (Bangladesh)
14. A07 Notifications (see the answer above)
15. A09 Activity log (finish), A10 Import (finish)

### Wave 2: money and delivery
16. F01 bKash / Nagad / Rocket (part of SFM4U Pay; sandbox only, no real keys)
17. F02 SSLCommerz / cards (sandbox only)
18. F05 Payout split (uses B07–B09)
19. G03 Couriers (finish: Pathao, Steadfast, RedX adapters; sandbox)
20. G04 Delivery man app (web app first)
21. E05 Advance payment
22. I01 Stock and warehouses (finish), I03 Stock transfer
23. C14 Expiry and batch

### Wave 3: shop counter and devices
24. H04 Offline POS
25. H05 Weight barcode scale
26. H06 Bluetooth printer and scanner
27. H07 Supported devices list

### Wave 4: sales growth and reports
28. K01 SMS (adapter for A07)
29. K02 App push (adapter for A07)
30. K04 SEO tools
31. K05 Banners and popups
32. D07 Friday offers
33. L01 Sales reports
34. L02 Profit reports
35. L03 Shop analytics
36. L04 Expenses

### Wave 5: platform
37. M01 REST API and app (includes the B03 app screen)
38. M02 Webhooks
39. A03 Licence and updates (client side, after CL-2)
40. J01 Customer panel (finish)
41. J05 Premium plans
42. C04 Bundles and combos
43. BP01 Business plan pages (texts are Owner input; leave empty slots)

### Wave 6: our own products (Owner priority)
44. N01-EDITOR Noakhali Builder editor like Elementor (on the Owner's Mac; CODEX-8)
45. N01-PRO Noakhali Builder with all Elementor Pro features (after N01-EDITOR)
46. N02 SFM4U Theme: full site editing with Noakhali Builder Theme Builder
47. S23/S24/S25 Golden Club Members, Event Manager and Blood Bank as marketplace products (package and test only; no club data)
48. M03 AI assistant with any provider (Claude / OpenAI / Grok / local), never required

### Wave 7: LATER items (promoted, so nobody waits)
49. B12 Store follow · 50. B17 Vacation mode · 51. E06 Pre-order · 52. E10 Abandoned cart · 53. G05 Delivery time slots · 54. C05 Product add-ons · 55. C06 Digital products · 56. D06 Gift cards · 57. L05 VAT report · 58. L06 Staff performance · 59. K03 Affiliates · 60. J07 Live chat · 61. C07 Booking and rental · 62. C15 AI product intake · 63. A12 One SFM4U login (plan only: accounts.sfm4u.com, needs Owner approval before any live change)

## CLAUDE's own queue (live work, review, publishing)
- CL-1 Review every ZIP in "02 Ready modules": code check, security check, tests on a real WordPress copy, then algibazar.com, then Showroom + marketplace. Order: A13, A06, A08, A11, G02, C12, B05, B06, B11, B13, B14, B15, B16, B18, then new ones.
- CL-2 Update server on marketplace plus the Module Manager update client (unblocks A03).
- CL-3 Marketplace sales: WooCommerce downloadable products, Force Downloads, admin-only download.
- CL-4 Noakhali Builder: install the Codex ZIP on marketplace and rebuild the marketplace pages with it.
- CL-5 sfm4u.com redesign (after the Owner's backup and a "yes"), then account.sfm4u.com Account Hub.
- CL-6 Every morning: update this file and the 06 board, and write a short report for the Owner.

## Owner inputs still needed (nobody may invent these)
- 5% policy text, prices of plans and modules, the vendor commission rates.
- Merchant accounts (bKash, Nagad, SSLCommerz, PayPal, Stripe). Never paste keys in chat.
- Approval before any live change on sfm4u.com, account.sfm4u.com or BD Golden Club.
