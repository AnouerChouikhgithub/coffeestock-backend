# CoffeeStock — Phase 1 Open Decisions

Status: **UNRESOLVED by definition** — every entry below is an open business decision awaiting the owner's ruling. Nothing here is decided; each recommendation is a default to react to, not a conclusion.

Each entry carries: the question, 2–3 options, trade-offs, a recommended default, and who must decide. These decisions gate implementation: per the roadmap, no Phase 2+ business logic is written until they are resolved or explicitly deferred.

---

## OD-1 — Negative-stock policy

**Question:** may the derived stock on hand of a material go below zero as the result of a consumption, waste, or adjustment entry, or must such entries be blocked (or gated) when the resulting balance would be negative?

This covers all scenarios:
- consumption/waste recorded before a receipt (the classic "milk ran out before delivery was logged");
- transcription slips where the operator submits a larger quantity than exists;
- adjustments that over-correct downward.

**Option A — Hard block.** Reject any entry whose notional resulting balance is < 0 (HTTP 409 `NEGATIVE_STOCK_BLOCKED`).
Trade-offs: guarantees physical plausibility of the ledger at every instant; but real shops record usage before receipts all the time, so this pushes delays/errors onto operators and encourages "fix it in the UI later" workarounds.

**Option B — Allow freely, no gating.** Negative balances are accepted as ledger truth.
Trade-offs: zero operational friction; but the ledger can drift far from physical reality without any signal, and reports become misleading until a stocktake pulls it back.

**Option C — Allow with threshold gate (recommended default).** Negative balances are permitted, but only down to a per-material floor (default `0` unless the material defines an explicit negative allowance) and with an automatic warning surfaced in the UI and the audit row. Entries beyond the floor are rejected or require Manager+ per the role matrix.
Trade-offs: accommodates real sequencing while capping damage; adds one config knob and UI messaging burden; still needs a stocktake to eventually reconcile.

**Recommended default:** Option C, with the floor defaulting to `0.000` for all materials until individually relaxed.
**Who must decide:** the coffee shop owner (business policy) — with the implementing developer confirming feasibility of the floor configuration.

---

## OD-2 — Role-permission matrix and role model

**Question:** which of the proposed roles (ADMIN, MANAGER, OPERATOR) may perform which stock-affecting operations, and is one role per user or can a user hold several?

The baseline proposal in `api-contract.md` ("Authorization matrix") — Operators consume/waste/create stocktake drafts, Managers receipt/adjust/commit, Admins manage suppliers/audit — is **not agreed yet**.

**Option A — Adopt the proposed three-role matrix as-is.**
Trade-offs: simple, matches team size; but role boundaries may not match actual staff responsibilities.

**Option B — Two roles only (Admin + Staff, Operator-level rights for everyone).**
Trade-offs: minimal friction in a small shop; but receipts and adjustments carry financial meaning and arguably need the Manager distinction.

**Option C — Three roles plus per-material override flags.**
Trade-offs: powerful, but speculative complexity for Phase 1 — also risks audit confusion about which authority applied.

**Recommended default:** Option A, one role per user, resolved exactly as written in the api-contract matrix.
**Who must decide:** the coffee shop owner with input from whoever will manage day-to-day operations (shift-leads/managers).

---

## OD-3 — Historical corrections: who may, and how they are approved

**Question:** who may record (a) back-dated entries (recorded now, dated in the past) and (b) compensating corrections to already-recorded documents — and does either require a second-person approval before taking stock effect?

**Option A — Manager+ may back-date freely; no approval for compensating entries; everything is audit-logged.**
Trade-offs: fast, low ceremony; but a single person can silently reshape inventory history.

**Option B — Manager+ may back-date; compensating entries require Admin approval (validated in the same request as an `Approval` record); all correction flows are audit-logged with reason.**
Trade-offs: two-person control where it matters (history reshaping) without burdening same-day operational logging; adds one approval step and means an Admin exists at the needed moment.

**Option C — No back-dating at all (entries only at recording time); corrections only as same-instant adjustments.**
Trade-offs: simplest audit story; but breaks real end-of-day logging where usage is tallied after the fact and operators would record yesterday's consumption "today", mis-stating daily reports.

**Recommended default:** Option B; Manager-or-Admin for back-dated operational entries, Admin-approved compensating entries, reason mandatory.
**Who must decide:** the coffee shop owner (control policy), consulted with whoever holds Admin on day one.

---

## OD-4 — Audit retention and access policy

