# CoffeeStock Development Roadmap

This roadmap defines the phased delivery plan for the CoffeeStock internal stock-management system. It is intentionally scoped to the project’s currently approved business domain and excludes unrelated POS, customer, loyalty, online ordering, and accounting features unless they are explicitly approved later.

## Project scope summary

In scope:
- raw materials and consumable supplies
- inventory quantities and units
- suppliers
- receipts and received batches
- consumption and waste
- stock adjustments and stocktaking
- inventory history, reports, and CSV export
- appropriate authentication, authorization, validation, and auditability

Out of scope unless explicitly approved:
- point of sale
- customer management
- loyalty programs
- online ordering
- payment, accounting, and revenue features

## Roadmap overview

| Phase | Status | Focus |
| --- | --- | --- |
| 0 | VERIFIED | Development environment and repository readiness |
| 1 | VERIFIED | Requirements, domain model, and API contract |
| 2 | VERIFIED | Backend domain model and migrations |
| 3 | VERIFIED | Materials and inventory API |
| 4 | VERIFIED | Suppliers, receipts, and batches |
| 5 | VERIFIED | Consumption, waste, adjustments, and stocktaking |
| 6 | VERIFIED | Frontend design system and application shell |
| 7 | NOT STARTED | Frontend inventory workflows |
| 8 | NOT STARTED | Dashboard, reports, and CSV export |
| 9 | NOT STARTED | Authentication, authorization, audit, and security hardening |
| 10 | NOT STARTED | Integration, release, and deployment readiness |

## Phase 0: Development environment and repository readiness

Status: VERIFIED

Goal and scope:
- confirm the workspace contains two independent repositories for frontend and backend
- verify dependency and tooling setup for each repo
- validate local PostgreSQL connectivity and backend health endpoint
- ensure README and project docs are present and self-contained

Prerequisites and dependencies:
- Node.js 20 LTS and npm are available
- PHP 8.3 and Composer are available
- Docker is available for local PostgreSQL
- both repositories are already initialized with independent Git remotes

Expected deliverables and files:
- `coffeestock-frontend/README.md`
- `coffeestock-backend/README.md`
- root `README.md`
- root `DEVELOPMENT_ROADMAP.md`
- root `docs/DEVELOPMENT_SETUP.md`
- shared docs under `docs/`
- frontend `package.json` scripts and backend `composer.json` scripts

Verified evidence:
- frontend repo remote exists: `https://github.com/AnouerChouikhgithub/coffeestock-frontend.git`
- backend repo remote exists: `https://github.com/AnouerChouikhgithub/coffeestock-backend.git`
- frontend package scripts are defined and validated in the repo
- backend PHPUnit suite passes after bootstrap fix (`OK (1 test, 2 assertions)`)
- backend Symfony container lint passes
- backend route `GET /api/health` is registered
- backend database query against PostgreSQL succeeds
- PHPStan passes without errors
- local backend environment uses PostgreSQL on host port `6543` mapped to container port `5432`

Coding-agent prompt:

```text
Set up the local CoffeeStock development foundation only. Confirm repository readiness, validate the frontend and backend configuration, and verify the environment is aligned with the existing package/composer scripts and local database mapping. Do not implement inventory business logic. Preserve each repository's independent Git history and avoid destructive commands. Document all verified commands and distinguish them from any commands that still need validation.
```

Test cases:
- frontend: `npm install` from fresh clone; expected result: dependencies install without errors
- frontend: `npm run lint`; expected result: passes or any issues are documented
- frontend: `npm run type-check`; expected result: passes or any issues are documented
- frontend: `npm run test`; expected result: passes or any issues are documented
- backend: `composer install`; expected result: dependencies install without errors
- backend: `php bin/console lint:container`; expected result: pass
- backend: `php vendor/bin/phpunit --testdox`; expected result: pass
- backend: `php vendor/bin/phpstan analyse --memory-limit=1G`; expected result: pass
- backend: `php bin/console doctrine:query:sql "SELECT 1"`; expected result: returns `1`

Acceptance criteria:
- frontend and backend repos are independent and maintain separate history
- README and setup docs are present and self-contained
- environment variables are configured without exposing secrets
- backend health endpoint responds successfully
- database connectivity is validated

