# CoffeeStock — Progress Tracker

> **Location ruling (2026-10-12, owner-approved):** the workspace root is intentionally NOT a
> git repository (per `docs/DEVELOPMENT_SETUP.md`), so this tracker lives in the backend repo on
> `docs/phase-N-status` branches instead of the root, as `docs/AGENT_PROMPT.md` originally
> specified. Everything else in AGENT_PROMPT.md applies unchanged.
> **Never commit credentials** — they live only in the gitignored `LOCAL_ENV.md` (backend repo).

Last updated: 2026-10-12 (plan APPROVED by owner — Phase 2 build started)

---

| Phase | Status | Branch (backend repo unless noted) | PR link | Last verification | Notes / assumptions |
| --- | --- | --- | --- | --- | --- |
| 0 | VERIFIED | `main` | (pre-tracker) | DB vol survived POSTGRES_PASSWORD flip (return code 0, no data loss); 1/1 test restored+, lint+, router+ — all genuinely rerun this session | Volume kept; `.env.local` left as-is with CURRENT_PASSWORD as a temp workaround |
| 1 | VERIFIED | `docs/phase-1-requirements-and-contract` (backend repo, 2da3442) | (pre-tracker) | All Phase 1 docs checked in (`requirements.md`, `domain-model.md`, `api-contract.md`, `open-decisions.md`, `decisions/ADR-0001-*`, plus DEVELOPMENT_ROADMAP.md edits) | Explicitly **never merged** to backend `main`; roadmap has no PR link, only generic evidence list |
| 2 | **IN_PROGRESS** (plan approved 2026-10-12) | code: `feat/phase-2-schema` (off `docs/phase-1-requirements-and-contract`) · tracker: `docs/phase-2-status` | — | Plan approved by owner in chat. Build started | OD-3/OD-6/OD-7 adopted as decided-by-default per plan §"Adopted-by-default decisions" |

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

1. Owner has not yet approved `docs/plans/phase-2-plan.md` (this is the pause point).
2. On approval: create `feat/phase-2-schema` off `docs/phase-1-requirements-and-contract`
   (NOT `main`, since Phase 1 was never merged to `main`), and implement per the owner
   (re)-decisions recorded in the plan + `docs/open-decisions.md` defaults.
3. Backend code verification flow: `composer install` → `php bin/console lint:container` →
   `doctrine:schema:validate` → migrations up/down/up on the **test** DB only →
   `php vendor/bin/phpunit --testdox` → `php vendor/bin/phpstan analyse --memory-limit=1G`.
4. After verification, roadmap status flip to VERIFIED + a PR, per AGENT_PROMPT.md step 6.
