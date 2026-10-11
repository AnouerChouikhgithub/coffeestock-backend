# CoffeeStock — Phase 1 Requirements

Status: **PROPOSED — pending user approval** (documented in `DEVELOPMENT_ROADMAP.md` Phase 1; not yet accepted into implementation).

This document defines the functional and business requirements for the CoffeeStock internal stock-management system. Requirements are numbered `REQ-NNN`, grouped by business capability. Each requirement has a one-line rationale and a verifiable acceptance criterion.

Scope boundaries follow `DEVELOPMENT_ROADMAP.md`:
- **In scope:** raw materials and consumable supplies; inventory quantities and units; suppliers; receipts and received batches; consumption and waste; stock adjustments and stocktaking; inventory history; reports and CSV export; authentication; authorization; validation; auditability.
- **Out of scope:** point of sale; customers; loyalty; online ordering; payments; accounting; revenue.

---

## 1. Raw materials and consumable supplies

### REQ-001 — Material catalogue entry
Each stock-keeping item (raw material or consumable supply) is recorded in the system as a **Material** with a unique name within the catalogue, a base stock unit, and optional reorder and stocktaking thresholds.

*Rationale:* the material is the primitive every other inventory capability references.

*Acceptance criterion:* creating a material with a duplicate name fails with a validation error; a material created with a valid name, base unit, and optional thresholds is retrievable by API with all supplied values.

### REQ-002 — Material base unit of measure
Every material has exactly one **base unit** (e.g. `g`, `ml`, `piece`) chosen from a fixed, system-defined unit list. All stock quantities for the material are tracked and reported in this base unit.

*Rationale:* a single canonical unit per material removes ambiguity in receipts, consumption, and reports.

*Acceptance criterion:* a material cannot be created or updated without a valid base unit from the approved unit list; the unit list is exposed via `GET /api/units`; assigning a unit outside the list is rejected with HTTP 422.

### REQ-003 — Material quantities to order (thresholds)
A material may define a **reorder threshold**: when stock on hand falls at or below this quantity, the material appears in "below threshold" reporting.

*Rationale:* the coffee shop needs a simple, deterministic signal for what to purchase.

*Acceptance criterion:* for a material with a reorder threshold, any stock level at or below the threshold is included in the below-threshold report; a material without a threshold is never included.

### REQ-004 — Consumable vs raw material typing
Each material is typed as a **raw material** (used in production) or a **consumable supply** (used operationally; e.g. cups, lids, cleaning supplies). Type affects reporting filters only, not stock accounting.

*Rationale:* the business distinguishes what is consumed by making drinks versus running the shop.

*Acceptance criterion:* both types pass through identical stock accounting; reports can filter by material type and return only the matching subset.

---

## 2. Inventory quantities and units

### REQ-010 — Quantities are decimal, shared-precision values
All stock, receipt, consumption, waste, and adjustment quantities are decimal numbers stored and transmitted with a single project-wide precision and scale (see `domain-model.md`, "Decimal precision"). Quantities are never represented as floating-point binary values in persistence or JSON payloads.

*Rationale:* fractional stock (grams, millilitres) must survive storage and transport losslessly.

*Acceptance criterion:* a quantity such as `0.1` + `0.2` accumulated over movements sums to a stored value of exactly `0.3` (to the defined scale) — never `0.30000000000000004` — whenever stock is re-derived from the ledger.

### REQ-011 — Unit conversion on receipt and consumption
Receipts, consumption, and waste may be recorded in the **same unit** as the material's base unit. Other units are **not accepted** for stock-affecting operations in Phase 1 — only the decimal value in the material's base unit is stored.

*Rationale:* restricting stock-movement entry to base units keeps the ledger unit-consistent and avoids speculative multi-unit machinery.

*Acceptance criterion:* an attempt to record a receipt or consumption line whose unit differs from the material's base unit is rejected with HTTP 422 carrying error code `UNIT_MISMATCH`.

### REQ-012 — Non-negative and bounded input quantities
Any quantity submitted to a stock-affecting endpoint must be a finite decimal greater than zero and not exceeding a project-wide maximum (10 000 000.000).

*Rationale:* prevents zero/negative submissions and absurd values that would corrupt the ledger or break precision assumptions.