Exit criteria and definition of done:
- both repos can be cloned and set up without access to the parent workspace
- required documentation is present and truthful
- all verification commands that were run have actual output supporting them

Unresolved business decisions:
- none for repository readiness itself

## Phase 1: Requirements, domain model, and API contract

Status: VERIFIED

Goal and scope:
- define business requirements for raw materials, stock, suppliers, receipts, usage, stocktaking, and reporting
- define the core domain model and API contract before business logic is implemented
- identify open business-policy decisions related to stock calculations, negative stock, correction rules, and auditability

Prerequisites and dependencies:
- Phase 0 verified and complete
- repository structure and local tooling are available

Expected deliverables and files:
- requirements document or ADRs under project docs
- API contract draft for `/api/*`
- domain glossary and invariants
- agreed stock accounting rules and business-policy decisions

Coding-agent prompt:

```text
Create the CoffeeStock requirements and API/domain contract for the stock-management domain before writing business logic. Cover raw materials, inventory, suppliers, receipts, consumption, waste, adjustments, stocktaking, and reporting. Define the API contract, domain vocabulary, and invariant checks for units, decimal precision, stock calculations, negative-stock policy, correction rules, and historical auditability. Explicitly call out unresolved business deciasions and require review points before implementation. Do not implement code or migration logic yet.
```

Test cases:
- requirement traceability review: each business capability maps to a named requirement
- API contract review: every endpoint has purpose, request schema, response schema, and error handling
- domain invariant review: unit conversions and decimal precision are explicitly specified
- negative-stock policy review: all scenarios are documented or marked unresolved
- historical correction review: what counts as a valid stock correction and how it is audited
- audit review: who can alter stock and how the change is traceable

Expected results for every test case:
- each requirement is mapped to a business rule or policy decision
- unresolved business decisions are explicitly labeled as unresolved
- the contract is reviewed before implementation begins

Acceptance criteria:
- no business logic is implemented before the contract exists
- every critical business rule is either explicitly defined or flagged unresolved
- stock accounting decisions are reviewed for accuracy and auditability

Exit criteria and definition of done:
- requirements, domain model, and API contract are approved for implementation
- unresolved business rules are listed and assigned for decision-making
- implementation can begin in a controlled, traceable manner

Unresolved business decisions:
- see `docs/open-decisions.md` for the authoritative list (OD-1 … OD-9) and the recommendations awaiting decision

Verified evidence:
- `docs/requirements.md` created: 40 numbered requirements (REQ-001 … REQ-112) across 12 capability groups, each with rationale and acceptance criterion, plus a capability → requirement → endpoint → business-rule traceability table
- `docs/domain-model.md` created: domain glossary, 11 entities with attributes/mutability rules, relationship summary, 12 numbered invariants (INV-1 … INV-12) covering ledger append-only rules, sign rules, scale-3 decimal precision (label DEC-1), base-unit-only entries (label DEC-2), rounding (INV-8, pending OD-9), and stock calculation reconciliation (INV-12)
- `docs/api-contract.md` created: 29 documented `/api/*` endpoints including the existing `GET /api/health`, each with purpose, method, path, request schema, response schema, status codes, and the uniform error payload; decimal-as-string JSON transport rule stated with rationale; proposed authorization matrix included
- `docs/open-decisions.md` created: 9 UNRESOLVED business decisions (OD-1 negative stock, OD-2 role matrix, OD-3 historical corrections, OD-4 audit retention, OD-5 stocktaking tolerance, OD-6 batch numbers, OD-7 field immutability, OD-8 auth token mechanism, OD-9 rounding policy), each with options, trade-offs, a recommended default, and the deciding party
- `docs/decisions/ADR-0001-stock-accounting-model.md` created: append-only movement ledger with derived stock levels PROPOSED (not accepted), with alternatives and trade-offs
-Self-review check (a) PASS: every capability in the Phase 1 scope maps to at least one requirement (traceability table covers all 12 groups).
- Self-review check (b) PASS: every one of the 29 endpoints has request schema, response schema, status codes, and error-handling documentation against the uniform error format.
- Self-review check (c) PASS: units and decimal precision are explicit — base-unit-only movement rule, scale-3 decimals, string decimal transport, and rounding rule (provisional under INV-8) are stated in `docs/domain-model.md` and referenced from the contract.
- Self-review check (d) PASS: negative-stock policy (OD-1), correction/approval policy (OD-3), and audit retention/access (OD-4, REQ-111) are either defined or listed as UNRESOLVED in `docs/open-decisions.md`.
- No application code, entities, migrations, or tests were created; files were changed only under `docs/` and in this roadmap file.
- Remaining unresolved decisions are tracked in `docs/open-decisions.md` (OD-1 … OD-9) and require the owner's ruling before Phase 2 implementation begins.

