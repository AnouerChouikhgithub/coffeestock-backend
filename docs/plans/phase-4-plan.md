# Phase 4 Plan — Suppliers, Receipts, and Batches

Status: IN_PROGRESS (blanket approval, owner 2026-10-12).

## Scope (api-contract.md)

Suppliers: POST /api/suppliers (201, 422 DUPLICATE_NAME), GET /api/suppliers
(paginated, includeInactive/search filters), GET /api/suppliers/{id}, PATCH
/api/suppliers/{id} (name/contact fields), POST /api/suppliers/{id}/deactivate (204).

Receipts: POST /api/receipts — records a delivery; creates N positive RECEIPT
movements in ONE transaction (INV-1), plus Batch rows when batch metadata is supplied
or the material requires batches (OD-6 default, `Material.batch_required`).
GET /api/receipts (paginated headers + item counts; supplier_id/material_id/date
filters), GET /api/receipts/{id} (full items).

## Rules enforced

- supplier must exist (404) and be active (422 SUPPLIER_INACTIVE, REQ-021)
- delivery_date ≤ today UTC (422 VALIDATION_FAILED, REQ-030)
- ≥1 item (422); each item: active material (422 MATERIAL_INACTIVE), quantity
  scale-3, >0, ≤10 000 000.000 (422 VALIDATION_FAILED, REQ-012); unit_cost ≥ 0
- batch_number required when material.batch_required is true (OD-6)
- receipt + items immutable: no PATCH/DELETE endpoints exist (REQ-032)
- ledger: exactly one positive RECEIPT movement per item, signed_quantity = item
  quantity (REQ-031), occurred_at = delivery date (UTC midnight)

## Assumption (recorded)

Auth is not yet implemented (Phase 9, OD-8). Ledger rows require non-null attribution
(INV-11), so writes attribute to a find-or-create "system" user until the authenticated
principal replaces it in Phase 9. Documented in PROGRESS.md and the PR body.

## Tests

SupplierApiTest: create/list/read/patch/deactivate; duplicate name 422; email validation.
ReceiptApiTest: happy path creates N movements + batch + items (reconciles against
inventory sum), future date 422, empty items 422, inactive supplier 422 SUPPLIER_INACTIVE,
inactive material 422 MATERIAL_INACTIVE, RAW material without batch → 422, invalid
quantity 422, list filters, 404 unknown ids, immutability (no PATCH route → 405).
