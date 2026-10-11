# CoffeeStock — Phase 1 Domain Model

Status: **PROPOSED — pending user approval** (see ADR-0001 for the stock accounting model, which is also PROPOSED).

This document defines the CoffeeStock domain vocabulary, entities, attributes, relationships, immutability rules, and invariants. It is self-contained: entity and field names used here are the same as those in `api-contract.md` and `requirements.md`.

---

## Glossary of domain terms

| Term | Definition |
| --- | --- |
| **Material** | A stock-keeping raw material (e.g. coffee beans, milk) or consumable supply (e.g. cups, lids) tracked in inventory. |
| **Raw material** | A Material used in production of sold items. |
| **Consumable supply** | A Material consumed operationally rather than in production. Same accounting as raw materials. |
| **Base unit** | The single canonical unit of measure for a Material. All stock quantities for a material are expressed in its base unit. |
| **Stock on hand** | The derived, current quantity of a Material, computed as the sum of signed quantities of all its stock movements. Never stored as an editable field (see ADR-0001). |
| **Supplier** | An external party from whom materials are purchased. |
| **Receipt** | A recorded delivery of one or more material quantities from a Supplier, composed of Receipt Items. |
| **Receipt Item** | One line of a Receipt: a material, its received quantity, optional unit cost, batch metadata, and the stock movement it produced. |
| **Batch** | A traceability grouping for received quantities of one material, identified by an optional supplier-assigned batch number. |
| **Consumption** | A recorded stock decrease representing operational or production use of a material. |
| **Waste** | A recorded stock decrease representing loss, spoilage, or disposal. Distinct from Consumption at ledger level. |
| **Stock Adjustment** | A signed, operator-initiated, reason-mandated correction movement that is not a receipt, consumption, waste, or stocktake delta. |
| **Stocktake** | A recorded physical count of one or more materials at a point in time, producing Reconciliation entries. |
| **Stocktake Count** | One material's physical counted quantity recorded within a Stocktake. |
| **Reconciliation** | The Stocktake Delta movement that aligns derived stock on hand to the physical counted quantity. |
| **Stock Movement** | The single, append-only ledger row describing one stock-changing event: type, signed quantity, material, timestamp, source, acting user. |
| **Ledger** | The ordered, immutable collection of all Stock Movements. The only source of truth for stock levels. |
| **Audit Log** | An append-only record of who performed which stock-affecting operation, when, and with what outcome. Distinct from the ledger: the ledger records stock truth; audit records human accountability. |
| **Reorder threshold** | A Material-level quantity; stock at or below it marks the material as below-threshold for reporting. |
| **Compensating entry** | A later stock movement recorded specifically to offset the stock effect of an earlier, incorrect entry. Never an edit of the original row. |

---

## Entities, attributes, and relationships

All timestamps are UTC. All decimals use scale 3 unless stated otherwise (see "Decimal precision" below).

### Material

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `name` | string | required, unique across materials, max 120 chars | mutable (rename does not affect history) |
| `type` | enum (`RAW`, `CONSUMABLE`) | required | mutable |
| `base_unit` | enum from unit list (`g`, `kg`, `ml`, `l`, `piece`) | required | immutable once set (any movement depends on it) |
| `reorder_threshold` | decimal(12,3), nullable | nullable; ≥ 0 | mutable |
| `stocktake_threshold` | decimal(12,3), nullable | nullable; ≥ 0; activates OD-5 tolerance threshold for this material | mutable |
| `is_active` | boolean | default true | mutable (soft delete only) |
| `created_at`, `updated_at` | timestamps | UTC | immutable/managed |

**Immutability rule:** `base_unit` is immutable once any Stock Movement exists for the material; before that, changing it is allowed because it changes the meaning of nothing recorded.

Relationships: has many Stock Movements (via source reference), has many Receipt Items.

### Supplier

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `name` | string | required, unique, max 160 chars | mutable |
| `contact_name` | string, nullable | max 120 | mutable |
| `email` | string, nullable | valid email format when present | mutable |
| `phone` | string, nullable | max 32 | mutable |
| `is_active` | boolean | default true | mutable |
| `created_at`, `updated_at` | timestamps | UTC | immutable/managed |

