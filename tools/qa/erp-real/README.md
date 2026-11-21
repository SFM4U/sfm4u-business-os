# Real ERPNext replay (test site only)

Proves that the documents Business OS sends are accepted by a real ERPNext, without exposing the test site.

1. `wp eval-file tools/qa/erp-real/gen-docs.php > docs.json` on a local WordPress with the plugin (uses the ErpSync builders).
2. Copy `docs.json` + `replay.py` to the ERP host, `docker cp` into the backend container.
3. `docker exec -e K=<api key> -e S=<api secret> erp-backend-1 python3 /tmp/replay.py /tmp/docs.json`
   (key/secret read from root-only files on the host, never printed; site chosen by Host header `erp-test.localhost`).

Run 2026-11-21 (ERPNext 16.60.0 / Frappe 16.60.2, test site `erp-test.localhost`, company "SFM4U BD Test", BDT):
30/30 PASS — walk-in POS invoice 67.00 with Actual round-off row + cash Payment Entry (outstanding 0);
khata member customer (leaf group "Individual"); member invoice 300.00 + Payment Entry 220.00 (200 allocated, outstanding 100.00);
due-only Payment Entry 50.00; resellable return -33.50 with round-off row + cash Pay entry; damaged return -100.00 with update_stock 0;
every Payment Entry found again by reference_no; ERP stock: rice 50 -> 49, salt 50 -> 47 (damaged not back in stock).
Found and fixed: ERPNext refuses customer group "All Customer Groups" (group node) -> default "Individual" (option sfmwh_erp_customer_group).
