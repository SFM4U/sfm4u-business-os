=== SFM4U Wholesale ===
Contributors: sfm4u
Tags: wholesale, woocommerce, inventory
Requires at least: 6.7
Requires PHP: 8.1
Stable tag: 0.2.1-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wholesale applications, pack pricing and optional NET30, warehouse and POS operations.
Development release candidate; local qualification does not authorize production installation.

== Installation ==
1. Back up matched plugin files and database. Install WooCommerce; Dokan Lite is optional for marketplace workflows.
2. Upload this ZIP as sfm4u-wholesale. Core wholesale uses WooCommerce CRUD and works without the optional finance modules.
3. Optional finance needs MySQL/MariaDB transactional InnoDB tables and the loss-aware database adapter. SQLite is not supported for atomic finance/operations.
4. Place modules/inventory-bridge/src/LossAwareWpdb.php outside the replaceable plugin folder, at wp-content/sfm4u-db/LossAwareWpdb.php. A reviewed wp-content/db.php must require that file and instantiate SFM4U\Inventory\LossAwareWpdb using the site's existing DB constants. Never overwrite another database drop-in without compatibility review. No credentials belong in this plugin.
5. Enable SFM4U_B2B_ENABLED in wp-config.php for NET30. Enable SFM4U_OPERATIONS_ENABLED for warehouse/POS. An administrator activates/upgrades schema on the next admin request. Check sfmwh_b2b_schema=1 and sfm4u_operations_schema=3. Resolve incomplete-setup notices before recording operations.
6. Approve wholesale applicants in user profiles; configure rules in product administration. One customer account can apply for wholesale/vendor access. Configure credit accounts and terms through B2B Credit Accounts.
7. Assign cashier/register/merchant and credit-manager access through the protected user profile fields. Assign product merchant scope in inventory. Reconcile opening Woo stock, count approval, cost state and receipt lots before operational use.
8. Configure POS cash rounding, variance policy and frozen merchant invoice information through admin settings. Never use synthetic BIN/barcode examples as issued business identifiers.

== Supplier orders and SKU labels ==
Set each product's reorder point and target in Inventory. In WooCommerce → Stock valuation and reorder, select eligible products and prepare a purchase order draft. The draft is an editable form. Fill in supplier and unit costs before issuing. Use the issued PO number to record each actual delivery.
In Products, select items and choose Preview SKU labels in Bulk actions. You can also enter product or variation IDs under WooCommerce → Barcode labels. Choose A4 or 58 mm thermal, preview and print at actual size with browser headers and footers off. Labels show the current stored catalogue price and print up to 100 labels per batch. Long or non-ASCII SKUs may require a shorter printable SKU.

== Operations reports ==
WooCommerce → Stock counts lists recorded counts and signed unit variances. Open a count ID to review its details. Approved rows show the recorded value adjustment.
WooCommerce → Wholesale margin shows BDT ledger sales, costs, gross profit and coverage gaps over up to 31 UTC days. The margin screen requires the optional operations setup. Wholesale margin and Wholesale low stock have manager-only CSV exports.

== Public two-price table (0.9.0-dev) ==
A shop can tick "Show both prices to every customer" on the product form (wp-admin or Dokan). Every customer, guests included, then sees: 1 piece = retail price, minimum or more = wholesale price, plus any bigger-order steps written as "50=90, 100=85" (same format as AlgiBazar Product Experience; Bangla digits allowed). Below the minimum the cart charges retail; at the minimum it switches to wholesale by itself. Variable products (sizes/colours) use the parent product's rule for every variation; each variation line counts its own quantity. An optional "lowest price you accept" (floor) blocks any tier, group or pack price below it. Rules that are not public keep the old behaviour: only eligible logged-in buyers see them and the minimum is strict.

