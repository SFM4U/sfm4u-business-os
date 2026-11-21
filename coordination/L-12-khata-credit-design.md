# L-12 · Khata (credit) sales after C-09: design proposal (Claude, 2026-11-21)

Status: PROPOSAL. Needs the Owner's approval (and the accountant's view) before code. Nothing is live.

## Today (after C-09, commit 2ffc232)
- A POS sale with part on khata (`credit_minor > 0`) becomes a WooCommerce order in status `on-hold`. Stock goes down in WooCommerce, and the khata row is written in the same transaction.
- ERP export and Black StockFeed only run for `processing`/`completed`, so credit sales do NOT reach ERPNext or the Black member's AlgiBazar store.
- Refunds of credit sales are refused (409) until this design is done.
- Due-only payments are khata rows only. Nothing goes to WooCommerce or ERPNext.

## Problem
1. ERP stock drifts: the goods left the shop but ERPNext never hears about the sale.
2. ERP money: the unpaid part is a receivable, but ERPNext has no customer for it. Today every export uses one "Walk-in Customer".
3. Black member auto stock: if a Black member buys on khata, their AlgiBazar store never gets the stock.
4. Existing gap found while designing: `invoiceDoc` sends `is_pos=1` with no `payments` rows. A real ERPNext POS invoice needs payment rows (mode of payment). The mock does not check this. To verify on the ERP test site.

## Proposal (recommended)
A. **ERP customer per khata member**: one ERPNext Customer per member who uses khata, named by the masked member number (e.g. "SFM4U 00••123"). No phone, NID or address is sent. Walk-in sales keep "Walk-in Customer".
B. **Credit sale → ERP**: export as a normal Sales Invoice (`is_pos=0`, `update_stock=1`) for the full total to that customer. Then post a Payment Entry (Receive, mode Cash) for the cash taken now (total − credit + due paid). ERPNext then shows the outstanding amount, which equals the khata due.
C. **Due-only payment → ERP**: Payment Entry (Receive, Cash) against that customer, allocated to the oldest open invoices (ERPNext "Get Outstanding Invoices" logic). Sent once per khata ref, after commit, with retry like refunds.
D. **Black StockFeed for credit sales**: send the stock once the sale commits, same as cash sales. The goods are already in the member's hands. Option: wait until the khata due for that sale is paid (safer, but the store shows less stock).
E. **Refund/reversal of a credit sale**: manager only. Credit note (return invoice) in ERP; khata gets an `adjust` row for the unpaid part; cash back only for the part actually paid.
F. **Reconciliation check**: daily job compares each member's khata balance per store with the ERPNext customer outstanding and lists differences. It never auto-fixes.

## Owner decisions needed
1. OK to create one ERPNext Customer per khata member, named by the masked member number? (Recommended.)
2. Black member buying on khata: send stock to their AlgiBazar store at once (recommended), or only after the due is paid?
3. Which ERP account is "Cash" for POS Payment Entries per shop (mode of payment "Cash" → account)?

## Tests to add when approved
erp-khata-test.php (mock): credit sale → invoice outstanding = credit; payment entry for cash part; due payment → payment entry allocated; replay → no second document; reversal → credit note + khata adjust. StockFeed credit-sale test. Mock ERPNext: Payment Entry endpoint + outstanding per customer.

Sources: ERPNext Payment Entry https://docs.frappe.io/erpnext/payment-entry ; Sales Invoice https://docs.frappe.io/erpnext/sales-invoice ; WooCommerce order statuses https://woocommerce.com/document/managing-orders/order-statuses/