Important review points for Phase 1:
- units and conversion rules must be deliberate and testable
- decimal precision must be specified for purchase quantities, usage quantities, and stock levels
- stock calculations must be reviewed for safety, precision, and reconciliation requirements
- negative stock policy must be decided before implementation, not assumed
- historical corrections and audit trails must be defined before the first domain entity is committed

## Phase 2: Backend domain model and database migrations

Status: VERIFIED

Goal and scope:
- implement the approved domain model in Symfony
- create Doctrine entities and migration scripts for approved database structures
- verify schema integrity and migration repeatability

Prerequisites and dependencies:
- Phase 1 requirements and contract are approved
- API contract and domain model are defined

Expected deliverables and files:
- Doctrine entities
- repositories and value objects as required
- migration files
- schema validation and migration smoke tests

Coding-agent prompt:

```text
Implement the approved CoffeeStock backend domain model and migrations only after the requirements and API/domain contract are approved. Create the database entities, repositories, and migration logic needed for the approved stock-management domain. Preserve decimal precision, validation, and auditability requirements. Do not implement unrelated features or add speculative fields. Validate migrations and verify database schema integrity before moving to feature APIs.
```

Test cases:
- migration generation for each approved entity
- database schema validation
- persistence of material and supplier data
- precision checks for decimals and quantities
- uniqueness and relational integrity checks

Expected results for every test case:
- migrations apply cleanly in a fresh test database
- data persists with approved validation constraints
- invalid values are rejected by the domain and database rules

Acceptance criteria:
- all approved entities are represented in the schema
- migrations are deterministic and repeatable
- database constraints match the domain model

Exit criteria and definition of done:
- migration set is committed and validated
- the database schema reflects the approved domain design
- backend can move to business-feature API development

Unresolved business decisions:
- which fields are immutable once recorded and which can be edited (resolved by default: OD-7 adopted per docs/plans/phase-2-plan.md)
- approval rules for historical corrections (resolved by default: OD-3 adopted per docs/plans/phase-2-plan.md)

Verified evidence (phase 2):
- PR: https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/2 (feat/phase-2-schema, base docs/phase-1-requirements-and-contract)
- 13 entities + 8 enums + 14 repositories + migration Version20261012002054
- `php bin/console lint:container` → "linted successfully"
- `php bin/console doctrine:schema:validate` → mapping OK; schema "in sync"
- migration down/up re-executed successfully on TEST db; dev db untouched
- `php vendor/bin/phpunit --testdox` → OK (13 tests, 36 assertions), incl. scale-3 decimal round-trips, unique constraints, not-null attribution, jsonb round-trip, and FK append-only guard on stock_movement
- `php vendor/bin/phpstan analyse --memory-limit=1G` → [OK] No errors (level 6)

## Phase 3: Materials and inventory API

Status: VERIFIED

Goal and scope:
- expose APIs for materials and inventory quantity management
- support reads, writes, and stock adjustments for approved operations

Prerequisites and dependencies:
- Phase 2 database model and migrations are complete

Expected deliverables and files:
- Symfony controllers and DTOs for materials and stock
- dedicated services for stock calculations and validations
- API tests and integration checks

Coding-agent prompt:

```text
Implement the materials and inventory API for CoffeeStock using the approved contract. Include CRUD, quantity tracking, unit awareness, and validation for stock values. Ensure policies for negative stock, quantity precision, and historical tracking are enforced in the service and API layers. Add tests covering valid operations, invalid inputs, and reconciliation scenarios.
```

Test cases:
- create material with valid unit and threshold values
- reject invalid unit or decimal precision
- calculate stock from receipts and consumption
- validate negative-stock behavior under approved policy
- read material inventory and history

Expected results for every test case:
- valid requests succeed with the correct HTTP status and payload
- invalid values fail with validation errors
- stock calculations match the contract and approved policy