Relationships: has many Receipts. Deactivation (soft, not delete) hides the supplier from new-receipt selection but keeps all history readable.

### Receipt (header)

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `supplier_id` | FK → Supplier | required; must reference an active supplier at creation | immutable |
| `delivery_date` | date | required; ≤ today (UTC); defaults to today | immutable |
| `created_by_user_id` | FK → User | required | immutable |
| `created_at` | timestamp | UTC | immutable |

**Entirely immutable once recorded**, including its items (REQ-032). corrections happen only as compensating ledger movements per OD-3.

Relationships: belongs to Supplier; has many Receipt Items.

### Receipt Item

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `receipt_id` | FK → Receipt | required | immutable |
| `material_id` | FK → Material | required; material must be active | immutable |
| `quantity` | decimal(12,3) | required; > 0; ≤ 10 000 000.000 | immutable |
| `unit_cost` | decimal(12,3), nullable | nullable; ≥ 0; in the shop's **purchasing currency** (a single currency assumption — currency handling is out of Phase 1 scope) | immutable |
| `batch_id` | FK → Batch, nullable | per batch policy OD-6 | immutable |
| `stock_movement_id` | FK → Stock Movement | required; the one positive `RECEIPT` movement this item produced | immutable |

**Entirely immutable once recorded.**

### Batch

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `material_id` | FK → Material | required | immutable |
| `supplier_id` | FK → Supplier | required | immutable |
| `batch_number` | string, nullable | supplier-assigned; required only when the accepted OD-6 policy requires it; nullable otherwise | immutable |
| `received_at` | timestamp | UTC; set from the Receipt's delivery date | immutable |

Relationships: belongs to Material and Supplier; referenced by Receipt Items.

### Consumption / Waste Entry

`ConsumptionEntry` and `WasteEntry` share one shape but are distinct ledger-facing types:

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `material_id` | FK → Material | required; material must be active | immutable |
| `quantity` | decimal(12,3) | required; > 0; ≤ 10 000 000.000; interpreted as a **decrease** | immutable |
| `recorded_at` | timestamp | UTC; ≤ now; back-dating per OD-3 | immutable |
| `note` | string, nullable | max 500 | immutable |
| `created_by_user_id` | FK → User | required | immutable |
| `stock_movement_id` | FK → Stock Movement | required; the one negative movement produced | immutable |

**Entirely immutable once recorded.**

### Stock Adjustment

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `material_id` | FK → Material | required | immutable |
| `signed_delta` | decimal(13,3) | required; ≠ 0; range −10 000 000.000 … +10 000 000.000 | immutable |
| `reason` | string | required, non-empty, max 500 | immutable |
| `created_by_user_id` | FK → User | required | immutable |
| `approval_id` | FK → Approval, nullable | populated when OD-1/OD-5 policies require pre-approval | immutable |
| `stock_movement_id` | FK → Stock Movement | required | immutable |

**Entirely immutable once recorded.**

### Stocktake

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `taken_at` | timestamp | UTC, ≤ now | immutable |
| `status` | enum (`DRAFT`, `COMMITTED`) | a Stocktake is created DRAFT and becomes COMMITTED only when all its deltas have any required approvals (OD-5) | mutable only DRAFT → COMMITTED |
| `created_by_user_id` | FK → User | required | immutable |
| `committed_by_user_id` | FK → User, nullable | set at commit; must satisfy OD-5 approver role | immutable |

A Stocktake is committed wholesale — no partial commits.

### Stocktake Count (per material within a stocktake)

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `stocktake_id` | FK → Stocktake | required | immutable |
| `material_id` | FK → Material | required; unique within a Stocktake | immutable |
| `counted_quantity` | decimal(12,3) | required; ≥ 0; ≤ 10 000 000.000 | immutable |
| `derived_stock_at_count` | decimal(12,3) | snapshotted at DRAFT creation; ledger-derived | immutable |
| `delta` | decimal(13,3) | `counted_quantity − derived_stock_at_count` | immutable |
| `reconciliation_movement_id` | FK → Stock Movement, nullable | set when the stocktake commits | mutable only during commit |

Editable while DRAFT; frozen at COMMIT.

