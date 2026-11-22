---
name: sfm4u-system-map
description: Use first whenever any AI works on SFM4U software, sites, modules, downloads or customer requests. Map of every SFM4U system and where it lives, how releases and downloads work, and how to find anything for the Owner or a customer.
---

# SFM4U system map (where everything is, how to find it)

Owner: MD SHALA UDDIN. Last updated by Claude on 2026-11-22. Update this file after every work block, in the same session.

## 1. The big picture (Owner's design)
- **SFM4U** is the company. **SFM4U Business Software** is ONE software made of modules: every business system SFM4U builds becomes a module or a product of it.
  - Covers any business: shop, wholesale, clinic and more.
  - Covers the whole chain, from buying stock to the customer's door.
- **marketplace.sfm4u.com is the home of all SFM4U software**, the same way Elementor or Dokan keep their software on their own site:
  - it shows every module and product (Complete / Upcoming), with business plans on the side;
  - it holds ONE release ZIP per version;
  - later it sells software (full software or single modules) and sends updates.
- **Our sites only install the plugin and receive updates** (algibazar.com first, then others).
  - A plugin must be installed on a site to run. That is normal and does not overload the server. The master copy and the updates stay on marketplace.
- **Allowed outside software:** WordPress + WooCommerce only. Everything else is SFM4U's own code (security: the Owner's AI runs on these sites).
- **No double copies:** the same software must never live as two separate master copies in two places.

## 2. Sites (all on ONE Hostinger account u854667922, order 1009937647; shared CPU, memory and disk)
| Site | Role | Notes |
|---|---|---|
| sfm4u.com | Company website | Menu "Marketplace" → marketplace.sfm4u.com (changed 2026-11-22). Hostinger AI theme + Elementor today. |
| marketplace.sfm4u.com | Home of SFM4U Business Software and all software | Theme sfm4u-marketplace 0.2.0, plugin "SFM4U Business Software — Marketplace" (module store) 0.2.0, protected release store `wp-content/sfm4u-releases`. WP install id 31691339. |
| algibazar.com | SFM4U's marketplace for goods (first live client of the software) | Business Software 0.9.23-dev + modules E07 Order Tracking (/track-order/) and E04 COD Control; Dokan Lite (being replaced step by step by our modules). |
| thenoakhali.com | Test site | Business Software test copy (plugin folder sfm4u-wholesale-test); Noakhali Builder runs here today (to be moved to marketplace, then switched off here). |
| nijamlibrary.com | Partner (Nijam Library) | SFM4U Store Mirror shows its AlgiBazar stock; buying always goes to AlgiBazar. |
| account.sfm4u.com / accounts.sfm4u.com | SFM4U accounts | Future single login ("Login with SFM4U"). |
| business / realestate / partnership .sfm4u.com | Company sections | Linked from the sfm4u.com menu. |

## 3. Where the code and files are
- **GitHub SFM4U/sfm4u-business-os** (PUBLIC today; the Owner decides whether to make it private):
  - `wh/sfm4u-wholesale` = Business Software core;
  - `modules/sfm4u-module-*` = add-on modules;
  - `sites/marketplace-theme` = marketplace theme;
  - `sites/sfm4u-module-store` = marketplace store plugin plus `catalog.json`, the list of every item;
  - `coordination/` = plans, catalog, tasks, skills.
- **Build branches (what Hostinger deploys):**

| Branch | Goes to |
|---|---|
| `algibazar-bos-build` | algibazar.com |
| `test-site-build` | thenoakhali.com |
| `module-<slug>-build` | the module on algibazar.com |
| `marketplace-theme-build` | marketplace theme |
| `module-store-build` | marketplace store plugin |
| `releases-build` | `marketplace.sfm4u.com/wp-content/sfm4u-releases` |

- **GitHub SFM4U/sfm4u-ceo-ai** (PRIVATE): branches `site-build-sfm4u-theme` and `site-build-noakhali-builder*`.
  - These hold SFM4U Theme 0.1.10 and Noakhali Builder core / Pro / WooCommerce / Template Library / Marketplace.
  - Hostinger can deploy them only after the Owner adds the Hostinger read-only deploy key.
- **Google Drive:**
  - "2. SFM4U Showroom" = finished, tested ZIPs, including Noakhali Builder and the SFM4U Theme.
  - "5. SFM4U Factory — AI Work Floor" = work in progress:
    - "SFM4U Business Software — Module Factory": 00 start here v2, 01 catalog, 02 ready, 03 joined, 04 module log, 05 work rules, the source ZIP, the logo, the skills;
    - Work Queue and Product Plan docs.
  - "SFM4U Business Master" holds the Master Notes for each product (English).

## 4. How a release works (rule)
1. Build and test locally (`pass N fail 0`).
2. Commit to GitHub.
3. Deploy to the test site, then to algibazar.com.
4. Put the ZIP in `releases-build`: `<slug>-<version>.zip`, plus an entry in `releases.json` with sha256, size and requirements.
5. Mark the item BUILT (and its version) in `sites/sfm4u-module-store/catalog.json`. The marketplace page updates by itself.
6. Copy the ZIP to Drive "03 Joined into software".
7. Write one line in TASKS.md, in the Module Log and in the Master Notes.