Acceptance criteria:
- inventory calculations are accurate and consistent
- price and quantity precision rules are enforced
- API responses are stable and documented

Exit criteria and definition of done:
- materials and inventory API passes functional and integration tests
- validation and stock invariants are enforced
- contract review is complete

Unresolved business decisions:
- whether some inventory-level rounding can occur at creation time or only at reporting time

Verified evidence (phase 3):
- PR: https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/4 (feat/phase-3-materials-api, base feat/phase-2-schema)
- Implemented: GET /api/units; POST/GET/PATCH /api/materials (filters, pagination, contract error codes incl. DUPLICATE_NAME, base_unit immutability 422); GET /api/inventory, /api/inventory/{id}, /api/inventory/{id}/movements (ledger-derived stock per INV-3, below-threshold flag, movement_type/date filters)
- `php vendor/bin/phpunit` → OK (31 tests, 81 assertions), incl. exact ledger-sum reconciliation ("7.625"), scale-3 validation, duplicate-name and invalid-unit rejections, newest-first history, 404/422/400 error paths
- `php vendor/bin/phpstan analyse --memory-limit=1G` → [OK] No errors (level 6)
- `php bin/console lint:container` → linted successfully
- Fresh test DB: drop → create → migrate → down → up all [OK]; `doctrine:schema:validate` → in sync
- Auth (401/403) deliberately deferred to Phase 9 (OD-8); recorded in docs/plans/phase-3-plan.md

## Phase 4: Suppliers, receipts, and batches

Status: VERIFIED

Goal and scope:
- support supplier records, purchase receipts, and batch traceability
- maintain inventory provenance for materials received

Prerequisites and dependencies:
- Phase 3 materials and inventory API complete

Expected deliverables and files:
- supplier entity and API
- receiving workflow and receipt records
- batch traceability and provenance metadata

Coding-agent prompt:

```text
Add the CoffeeStock supplier, receipt, and received-batch flow. Support supplier management, receipt creation, batch associations, and quantities linked to stock updates. Maintain auditability and validation for supplier data, receipt values, and batch integrity. Add tests for valid receipts, invalid supplier records, duplicate batch handling, and reconciliation checks.
```

Test cases:
- create supplier with valid contact and tax details
- create receipt with quantity and batch metadata
- reject missing batch or invalid quantity
- ensure receipt items increase stock correctly

Expected results for every test case:
- receipt flows update stock in a consistent, auditable way
- invalid data is rejected before persistence

Acceptance criteria:
- supplier and receipt records are captured and traceable
- receipt entries reconcile to stock movement history

Exit criteria and definition of done:
- suppliers and receipts are fully working and auditable
- stock movement history matches actual received quantities

Unresolved business decisions:
- whether batch numbers are required for all received materials (resolved by default: OD-6 adopted; enforced via Material.batch_required, see docs/plans/phase-4-plan.md)

Verified evidence (phase 4):
- PR: https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/6 (feat/phase-4-suppliers-receipts, base feat/phase-3-materials-api)
- Implemented: supplier CRUD + soft deactivate; POST /api/receipts (transactional, one positive RECEIPT movement per item, batch reuse/create, OD-6 enforcement); GET /api/receipts list/read with filters; no mutation routes for receipts (REQ-032)
- `php vendor/bin/phpunit` → OK (49 tests, 171 assertions), incl. ledger reconciliation (receipt items equal movement sums), inactive supplier/material rejections, batch-required rejections, invalid quantity variants, immutability (405 on PATCH/DELETE)
- `php vendor/bin/phpstan analyse --memory-limit=1G` → [OK] No errors (level 6)
- `php bin/console lint:container` → linted successfully
- Fresh test DB: drop → create → migrate → down → up all [OK]; `doctrine:schema:validate` → in sync
- Attribution uses a find-or-creates "system" user until Phase 9 auth (documented placeholder)

## Phase 5: Consumption, waste, adjustments, and stocktaking

Status: NOT STARTED

Goal and scope:
- model consumption, waste, stock adjustments, and physical stocktaking
- support inventory corrections and approval workflows

Prerequisites and dependencies:
- Phases 3 and 4 complete