*Acceptance criterion:* quantity values of `0`, `-1`, a value with more than 3 decimal places, or a value above the maximum are each rejected with HTTP 422 and a field-level validation error.

### REQ-013 — Stock on hand
**Stock on hand** for a material is always **derived** from the immutable movement ledger: it is the sum of signed movement quantities for that material (receipts positive; consumption, waste, and negative adjustments negative; positive adjustments positive; stocktake deltas as recorded). Stock on hand is never stored as an editable quantity column (see ADR-0001, PROPOSED).

*Rationale:* a derived figure cannot silently drift from history.

*Acceptance criterion:* recomputing stock on hand from the movement ledger for any material reproduces exactly the value the inventory API returns, at all times including immediately after concurrent movements.

---

## 3. Suppliers

### REQ-020 — Supplier record
A **Supplier** carries a required unique name and optional contact name, email, and phone number.

*Rationale:* receipts must be attributable to who supplied the goods, even if contact detail is unknown at first.

*Acceptance criterion:* a supplier can be created with only a name; a duplicate supplier name is rejected with HTTP 422.

### REQ-021 — Soft supplier deactivation
A supplier may be deactivated (not deleted). Deactivated suppliers are hidden from selection lists and from creating new receipts, but remain readable and remain attached to historical receipts.

*Rationale:* history must stay attributable even when a supplier stops trading with the business.

*Acceptance criterion:* a receipt cannot be created against a deactivated supplier (HTTP 422, error code `SUPPLIER_INACTIVE`); deactivating a supplier does not alter any historical receipt.

---

## 4. Receipts and received batches

### REQ-030 — Receipt header
A **Receipt** records a single delivery from a supplier: the supplier, the delivery date (defaults to today, UTC, and never in the future), and a collection of receipt **items**.

*Rationale:* deliveries are the atomic business event by which stock enters the shop.

*Acceptance criterion:* a receipt with a delivery date in the future is rejected with HTTP 422; a receipt without at least one item is rejected with HTTP 422.

### REQ-031 — Receipt item creates a batch movement
Each receipt item references a material, a quantity in the material's base unit (per REQ-012 rules), an optional unit purchase cost, and creates exactly one **positive** stock movement, type `RECEIPT`. The item may reference an existing batch or carry batch metadata (per the batch-number policy, see `open-decisions.md` item OD-6).

*Rationale:* received goods must translate into ledger entries without a separate manual stock step.

*Acceptance criterion:* after a successful receipt with N items, the ledger contains exactly N movements of type `RECEIPT` with positive quantities equal to the items, and the materials' stock on hand increases accordingly.

### REQ-032 — Receipt immutability
Once recorded, a receipt and its items are **immutable**. Corrections are made by later compensating ledger movements, never by editing the receipt (per the historical-correction policy, see `open-decisions.md` item OD-3).

*Rationale:* immutable receipts keep purchase history and stock provenance trustworthy.

*Acceptance criterion:* no endpoint exists that mutates a recorded receipt or its items; any compensating entry for a mis-keyed receipt is itself a ledger movement with an audit record.

---

## 5. Consumption and waste

### REQ-040 — Consumption recording
**Consumption** is a stock decrease recording that material was used in operations. Each consumption entry references one material, a positive quantity in the material's base unit, and an optional free-text note. Each consumption entry creates exactly one **negative** ledger movement of type `CONSUMPTION`.

*Rationale:* usage is the primary stock-exit event and must be traceable.

*Acceptance criterion:* a valid consumption entry produces one `CONSUMPTION` movement and reduces the material's stock on hand by exactly the submitted quantity.

### REQ-041 — Waste recording
**Waste** is a stock decrease recording loss, spoilage, or disposal, distinguished from consumption by movement type and by an optional reason note. Waste never re-enters stock as consumption.

*Rationale:* the business needs to separately track avoidable loss from productive use.

*Acceptance criterion:* a valid waste entry produces one `WASTE` movement; filtering inventory history by `WASTE` returns only waste entries; waste quantities never appear in consumption aggregates.

### REQ-042 — Dated entry recording
Consumption and waste may be recorded with a date; if omitted, the entry date is "now" (UTC). Entries dated in the past are allowed only per the historical-correction policy (`open-decisions.md` OD-3); entries dated in the future are always rejected.

*Rationale:* real-world logging often happens once at end of day, so back-dated entries must be supported without letting future fiction into the ledger.

