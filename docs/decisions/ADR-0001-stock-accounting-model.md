# ADR-0001 — Stock accounting model

Status: **PROPOSED** (not accepted — awaiting review alongside the OD-1…OD-8 decisions)

Date: 2026-10-11

## Context

CoffeeStock needs a rule for how the system knows how much of each material is on hand. The choice drives:

- data integrity and trustworthiness of inventory figures (the ledger is the product here — see REQ-013, REQ-060, INV-3, INV-12),
- how corrections, stocktakes, and adjustments behave,
- how auditability works (who changed stock, when, why),
- and the cost of implementing every downstream feature (reports, CSV export, reconciliation checks).

The candidate accounting models differ in where truth lives.

## Decision (proposed)

Adopt an **append-only stock-movement ledger** as the single source of truth for stock levels. Every stock-changing business event (receipt item, consumption, waste, adjustment, stocktake delta) appends exactly one immutable `StockMovement` row with a **signed quantity**. **Stock on hand is always derived** at read time as the sum of signed quantities per material:

```
stock_on_hand(material, t) = Σ signed_quantity(movement) for movement.material = material ∧ movement.occurred_at ≤ t
```

No mutable "current quantity" column exists on Material. All corrections (mis-keyed receipts, wrong quantities) are made by appending **compensating movements**, never by editing history. Decimal precision (scale 3), rounding rules (INV-8, pending the OD-9 rounding decision), units (base-unit-only), and sign rules are specified in `domain-model.md` ("Invariants").

## Considered alternatives

### Option A (proposed) — Append-only ledger with derived stock on hand

*Mathematical truth is rebuilt from events; nothing editable can leak.*

- **+ History is the product.** "Why did stock change?" is answerable directly from data, no separate audit reconstruction.
- **+ Corrections never destroy information.** A compensating movement preserves the fact that a mistake happened, by whom, and when — stronger audit story than edits.
- **+ Reconciliation is a first-class check.** Verifying derived stock against a physical count (stocktake) is literally what the model is built for; INV-12 gives a formal test that reports equal ledger sums.
- **+ Deterministic range reporting.** Point-in-time and date-range balances are computable from the same rows — needed for REQ-070/071 without extra bookkeeping.
- **− Read cost.** Every inventory read must aggregate movements. This is the main engineering objection.
- **− Storage growth.** Ledgers never shrink. (For an internal coffee shop: thousands, not millions, of rows per year — negligible for PostgreSQL.)
- **− Discipline required.** Every write path must go through the ledger; any shortcut that mutates a balance defeats the model.

*Read-cost mitigation built into the proposal:* the ledger is expected to be small enough that aggregate queries with an index on `(material_id, occurred_at)` remain comfortably fast (well under 10 ms at tens of thousands of rows). If profiling ever disproves this, a **snapshot-cache table** (`material_id, as_of, balance`) maintained inside the same transaction as movement inserts is a safe, purely additive optimization — derivation remains the semantic truth, the cache is just an accelerator. This is explicitly deferred; build no cache in Phase 1.

### Option B — Mutable current-quantity column on Material

*A classic inventory table: `quantity` column updated by application code on each receive/consume/adjust.*

- **+ Reads are trivial.** `SELECT quantity FROM material` — no aggregation ever.
- **+ Simplest mental model** for a first implementation.
- **− No trustworthy history without a second log.** You end up building an audit/movement table anyway to answer "why" — at which point you have both a mutable truth and a log, and the two can silently disagree.
- **− Concurrent writes are the subtle failure mode.** Two operators consuming simultaneously must serialize updates (locks/select-for-update) or the balance loses increments — exactly the class of bug that makes inventory systems lie.
- **− Corrections are destructive.** Fixing a wrong receipt means editing (or deleting) rows: the mistake becomes invisible, weakening both audit and debugging.
- **− Point-in-time reporting is impossible** retroactively unless the movement log exists anyway (then you are back to Option A with extra mutable state to defend).

### Option C — Current-quantity column + movement log (hybrid)

*Ledger writes happen, but a maintained balance column is used for reads.*

- **+ Fast reads, full history.** The best of both on paper.
- **− Two sources of truth.** The balance column is a cache written in several places; any bug, migration, or manual DB touch can desynchronize it from the ledger invisibly — the worst failure class for an inventory system, because nothing throws an error; numbers are just quietly wrong.
- **− INV-12 verification becomes mandatory forever.** Reconciliation-check code must run continuously (or on every write) to detect drift, adding permanent complexity.
- **Snapshot-cache variant (noted in Option A):** acceptable **only** as a derived, regenerable cache with a documented rebuild command; it is not the hybrid — the hybrid makes the cache the readable truth. This is why the proposal keeps reads on raw derivation and stores the cache-option for later, after measurement.

## Why the proposed option fits CoffeeStock specifically

- Hinting back at requirements: auditability and reconciliation are named requirements (REQ-060, REQ-112, INV-12), not nice-to-haves. The ledger model is the only candidate where those are structural rather than bolted-on.
- Scale: a coffee shop's movement volume is small; read-cost objections are theoretical here, and the escape hatch (snapshot cache) is designed in.
- The immutability decisions in `open-decisions.md` (OD-3 correction authority, OD-4 retention) presuppose history that cannot be reshaped; Option A is the model where that presupposition holds.

## Consequences

**If accepted:**
- Entities: `StockMovement` (append-only), plus source documents (`Receipt`, `Consumption`/`Waste` entries, `StockAdjustment`, `Stocktake`/`StocktakeCount`) as specified in `domain-model.md`.
- Every write path creates its movement inside the same DB transaction as the source document — atomicity is mandatory, not optional.
- Read paths (`/api/inventory`, reports) aggregate the ledger; performance work is deferred until measured (snapshot-cache escape hatch documented above).
- Corrections replace edits everywhere in the UI/API: no mutation endpoints for ledger rows or source documents (REQ-032, INV-2).
- Negative-stock and rounding policies (OD-1, OD-9) gate on top of this model without structural change.

**If rejected:** the fallback is Option C with mandatory continuous reconciliation checks — heavier and riskier, so rejection should weigh the audit requirements in `requirements.md` §12 honestly before discarding the ledger model.

## Related decisions

- OD-1 (negative stock) — how the ledger treats resulting negative balances.
- OD-3 (historical corrections) — who may append compensating movements.
- OD-4 (retention) — how long ledger rows live.
- OD-7 (field immutability) — confirms rows this ADR makes immutable.
- OD-9 / INV-8 (rounding) — how decimal aggregates are quantized.

All remain **UNRESOLVED** in `open-decisions.md`; this ADR does not presuppose their answers.
