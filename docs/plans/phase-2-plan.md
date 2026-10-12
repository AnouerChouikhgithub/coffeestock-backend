# Phase 2 Plan — Backend Domain Model and Database Migrations

Status: **DRAFT awaiting owner approval** (plan-approval phase per `docs/AGENT_PROMPT.md`).
Written: 2026-10-12.

## Scope

Implement Doctrine **entities and migration(s) only** — no controllers, no API endpoints, no
business-rule enforcement beyond what the schema itself can carry. Decimals are mapped as
`decimal` columns with scale 3, returned/handled as **strings** (never PHP `float`), matching
the JSON-string transport rule in `docs/api-contract.md`.

Everything here derives from `docs/domain-model.md` (11 entities) and
`docs/decisions/ADR-0001-stock-accounting-model.md` (append-only ledger model).

### Adopted-by-default decisions used in this plan

Per owner ruling (2026-10-12 in chat), the recommended defaults in `docs/open-decisions.md`
are treated as decided for Phase 2 unless the owner overrides before build starts:

- **OD-3** — `Manager+` may back-date entries; compensating entries require Admin approval.
  Schema impact: `User.role` is needed now on the attribution FKs, so a minimal `User`
  entity (identity + role enum) ships in Phase 2 ahead of Phase 9 auth hardening.
- **OD-6** — Batch number: required flag per material, default ON for `RAW`, OFF for
  `CONSUMABLE`. Schema impact: `Material.batch_required: boolean` and `Batch.batch_number`
  stays nullable (constraint enforced at app level, not DB level).
- **OD-7** — Field immutability exactly as written in the domain-model tables; plus
  `AppEnum`-side DRAFT stocktake deletion allowed. Schema impact: none beyond what the
  entity definitions already state.

This plan does NOT resolve OD-1 (negative stock), OD-2 (role matrix beyond needing the
`User.role` column to exist), OD-4 (audit retention), OD-5 (stocktake tolerance), OD-8 (auth
mechanism), or OD-9 (rounding) — those stay UNRESOLVED in `docs/open-decisions.md`.

## Entities to create (11)

Each under `coffeestock-backend/src/Entity/`, Doctrine attributes, strict types, PSR-12.

1. **Material** — `name:string unique max:120`, `type:enum(RAW|CONSUMABLE)`,
   `base_unit:string enum(g|kg|ml|l|piece)`, `reorder_threshold:decimal(12,3) nullable`,
   `stocktake_threshold:decimal(12,3) nullable`, `batch_required:bool default:false`,
   `is_active:bool default:true`, `created_at/updated_at` (UTC). `base_unit` immutability is
   enforced in-app (a service-layer check), not by the DB, since any movement depends on it.
2. **Supplier** — `name:string unique max:160`, `contact_name:string nullable max:120`,
   `email:string nullable valid-email`, `phone:string nullable max:32`, `is_active:bool`,
   timestamps.
3. **Receipt** — `supplier:FK→Supplier`, `delivery_date:date ≤ today`, note, timestamps,
   immutable post-creation.
4. **ReceiptItem** — `receipt:FK`, `material:FK`, `quantity:decimal(12,3) >0 ≤10M`,
   `unit_cost:decimal(12,3) nullable ≥0`, `batch:FK→Batch nullable`, `stock_movement:FK`.
5. **Batch** — `material:FK`, `supplier:FK`, `batch_number:string nullable`,
   `received_at:datetime UTC`, `expires_at:date nullable`.
6. **ConsumptionEntry** — material, positive `quantity:decimal(12,3)`, `recorded_at ≤ now`
   (UTC), note, `created_by_user:FK→User`, `stock_movement:FK`.
7. **WasteEntry** — same shape as ConsumptionEntry, distinct type.
8. **StockAdjustment** — material, `signed_delta:decimal(13,3) ≠0 within ±10M`,
   `reason:string max:500 non-empty`, `created_by_user`, `approval:FK→Approval nullable`,
   `stock_movement:FK`.
9. **Stocktake** — `taken_at ≤ now`, `status:enum(DRAFT|COMMITTED)`,
   `created_by_user`, `committed_by_user:FK nullable`.
10. **StocktakeCount** — `stocktake:FK`, `material:FK unique-within-stocktake`,
    `counted_quantity:decimal(12,3) ≥0`, `derived_stock_at_count:decimal(12,3)`,
    `delta:decimal(13,3)`, `reconciliation_movement:FK nullable`.
11. **StockMovement** (the ledger) — `material:FK`, `type:enum(RECEIPT|CONSUMPTION|WASTE|ADJUSTMENT|STOCKTAKE_DELTA)`,
    `signed_quantity:decimal(13,3) ≠0`, `occurred_at ≤ now UTC`,
    `source_document_type:enum(RECEIPT_ITEM|CONSUMPTION_ENTRY|WASTE_ENTRY|ADJUSTMENT|STOCKTAKE_COUNT)`
    + `source_document_id` (targetId, not an FK — polymorphic provenance),
    `created_by_user:FK`, `batch:FK nullable`. Append-only at the app level.