*Acceptance criterion:* an entry dated in the future is rejected with HTTP 422; an entry dated "now" when no date is supplied is recorded with a server-assigned UTC timestamp.

---

## 6. Stock adjustments and stocktaking

### REQ-050 — Stock adjustments
A **Stock Adjustment** is a signed, operator-initiated ledger movement (`ADJUSTMENT`, positive or negative) used to correct stock that is not a receipt, consumption, or waste. Each adjustment carries a mandatory reason note and takes effect per the negative-stock policy (`open-decisions.md` OD-1).

*Rationale:* real inventory drifts; a controlled correction channel that stands apart from operational movements keeps accounting sane.

*Acceptance criterion:* an adjustment with an empty reason is rejected with HTTP 422; an approved adjustment produces exactly one `ADJUSTMENT` movement with the signed quantity and reason recorded.

### REQ-051 — Stocktaking events (count and reconcile)
A **Stocktake** records a physical count of one or more materials at a point in time. For each counted material, the system computes the **delta** between counted quantity and current derived stock on hand and records one `STOCKTAKE_DELTA` movement per material to reconcile stock to the physical count.

*Rationale:* periodic physical counts are how the ledger is pulled back to reality.

*Acceptance criterion:* after a completed stocktake with delta entries, every affected material's stock on hand equals its counted quantity exactly.

### REQ-052 — Stocktake variance tolerance and approval
Variances (absolute deltas) beyond a configured tolerance trigger an approval step before the delta is committed; variances within tolerance may be committed directly. Tolerance values and approver role are per `open-decisions.md` OD-5 (UNRESOLVED; the default proposed there applies once accepted).

*Rationale:* large discrepancies should face human review before ever hitting the ledger.

*Acceptance criterion:* a variance within the accepted tolerance commits without approval; a variance beyond tolerance remains uncommitted until an approval record exists — and no API accepts it before then.

---

## 7. Inventory history

### REQ-060 — Complete, immutable movement history
Every stock-changing event (receipt item, consumption, waste, adjustment, stocktake delta) produces exactly one **Stock Movement** ledger row recording material, movement type, signed quantity, source document reference, timestamp, and acting user. Ledger rows are never edited or deleted.

*Rationale:* a single append-only movement source is the basis for stock derivation, reconciliation, and audit.

*Acceptance criterion:* for any material, the ordered movement history reconstructs stock on hand by cumulative summation, and no ledger row can be altered through any endpoint at any time.

### REQ-061 — Material-scoped history query with filters
The history API returns movements for a material, filterable by movement type and date range, newest first, paginated (`page`, `perPage`).

*Rationale:* operators need to answer "why did this stock change?" without export tooling.

*Acceptance criterion:* requesting history filtered by a movement type returns only movements of that type for that material, in descending timestamp order, honouring pagination bounds.

### REQ-062 — Read-only provenance on movements
Each movement displays its source: receipt id, consumption/waste entry id, adjustment id, stocktake id, or the acting user where no document exists.

*Rationale:* auditability requires each ledger row to point back to the business event that produced it.

*Acceptance criterion:* every ledger row returned by the history API carries either a source document reference or an acting-user attribution, never neither.

---

## 8. Reports and CSV export

### REQ-070 — Current inventory report
A report of all materials with current stock on hand, base unit, material type, and below-threshold flag (per REQ-003), filterable by material type.

*Rationale:* day-to-day purchasing questions are answered by one up-to-date list.

*Acceptance criterion:* the report reflects stock derived from the ledger at request time and marks every material currently at or below its reorder threshold.

### REQ-071 — Material movement report
A date-range and type-filterable movement report (consumed / wasted / received / adjusted / stocktake deltas) per material or across all materials.

*Rationale:* operational review and loss analysis need aggregated views of movement types over time.

*Acceptance criterion:* sums per movement type over the selected range equal the per-type sums computed independently from the ledger.

### REQ-072 — CSV export of reports
Any report can be exported as CSV with a stable, documented column order and comma-separated values, honouring the report's filters. Numeric columns are rendered with the project decimal scale and `.` decimal separator, without thousands separators.

*Rationale:* spreadsheets are how the business will actually consume this data.

