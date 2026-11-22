# AGENTS.md · SFM4U Business OS (read first, every AI, every task)

Owner: MD SHALA UDDIN (SFM4U). Reply to the Owner in simple Bangla; technical terms in English.
First research today's latest official practice for the task and list your sources, then do the work.
Plan of record: Drive Factory/01 "Full Plan v2" + "SFM4U-BUSINESS-OS-POS-DECISION-AND-NIGHT-PLAN-2026-11-21" + Skill 209 (v4).

## Product in one line
One WordPress/WooCommerce plugin (`wh/sfm4u-wholesale`, slug `sfm4u-wholesale`) with modules: wholesale tiers, Business/Black member price
(card master = account.sfm4u.com), counter POS, stock/purchasing, B2B/Dokan vendors, Black auto stock to the member's AlgiBazar store (StockFeed), ERP link (mock).
ERPNext stays the accounting/stock ledger. Business OS never keeps a parallel accounting balance.

## Who owns what
| Area | Paths | Owner AI |
|---|---|---|
| Repo baseline, CI, releases/ZIP+SHA | `.github/`, `tools/`, `coordination/` | Claude |
| Pricing, tiers, rescale, member price, cards, CardLink/MemberLink, Black StockFeed, Dokan/Woo compatibility, ERP mock | `wh/sfm4u-wholesale/src/**` (except POS/inventory below), `whtest/`, `wh/tests-local/`, `wh/erp-tests/` | Claude |
| POS register, tender, receipt, refund/credit, drawer, daily close, stock counts/reconciliation | `wh/sfm4u-wholesale/modules/pos/**`, `wh/sfm4u-wholesale/modules/inventory-bridge/**`, `tests/codex/**` | Codex |
| Marketplace modules H–Q (returns, support, announce, abuse, earnings, withdrawplus, reviews, shopcoupon, shopstats, vendor dashboard/app) — plan `coordination/MARKETPLACE-MODULES-PLAN.md` | `wh/sfm4u-wholesale/modules/{returns,support,announce,abuse,earnings,withdrawplus,reviews,shopcoupon,shopstats}/**`, `wh/tests-local/<slug>-test.php` for those slugs | Codex |
| Module dashboards (ModuleHome, Dashboard), module contract, modules A–G (verify, shipping, staff, vendorplan, spmv, AlgiBazar plugin merge), joining and releases | `wh/sfm4u-wholesale/src/**`, `wh/sfm4u-wholesale/modules/{verify,shipping,staff,vendorplan,spmv,algibazar-*}/**` | Claude |
| Shared fixtures | `tests/fixtures/` | both (add, never silently change the other's) |

Never edit the other AI's area. Need a change there? Add a task in `coordination/TASKS.md` for that AI.

## Workflow (one task at a time)
1. `git pull`; read `coordination/TASKS.md`; take your first `READY` task; set `IN PROGRESS (name, UTC)`; push that line first.
2. Branch `claude/<id>-…` or `codex/<id>-…`. Small commits. PR to `main`. CI must be green.
   Exception: Claude's session has no PR tool — Claude pushes its own area to `main` only after all local checks pass and records evidence.
3. Record PASS / FAIL / NOT TESTED with exact commands, plugin ZIP SHA-256 and source commit in TASKS.md. Then take the next task.
4. If your session stops, the next run resumes from TASKS.md (no new plan). Keep the work resumable.

## Checks
- Unit: `ln -sfn "$PWD/wh" /home/claude/wh; for t in m c t m1 m2 m4; do php whtest/$t.php | tail -1; done`
- WordPress integration (shop site): see `wh/tests-local/` (WP-CLI `eval-file`; ERP mock `python3 wh/tests-local/mock_erpnext.py 8897`).
- POS staging (Codex lane): `tools/qa/pos-staging.sh` → 4-phase operations workflow (`/fixture-out/operations-workflow.txt`).

## Hard rules
- No secrets, real customer data, or live site/ERP/hosting changes. Synthetic fixtures only. No uploads to live sites.
- Money in integer minor units; never silently round prices or rewrite historical receipts. Whole-taka POS rule (G8) needs the Owner's decision first.
- Stale/expired/suspended/unpaid membership never grants a discount. A retry never creates a second sale/refund/stock move.
- Never claim tested/deployed without your own evidence.