12. **AuditLog** — `actor_user:FK→User`, `action:enum(...)`, `target_entity:string`,
    `target_entity_id`, `occurred_at`, `outcome:enum(SUCCESS|FAILURE)`, `detail:jsonb nullable`.
    Append-only.

(Polymorphic provenance uses the `source_document_type + source_document_id` pair rather
than an FK, exactly as `docs/domain-model.md` specifies for StockMovement.)

### User / Approval (the two entities the domain model says to defer)

Per the domain-model's "User / Approval" section these are Phase 9 in business terms, but
**schema-wise they are needed NOW** because every stock-writing row requires attribution
(`created_by_user_id / actor_user_id` non-null, INV-11) and OD-3 needs `User.role`:

- **User** — `username:string unique max:120`, `password:string` (hashed placeholder,
  real hashing lands in Phase 9), `role:enum(OPERATOR|MANAGER|ADMIN)`. No auth/login logic in
  Phase 2.
- **Approval** — `approver_user:FK→User`, `target_entity:string`, `target_entity_id`,
  `comment:string nullable max:500`, `created_at`. Basis for OD-3/OD-5 approval records.

## Repositories

Doctrine repositories for entities that need queries even without controllers: `MaterialRepository`,
`SupplierRepository`, `StockMovementRepository` (ledger aggregation groundwork). Others get the
default `EntityRepository` until their phase adds behavior.

## Enum handling

PHP 8.3 backed string enums (App\Enum\*) for `MaterialType`, `BaseUnit`, `MovementType`,
`SourceDocumentType`, `StocktakeStatus`, `AuditOutcome`, `UserRole`. Doctrine native
`EnumType` (symfony/doctrine < 7.4 ships it) rather than string columns.

## Migrations

Single, ordered migration per entity group as Doctrine generates them, committed and run
against the **test database only**. The dev database will NOT be migrated by me — I'll
document the migrate command so the owner can run it when ready (this respects AGENT_PROMPT:
"Never run tests or destructive migrations against the dev database").

## Tests (drive the build; written alongside entities)

Per AGENT_PROMPT step 4 (roadmap test-cases — "persistence of material and supplier data",
"precision checks for decimals and quantities", "uniqueness and relational integrity checks")
plus roadmap acceptance criteria ("migrations are deterministic and repeatable"):

- **Schema-level (no controllers yet):**
  - `MigrationSmokeTest` — `doctrine:database:drop`/`create`/`migrations:migrate` on the
    test DB only, asserting zero errors, and that
    `doctrine:schema:validate --skip-sync` passes.
  - `MaterialPersistenceTest` — create/read with all fields set, including a
    `0.001`-vs-`12345678.123` decimal round-trip asserting scale-3 string equality.
  - `SupplierPersistenceTest` — create/read with validation attributes in place.
  - `StockMovementUniquenessTest` — unique constraint guarantee on Material.name and
    Supplier.name (a duplicate insert throws, catches what the DB does).
  - `AuditLogPersistenceTest` — JSONB `detail` round-trip (a nested array stores/retrieves
    equal).
  - `LedgerAppendOnlySchemaTest` — schema-level check that no `ON UPDATE CASCADE` /
    `ON DELETE SET NULL` exists on FKs pointing INTO `stock_movement` (this is the closest
    thing to OD-7's "no endpoint mutations" guarantee that pure schema can offer).
- **Foundry factories** for `Material`, `Supplier`, `StockMovement` (used here by tests, and
  reused by phases 3–5+ functional tests later).

## Out of scope

- Controllers, endpoints, DTOs, validation plumbing around them (Phases 3–5).
- Auth/session logic (Phase 9) — `User` row storage only.
- Any business-rule enforcement beyond schema constraints (that's service-layer work, later).
- Frontend (Phases 6–8).

## Risks / assumptions

- **Doctrine enum handling:** the plan assumes `symfony/doctrine` ships a native EnumType in
  the version this project pins (verify before writing migrations; fall back to classic
  `string` columns + `EnumType` mapping if not — document in PROGRESS notes if it changes).
- **Casting decimals to strings:** I'll rely on Doctrine's `decimal` type + strict string
  getters to avoid float leakage; tests assert string equality round-trips, not float epsilon.
- **Permissions to touch `compose.yaml`** to add the pg_vol mount — not needed; compose.yaml
  already has the right volume, nothing to change.
- **No `docker compose down -v` used anywhere** (protects the existing database volume,
  per the local-rules'd rule about never deleting the dev volume).

## Estimate / order of work

1. Foundry + DoctrineFixturesBundle via composer (owner pre-approved this).
2. Enums first, then entities (Material & User first, then movement-ledger layer last).
3. Repositories.
4. Migration via `doctrine:migrations:diff` against the test database.
5. Tests alongside each entity batch (not all at the end).
6. `php-cs-fixer` + `phpstan` clean.
7. Update `docs/DEVELOPMENT_SETUP.md` statement about what's now implemented, plus roadmap
   "verified evidence" — after verification passes, per AGENT_PROMPT step 6.
