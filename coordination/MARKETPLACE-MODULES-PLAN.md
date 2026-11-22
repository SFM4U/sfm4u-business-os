# SFM4U Business Software — Marketplace Modules Plan (r6, 2026-11-22)

Owner: MD SHALA UDDIN. Written by Claude from the Owner's voice message of 2026-11-22.
This file is the plan of record for the module build. Codex and Claude both work from it.
The full product plan lives in Google Drive, in the Factory folder, in "SFM4U BUSINESS SOFTWARE — PRODUCT PLAN (living)". That plan keeps everything from r1 to r5; this is r6.

## 1. Goal (Owner's words, short)

- Dokan sells a marketplace plugin. SFM4U will sell **SFM4U Business Software** the same way, as its own product.
- It must have **every feature that Dokan Lite and Dokan Pro have, and work better**.
- We build our own code. We **never copy Dokan Pro code, names or branding**.
- It must work on AlgiBazar first, with Dokan Lite installed, without breaking anything.
- **Each module has its own dashboard.**
  - Clicking a module opens its own home page with: numbers, its pages, its settings, who may use it, and help in Bangla and English.
  - The main Dashboard (built in 0.9.17-dev) shows everything together.
- Build all modules: Codex builds its part, Claude builds its part, then Claude puts everything together, tests it and releases it.

## 2. Module standard (every module must follow this)

1. **Folder:** `wh/sfm4u-wholesale/modules/<slug>/`
   - `src/` holds the code, in namespace `SFM4U\Modules\<Name>`.
   - `tests/` holds the module's tests.
   - `README.md` explains what the module does, how to switch it on and off, and what data it keeps.
2. **Registration:**
   - One entry in `Modules::all()` with: English name, Bangla name, what it does, and its admin page slug.
   - The module is on or off per site, and its code loads only when it is on.
3. **Dashboard:** the module provides `dashboard(): array`, which returns:
   - `numbers`: up to 6 tiles, each a label, a value and a link.
   - `pages`: links to the module's admin pages.
   - `settings`: the settings fields.
   - `help`: help text in English and Bangla.

   The module home page (the ModuleHome framework, built by Claude) draws the dashboard from this.
4. **Data:**
   - Own tables or meta, with the prefix `sfm_<slug>_`.
   - Never mix data between businesses or shops.
   - Each vendor sees only their own data.
   - Customers, vendors, wholesalers, staff and admins each get their own permissions.
5. **Money:**
   - Keep amounts in integer minor units.
   - ERPNext stays the accounting ledger; the software never keeps a second balance.
   - Nothing silently rounds a price.
6. **Vendor side:** if vendors use the module, add a page in the Dokan vendor dashboard (`/dashboard/<slug>`), and later in the app through REST under `sfm4u/v1/<slug>`.
7. **Language:** Bangla and English labels. The one-click switch works the same way as on the product sheet.
8. **Tests:**
   - A unit test, plus a WordPress integration test in `wh/tests-local/<slug>-test.php`.
   - The test prints `pass N fail 0`.
   - Use synthetic data only. Never real customers.
9. **Safety:**
   - No secrets in code.
   - Every form uses a nonce and a capability check.
   - Every output is escaped.
   - A retry never creates a second payment, refund or stock move.

## 3. Module list and who builds it

Dokan Lite already gives us: vendor registration, the vendor dashboard, products, orders, withdraw (manual) and reverse withdrawal.
Dokan Pro features come from public listings (dokan.co, weDevs, WordPress.org). The dokan.co site could not be opened from Claude's network, so the exact Pro tier contents are not verified.