**Question:** how long are audit-log and stock-movement history rows retained, who may read them at what level of granularity, and is there any purge/export procedure?

**Option A — Retain indefinitely; Admin-only read of audit logs; movement history readable by all staff.**
Trade-offs: strongest audit posture and cheapest to implement now; storage grows unbounded but for an internal shop this is realistically years of negligible volume.

**Option B — Retain for a fixed window (e.g. 3 years), then archive to CSV snapshots offline; same access rules.**
Trade-offs: bounded growth; adds an archive job and a rule for "when the archive becomes the only copy" — deferred complexity.

**Option C — Tiered access (full detail Admin, summarized Manager, own-actions Operator).**
Trade-offs: flexibly privacy-conscious; but more authorization logic and unclear benefit for an internal tool owned by the same business.

**Recommended default:** Option A until volume or policy forces otherwise; revisit after 12 months of real data.
**Who must decide:** the coffee shop owner (business/legal preference on retaining records, potentially with local business-record-keeping rules in mind).

---

## OD-5 — Stocktaking tolerance thresholds and variance approval

**Question:** at what absolute Variance (|delta| between counted and derived stock) does a stocktake count require approval before commit, and which role may give that approval?

**Option A — Zero tolerance: every non-zero delta requires Manager approval at commit.**
Trade-offs: maximally conservative; but in a busy shop every count then needs a second person, which stalls stocktakes during rush.

**Option B — Per-material `stocktake_threshold` (already on the Material entity, domain model): counts where |delta| ≤ the material's threshold (or ≤ a global default when unset, e.g. 2% of derived stock) commit without approval; larger deltas require Manager-or-Admin approval.**
Trade-offs: calibrated to material value/size (1 g of saffron matters more than 1 g of beans); needs a defensible default and occasional tuning.

**Option C — Percentage-of-stock tolerance only, no per-material field.**
Trade-offs: simplest config; but a small absolute error on a small stock can pass unnoticed, and percentages need careful rounding rules.

**Recommended default:** Option B, global default threshold = max(0, 2% of derived stock) when no per-material `stocktake_threshold` is set; approver = Manager or Admin; commit endpoint already shaped for this (see `POST /api/stocktakes/{id}/commit`).
**Who must decide:** the coffee shop owner, with the shift-lead who will actually run counts.

---

## OD-6 — Batch number requirement

**Question:** are supplier batch numbers required on every receipt item, on none, or required by material class?

**Option A — Required on every receipt item (nullable nowhere).**
Trade-offs: strongest traceability; but many consumables (cups, napkins) arrive unbatched and operators would invent numbers, degrading data quality.

**Option B — Optional globally; nullable; exposed in provenance when present.**
Trade-offs: matches real-world supply variability; but for perishables (milk, fresh stock) the shop loses the recall path that batch tracking buys.

**Option C — Required per material via a `batch_required` material flag (nullable elsewhere), recommended ON for perishable/raw materials, OFF for consumables.**
Trade-offs: right amount of traceability where it matters; adds one material flag and a validation branch.

**Recommended default:** Option C, with the flag defaulting ON for `RAW` materials and OFF for `CONSUMABLE` supplies; recalls/traceability for consumables remain possible via receipt+supplier when batch data is available.
**Who must decide:** the coffee shop owner with the supplier-side reality check (what roasteries/dairies actually print on deliveries).

---

## OD-7 — Immutability of fields (already provisionally specified — confirm or amend)

**Question:** confirm or amend the field-level immutability rules in `domain-model.md` ("Mutable?" column): receipt and its items fully immutable; `Material.base_unit` immutable once movements exist; consumption/waste/adjustment entries fully immutable; stocktake counts frozen at COMMIT; supplier contact fields mutable; and all corrections flowing through compensating ledger movements.

**Option A — Adopt the rules exactly as specified in the domain model.**
Trade-offs: simple and consistent; renaming a material or fixing a supplier email remains possible; everything stock-meaningful is frozen.

**Option B — Allow Admin to edit receipt `note`-level metadata only (never quantities, dates, or supplier).**
Trade-offs: harmless correction valve; but adds a mutation path to an otherwise fully immutable document class, which complicates the audit story slightly.

**Option C — Allow a DRAFT stocktake's `counted_quantity` edits by any role until COMMIT (already implied) — and additionally allow deleting a DRAFT stocktake entirely.**
Trade-offs: practical for mis-keyed counts; "delete" of a DRAFT is genuinely harmless since no ledger rows exist yet.