### Stock Movement (the ledger)

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `material_id` | FK → Material | required | immutable |
| `movement_type` | enum (`RECEIPT`, `CONSUMPTION`, `WASTE`, `ADJUSTMENT`, `STOCKTAKE_DELTA`) | required | immutable |
| `signed_quantity` | decimal(13,3) | required; ≠ 0; positive for `RECEIPT` and positive adjustments; negative for `CONSUMPTION`, `WASTE`, negative adjustments; `STOCKTAKE_DELTA` bears the delta's own sign | immutable |
| `occurred_at` | timestamp | UTC; ≤ now | immutable |
| `source_document_type` | enum (`RECEIPT_ITEM`, `CONSUMPTION_ENTRY`, `WASTE_ENTRY`, `ADJUSTMENT`, `STOCKTAKE_COUNT`) | required | immutable |
| `source_document_id` | identifier | required | immutable |
| `created_by_user_id` | FK → User | required | immutable |
| `batch_id` | FK → Batch, nullable | present for `RECEIPT` movements when batch is known | immutable |

**Fully append-only and immutable.** No update, no delete, from any endpoint, ever (REQ-060).

### Audit Log

| Attribute | Type | Constraints | Mutable? |
| --- | --- | --- | --- |
| `id` | identifier | system-generated | immutable |
| `actor_user_id` | FK → User | required | immutable |
| `action` | enum (one value per stock-affecting operation type, incl. `RECEIPT_CREATE`, `CONSUMPTION_CREATE`, `WASTE_CREATE`, `ADJUSTMENT_CREATE`, `STOCKTAKE_COMMIT`, `MATERIAL_UPDATE`, `SUPPLIER_UPDATE`) | required | immutable |
| `target_entity` + `target_entity_id` | string + identifier | required | immutable |
| `occurred_at` | timestamp | UTC | immutable |
| `outcome` | enum (`SUCCESS`, `FAILURE`) | required | immutable |
| `detail` | jsonb, nullable | free-form context (e.g. failure reason) | immutable |

**Fully append-only.** Retention and visibility per OD-4; listing is Admin-only (REQ-112).

### User / Approval

User accounts and approval records exist to satisfy authentication (REQ-080…082) and the OD-2/OD-3/OD-5 policies. `User` carries identity, password credential, and a single role (ADMIN/MANAGER/OPERATOR). `Approval` carries approver id, target document, timestamp, and comment. Their exact schema is part of Phase 9 hardening; only role-attribution is required now.

---

## Relationships summary

- Supplier 1—N Receipt 1—N Receipt Item N—1 Material
- Receipt Item 1—1 Stock Movement (`RECEIPT`)
- ConsumptionEntry / WasteEntry / Stock Adjustment 1—1 Stock Movement
- Stocktake 1—N Stocktake Count; each committed Count 1—1 Stock Movement (`STOCKTAKE_DELTA`)
- Material 1—N Stock Movement; Material 1—N Batch; Supplier 1—N Batch
- Stock Movement N—1 User (acting user); Audit Log N—1 User (actor)

---

## Invariants

### INV-1 — Ledger completeness
Every stock-changing business event produces exactly one Stock Movement row; no event produces more than one, and no Movement exists without a source document. *Checked:* on every write, in a transaction.

### INV-2 — Ledger immutability
Stock Movement rows are never updated or deleted. Corrections are new rows (compensating entries). *Checked:* structurally — no mutation path exists in code or API.

### INV-3 — Stock on hand is ledger-derived only
`stock_on_hand(material, t) = Σ signed_quantity of all movements of material with occurred_at ≤ t`. No stored, editable balance field exists. *Checked:* the inventory API computes and returns this value; ADR-0001 is the trade-off record.

### INV-4 — Sign rules per movement type
- `RECEIPT` movements: `signed_quantity > 0`
- `CONSUMPTION` and `WASTE` movements: `signed_quantity < 0`
- `ADJUSTMENT`: any non-zero sign
- `STOCKTAKE_DELTA`: sign follows `counted − derived`
*Checked:* persistence + service-layer validation on every write.

### INV-5 — Quantity precision and bounds (label DEC-1)
Every quantity obeys scale 3 (at most 3 decimal places) and magnitude ≤ 10 000 000.000 (label DEC-1). Signed quantities (adjustments, stocktake deltas) may be negative; all others > 0. *Checked:* persistence + validation on every write.

