# CoffeeStock — Phase 1 API Contract

Status: **PROPOSED — pending user approval** (documented in `DEVELOPMENT_ROADMAP.md` Phase 1; endpoints here are a contract for review, not an implemented surface).

Every `/api/*` endpoint planned for CoffeeStock, with purpose, method, path, request schema, response schema, status codes, and the error format. Conventions follow `docs/api-baseline.md`.

Conventions:
- All request and response bodies are JSON unless stated otherwise (CSV export is `text/csv`).
- All timestamps are UTC ISO-8601 strings, e.g. `2026-10-11T09:30:00+00:00`.
- All decimals are JSON **strings** — see "Decimal representation in JSON" below.
- Pagination for list endpoints: `?page=1&perPage=50` (`perPage` max 100, default 50). Responses use `page`, `perPage`, `total`, `items`.
- Authentication: all endpoints require `Authorization: Bearer <token>` except `GET /api/health` (see OD-8 for the token mechanism decision).

---

## Decimal representation in JSON

All decimal quantities in request and response bodies are encoded as **JSON strings**, never JSON numbers:

```json
{ "quantity": "1.250", "reorder_threshold": null }
```

**Why:** JSON numbers are binary floating-point in the vast majority of client runtimes (JavaScript's `Number` being the canonical case). Parsing `1.250` or accumulating `0.1 + 0.2` as binary floats produces artifacts (`0.30000000000000004`) and silent digit drift on round-trips. For an inventory ledger where sums must reconcile exactly against movement history (REQ-013, INV-12), transport must preserve the exact decimal literal. Strings do this: `"0.1"`, `"0.2"`, and `"0.3"` round-trip bit-for-bit as decimal literals. The server stores decimals as SQL `DECIMAL(12,3)`/`DECIMAL(13,3)` and serializes them to strings with exactly 3 decimal places.

Client rule: parse quantity strings with a decimal-capable type (e.g. JS `bigint`-scaled arithmetic, `decimal.js`, PHP `bcmath`); never cast them to `Number`/float for arithmetic.

### Authorization matrix (PROPOSED, pending OD-2)

| Capability | OPERATOR | MANAGER | ADMIN |
| --- | --- | --- | --- |
| Materials read / units / inventory / history / reports | ✓ | ✓ | ✓ |
| Material create/update | — | ✓ | ✓ |
| Suppliers create/update/deactivate | — | ✓ | ✓ |
| Receipts create/read | — | ✓ | ✓ |
| Consumption / waste create | ✓ | ✓ | ✓ |
| Stock adjustment create | — | ✓ | ✓ |
| Stocktake create / commit | ✓ create DRAFT | ✓ commit | ✓ |
| Audit-log listing | — | — | ✓ |

Role set and assignment are per OD-2 (UNRESOLVED); this matrix is the baseline proposal to react to, not a decision.

---

## Error format

Every error response uses one payload shape:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Request validation failed.",
    "details": [
      { "field": "quantity", "issue": "must be greater than 0" }
    ]
  }
}
```

- `code` — stable machine-readable string (see the catalogue below).
- `message` — human-readable summary.
- `details` — array, present for validation errors and empty (`[]`) elsewhere; each item has `field` (dot-path) and `issue`.
- No stack traces, no internal identifiers, no secret material in any error payload.

Status codes used and when:
- `200` — successful read or update
- `201` — successful creation; response body is the created resource; `Location` header set for singleton creations
- `204` — successful deletion-equivalent operations (e.g. deactivate) with no body
- `400` — malformed JSON body or unparsable request
- `401` — missing/invalid credentials (`AUTH_REQUIRED`, `AUTH_INVALID`)
- `403` — authenticated but lacking permission (`FORBIDDEN`)
- `404` — resource not found (`NOT_FOUND`)
- `409` — policy conflict, e.g. negative-stock rejection under the decided OD-1 policy (`CONFLICT`)
- `422` — semantic validation failure (`VALIDATION_FAILED`, or codes below)
- `500` — unexpected server error (`INTERNAL_ERROR`), logged, never detailed to the client

Error code catalogue (stable strings): `VALIDATION_FAILED`, `AUTH_REQUIRED`, `AUTH_INVALID`, `FORBIDDEN`, `NOT_FOUND`, `CONFLICT`, `UNIT_MISMATCH`, `SUPPLIER_INACTIVE`, `MATERIAL_INACTIVE`, `DUPLICATE_NAME`, `NEGATIVE_STOCK_BLOCKED`, `APPROVAL_REQUIRED`, `STOCKTAKE_NOT_DRAFT`, `INTERNAL_ERROR`.

---

## Endpoints

### GET /api/health (existing)

Purpose: confirm the application is up and the database is configured; no authentication.
Request: none.

Response `200`:
```json
{
  "status": "ok",
  "database": "ok",
  "timestamp": "2026-10-11T09:30:00+00:00"
}
```
Statuses: `200`, `503` (if the database check fails, `"database": "failed"`).
Errors: standard payload with `INTERNAL_ERROR` on unexpected failure.

---

### POST /api/login

Purpose: exchange credentials for a session token (mechanism per OD-8).
Request:
```json
{ "username": "string (1..120)", "password": "string (1..200)" }
```

Response `200`:
```json
{ "token": "string", "expires_at": "2026-10-11T17:30:00+00:00", "user": { "id": 1, "username": "barista1", "role": "OPERATOR" } }
```
Statuses: `200`, `400` (malformed), `401` (`AUTH_INVALID`), `422`, `500`.
Errors: `401` responses never reveal whether the username exists (same payload for unknown user and wrong password).

---

### GET /api/units

Purpose: list the fixed approved unit set (REQ-002).
Request: none (auth required).

Response `200`:
```json
{ "items": [ "g", "kg", "ml", "l", "piece" ] }
```
Statuses: `200`, `401`, `500`.

---

### POST /api/materials

Purpose: create a material (REQ-001..004).
Authorization: Manager, Admin.

Request:
```json
{
  "name": "string (1..120, unique)",
  "type": "RAW | CONSUMABLE",
  "base_unit": "g | kg | ml | l | piece",
  "reorder_threshold": "string decimal ≥ 0 or null",
  "stocktake_threshold": "string decimal ≥ 0 or null"
}
```

Response `201`: the Material resource as in `GET /api/materials/{id}`.

Statuses: `201`, `400`, `401`, `403`, `422` (`VALIDATION_FAILED`, `DUPLICATE_NAME`, `UNIT_MISMATCH` for an out-of-list unit), `500`.

---

### GET /api/materials

Purpose: list materials with filters and pagination.
Request: none (auth required; query parameters below).
Query: `?page=1&perPage=50&type=RAW|CONSUMABLE&search=<name substring>&includeInactive=false`.

Response `200`:
```json
{
  "page": 1, "perPage": 50, "total": 42,
  "items": [ { "id": 1, "name": "Espresso beans", "type": "RAW", "base_unit": "g", "reorder_threshold": "500.000", "stocktake_threshold": null, "is_active": true, "created_at": "2026-10-11T09:30:00+00:00" } ]
}
```
Statuses: `200`, `401`, `422` (invalid filter values), `500`.

---### GET /api/materials/{id}

Purpose: retrieve one material.
Request: none (auth required; material id in path).
Response `200`: Material resource as in `GET /api/materials` (single item shape).
Errors: standard error payload.
Statuses: `200`, `401`, `404`, `500`.

---

### PATCH /api/materials/{id}

Purpose: update an editable material field.
Authorization: Manager, Admin.
Request: any subset of `{ "name", "type", "reorder_threshold", "stocktake_threshold", "is_active" }`.
`base_unit` is **not accepted** here (immutable per domain model; any movement depends on it).

Response `200`: updated Material resource.
Statuses: `200`, `400`, `401`, `403`, `404`, `422` (`VALIDATION_FAILED`, `DUPLICATE_NAME`; also `422` with `VALIDATION_FAILED` if `base_unit` is present in the body), `500`.

---

### POST /api/suppliers

Purpose: create a supplier (REQ-020).
Authorization: Manager, Admin.
Request: `{ "name": "string (1..160, unique)", "contact_name": "string|null (≤120)", "email": "string|null (valid email)", "phone": "string|null (≤32)" }`

Response `201`: Supplier resource:
```json
{ "id": 1, "name": "Roastery Co", "contact_name": "Ann", "email": "ann@roastery.example", "phone": "+1234", "is_active": true }
```
Statuses: `201`, `400`, `401`, `403`, `422` (`VALIDATION_FAILED`, `DUPLICATE_NAME`), `500`.

---

### GET /api/suppliers

Purpose: list suppliers.
Request: none (auth required; query parameters below).
Query: `?page=&perPage=&includeInactive=false&search=`.
Response `200`: paginated supplier items as above.
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/suppliers/{id}

Purpose: retrieve one supplier (active or inactive).
Request: none (auth required; supplier id in path).
Response `200`: Supplier resource as in `POST /api/suppliers` (with `is_active` reflecting current state).
Errors: standard error payload.
Statuses: `200`, `401`, `404`, `500`.

---

### PATCH /api/suppliers/{id}

Purpose: update supplier contact fields.
Authorization: Manager, Admin.
Request: subset of `{ "name", "contact_name", "email", "phone" }`.
Response `200`: updated supplier.
Statuses: `200`, `400`, `401`, `403`, `404`, `422` (`DUPLICATE_NAME`, `VALIDATION_FAILED`), `500`.

---

### POST /api/suppliers/{id}/deactivate

Purpose: soft-deactivate a supplier (REQ-021).
Authorization: Admin only.
Request: none.
Response `204` empty body.
Statuses: `204`, `401`, `403`, `404`, `500`.

---

### POST /api/receipts

Purpose: record a delivery (REQ-030..032) → creates N positive `RECEIPT` movements + audit rows.
Authorization: Manager, Admin.
Request:
```json
{
  "supplier_id": 1,
  "delivery_date": "2026-10-11",
  "note": "string|null (≤500)",
  "items": [
    {
      "material_id": 1,
      "quantity": "1.250",
      "unit_cost": "12.500",
      "batch": { "batch_number": "RO-2026-041", "expires_at": null }
    }
  ]
}
```
Rules enforced: at least one item; delivery date ≤ today; supplier active; each quantity per REQ-012; each material active and its unit is the base unit implicitly (no unit field exists — see the unit labeling note in `domain-model.md`, INV-6); batch metadata per OD-6 (nullable unless policy decides otherwise).

Response `201`:
```json
{
  "id": 10,
  "supplier_id": 1,
  "delivery_date": "2026-10-11",
  "created_at": "2026-10-11T09:30:00+00:00",
  "created_by_user_id": 3,
  "items": [ { "id": 20, "material_id": 1, "quantity": "1.250", "unit_cost": "12.500", "batch_id": 4, "stock_movement_id": 881 } ]
}
```
Statuses: `201`, `400`, `401`, `403`, `404` (supplier id unknown), `422` (`VALIDATION_FAILED`, `SUPPLIER_INACTIVE`, `MATERIAL_INACTIVE`), `500`.

---

### GET /api/receipts

Purpose: list receipts.
Request: none (auth required; query parameters below).
Query: `?page=&perPage=&supplier_id=&material_id=&date_from=&date_to=`.
Response `200`: paginated receipt headers with item counts (not full items).
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/receipts/{id}

Purpose: retrieve one receipt with full items.
Request: none (auth required; receipt id in path).
Response `200`: Receipt resource as in `POST /api/receipts` response.
Errors: standard error payload.
Statuses: `200`, `401`, `404`, `500`.

---

### POST /api/consumptions

Purpose: record one consumption of one material (REQ-040).
Authorization: Operator, Manager, Admin.
Request:
```json
{ "material_id": 1, "quantity": "0.250", "recorded_at": "2026-10-11T09:00:00+00:00 or null", "note": "string|null (≤500)" }
```
Rules: quantity > 0; recorded_at ≤ now (back-dating per OD-3); negative balance permitted only per decided OD-1 policy else `409 NEGATIVE_STOCK_BLOCKED`.

Response `201`: `{ "id": 55, "material_id": 1, "quantity": "0.250", "recorded_at": "...", "created_by_user_id": 2, "stock_movement_id": 882 }`
Statuses: `201`, `400`, `401`, `404` (material id unknown), `409` (`NEGATIVE_STOCK_BLOCKED`), `422`, `500`.

---

### POST /api/wastes

Purpose: record one waste of one material (REQ-041) — same shape, rules and statuses as `POST /api/consumptions`, distinct ledger type `WASTE`.
Authorization: Operator, Manager, Admin.
Request:
```json
{ "material_id": 1, "quantity": "0.100", "recorded_at": "2026-10-11T09:00:00+00:00 or null", "note": "Spoiled milk — string|null (≤500)" }
```
Response `201`: `{ "id": 56, "material_id": 1, "quantity": "0.100", "recorded_at": "...", "created_by_user_id": 2, "stock_movement_id": 884 }`
Statuses: `201`, `400`, `401`, `404` (material id unknown), `409` (`NEGATIVE_STOCK_BLOCKED`), `422`, `500`.

---

### POST /api/adjustments

Purpose: record a signed stock adjustment (REQ-050).
Authorization: Manager, Admin.
Request:
```json
{ "material_id": 1, "signed_delta": "-0.500", "reason": "Spillage during decanting", "recorded_at": "… or null" }
```
Rules: `signed_delta ≠ 0`, within ±10 000 000.000 scale 3; non-empty `reason`; negative resulting balance per OD-1 else `409 NEGATIVE_STOCK_BLOCKED`; `APPROVAL_REQUIRED` per OD-5 if the material's stocktake threshold is exceeded by |delta| (proposed rule — pending OD-5).

Response `201`: `{ "id": 9, "material_id": 1, "signed_delta": "-0.500", "reason": "…", "approval_id": null, "created_by_user_id": 2, "stock_movement_id": 883 }`
Statuses: `201`, `400`, `401`, `404` (unknown material), `409`, `422`, `500`.

---

### POST /api/stocktakes

Purpose: create a DRAFT stocktake with physical counts (REQ-051).
Authorization: Operator (create DRAFT), Manager, Admin.
Request:
```json
{
  "counts": [
    { "material_id": 1, "counted_quantity": "82.000" }
  ]
}
```
Rules: ≥1 count; each material unique within the stocktake; quantities ≥ 0; the server snapshots `derived_stock_at_count` = current ledger-derived stock at creation. Material must be active.

Response `201`:
```json
{
  "id": 7, "status": "DRAFT", "taken_at": "2026-10-11T10:00:00+00:00",
  "created_by_user_id": 2, "committed_by_user_id": null,
  "counts": [ { "id": 30, "material_id": 1, "counted_quantity": "82.000", "derived_stock_at_count": "84.123", "delta": "-2.123", "reconciliation_movement_id": null } ]
}
```
Statuses: `201`, `400`, `401`, `404`, `422` (duplicate material, invalid values, `MATERIAL_INACTIVE`), `500`.

---

### GET /api/stocktakes/{id}

Purpose: retrieve a stocktake with counts and deltas.
Request: none (auth required; stocktake id in path).
Response `200`: the resource shape as in `POST /api/stocktakes` (with `status` either `DRAFT` or `COMMITTED`).
Errors: standard error payload.
Statuses: `200`, `401`, `404`, `500`.

---

### POST /api/stocktakes/{id}/commit

Purpose: commit a DRAFT stocktake, converting each count's delta into a `STOCKTAKE_DELTA` movement (REQ-051, REQ-052).
Authorization: Manager, Admin.
Request: `{ "approvals": [ { "count_id": 30, "approver_comment": "string|null" } ] }` — entries only for counts whose |delta| exceeds tolerance per OD-5.
Rules: stocktake is DRAFT else `409 STOCKTAKE_NOT_DRAFT`; counts over-tolerance require an approval entry else `409 APPROVAL_REQUIRED`; the acting user must satisfy the OD-5 approver role; commit is atomic — all deltas land or none (INV-10). On success each count's `reconciliation_movement_id` is set, status → `COMMITTED`, `committed_by_user_id` set; an audit row `STOCKTAKE_COMMIT` is written.

Response `200`: the committed Stocktake resource.
Statuses: `200`, `400`, `401`, `403`, `404`, `409` (`STOCKTAKE_NOT_DRAFT`, `APPROVAL_REQUIRED`), `500`.

---

### GET /api/inventory

Purpose: current inventory overview (REQ-070): material, stock on hand, base unit, type, below-threshold flag.
Request: none (auth required; query parameters below).
Query: `?page=&perPage=&type=RAW|CONSUMABLE&belowThresholdOnly=false`.
Response `200`:
```json
{
  "page": 1, "perPage": 50, "total": 42,
  "items": [ { "material_id": 1, "name": "Espresso beans", "type": "RAW", "base_unit": "g", "stock_on_hand": "84.123", "below_threshold": false } ]
}
```
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/inventory/{materialId}

Purpose: current stock on hand and detail for one material (REQ-013 read).
Request: none (auth required; material id in path).
Response `200`: single item shape as above.
Statuses: `200`, `401`, `404`, `500`.

---

### GET /api/inventory/{materialId}/movements

Purpose: movement history for a material (REQ-061).
Request: none (auth required; material id in path and query parameters below).
Query: `?page=&perPage=&movement_type=RECEIPT|CONSUMPTION|WASTE|ADJUSTMENT|STOCKTAKE_DELTA&date_from=&date_to=`, newest first.
Response `200`:
```json
{
  "page": 1, "perPage": 50, "total": 120,
  "items": [ { "id": 881, "movement_type": "RECEIPT", "signed_quantity": "1.250", "occurred_at": "2026-10-11T09:30:00+00:00", "source_document_type": "RECEIPT_ITEM", "source_document_id": 20, "created_by_user_id": 3, "batch_id": 4 } ]
}
```
Statuses: `200`, `401`, `404` (unknown material), `422`, `500`.

---

### GET /api/reports/inventory

Purpose: same data as `GET /api/inventory`, full-fidelity (all pages available, no `belowThresholdOnly` shorthand limits); kept as a separate route for reporting semantics and export symmetry.
Request: none (auth required; query parameters below).
Query: `?type=&date=` (date snapshot reconstruction is **out of scope** — `date` accepted only as `today`).
Response `200`: same item shape as `GET /api/inventory`.
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/reports/movements

Purpose: movement aggregates (REQ-071).
Request: none (auth required; query parameters below).
Query: `?date_from=&date_to=&material_id=&movement_type=`.
Response `200`:
```json
{ "items": [ { "material_id": 1, "movement_type": "CONSUMPTION", "total_quantity": "12.750", "movement_count": 31 } ] }
```
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/reports/inventory/export.csv

Purpose: CSV export (REQ-072) of the current-inventory report, honouring `?type=`.
Request: none (auth required; `?type=` query filter optional).
Response `200`, `Content-Type: text/csv; charset=utf-8`:
```csv
material_id,name,type,base_unit,stock_on_hand,below_threshold
1,Espresso beans,RAW,g,84.123,false
```
Decimal columns render with scale 3 and `.` separator; headers always present; row order matches the JSON report.
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/reports/movements/export.csv

Purpose: CSV export of the movement report, honouring the same filters.
Request: none (auth required; same query filters as `GET /api/reports/movements`).
Response `200`, `Content-Type: text/csv`:
```csv
material_id,movement_type,total_quantity,movement_count
1,CONSUMPTION,12.750,31
```
Statuses: `200`, `401`, `422`, `500`.

---

### GET /api/audit-logs

Purpose: read the audit trail (REQ-112). Admin only.
Request: none (auth required; query parameters below).
Query: `?page=&perPage=&actor_user_id=&action=&date_from=&date_to=`.
Response `200`:
```json
{
  "page": 1, "perPage": 50, "total": 500,
  "items": [ { "id": 900, "actor_user_id": 2, "action": "CONSUMPTION_CREATE", "target_entity": "consumption_entry", "target_entity_id": 55, "occurred_at": "2026-10-11T09:00:00+00:00", "outcome": "SUCCESS", "detail": null } ]
}
```
Statuses: `200`, `401`, `403`, `422`, `500`.

---

## Endpoint summary

| # | Method | Path | Purpose | Roles (baseline) |
| --- | --- | --- | --- | --- |
| 1 | GET | `/api/health` | liveness + DB check (no auth) | — |
| 2 | POST | `/api/login` | credentials → token | — |
| 3 | GET | `/api/units` | approved unit list | all |
| 4 | POST | `/api/materials` | create material | Manager, Admin |
| 5 | GET | `/api/materials` | list materials | all |
| 6 | GET | `/api/materials/{id}` | read material | all |
| 7 | PATCH | `/api/materials/{id}` | update material | Manager, Admin |
| 8 | POST | `/api/suppliers` | create supplier | Manager, Admin |
| 9 | GET | `/api/suppliers` | list suppliers | all |
| 10 | GET | `/api/suppliers/{id}` | read supplier | all |
| 11 | PATCH | `/api/suppliers/{id}` | update supplier | Manager, Admin |
| 12 | POST | `/api/suppliers/{id}/deactivate` | soft-deactivate supplier | Admin |
| 13 | POST | `/api/receipts` | record delivery | Manager, Admin |
| 14 | GET | `/api/receipts` | list receipts | all |
| 15 | GET | `/api/receipts/{id}` | read receipt | all |
| 16 | POST | `/api/consumptions` | record consumption | all roles |
| 17 | POST | `/api/wastes` | record waste | all roles |
| 18 | POST | `/api/adjustments` | signed correction | Manager, Admin |
| 19 | POST | `/api/stocktakes` | create DRAFT stocktake | all roles |
| 20 | GET | `/api/stocktakes/{id}` | read stocktake | all |
| 21 | POST | `/api/stocktakes/{id}/commit` | commit stocktake | Manager, Admin |
| 22 | GET | `/api/inventory` | inventory overview | all |
| 23 | GET | `/api/inventory/{materialId}` | one material stock | all |
| 24 | GET | `/api/inventory/{materialId}/movements` | movement history | all |
| 25 | GET | `/api/reports/inventory` | inventory report | all |
| 26 | GET | `/api/reports/movements` | movement aggregates | all |
| 27 | GET | `/api/reports/inventory/export.csv` | CSV export | Manager, Admin |
| 28 | GET | `/api/reports/movements/export.csv` | CSV export | Manager, Admin |
| 29 | GET | `/api/audit-logs` | audit trail | Admin |