*Acceptance criterion:* exporting the current-inventory report produces a CSV whose numeric column values, parsed back as decimals, match the JSON report values exactly, in the same row order.

---

## 9. Authentication

### REQ-080 — Authenticated access to all inventory endpoints
Every endpoint under `/api` except `GET /api/health` requires an authenticated user. Unauthenticated requests receive HTTP 401 with the standard error payload.

*Rationale:* the system holds business-sensitive inventory data and is not public.

*Acceptance criterion:* calling any inventory endpoint without credentials returns HTTP 401; `GET /api/health` remains reachable without credentials.

### REQ-081 — Token/session mechanism and user roles
Users authenticate with a username/password login endpoint that returns a token (see the security mechanism UNRESOLVED item, `open-decisions.md` OD-8). Users have one of the roles **ADMIN**, **MANAGER**, or **OPERATOR** (super set of Operator). Details per OD-2.

*Rationale:* authorization and audit both depend on knowing who performed each action and what they may do.

*Acceptance criterion:* login with valid credentials returns a token; login with invalid credentials returns HTTP 401 and never reveals whether the username exists.

### REQ-082 — Audit attribution of the acting user
Every stock-affecting write records the acting authenticated user's identity in the movement row.

*Rationale:* accountability for who changed stock is a hard audit requirement.

*Acceptance criterion:* for any ledger row created after this requirement ships, the acting user id is non-null and matches the authenticated principal of the API call that created it.

---

## 10. Authorization

### REQ-090 — Role-based write permissions
Role permissions for stock-affecting operations follow the matrix defined in `api-contract.md` ("Authorization matrix"). The baseline proposal there (Operator may consume/waste, Manager may receipt/adjust/stocktake, Admin only may manage suppliers and users) is **PROPOSED, not decided** — see OD-2.

*Rationale:* stock-affecting powers should not default to the broadest role available.

*Acceptance criterion:* enforcing the accepted matrix, a role absent from an endpoint's permission set receives HTTP 403 on that endpoint and HTTP 200/201 on endpoints it holds.

### REQ-091 — Historical correction authority
Who may record dated-in-the-past entries and compensating corrections follows OD-3 (UNRESOLVED until decided). Until the policy is accepted, back-dated entries are accepted only from roles holding Manager or Admin, and compensating corrections require an approval record.

*Rationale:* tampering with history must be at least as hard as the strictest candidate policy.

*Acceptance criterion:* while OD-3 is unresolved, an Operator-role attempt to back-date a stock-affecting entry is rejected with HTTP 403.

---

## 11. Validation

### REQ-100 — Server-side validation of all input
All incoming request bodies are validated server-side against explicit schemas; the API trusts nothing from the client. Failures return HTTP 422 with the standard error payload and per-field error details.

*Rationale:* client-side checks are advisory; correctness lives at the boundary.

*Acceptance criterion:* every documented request field that carries a validation constraint (documented per endpoint in `api-contract.md`) rejects invalid values with HTTP 422 and a field-level error array; no accepted request bypasses validation.

### REQ-101 — Enumerated consent for enums
Fields restricted to an enumerated set (material type, movement type filters, report type, unit) reject values outside the enumeration with HTTP 422 identifying the allowed set in the error detail.

*Rationale:* enumerated domains are the cheapest place to fail fast and loudly.

*Acceptance criterion:* submitting an out-of-enum value to any enum-constrained field returns HTTP 422 with a detail message listing valid values.

---

## 12. Auditability

### REQ-110 — Audit log of stock-affecting operations
Every stock-affecting operation (receipt, consumption, waste, adjustment, stocktake delta, and any compensating correction) writes one **Audit Log** row capturing who (user), what (target entity and id), when (UTC timestamp), and outcome (success/failure reason reference). Audit-log rows are append-only.

*Rationale:* the ledger records stock truth; the audit log records human accountability for changing it.

*Acceptance criterion:* for every successful stock-affecting API call, exactly one audit-log row exists referencing the ledger movement created; audit rows cannot be modified or deleted through any endpoint.

### REQ-111 — Audit-log and ledger retention is a business decision
Retention duration and access rights for audit and movement history follow OD-4 (UNRESOLVED). Until decided, no purge, truncation, or retention-limit code is implemented, and history is retained indefinitely.

*Rationale:* deleting audit/ledger data prematurely is irreversible; deferring retention to a decision avoids an unfixable gap.