**Recommended default:** Options A + C together (adopt the domain-model table as-is; allow deleting DRAFT stocktakes), skipping Option B unless the owner disagrees.
**Who must decide:** the coffee shop owner, jointly with the developer on audit-path implications.

---

## OD-8 — Auth token mechanism

**Question:** which session/token mechanism does login return and validate?

**Option A — Opaque random server-side session token persisted in a `sessions` table (Postgres), validated per request.**
Trade-offs: trivially revocable, easy to reason about; adds one table and a session lookup per request.

**Option B — JWT (stateless, signed, short-lived) with a refresh flow.**
Trade-offs: no session table; but revocation requires a denylist, which erodes the statelessness benefit.

**Option C — Symfony-native security session-cookie flow (typical for server-rendered or same-origin SPAs).**
Trade-offs: least custom code on the Symfony side; needs careful CORS/cookie configuration for the Vite origin and is unusual for a token-style contract.

**Recommended default:** Option A (opaque bearer token in Postgres) — simplest to secure correctly at this scale, aligns with the Bearer-header contract already written in `api-contract.md`.
**Who must decide:** the implementing developer recommends, the coffee shop owner accepts the operational implications (token expiry length, revocation behaviour).

---

## OD-9 — Rounding policy for decimal quantities and unit conversions

**Question:** when decimal quantities are quantized (stored, aggregated, or converted between units), which rounding rule applies, and where in the pipeline is it applied?

The provisional rule currently stated in `domain-model.md` (INV-8) is half-up rounding to scale 3, applied only at the persistence boundary, with inputs carrying more than 3 decimal places rejected outright — i.e. no rounding ever occurs on user input or mid-aggregation. That provisional rule is itself awaiting confirmation or replacement here.

**Option A — Half-up (arithmetic) rounding to scale 3, at the persistence boundary only (adopt the provisional rule).**
Trade-offs: the intuitive "everyday" rounding; safe because inputs are pre-restricted to scale 3, so aggregation of already-quantized values never needs rounding mid-sum; matches what staff expect when comparing reports to physical reality.

**Option B — Banker's rounding (half-even) to scale 3 everywhere.**
Trade-offs: statistically neutral over many aggregates (no systematic upward drift); but harder to explain to operational staff, and the difference only matters for tie values — rare after inputs are restricted to scale 3.

**Option C — No rounding at interaction time; store full precision (scale up to 6) and round only in reports.**
Trade-offs: maximal fidelity; but violates the project principle of one shared precision (REQ-010), complicates ledger-vs-report reconciliation (two different "correct" values for the same quantity), and 6-decimal grams is meaningless operationally for a coffee shop.

**Recommended default:** Option A — confirm the provisional rule as final. Note: unit conversion (the base-unit-only rule, REQ-011 / `domain-model.md` INV-6) is currently moot for rounding because Phase 1 accepts only base-unit quantities; if multi-unit entry (e.g. receive 2 kg on a material whose base unit is g) is ever approved, the conversion factor and its rounding rule must be defined here before implementation.
**Who must decide:** the implementing developer proposes; the coffee shop owner confirms the operational behaviour.

---

## Decision log summary

| ID | Topic | Status | Default recommendation | Decider |
| --- | --- | --- | --- | --- |
| OD-1 | Negative stock | UNRESOLVED | Allow with 0-floor + warning | Owner |
| OD-2 | Role matrix | UNRESOLVED | Adopt proposed 3-role matrix | Owner |
| OD-3 | Historical corrections | UNRESOLVED | Manager+ back-date; Admin-approved compensations | Owner |
| OD-4 | Audit retention/access | UNRESOLVED | Indefinite retention, Admin-read audit | Owner |
| OD-5 | Stocktake tolerances | UNRESOLVED | Per-material threshold, 2% fallback, Manager+ approves | Owner + shift-lead |
| OD-6 | Batch numbers | UNRESOLVED | Required flag per material, ON for RAW | Owner + supplier reality |
| OD-7 | Field immutability | UNRESOLVED | Adopt domain-model table + DRAFT stocktake delete | Owner + developer |
| OD-8 | Auth token mechanism | UNRESOLVED | Opaque bearer token in Postgres | Developer recommends, Owner accepts |
| OD-9 | Rounding policy | UNRESOLVED | Half-up to scale 3 at persistence boundary only (confirm provisional INV-8) | Developer proposes, Owner confirms |