Expected deliverables and files:
- consumption and waste APIs
- adjustment and stocktake workflows
- audit and variance-report capabilities

Coding-agent prompt:

```text
Implement the CoffeeStock consumption, waste, adjustment, and stocktaking domain. Support usage reductions, waste logging, reconciliation adjustments, and stocktaking events while preserving an auditable history. Ensure negative-stock rules, adjustment approvals, delta calculations, and historical corrections follow the approved contract and review decisions.
```

Test cases:
- consume material and decrement stock correctly
- log waste and maintain stock history
- create approved adjustment and verify audit log
- validate stocktake variance and reconciliation behavior
- reject invalid adjustment payloads

Expected results for every test case:
- each action updates stock according to the approved policy
- adjustments are auditable and traceable
- invalid actions are rejected with deterministic error handling

Acceptance criteria:
- stock movement history remains complete and traceable
- adjustment and stocktake flows are safe and reviewable
- negative-stock violations are blocked unless approved by policy

Exit criteria and definition of done:
- consumption, waste, and adjustment flows are validated end to end
- stocktaking and variance processes are reproducible and auditable

Unresolved business decisions:
- approval workflow for stock adjustments and stocktake variance approvals (resolved by default: OD-1 zero-floor with an explicit `approved` flag; role enforcement deferred to Phase 9, see docs/plans/phase-5-plan.md)
- difference threshold and tolerance for physical counts (resolved by default: OD-5 adopted; per-material `stocktake_threshold` else 2% of the snapshot derived stock)

Verified evidence (phase 5):
- PR: https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/9 (feat/phase-5-movements, base feat/phase-4-suppliers-receipts)
- Implemented: POST /api/consumptions and POST /api/wastes (each one negative CONSUMPTION/WASTE ledger movement, REQ-040/041, waste requires a reason note BR-15); POST /api/adjustments (signed delta, mandatory reason, REQ-050/BR-17, OD-1 zero-floor + OD-5 threshold approval); POST/GET /api/stocktakes and POST /api/stocktakes/{id}/commit (draft snapshots derived stock, commit posts STOCKTAKE_DELTA atomically per INV-10)
- Concurrency: `StockGuard` takes a `SELECT ... FOR UPDATE` per-material row lock inside the write transaction so two simultaneous writes cannot both read the same pre-write balance and overdraw (covered by `StockConcurrencyTest`)
- `php vendor/bin/phpunit` → OK (74 tests, 319 assertions), incl. ledger reconciliation, OD-1 zero-floor rejections, waste-vs-consumption type separation, stocktake delta/commit flows, and the concurrency lock test
- `php vendor/bin/phpstan analyse --memory-limit=1G` → [OK] No errors (level 6)
- `php bin/console lint:container` → linted successfully
- Fresh test DB: drop → create → migrate → down → up all [OK]; `doctrine:schema:validate` → in sync (Phase 5 needs no new migration)
- Attribution uses a find-or-creates "system" user until Phase 9 auth (documented placeholder)

## Phase 6: Frontend design system and application shell

Status: VERIFIED

Goal and scope:
- establish the app shell, design system, layout patterns, and shared UI primitives
- prepare pages and state patterns for inventory workflows

Prerequisites and dependencies:
- Phase 0 verified
- backend foundation is available

Expected deliverables and files:
- shared components and design tokens
- layout and navigation shell
- route structure and app shell tests

Coding-agent prompt:

```text
Build the CoffeeStock frontend shell and design system for the internal inventory application. Create the layout, navigation, theme tokens, and reusable UI components needed for inventory workflows. Keep the integration aligned with the existing backend API contract and do not add unapproved business logic beyond shell and layout concerns.
```

Test cases:
- route rendering
- layout responsiveness
- shell accessibility checks
- API error states for backend unavailability

Expected results for every test case:
- routes render reliably
- error states are explicit and user-friendly
- shell remains stable under backend failures

Acceptance criteria:
- design system is usable for future inventory pages
- app shell is stable and accessible

Exit criteria and definition of done:
- shell and design system are ready to host inventory workflows

Unresolved business decisions:
- whether the app uses a single dashboard-first navigation model or multiple operational sections (resolved by default: single dashboard-first model with one left sidebar — Materials, Receipts, Consumption & Waste, Adjustments, Stocktaking, Reports)

