# Phase 3 Plan — Materials and Inventory API

Status: IN_PROGRESS (owner gave blanket go-ahead for all phases on 2026-10-12).

## Scope

Per docs/AGENT_PROMPT.md phase notes and docs/api-contract.md, and using the approved
domain model from Phase 2, this phase implements in `coffeestock-backend`:

- `GET /api/units` — fixed unit list, no DB access
- `POST /api/materials` — create (Manager/Admin by matrix baseline; auth hardening is Phase 9 so
  all roles currently accepted, recorded as a Phase 9 todo)
- `GET /api/materials` — list, filters: type, search, includeInactive, page/perPage
- `GET /api/materials/{id}` — read one
- `PATCH /api/materials/{id}` — update allowed fields only; `base_unit` explicitly rejected with
  422 (it is not in the allowed-field set; contract's 422 on PATCH if base_unit present)
- `GET /api/inventory` — list materials with current stock_on_hand derived from the ledger
  (INV-3), below-threshold flag (REQ-003), filters: type, belowThresholdOnly
- `GET /api/inventory/{materialId}` — one material stock
- `GET /api/inventory/{materialId}/movements` — movement history, filters: movement_type,
  date_from/date_to, pagination, newest first (REQ-061)

## Out of scope

Suppliers/receipts (Phase 4), consumption/waste/adjustment/stocktaking (Phase 5),
auth/session enforcement (Phase 9), CSV export (Phase 8).

Note: since Phase 3's endpoints are /api/* and mandate an Authorization header, but there is no
auth mechanism yet (OD-8 still unresolved and out of this phase's scope), this phase ships the
endpoints WITHOUT gating on authentication yet. The API contract's 401/403 semantics are
 deferred to Phase 9 when a real session/token mechanism exists. Recorded in PROGRESS.md.

## Design

- Controllers thin; logic in `App\Service\MaterialService` and `App\Service\InventoryService`.
- Request validation via Symfony Validator with DTO input classes (noaza direct $_POST access).
- Responses serialized manually to match the contract's exact JSON shapes (arrays), avoiding
  serializer metadata coupling; strings for all decimals, `null` passthrough.
- Errors use the contract's uniform payload via a small `ApiErrorResponse` helper.
- 400 on malformed JSON, 422 on validation failed / DUPLICATE_NAME / UNIT_MISMATCH /
  VALIDATION_FAILED for base_unit-in-body, 404 NOT_FOUND, 200/201/204 for success.
- Ledger integration: MaterialService records no movements itself (Phase 2 was schema-only);
  inventory derives stock via StockMovementRepository::getStockOnHand() (built in Phase 2).

## Tests

Functional (WebTestCase) hitting real routes on the TEST db:

- units: returns the exact 5-element array
- create: happy path (all fields), duplicate name → 422 DUPLICATE_NAME, invalid unit → 422
  UNIT_MISMATCH-style VALIDATION_FAILED with allowed list in detail, invalid type → 422,
  missing name → 422 with field details, quantity not supplied (create requires base fields)
- list: pagination bounds, type filter, search substring, includeInactive behaviour
- read: 404 for unknown id, 200 for known
- update: rename OK, base_unit-in-body → 422 VALIDATION_FAILED, duplicate name → 422,
  reorder_threshold null-tolerant; is_active=false soft delete semantics in list default
- inventory: matches ledger sums from seeded movements; includes materials with no movements
  (0.000) andbelowThresholdOnly filter type filter
- movements: newest-first ordering, per-type filter, date range, 404 for unknown material,
  pagination bounds

## Risks / assumptions

- Auth gating deferred (above), permissions enforced from Phase 9.
- The ledger currently has no writes from the API (receipts/etc. are Phase 4/5); inventory tests
  seed movements directly via factories to simulate prior phases' data.
- The api-contract requires Authorization on /api/* but this phase's tests run unauthenticated;
  still noted as Phase 9 hardening. If the owner prefers 401-until-Phase-9 semantics, that is
  a one-line change in each controller; recorded in PROGRESS notes as an open option.

## Verification steps

composer install; lint:container; schema:validate (skip sync since no new migration);
phpunit --testdox; phpstan analyse; then PR to feat/phase-2-schema (stacked).
