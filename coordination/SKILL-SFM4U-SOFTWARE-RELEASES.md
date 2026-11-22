---
name: sfm4u-software-releases
description: Use when releasing, selling, downloading or updating any SFM4U software (Business Software core, add-on modules, Noakhali Builder, SFM4U themes) across marketplace.sfm4u.com, algibazar.com and other SFM4U sites. Covers where the release ZIP lives, protected downloads, update notices, and editing pages with Noakhali Builder.
---

# SFM4U software releases, sales and updates

Owner: MD SHALA UDDIN. Written by Claude, 2026-11-22.

## Owner goals
- **One file per release.** Upload each module or plugin release ONCE, and never keep double copies for customers.
- **Sales on marketplace.sfm4u.com.**
  - The marketplace shows each item: what it does, where it already runs (for example "this page is edited with Noakhali Builder"), and reviews.
  - A paying customer downloads that same release file.
- **Sites get updates automatically.** algibazar.com and other SFM4U sites see "update available" by themselves. Only an authorised admin installs the update.
- **One update list with three kinds of item, kept separate:**
  - an update for an installed module;
  - an update for SFM4U Business Software (the core);
  - a new module, shown as "available to install".
- **No-code editing.** The Owner does not write code. Pages must be editable visually with our own Noakhali Builder.
- **Only our own software.** WordPress + WooCommerce are the only outside software allowed.

## Facts checked (2026-11-22)
- **Shared hosting account.** algibazar.com, marketplace.sfm4u.com, sfm4u.com, thenoakhali.com and the other sites all run on the SAME Hostinger hosting account (u854667922, order 1009937647). They share the same CPU, memory, PHP-worker and disk limits.
  - A second copy of a ZIP only costs a little disk space. It does not slow the server.
  - Downloads cost server work on whichever site serves them, and both sites draw on the same account.
- **WooCommerce download methods** (WooCommerce Digital/Downloadable Product Guide):
  - *Force Downloads* (default): PHP sends the file and hides the real URL. Each download uses a PHP worker while it runs.
  - *X-Accel-Redirect / X-Sendfile*: the web server sends the file. This needs server support. Not tested on Hostinger yet.
  - *Redirect only*: insecure. The file URL is public, so never use it for paid files.
- **WordPress self-hosted updates** (Make WordPress Core, "Update URI" in WP 5.8):
  - A plugin header `Update URI: https://marketplace.sfm4u.com/...` makes WordPress skip wordpress.org.
  - The filter `update_plugins_marketplace.sfm4u.com` then supplies our version, package URL and requirements.
  - WordPress shows the normal "update available" notice. An admin clicks update.
  - Update data is cached in a transient, so a manual "check again" is needed after a release.
- **Network limits on Claude's side.** From Claude's cloud container, the Hostinger file-upload host and the SFM4U sites cannot be reached directly. Deploys work through the Hostinger API git deploy and through WPVibe.