Verified evidence (phase 6):
- PR: https://github.com/AnouerChouikhgithub/coffeestock-frontend/pull/1 (feat/phase-6-shell, base main)
- Implemented: design tokens (src/styles/tokens.css); AppShell with dashboard-first left sidebar, header, main region, skip link and banner/navigation/main landmarks, aria-current on the active NavLink; DashboardPage with a live /api/health check (loading / error / retry states); PlannedPage placeholder for the Phase 7 sections; shared primitives Alert (role=alert for errors), LoadingState (role=status), EmptyState; route table in src/app/App.tsx with BrowserRouter in main.tsx; responsive sidebar collapse under 48rem; prefers-reduced-motion honored.
- `npm run type-check` → OK
- `npm run lint` → OK (0 warnings, --max-warnings 0)
- `npm run test` → OK (6/6): route rendering, landmarks, skip link, active-link aria-current, planned-section placeholder, backend-unavailable error state + retry
- `npm run build` → OK (tsc -b && vite build)

## Phase 7: Frontend inventory workflows

Status: VERIFIED

Goal and scope:
- create UI for materials, inventory overview, batches, and operational stock flows
- connect frontend screens to backend APIs

Prerequisites and dependencies:
- Phase 6 shell and design system complete
- Phase 3-5 API contracts available

Expected deliverables and files:
- inventory dashboard pages
- form screens and tables
- UX flows for stock changes and adjustments

Coding-agent prompt:

```text
Implement the CoffeeStock frontend inventory workflows based on the approved backend APIs and designs. Add screens for materials, inventory levels, receipts, usage, stock adjustments, and stocktaking. Ensure flows include loading, validation, error states, and audit-aware UI messaging without introducing additional hidden domain logic.
```

Test cases:
- list materials and stock values
- create receipt flow
- consume material flow
- stock adjustment flow
- stocktaking variance flow

Expected results for every test case:
- UI reflects API response states correctly
- validation messages match documented rules
- success and error states are explicit

Acceptance criteria:
- operational workflows are navigable and stable
- each workflow is connected to the backend contract and validated

Exit criteria and definition of done:
- inventory workflows are working against real API data
- UI state and backend state remain aligned

Unresolved business decisions:
- whether the UI should show raw stock values or normalized units in a single presentation model
  (resolved by default: show each material's base-unit raw stock value, no UI unit conversion —
  pre-approved decision, same as Phase 6).

Verified evidence (phase 7):
- PR: https://github.com/AnouerChouikhgithub/coffeestock-frontend/pull/2 (feat/phase-7-workflows,
  base feat/phase-6-shell)
- Implemented: MaterialsPage, InventoryPage, ReceiptsPage, ConsumptionPage, AdjustmentsPage,
  StocktakingPage + per-page test suites (MaterialsPage.test, InventoryPage.test, ReceiptsPage.test,
  ConsumptionPage.test, AdjustmentsPage.test, StocktakingPage.test); shared picker helpers
  MaterialSelect; services materials/receipts/usage/adjustments/stocktakes/suppliers/inventory.
- `npm run lint` → OK (0 warnings, --max-warnings 0)
- `npm run type-check` → OK
- `npm run test` → OK (27 tests, 7 test files): route rendering, loading/empty/error states, field
  errors from backend error codes, the OD-1 negative-stock explanation, waste reason requirement,
  stocktake approval-comment flow before commit, material/base-unit-as-string assertions.