## 5. How downloads work
- **Release files:** `marketplace.sfm4u.com/wp-content/sfm4u-releases/`. Direct access is denied (403, checked 2026-11-22). Never give anyone a raw file link.
- **The Owner or an admin:** download from marketplace wp-admin (admin-only download button: planned), or take the ZIP from Drive "03 Joined into software".
- **A customer** (planned):
  1. They buy on marketplace (WooCommerce downloadable product, Force Downloads, expiring link).
  2. Or they send a Request from the module page. Requests are in wp-admin → SFM4U Business Software → Module requests.
  3. The Owner's AI looks the item up in `catalog.json` / `releases.json` and answers with the module page link, never a raw file.
- **Our sites' updates** (planned): the Module Manager asks marketplace (Update URI plus the `update_plugins_marketplace.sfm4u.com` filter). It shows three separate kinds of item: module update, core update, new module. An admin clicks to install; the checksum is checked and the old version saved.

## 6. Module codes (catalog)
- A platform, B marketplace/shops, C products, D prices, E orders, F payments, G delivery, H POS, I stock, J customers, K marketing, L reports, M apps, N future products, S our software/themes/builder, BP business plans.
- BUILT and live: A01, A02, A04, A05, D01–D04, C01, C13, E03, E04, E07, F03, F04, H01–H03, I02, I04–I09, J02, J03, plus S01–S22 (products) and BP01.
- Next modules: G01 delivery zones, B04 verification, A06 roles, B01/B03 stores and vendor panel, E01 orders, B07–B09 commission/earnings/withdraw, J01 customer panel, G02 pickup, C12 product page.

## 7. Open items (update when done)
- Deploy key on SFM4U/sfm4u-ceo-ai (Owner), then: Noakhali Builder and the other SFM4U plugins onto marketplace; rebuild the marketplace pages with Noakhali Builder ("built without Elementor"); switch the builder off on thenoakhali.com.
- Marketplace: the Owner resets his password; WooCommerce sales, admin download and the update client still to build.
- Decide whether the business-os repo becomes private.
- 5% policy text, prices and plans.

## 8. Owner rules added 2026-11-22 (late)
- Every module/plugin/theme goes FIRST to Drive "2. SFM4U Showroom", THEN to marketplace (release store). Sites get the same program from marketplace.
- Deleting duplicates (standing permission from the Owner): before deleting anything from a site, check that the newest copy exists in (1) the live site list, (2) Factory, (3) Showroom, (4) marketplace release store, (5) skills/records. If the copy is in Showroom AND marketplace, delete the duplicate without asking. Never delete the only copy.
- thenoakhali.com is a test site only: duplicates removed 2026-11-22 (Noakhali Builder x5, Business Software copies, Account Hub, Link, Smart Serial, Hiring, Services, Work, Golden Club Members). The site still loads.
- Private release store (all 17 ZIPs): marketplace.sfm4u.com/wp-content/sfm4u-release-store (from private repo SFM4U/sfm4u-ceo-ai branch releases-store; direct access 403). Manifest releases.json (slug, code, version, sha256, size). The older public folder wp-content/sfm4u-releases (core + 2 modules) stays until the store plugin reads the private one.
- Noakhali Builder (core, Pro, Template Library) is ACTIVE on marketplace; WooCommerce + Marketplace add-ons installed but off (need WooCommerce there). SFM4U Theme 0.1.10 installed (not active).
- OLD Showroom rule (Owner 2026-11-22): Drive "2b. SFM4U OLD Showroom" (id 1CZDR2zQdGj6FJ9E8Sq5P-bE9mZMljGLZ). Flow: new build → Factory "02 Ready" → test → live OK → new version into Showroom; the previous version moves to OLD Showroom; deleting happens ONLY from OLD Showroom, never directly from Showroom.
- Every SFM4U system lives in two places: Showroom (Drive) + marketplace release store. Sites run an installed copy (that copy is needed, like Elementor) and get updates from marketplace.
- Editing without code: marketplace pages today are theme code (PHP/CSS by Claude). Module/plan names, texts, versions are editable without code in marketplace wp-admin → SFM4U Business Software. Page texts will move to Noakhali Builder so the Owner can change any word.
- Translation: Owner wants GTranslate on every site like sfm4u.com (Owner's explicit choice; it is outside software — exception to the "WP + Woo only" rule, noted).
- algibazar.com cleanup 2026-11-22: old connectors sfm4u-stock-receiver (0.8.0) + sfm4u-algibazar-connector (0.8.1) and unused http-headers queued for uninstall (copy in release store + OLD Showroom; no uninstall hooks). Running plugins (Business Software, E07, E04) stay — a site needs its installed copy.
- Multi-AI rule (Owner 2026-11-22): Drive Module Factory "06 — AI WORK BOARD" + folder "07 Locks". One task = one AI; claim with LOCK-<TASK>.txt (lock file is the authority), log WORKING/DONE lines, stale after 6 h → TAKEOVER. Names CODEX-1..7, CLAUDE, HERMES. First assignments: CODEX-1 A13 Forms, -2 G01, -3 B04, -4 A06, -5 B01+B02, -6 E01, -7 B07-B09.
- Fluent Forms removed from sfm4u.com and bdgoldenclub.com (Owner order; all 24 entries were internal tests; uninstall waiting approval). Our own SFM4U Forms (A13) will replace it.
- Owner rule 2026-11-22: from now on every page is designed with Noakhali Builder, not Elementor; the builder editor must match Elementor (spec N01-EDITOR in Module Factory).