| # | SFM4U module (slug) | Dokan feature it replaces | Better than Dokan because | Builder |
|---|---|---|---|---|
| A | Module dashboards (`modulehome`) + main Dashboard | – | every module gets its own home page with numbers, pages, settings, access and help | **Claude** |
| B | Seller verification (`verify`) | Seller Verification | NID / passport / trade licence, phone OTP and address; linked to the SFM4U account (accounts.sfm4u.com); a "Verified" badge on the store and the product | **Claude** |
| C | Delivery zones & shipping (`shipping`) | Table Rate / Distance shipping | Noakhali and Bangladesh areas; pickup point (Store Location & Pickup); weight, distance or flat rate per shop; works with the Steadfast courier | **Claude** |
| D | Vendor staff (`staff`) | Vendor Staff Manager | shop owner, manager and cashier roles; staff never see the cost price; card + PIN login | **Claude** |
| E | Vendor plans & subscription (`vendorplan`) | Vendor Subscription | Regular / Business / Business Black plans; product limit, commission, end date; uses the existing access grants | **Claude** |
| F | One product, many sellers (`spmv`) | Single Product Multi-Vendor | the customer compares price, delivery and seller rating on one product page | **Claude** |
| G | AlgiBazar plugins brought inside (L-27) | – | Store Location & Pickup, Shop & Owner Profiles, Customer Profile, Product Experience become modules | **Claude** |
| H | Returns & warranty (`returns`) | Return & Warranty Request (RMA) | return, exchange and warranty requests with photos; refund goes through the existing POS/refund rules; no double refund | **Codex** |
| I | Store support tickets (`support`) | Store Support | customer ↔ shop tickets; admin can see and help; WhatsApp/email notice | **Codex** |
| J | Announcements (`announce`) | Announcements | admin → all shops or chosen shops; Bangla/English; read/unread | **Codex** |
| K | Report abuse (`abuse`) | Report Abuse | customers report fake or wrong products; admin reviews; the product can be held | **Codex** |
| L | Commission & earnings (`earnings`) | Admin earning reports, commission | per shop, category or plan; admin earning report; vendor statement; numbers match the orders exactly | **Codex** |
| M | Withdraw plus (`withdrawplus`) | Auto withdraw, withdraw methods | bKash / Nagad / bank methods; schedule (weekly/monthly); minimum amount; reverse withdrawal kept; never pays twice | **Codex** |
| N | Store reviews & ratings (`reviews`) | Vendor Review | only buyers who bought can review; the shop can reply; rating appears on the store and in SPMV | **Codex** |
| O | Shop coupons & marketing (`shopcoupon`) | Vendor coupons | shop coupons, member coupons, Premium offers (works with Retail Prices) | **Codex** |
| P | Shop analytics (`shopstats`) | Vendor analytics / reports | sales, best products, stock value, profit (cost stays private from staff) | **Codex** |
| Q | Vendor dashboard & app screens (J3) | Dokan vendor dashboard | product sheet A–Z and every module's vendor page in the Dokan dashboard and the app | **Codex** (after H–P) |
| R | Later: booking, auction, product subscription, live chat, store follow | Booking, Auction, Subscriptions, Live Chat | – | later, Owner decides |

Systems that already exist stay as they are and each gets its own dashboard in step A: wholesale, retail prices, product sheet, purchase & cost, membership, POS, receipts, ERP link, delivery & messages, AlgiBazar stock link.

## 4. Order of work

1. **Claude, A:** the module dashboard framework and the module contract. Codex follows this contract.
2. **In parallel:**
   - Codex builds H → I → J → K → L → M → N → O → P. Each one is a branch and a PR with green tests. Mark each DONE in TASKS.md.
   - Claude builds B → C → D → E → F → G.
3. **Claude puts it all together:**
   - Register every module and run one full regression.
   - Build one release ZIP, deploy to the test site (thenoakhali.com), then to algibazar.com.
   - Report to the Owner.
4. **Codex, Q:** the vendor dashboard and app screens for every module.
5. **Sale version (Owner decides later):**
   - Licence per domain, plans and prices.
   - The product page and documentation, in English and Bangla.

## 5. Rules (unchanged)

- Build phase: keep everything and delete nothing; an old plugin is switched off but stays installed.
- The Owner reviews; then we test and improve.
- Never claim something is tested or deployed without evidence.
- Never ask the Owner to explain this again: read this file and the Drive product plan.