- `npm run build` → OK (tsc -b && vite build; dist/assets/index-*.css + index-*.js)
- Live integration: frontend dev server (Vite, http://localhost:5173) ran against a Phase 5 API
  worktree (`../coffeestock-backend-phase5` off `feat/phase-5-movements`); smoke path verified in
  browser: materials list, inventory showing Espresso beans 595.000 g with Movements drill-down,
  receipts list + create form (supplier, date, per-item material/quantity/batch), consumption/waste,
  adjustments, and stocktaking create-commit. Console free of app errors; all XHR returns 200.
- Notes / assumptions: RAW-receipt batch required by backend (OD-6) surfaced as a per-row batch field
  with the exact `items.N.batch.batch_number` error key; backend rejects future delivery dates and
  negative-stock writes, and the UI mirrors those codes (VALIDATION_FAILED, NEGATIVE_STOCK_BLOCKED,
  APPROVAL_REQUIRED). Auth (401/403) and the Phase 7 plan doc are deferred; no new backend code.

## Phase 8: Dashboard, reports, and CSV export

Status: NOT STARTED

Goal and scope:
- provide operational dashboards and report pages
- create CSV export flows for inventory activity and history

Prerequisites and dependencies:
- Phases 5 and 7 complete

Expected deliverables and files:
- dashboard views and analytics widgets
- reporting modules
- CSV export utilities and tests

Coding-agent prompt:

```text
Build the CoffeeStock dashboard and reporting experience for inventory operations. Include inventory summary views, stock movements, historical trends, and CSV export flows. Ensure the output matches approved business rules and the underlying API contract without exposing unsupported metrics.
```

Test cases:
- dashboard view renders key metrics
- export generates CSV with correct schema
- report filters and date ranges behave correctly
- empty or partial data states are handled securely

Expected results for every test case:
- dashboards show correct values and states
- CSV export matches the agreed data structure
- invalid filters are rejected clearly

Acceptance criteria:
- reporting is accurate, readable, and exportable
- operational users can access the information needed for stock decisions

Exit criteria and definition of done:
- dashboard and reporting flows are validated against backend data
- CSV export criteria match approved reporting needs

Unresolved business decisions:
- which report fields are mandatory in CSV output vs optional

## Phase 9: Authentication, authorization, audit, and security hardening

Status: NOT STARTED

Goal and scope:
- secure all operational access
- implement audit trails, role control, and validation hardening

Prerequisites and dependencies:
- all core domain flows are at least functionally complete

Expected deliverables and files:
- authentication and authorization layer
- audit log design and retention policy
- security validation and penetration-focused checks

Coding-agent prompt:

```text
Implement CoffeeStock authentication, authorization, audit, and security hardening for the approved operational domain. Define roles and permissions, ensure secure API access, and record inventory history changes in auditable logs. Validate the system against access control, session, data validation, and sensitive information requirements before release.
```

Test cases:
- authentication and session validation
- authorization for stock updates and reports
- audit log creation for each material movement
- sensitive data exposure prevention
- validation of malicious payload handling

Expected results for every test case:
- users only access authorized operations
- sensitive actions are logged and traceable
- security-related validation is enforced consistently

Acceptance criteria:
- role-based access is enforced and auditable
- critical inventory operations are traceable
- security checks are in place before deployment

Exit criteria and definition of done:
- security and audit controls are validated
- access control and historical traceability are dependable

Unresolved business decisions:
- who can approve stock corrections and when
- retention policy for audit logs and historical corrections

## Phase 10: Integration, release, and deployment readiness

Status: NOT STARTED

Goal and scope:
- confirm end-to-end integration between frontend and backend
- prepare release, deployment, and operational documentation

Prerequisites and dependencies:
- all prior phases complete and accepted

Expected deliverables and files:
- end-to-end environment validation
- release checklist and deployment documentation
- production configuration and environment segregation plan

Coding-agent prompt:

```text
Prepare CoffeeStock for release by validating end-to-end integration across the complete stack. Confirm the frontend and backend communicate correctly, run the release checklist, and document infrastructure, deployment, and operational readiness. Preserve the independent repository model and ensure production configuration is separated from local development configuration.
```

Test cases:
- end-to-end inventory flow validation
- production-like environment configuration check
- deployment readiness checklist
- incident and rollback review

Expected results for every test case:
- the full application works under expected release conditions
- deployment instructions are accurate and executable
- rollback and recovery paths are documented

Acceptance criteria:
- application is deployable and testable in an environment matching the approved architecture
- operational and release docs are complete

Exit criteria and definition of done:
- release candidate is approved and ready for production deployment
- release documentation is complete and reviewed

Unresolved business decisions:
- target hosting model and infrastructure responsibilities
- production environment ownership and support model

## Governance notes

1. Do not treat any future phase as implemented unless there is repository evidence and actual test output.
2. Any unresolved policy decision must be explicitly called out and assigned before implementation proceeds.
3. Decimal precision, negative-stock handling, historical corrections, and auditability are critical decision points and must be reviewed before production logic is constructed.
4. This roadmap is intended to guide the next development phase without altering the current foundation setup.
