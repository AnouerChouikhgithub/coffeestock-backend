# CoffeeStock — Progress Tracker

> **Location ruling (2026-10-12, owner-approved):** the workspace root is intentionally NOT a
> git repository (per `docs/DEVELOPMENT_SETUP.md`), so this tracker lives in the backend repo on
> `docs/phase-N-status` branches instead of the root, as `docs/AGENT_PROMPT.md` originally
> specified. Everything else in AGENT_PROMPT.md applies unchanged.
> **Never commit credentials** — they live only in the gitignored `LOCAL_ENV.md` (backend repo).

Last updated: 2026-10-13 (Phase 6 VERIFIED)

---

| Phase | Status | Branch (backend repo unless noted) | PR link | Last verification | Notes / assumptions |
| --- | --- | --- | --- | --- | --- |
| 0 | VERIFIED | `main` | (pre-tracker) | DB vol survived POSTGRES_PASSWORD flip (return code 0, no data loss); 1/1 test restored+, lint+, router+ — all genuinely rerun this session | Volume kept; `.env.local` left as-is with CURRENT_PASSWORD as a temp workaround |
| 1 | VERIFIED | `docs/phase-1-requirements-and-contract` (backend repo, 2da3442) | (pre-tracker) | All Phase 1 docs checked in (`requirements.md`, `domain-model.md`, `api-contract.md`, `open-decisions.md`, `decisions/ADR-0001-*`, plus DEVELOPMENT_ROADMAP.md edits) | Explicitly **never merged** to backend `main`; roadmap has no PR link, only generic evidence list |
| 2 | **VERIFIED** | code: `feat/phase-2-schema` · tracker: `docs/phase-2-status` | https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/2 | phpunit OK (13 tests, 36 assertions); phpstan [OK] level 6; lint:container pass; schema:validate in sync; migration up/down/up OK on TEST db | OD-3/OD-6/OD-7 adopted decided-by-default per plan. Foundry + Fixtures added pre-approval. Dev db NOT migrated (owner will do it) |
| 3 | **VERIFIED** | code: `feat/phase-3-materials-api` · tracker: `docs/phase-3-status` | https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/4 | phpunit OK (31 tests, 81 assertions); phpstan [OK] level 6; lint:container pass; fresh test DB drop/create/migrate/down/up OK; schema:validate in sync | Materials CRUD + inventory/movements read API done per api-contract. Auth (401/403) deferred to Phase 9 (OD-8 unimplemented) |
| 4 | **VERIFIED** | code: `feat/phase-4-suppliers-receipts` · tracker: `docs/phase-4-status` | https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/6 | phpunit OK (49 tests, 171 assertions); phpstan [OK] level 6; lint pass; fresh test-DB migration rehearsal OK; schema:validate in sync | Suppliers CRUD + receipts API; first ledger WRITES (RECEIPT movements, transactional). OD-6 enforced via batch_required. Attribution = find-or-create 'system' user until Phase 9 |
| 5 | **VERIFIED** | code: `feat/phase-5-movements` · tracker: `docs/phase-5-status` | https://github.com/AnouerChouikhgithub/coffeestock-backend/pull/9 | phpunit OK (74 tests, 319 assertions); phpstan [OK] level 6; lint:container pass; fresh test-DB drop/create/migrate/down/up OK; schema:validate in sync (no new migration) | Consumption/waste/adjustments/stocktake write paths per api-contract (endpoints 16-21). OD-1 zero-floor with explicit `approved` flag; OD-5 per-material stocktake_threshold else 2% default. Per-material `SELECT ... FOR UPDATE` lock prevents concurrent overdraw (StockConcurrencyTest). Approver role enforcement deferred to Phase 9 |
| 6 | **VERIFIED** | code: `coffeestock-frontend` `feat/phase-6-shell` · tracker: `docs/phase-6-status` | https://github.com/AnouerChouikhgithub/coffeestock-frontend/pull/1 | npm type-check OK; lint OK (0 warnings); test OK (6/6); build OK | Frontend app shell + design system: tokens, AppShell (dashboard-first left sidebar per pre-approved decision), DashboardPage with live /api/health check, Alert/LoadingState/EmptyState primitives, router + landmarks + skip link + responsive collapse. Nav-model decision (single dashboard-first sidebar) adopted by default |

## Env / local secrets

- All local dev DB credentials live in the gitignored `coffeestock-backend/LOCAL_ENV.md`
  (create-if-missing, never commit).
- Backend dev DB: `coffeestock_dev` on host port **6543** via Docker Compose service `database`
  (`coffeestock-backend/compose.yaml`).
- Test DB: `coffeestock_test_test` — already created via
  `php bin/console doctrine:database:create --env=test --if-not-exists`. Tests run against this only.
- Destructive migrations/runs are prohibited against the dev DB.
- Root repo: not a git repo (owner-approved ruling above) — no longer created via `git init`.

## Where the next session resumes

1. Phases 2-6 are VERIFIED. Backend PRs stacked (#2/#4/#6/#9 code; #3/#5/#7/#10 docs);
   frontend PR #1 (`feat/phase-6-shell`, base `main`). Owner granted blanket go-ahead on
   2026-10-12 for all remaining phases — plan-approval gates no longer pause the run.
2. Next: **Phase 7** (frontend inventory workflows). Frontend branch
   `feat/phase-7-workflows` off `feat/phase-6-shell`. Replace the PlannedPage placeholders
   with real screens for Materials, Inventory overview, Receipts, Consumption & Waste,
   Adjustments, Stocktaking, built against `docs/api-contract.md` and the running backend
   (`feat/phase-5-movements`; `docker compose up -d database` + `symfony server:start` or
   `php -S` for the API). Phase notes: include loading, empty, validation and error states
   plus accessibility basics; amounts are scale-3 strings, never floats (no float math);
   show each material's base unit only — no unit conversion in the UI (pre-approved decision).
   Reuse the Phase 6 primitives (Alert, LoadingState, EmptyState) and design tokens.
3. Backend code verification flow (unchanged): `composer install` → `php bin/console lint:container` →
   `doctrine:schema:validate` → migrations up/down/up on the **test** DB only →
   `php vendor/bin/phpunit --testdox` → `php vendor/bin/phpstan analyse --memory-limit=1G`.
   Frontend flow: `npm run lint` → `npm run type-check` → `npm run test` → `npm run build`.
4. After verification, roadmap status flip to VERIFIED + a PR, per AGENT_PROMPT.md step 6.
5. Phase 8 will need backend report + CSV endpoints (check the contract; implement any missing
   ones on `feat/phase-8-reports-api` with its own PR, then the frontend separately).
