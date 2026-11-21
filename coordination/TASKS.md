# Night task queue · Business OS + POS · 2026-11-21 (target: 100 % local before any upload)

Status: `READY` → `IN PROGRESS (name UTC)` → `REVIEW (PR/commit + tests)` → `DONE` · `BLOCKED (reason + Owner action)`.
Candidate under test: plugin 0.9.8-dev, ZIP SHA-256 171956960dd8ca12d3bee0574a09a960047f65568cbc46e524909988bfcbe9ae.

## Done so far (Claude, evidence)
| ID | Result |
|---|---|
| B-000 | Repo baseline: 0.9.5 source+tests (commit d2e7aaf, archive SHA 8b6791f4…8b0f) → plugin 0.9.8 (a59e9e0). 0.9.6/0.9.7/0.9.8 diffs match Skill 209 v4. |
| B-001 | Unit (0.9.8): m 835, c 14, t 22, m1 43, m2 19, m4 13 (m4 rewritten; fails on 0.9.5, passes on 0.9.8). |
| B-002 | WP shop site (WP 7.1.5, WC 11.3.1, MariaDB 11.8): m1-public 13, m2-member 19, m2-recheck 9, m3-pos-card 25, receipt 16, tiers 18, guide 10, due 4, roles 11, profit 7, erp-order 12, link-lock 4 — all PASS. Test fixes: outdated money/decimal expectations (due, roles), 0.9.8 clash semantics (m2-recheck now checks both cases), missing seller fixture (profit). |
| B-003 | POS staging operations workflow (Codex tests, 4 phases) on 0.9.8: 54/54 PASS (sale replay, manager credit once, receipt COPY, drawer close zero variance after refund, two-person count, Daily Close, Woo/Dokan REST metadata security, reactivation). |

## Codex section — POS lane (whole night, in order)
| ID | Task | Status |
|---|---|---|
| C-01 | Codex matrix | REVIEW (Codex via Drive handoff `C-01-CODEX-MATRIX-2026-11-21.md`, base 8bc26d2, ZIP 17195696… verified) — 61 of 62 files PASS (5 with harness-only adjustments), 483-assertion B2B parent driver PASS, 4-phase 54/54 PASS on WP 7.1.4/WC 11.3.0/Dokan 5.3.4. `b2b-installed.php` NOT TESTED (needs 0.1.2-dev archive; Owner/lead to decide retire). Codex has no GitHub push yet. |
| C-02 | Member card at the counter end to end | DONE — Codex patch `C-02-POS-member-confirm.patch` (SHA-256 bde1f180…0c93, base 8bc26d2) reviewed and applied unchanged by Claude. Defect fixed: card suspended between quote and confirm returned 422 -> now 409 + rescan message. Claude rerun on fresh DB: counter-member-confirm 21/21, 4-phase 54/54, m3-pos-card 25, receipt 16, stockfeed-pos 15, erp-order 12, erp-refund 14, unit all PASS. Follow-up for C-06: Bangla string for "Membership changed. Scan the card again and review the cart." Note: the C-02 test is not re-runnable on the same DB (fixed usernames). |
| C-03 | Duplicate/concurrent submits (same key twice, two cashiers at once, retry after timeout) → exactly one sale, one receipt, one stock move; refund twice → once. (`g2-pos-concurrent`, `g2-authorized-recovery`) | READY |
| C-04 | Roles: cashier vs manager vs inventory manager vs vendor vs guest for sale, credit/refund, drawer in/out/close, count approval, reprint, X report (`pos-role-lifecycle`). | READY |
| C-05 | Stock mismatch: insufficient stock at confirm, count difference above the limit needs approval, Daily Close cash variance reported (never auto-fixed). | READY |
| C-06 | Receipts: 58 mm and 80 mm thermal + A4 invoice in Bangla, reprint = COPY, frozen BIN, unverified manual tender warning. Screenshot or HTML evidence. | READY |
| C-07 | Fix the first reproducible POS defect found in C-01..C-06 (only `modules/pos`, `modules/inventory-bridge`), rerun the 4-phase workflow + focused tests. Repeat. | READY |
| C-08 | POS: in `CounterSale::commit`, when the sale has a member card, save `_sfmwh_member_user` = the member user id (alongside the existing `_sfmwh_member_source`/masked number). Needed by L-01 (StockFeed::META_MEMBER_USER). Add a counter test: paid Black card sale → order meta set; non-member sale → not set. | READY |

## Claude section — wholesale, membership, Black stock, ERP mock
| ID | Task | Status |
|---|---|---|
| L-01 | Black auto stock via POS counter sale | REVIEW (Claude 07:05 UTC) — StockFeed side done: POS member order at `processing` queues a one-off send 30 s later (never inside the sale transaction), re-checks order is still paid, sends once, retries on failure; online orders still wait for `completed`. Test `wh/tests-local/stockfeed-pos-test.php` 15/15; regression suite + unit all PASS. Needs C-08 for the real counter path. Source now ahead of the 0.9.8 ZIP (release bump in L-07). |
| L-02 | Entitlement: Business membership system only on wholesale shops SFM4U opened (licensed shop flag on the receiver/shop pairing). Non-licensed sites never show member price or card features. Owner to confirm exact rule (see Owner questions). | READY |
| L-03 | Two-site tests | DONE (Claude 07:45 UTC) — `tools/qa/two-site.sh` (shop :8080, master :8899, AlgiBazar receiver :8894 with Dokan): link-shop 16/16, link-central 12/12, pair-master 4/4, pair-shop-finish 6/6, stock-recv-setup 5/5, stock-send-test 6/6, receiver stock 20 as draft PASS, bos-shop-test 33/33 (on AlgiBazar Connector built from current source), bos-master-check 3/3. Test fix: bos-shop-test master path via SFM4U_MASTER_WP. Note: committed `wh/receiver-build/out` is a stale 0.9.5 build -> rebuild + version bump at release (L-07). |
| L-04 | Dokan compatibility | DONE (Claude 07:15 UTC) — Dokan Lite 5.3.5 active on the shop site: full WP suite (13 files) PASS; profit page via Dokan seller path. |
| L-05 | ERP mock reconciliation | REVIEW (Claude 07:25 UTC) — Found 2 gaps and fixed in src/Erp/ErpSync.php: (1) POS sales were exported inside the sale transaction -> now a one-off job after commit, re-checks the sale; (2) refunds never reached ERPNext -> now a return Sales Invoice (is_return=1, return_against original, negative qty) once per refund, retries if the original is not in ERP yet; amount-only refunds refused with a note (manual credit note). Mock now validates returns. Test `wh/tests-local/erp-refund-test.php` 14/14 (ERP stock: sold 3, returned 1 -> net -2). erp-order 12/12. ERP export stays OFF by default. |
| L-06 | CI | DONE locally — `.github/workflows/ci.yml` (lint + unit + secret guard). GitHub Actions run NOT TESTED from this session (no Actions access). |
| L-07 | Release candidate: ZIP + SHA + source commit + rollback in Factory/01 Ready Project after all lanes pass. | BLOCKED until C-01..07, L-01..05 pass |

## Owner questions (no change until answered)
0. This repo is PUBLIC on GitHub (checked 2026-11-21 07:45 UTC via API). No secrets/personal data found by scan. Keep public or make private (Settings -> General -> Danger Zone)?
1. G8 whole-taka rule at the POS (round cash totals? up/down/nearest?).
2. Black auto stock target: the member's AlgiBazar.com store (current design) or also his own separate shop website/POS?
3. "Only shops we open get Business membership": is a shop = a WordPress site paired by us, or a Dokan store on AlgiBazar?