== SFM4U member price online (0.9.1-dev) ==
An active SFM4U Business member, or a Business Black member whose monthly fee is paid, gets the wholesale price at ANY quantity (the lowest price in the product's rule, or their own buyer-group price). The customer must be logged in. A card issued on this site works directly. A card from account.sfm4u.com is linked from the box on the cart and checkout pages (or the [sfm4u_member_price] shortcode): type the card number, member number, email or mobile, then the 6-digit code shown in the member's SFM4U account. One member can be linked to one account per site. The tier is re-checked at the account site every 10 minutes; if it cannot be reached, the last answer is used for up to 24 hours. Orders record the masked member number and tier. Needs this site paired with account.sfm4u.com (shop role) for linked cards.

== Member card at the counter and on orders (0.9.2-dev) ==
POS: choose the price tier "SFM4U member card" and scan the card (or type the member number). The counter checks the card (this site first, then account.sfm4u.com) and shows the member name, business, card type and masked number. Products with a wholesale rule get the member price at any quantity; products without a rule stay at retail. Only an active Business card or a paid Business Black card is accepted; the card is checked again when the sale is confirmed, and the order records the masked number.
Orders: on an unpaid order that is pending or on hold, staff can type or scan a member card in "Apply SFM4U member card" and press Update. Lines with a wholesale rule are repriced (never raised), totals are recalculated and an order note records who did it and how much was saved. It can be applied once per order.

== Night qualification (0.9.9-dev, Connector 0.8.1-dev) ==
* Business Black auto stock now also works for counter (POS) member sales: the paid sale queues a one-off send to the member's AlgiBazar store after the sale has committed (never inside the sale), checks the sale still stands, sends once and retries on failure. Online orders still wait for "completed".
* ERPNext (off by default): POS sales are exported after commit; refunds/returns are sent as ERPNext return invoices (credit notes) against the original invoice, once per refund.
* POS: the member card is checked again when the sale is confirmed; if it stopped qualifying the cashier gets 409 "Membership changed. Scan the card again" (Codex, C-02).
* ERPNext rounding: the POS cash-rounding fee and per-unit rate rounding go to ERPNext as one "Actual" charge row on the round-off account (option sfmwh_erp_round_off_account, e.g. "Round Off - SFM"; rounded total off), so ERP invoice and return totals equal WooCommerce to the poisha. If there is a difference and no account is set, the export waits and logs why.
* ERPNext returns: damaged returns (WooCommerce refund without "restock") are sent with update_stock 0, so ERP stock does not go up. Resellable returns go back in stock as before.
* Member numbers: 1-100 and the special numbers (111, 222 … 999, 200 … 900, 1000, 1111 … 9999) are kept for our own shops and family; only the administrator gives them. Everyone else gets the next normal number when the account opens. The administrator can change any member number (Member cards → Member number); shops and AlgiBazar follow the change automatically by the member's stable key, and old numbers are never reused.
* ERPNext and khata (L-12): POS sales go to ERPNext as normal invoices plus a cash Payment Entry (set "Cash account" on the ERP link page). A sale partly on khata is billed to the member's own ERPNext customer (masked member number), so ERP shows the same due; due-only payments become Payment Entries; cash refunds become a credit note plus a cash payment out. Black members buying on khata get the stock in their AlgiBazar store at once.
* Khata at the counter (for POS due): Members::recordInTransaction() writes inside the sale's transaction; notifyRecorded() after commit; request keys validated (never cut); cashier summary hides notes.
* POS (Codex): multi-line cart credits with fair rounding share, role matrix, Bangla strings, Black-store vendor rule in B2B tests.
* Tests: stockfeed-pos 15, erp-refund 14, erp-rounding 14, khata-due 25, counter-member-confirm 28, cart-credit-rest 79, 4-phase operations 54.

== Linked shop: member number clash guard (0.9.8-dev / card 0.7.3-dev) ==
* A shop that gave out its own member numbers before it was linked to account.sfm4u.com could hold the same digits as a different person's central number. Scanning that number or adopting the central member could then pick the wrong local customer (khata and points mixed).
* Now, on a linked shop, only numbers that came from the SFM4U account site decide who a number belongs to (user meta sfm_wr_central or an active link). Old local numbers are ignored for lookups, adopt and the anti-sharing check; the master is asked instead. Unlinking clears the mark.
* Tests: member-card-build/tests/link-clash-test.php (11), link-shop-test 16/16, m2-recheck updated (9).

== Live check fixes (0.9.7-dev) ==
* POS counter: a wrong or non-member card now shows a clear message ("Card not found." / Bangla) instead of the generic register error; floor-price refusals are shown too.
* Live test on thenoakhali.com (2026-11-21): product price box, cart tiers 1-49/50-99/100+, guest sees retail only, POS retail/wholesale/member quotes, 31 admin pages: all correct.

== Live fix: price precision (0.9.6-dev) ==
* Found on the live test site: a wholesale rule saved when the store used 0 decimals was hidden after the store switched to 2 decimals (no price table, no member price). Rules are now converted to the store's decimals automatically (exact up; down only when no paisa is lost, otherwise refused).
* Same conversion at the POS counter and when a member price is applied to an order.
* Tests: whtest/m4.php (11).

== Owner settings, Black renewals, ERPNext orders (0.9.5-dev) ==
WooCommerce -> SFM4U member price: (1) card online: "card number + matching account" by 6-digit code (default, safest) or "card number alone"; (2) member price for small quantities: base wholesale/group price (default, safest) or the lowest tier price even for 1 piece; (3) cash whole-taka rounding stays on the POS register settings page; (4) Business Black reminder window and a list of expired/due members (expired members lose the member price automatically).
ERPNext: paid orders can be sent as Sales Invoices (option sfmwh_erp_orders = yes, off by default). Branch map option sfmwh_erp_branch_map (JSON: {"pos:MAIN":"Stores - SFM","web":"Online - LLC"}) picks the warehouse; the company comes from the warehouse in ERPNext. Member sales carry the masked member number in the remarks. Each order is sent once.

== POS counter ==
Use the POS cashier role with a manager-assigned merchant and register. Open the drawer, search or scan a SKU, set the tier and discount, review the cart total and confirm. The wholesale option uses stored quantity breaks and minimums. Cash records tender and change; bKash, Nagad and card record only method and amount.
The counter saves a priced sale in the current browser before sending. Keep that browser, account and register session available until it syncs. An open counter can queue a previously priced cart while disconnected. Search, pricing, stock confirmation and final receipts require a connection. Clear the device queue before closing the register. The daily cash summary uses UTC.
Print confirmed receipts at actual size using A4 or a 58 mm roll. The counter has no payment gateway.

== Operational boundaries ==
POS currently supports BDT, two decimals, untaxed simple products and whole stock units. Cash rounding is separate. Manual card/mobile references are unverified records: no gateway, transfer, verified payment or reconciliation is performed. Use Woo checkout for tax-aware/approved wholesale pricing. Parked single-line baskets do not reserve stock or price. Receipt reprints use immutable original snapshots.
Native valuation supports store-currency whole-unit stock events. Unknown historical cost, unsupported currency/fractional quantities and inconsistent stock enter manager review. Do not bypass the review queue. A supplier credit is an AP adjustment, not an automatic goods return or cash payment.
Invoices have configurable BIN, VAT and serial fields. No fiscal certification is claimed. Physical printer, drawer, scanner and scale compatibility requires owner hardware testing. Offline/branch quotas, layaway, kits and fiscal-device adapters are separately scoped future work.

== Upgrade and rollback ==
Test on a sanitized local copy first. Stop writes before rollback. Restore both matched files and database; replacing the ZIP alone cannot reverse newer financial writes. Reconcile every order/payment/stock event after the snapshot before any real restore. Deactivation preserves business data; do not delete financial records.

== Privacy and acceptance ==
Keep My Account, Cart, Checkout, vendor dashboards, POS and private finance pages out of shared caches. Exact site theme/custom plugins/cache policy, independent business/accounting/security review and live authorization remain required. This ZIP does not include WooCommerce, Dokan, a theme, data, secrets or payment credentials.