### INV-6 — Unit consistency (label DEC-2)
A stock-affecting quantity is always in the material's base unit (label DEC-2). No other unit can enter a movement. *Checked:* service-layer validation; no alternative-unit field exists on movements.

### INV-7 — Temporal sanity
No stock-affecting event is dated in the future relative to server UTC now. Back-dating is governed by OD-3. *Checked:* validation on every write.

### INV-8 — Rounding (OD-9)
Rounding ties to the rounding-policy decision **OD-9** in `open-decisions.md` (UNRESOLVED: half-up, half-even, or report-only rounding are the candidates). **Until OD-9 is decided, the provisional rule is:** half-up rounding to scale 3, applied only when quantizing at the persistence boundary; inputs are rejected if they carry more than 3 decimal places, so no rounding ever occurs at user-input time, and no rounding occurs mid-aggregation. If OD-9 is decided differently, the provisional rule is replaced, and reports + ledger both follow the decided rule.

### INV-9 — Source-document integrity
Each movement's `source_document_type` + `source_document_id` resolve to an existing row for its lifetime. Because source documents (receipt items, consumption entries, etc.) are themselves immutable, provenance never dangles. *Checked:* FK integrity at persistence.

### INV-10 — Reconciliation equality (stocktake)
When a Stocktake commits, for each counted material the resulting `STOCKTAKE_DELTA` movement brings the material's derived stock exactly to `counted_quantity`. *Checked:* recomputation at commit time, in the same transaction as the movement inserts.

### INV-11 — Attribution
Every Stock Movement and every Audit Log row has a non-null `created_by_user_id` / `actor_user_id` matching the authenticated principal of the creating API call. *Checked:* request context propagation on every write.

### INV-12 — Reporting consistency (reconciliation with history)
For any material and any date range, per-type movement sums from the reports API exactly equal the same sums computed independently by cumulating the ledger from empty. This is the formal statement that derived stock "reconciles with movement history" (see REQ-013 and REQ-071 acceptance criteria).

---

## Decimals and units — explicit statement (self-review item c)

- **Decimal representation:** all quantities persist as SQL `DECIMAL(12,3)` (up to 10 billion scaled units; plenty for a coffee shop's largest bulk order) and all movement-derived signed values as `DECIMAL(13,3)` (one extra digit to allow negative sums without overflow). They are **never** stored as binary floats.
- **Decimal transmission (JSON):** quantities are transmitted as **JSON strings** (e.g. `"1.250"`), not JSON numbers. Rationale: JavaScript's `Number` is a binary float; `0.1 + 0.2 === 0.30000000000000004`, and round-tripping `1.230` through a JSON number risks silent digit drift and premature rounding. Strings keep the exact decimal literal `(scale 3)` intact in both directions, free of exponent/notation issues; clients parse with a decimal-capable library. Full rationale and conventions: `api-contract.md`, "Decimal representation in JSON".
- **Scale = 3** covers grams (0.001 g) and millilitres; coffee-shop practical needs are met with margin. All numeric rounding obeys INV-8 until OD-9 is decided.
- **Units:** a fixed unit list (`g`, `kg`, `ml`, `l`, `piece`) is the complete set for Phase 1; the material's base unit is the only legal unit on any movement (labelled DEC-2 below; see also REQ-011).

---

## What is decided vs proposed vs unresolved here

- **Decided (provisional, still reviewable):** append-only ledger = source of stock (deep trade-off analysis in ADR-0001, itself only PROPOSED); base-unit-only movements; scale-3 decimals; string decimal transport in JSON; the full corpus of immutability rules above.
- **Proposed:** ADR-0001 (stock accounting model) — PROPOSED, not accepted.
- **Unresolved:** OD-1 … OD-8 in `open-decisions.md` (negative stock, rounding policy snap-in point, correction authority and approval flow, retention and access, stocktake tolerances, batch-number requirement, role-permission matrix, auth token mechanism). Where an invariant above (notably INV-8) depends on an unresolved decision, the **provisional rule** in force is stated inline and is explicitly replaceable.
