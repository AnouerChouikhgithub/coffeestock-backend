# Phase 5 Plan — Consumption, Waste, Adjustments, Stocktaking

Status: BUILT (blanket approval, owner 2026-10-12 — plan-approval gates no longer pause the run).

## Scope (api-contract.md, endpoints 16–21)

- POST /api/consumptions — negative CONSUMPTION movement per entry (REQ-040)
- POST /api/wastes — negative WASTE movement per entry (REQ-041), distinct ledger type,
  reason required via `note` (BR-15; no schema change — note carries the reason)
- POST /api/adjustments — signed ADJUSTMENT with mandatory reason (REQ-050, BR-17)
- POST /api/stocktakes — DRAFT with counts; server snapshots derived stock, stores
  delta = counted − derived (REQ-051)
- GET /api/stocktakes/{id} — draft or committed resource with counts
- POST /api/stocktakes/{id}/commit — STOCKTAKE_DELTA per non-zero delta, atomic (INV-10)

No schema migration is needed: all entities/tables shipped in the Phase 2 migration.

## Policies adopted (recorded defaults)

- **OD-1 (negative stock)** — pre-approved decision, zero-floor variant: any entry whose
  resulting derived balance would fall below 0.000 is rejected with
  409 NEGATIVE_STOCK_BLOCKED (consumption, waste, adjustment, stocktake delta).
  An *adjustment* may push stock negative only when the request explicitly carries
  `approved: true` — the pre-approved OD-1 wording ("manager-approved adjustment with
  a reason"); approval is modelled as an `approval` row. Role enforcement ships in
  Phase 9; until then the acting system user is accepted as approver.
- **OD-5 (stocktake tolerance)** — pre-approved decision: per-material
  `stocktake_threshold` when set, else the default 2% of the snapshot derived stock.
  Over-tolerance counts require an `approvals` entry at commit else 409
  APPROVAL_REQUIRED. Adjustments whose |signed_delta| exceeds the material's
  stocktake_threshold follow the same rule (contract's proposed rule, adopted).
- **Audit (REQ-110)** — every successful stock-affecting operation writes one
  append-only AuditLog row (SUCCESS), including stocktake commits.
- **Concurrency** — UsageService/AdjustmentService/StocktakeService take
  `SELECT … FOR UPDATE` on the material row inside the write transaction before
  reading the balance (StockGuard::lockMaterial), so two simultaneous consumptions
  cannot both observe the same pre-write balance. Covered by StockConcurrencyTest.

## Behavior details

- recorded_at defaults to now UTC; future timestamps rejected 422 (INV-7); parsed
  by shared App\Service\Timestamp
- movement occurred_at = recorded_at (consumption/waste/adjustment); stocktake
  delta occurred_at = commit time
- stocktake draft snapshots derived stock per material at creation; delta stays
  frozen; commit creates STOCKTAKE_DELTA movements only for non-zero deltas, sets
  reconciliation_movement_id and status → COMMITTED in ONE transaction
- zero-floor check re-runs at commit time (stock may have moved since the draft)
- draft stocktakes are deletable per OD-7 default; no DELETE endpoint in this phase
  to keep the diff focused

## Tests

- UsageApiTest: happy path (201, ledger sum, provenance back-fill, audit), 404
  unknown material, 422 invalid quantities / future timestamps / inactive material,
  409 NEGATIVE_STOCK_BLOCKED, exact-floor allowed, waste reason required,
  WASTE-vs-CONSUMPTION type separation
- AdjustmentApiTest: reason required, non-zero delta required, both signs move
  stock, OD-1 blocked without approval / allowed with `approved: true` (+ approval
  row), OD-5 threshold block / override, audit rows
- StocktakeApiTest: draft snapshot + frozen delta, ledger untouched while DRAFT,
  validation failures (empty, 404, negative, duplicate), commit within tolerance,
  over-tolerance 409 APPROVAL_REQUIRED then success with approval entry, per-material
  threshold override, zero-delta no movement, re-commit 409 STOCKTAKE_NOT_DRAFT,
  downward delta zero floor at commit, 404 unknown stocktake
- StockConcurrencyTest: rival connection holds the material row lock → API writer
  cannot commit a movement; after release the same consumption succeeds once and
  the floor still holds