*Acceptance criterion:* no endpoint or job exists that deletes or truncates audit-log or ledger rows while OD-4 is unresolved.

### REQ-112 — Audit-log visibility is admin-scoped
The audit-log listing endpoint is Admin-only. Other roles do not receive audit-log data.

*Rationale:* audit surfacing must not itself become a data-exposure risk.

*Acceptance criterion:* a non-Admin role requesting the audit-log listing receives HTTP 403 while Admin receives HTTP 200 with paginated results.

---

## Traceability table

Capability → requirement IDs → API endpoints → business rules (BR-x rules are defined here; OD-x items are **UNRESOLVED** and tracked in `open-decisions.md`).

| Capability | Requirement IDs | API endpoints | Business rules |
| --- | --- | --- | --- |
| Raw materials and consumable supplies | REQ-001 … REQ-004 | `POST/GET /api/materials`, `GET/PATCH /api/materials/{id}`, `GET /api/units` | BR-1 unique material name; BR-2 one base unit per material from approved unit list; BR-3 crossing reorder threshold = below-threshold report inclusion; BR-4 type is raw vs consumable |
| Inventory quantities and units | REQ-010 … REQ-013 | `PATCH /api/materials/{id}`, all stock-affecting endpoints, `GET /api/inventory`, `GET /api/inventory/{materialId}` | BR-5 decimals with scale 3 and 10 000 000.000 max (DEC-1); BR-6 base-unit-only stock entries (DEC-2); BR-7 stock on hand derived from ledger (ADR-0001); BR-8 non-negative signed input quantity validation |
| Suppliers | REQ-020, REQ-021 | `POST /api/suppliers`, `GET /api/suppliers`, `GET/PATCH /api/suppliers/{id}`, `POST /api/suppliers/{id}/deactivate` | BR-9 unique supplier name; BR-10 deactivated suppliers excluded from receipt creation but kept readable |
| Receipts and received batches | REQ-030 … REQ-032 | `POST /api/receipts`, `GET /api/receipts`, `GET /api/receipts/{id}` | BR-11 delivery date not in future; BR-12 receipt items -> positive `RECEIPT` movements; BR-13 receipts immutable after creation |
| Consumption and waste | REQ-040 … REQ-042 | `POST /api/consumptions`, `POST /api/wastes` | BR-14 consumption -> negative `CONSUMPTION` movement; BR-15 waste -> negative `WASTE` movement; BR-16 no future-dated entries |
| Stock adjustments and stocktaking | REQ-050 … REQ-052 | `POST /api/adjustments`, `POST /api/stocktakes`, `GET /api/stocktakes/{id}` | BR-17 adjustment requires reason; BR-18 stocktake delta = counted − derived stock; BR-19 tolerance/approval per OD-5 |
| Inventory history | REQ-060 … REQ-062 | `GET /api/inventory/{materialId}/movements` | BR-20 append-only movements; BR-21 movement = one stock event; BR-22 history reconstructs stock on hand |
| Reports and CSV export | REQ-070 … REQ-072 | `GET /api/reports/inventory`, `GET /api/reports/movements`, `GET /api/reports/inventory/export.csv`, `GET /api/reports/movements/export.csv` | BR-23 derived-at-request-time stock in reports; BR-24 CSV numbers = scale 3, `.` separator; BR-25 movement sums equal ledger sums |
| Authentication | REQ-080 … REQ-082 | `POST /api/login`, all `/api` endpoints | BR-26 all `/api` except `/api/health` requires auth; BR-27 401 + uniform error payload; BR-28 acting user recorded on every write |
| Authorization | REQ-090, REQ-091 | all mutating endpoints + `GET /api/audit-logs` | BR-29 role matrix per api-contract.md (PROPOSED, pending OD-2); BR-30 back-dating restricted to Manager+ until OD-3 decided |
| Validation | REQ-100, REQ-101 | all `/api` endpoints | BR-31 422 + per-field errors; BR-32 enum fields reject out-of-set values |
| Auditability | REQ-110 … REQ-112 | audit rows written by all stock-affecting endpoints; `GET /api/audit-logs` | BR-33 append-only audit rows; BR-34 ledger + audit retained indefinitely until OD-4 decided; BR-35 audit listing Admin-only |
