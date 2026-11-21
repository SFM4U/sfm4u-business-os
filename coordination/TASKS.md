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
| C-01 | Run every `tests/codex/*.php` against 0.9.8 on POS staging (`tools/qa/pos-staging.sh` builds it). Make a pass/fail matrix in `coordination/codex-matrix.md`. Tests written for 0.6.0: separate "test outdated" from "real defect" with evidence. | READY |
| C-02 | Member card at the counter end to end: commit (not only quote) with valid Business, paid Black, unpaid Black, expired, suspended, unknown card; price/permission rechecked at confirm; card changes between quote and confirm → 409. | READY |
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
| L-03 | Two-site tests (master :8899 + shop): link-central, link-shop, pair-*, stock-send/recv, bos-master/bos-shop; rerun here (were 16/16 + 11/11 on the Owner's Mac). | READY |
| L-04 | Dokan compatibility run: activate Dokan Lite 5.3.5 on the shop site; rerun B-002 suite; vendor rule/floor/profit pages. | READY |
| L-05 | ERP mock reconciliation: POS sale + refund → ERP order export (mock :8897) references match; failed queue + replay; no live ERP. | READY |
| L-06 | CI: GitHub Actions for unit tests + PHP lint (WP integration later). | READY |
| L-07 | Release candidate: ZIP + SHA + source commit + rollback in Factory/01 Ready Project after all lanes pass. | BLOCKED until C-01..07, L-01..05 pass |

## Owner questions (no change until answered)
1. G8 whole-taka rule at the POS (round cash totals? up/down/nearest?).
2. Black auto stock target: the member's AlgiBazar.com store (current design) or also his own separate shop website/POS?
3. "Only shops we open get Business membership": is a shop = a WordPress site paired by us, or a Dokan store on AlgiBazar?