## Decision (recommended, Owner to confirm)
- **Single release host: marketplace.sfm4u.com** (Part 2 of the Owner's brief). Do not use algibazar.com.
  - The live shop should not spend its PHP workers serving downloads.
  - Selling from one site while serving files from another needs a cross-site signed-link system. That is more moving parts and adds no speed, because the hosting is shared anyway.
- **Each release on marketplace:**
  - one immutable ZIP: `<slug>-<version>.zip` plus SHA-256, minimum WP/WC/PHP versions, and changelog;
  - stored in a protected folder with deny rules, never in a public URL;
  - sold as a WooCommerce downloadable product with Force Downloads, a download limit and link expiry;
  - free internal sites (algibazar.com and other SFM4U sites) get a site licence instead of an order.
- **On every client site (algibazar.com first):**
  - The SFM4U Module Manager asks marketplace for updates on the normal WordPress update check, and again on "Check again".
  - It shows the three kinds of item separately: module updates, core update, new modules to install.
  - Every install or update needs an admin click. The SHA-256 is checked before installing. The old version is saved first, and Roll back is available.
- **Source of truth for code:** GitHub (build branches). The marketplace ZIP is built from the tested commit, and its SHA-256 is recorded in the Module Log.

## Noakhali Builder (no-code editing) — possible: YES
- Noakhali Builder is our own builder:
  - core 0.1.0-dev.96, Pro dev.20, WooCommerce dev.4, Template Library dev.3, Marketplace dev.3;
  - the ZIPs are in Drive Showroom, folder "C. Noakhali Builder + thenoakhali.com";
  - copies are on the private repo SFM4U/sfm4u-ceo-ai, branches `site-build-*`.
- **Once installed on marketplace.sfm4u.com:**
  - The Owner edits pages by dragging blocks, without code.
  - The marketplace page for Noakhali Builder can say "this site is edited with Noakhali Builder".
- **Install paths (pick one):**
  1. **Deploy key (Claude installs everything).** The Owner adds the Hostinger read-only deploy key to GitHub → SFM4U/sfm4u-ceo-ai → Settings → Deploy keys. Then Claude deploys and activates.
  2. **Owner uploads once.** In marketplace wp-admin → Plugins → Add New Plugin → Upload Plugin, choose each `noakhali-builder-*.zip` from Drive Showroom, then Activate. This needs the Owner's own marketplace password first ("Lost your password?").
- After install, the current SFM4U Marketplace theme pages can be rebuilt in Noakhali Builder. The theme stays as the frame (header and footer).

## Test plan before going live (synthetic data only, staging first)
1. Paid buyer gets the exact ZIP (SHA-256 matches). Unpaid buyer is refused. Expired link is refused. The raw file URL returns 403.
2. Five downloads at the same time. Both sites keep responding. Note PHP worker and CPU use in hPanel.
3. Module 1.0.0 is installed on the test copy, 1.0.1 is published. "Update available" appears after "Check again". The admin updates; settings and data are kept. Roll back works.
4. A new core version appears as a core update only. A new module appears only as "available to install".
5. Failure cases: wrong checksum, bad licence, incompatible WP/PHP version, update server unreachable. Each must give a clear message, and nothing breaks.
6. Record PASS / FAIL / NOT TESTED for each case in the Module Log, with versions and screenshots.

## Status (2026-11-22)
- **Part 1** (algibazar.com as the single file host): NOT RECOMMENDED, NOT TESTED (shared hosting, see Facts). No protected-download test was run.
- **Part 2** (marketplace.sfm4u.com as the release host + update client): CODE BUILT and tested locally (CL-2, branch `cl2-update-server`: store plugin 0.5.0 `src/ReleaseServer.php`, core 0.9.24-dev `src/UpdateClient.php`). NOT deployed yet.
  - Needs WooCommerce on marketplace and a protected release folder.
  - Needs an update endpoint in the Module Store and an update client in the Module Manager.
- **CL-3 sales** (branch `cl3-sales`, store plugin 0.6.0 `src/Sales.php`): CODE BUILT and tested locally (`tests/sales-test.php` pass 80 fail 0; `tests/release-server-test.php` pass 39 fail 0). NOT deployed.
  - Admin-only "Download ZIP" (edit screen + module list), streamed from the private release store after the sha256 check.
  - With WooCommerce: "Sell this" box makes a virtual + downloadable product (price empty until the Owner sets it), file URL = our signed `/wp-json/sfm4u-releases/v1/buy/<slug>` endpoint, 5 downloads / 365 days; order completed → licence row; My Account → "SFM4U licences" makes a site key (shown once, HMAC only, 5 per hour) that only sees owned slugs. Owner keys still see everything.
  - Without WooCommerce: selling is off and the request form stays; admin download still works.
- **Noakhali Builder on marketplace:** BLOCKED. It needs the deploy key, or the Owner's one-time upload.
- **sfm4u.com menu "Marketplace" → https://marketplace.sfm4u.com/:** prepared as two search-replace operations. They wait for the Owner's approval in WPVibe.

## Sources
- WooCommerce, Digital/Downloadable Product Guide: https://woocommerce.com/document/digital-downloadable-product-handling/
- WooCommerce developer docs, protect the uploads folder: https://developer.woocommerce.com/docs/code-snippets/using_nginx_server_to_protect_your_uploads_directory/
- Make WordPress Core, "Update URI" plugin header (WP 5.8): https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/
- WordPress developer reference, `update_plugins_{$hostname}`: https://developer.wordpress.org/reference/hooks/update_plugins_hostname/
