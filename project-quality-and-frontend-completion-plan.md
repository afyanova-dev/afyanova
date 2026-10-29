# Project Quality and Frontend Completion Plan

**Status:** Execution started; all five workstreams remain tracked  
**Created:** 2026-10-23  
**Purpose:** Carry every action from the whole-project audit through implementation, verification, and operational acceptance without dropping a workstream.

## 1. Completion rule

This plan has five required workstreams. None may be silently removed or
reported complete because another workstream passed. Each workstream must meet
its own exit criteria, and the final release-readiness review must account for
all five.

| ID | Required workstream | Audit recommendation |
|---|---|---|
| W1 | Stabilize backend quality gates | Investigate the 21 Pest failures and resolve PHPStan and conformance failures. |
| W2 | Complete Admin Phase 2 and deliver agreed Phase 3 facility operations UI | Build scoped identity administration, then facility operations setup with one authoritative write path per setting. |
| W3 | Split frontend pages into lazy-loaded route chunks | Replace eager page loading and measure production output. |
| W4 | Add isolated-database browser E2E coverage | Cover Inventory scoping, Procurement receiving/invoice/return, and Admin access management. |
| W5 | Complete Procurement operational rollout | Perform load verification, UAT, training, pilot/cutover, and post-go-live reconciliation. |

## 2. Guardrails

- This plan does not authorize changing clinical policy, expanding user
  privileges, or implementing conditional RFQ/contract screens.
- Domain owners retain validation, authorization, lifecycle rules, persistence,
  and audit ownership. Admin is an entry point, not generic table CRUD.
- Do not weaken or delete tests just to make a gate green. For each failure,
  establish whether code is wrong, the test is stale, or the environment is
  invalid; record the evidence and make the smallest justified correction.
- The audit workspace has no Git metadata. Establish a reviewed source-control
  baseline before implementation so unrelated and pre-existing changes can be
  distinguished. Do not reset or overwrite unreviewed work.
- Keep development/test data separate from the live developer database.
  Stateful E2E tests must use a dedicated disposable database and must fail
  closed if its configured database is not the isolated test target.
- Do not declare production readiness from static checks alone. Each applicable
  workstream requires its automated checks and a workflow-level acceptance.

## 3. Dependencies and sequence

```text
W1 baseline and backend stabilization
  ├── enables W2 Admin writes and safe authorization contracts
  ├── enables W4 dependable browser workflow tests
  └── required before W5 production pilot

W3 lazy page loading ───────────────┐
W2 Admin delivery ──────────────────┼── W4 E2E covers delivered workflows
Procurement workflow verification ──┘

W1 + W4 + Procurement acceptance ───── W5 pilot/cutover and reconciliation
```

W3 may proceed after the source-control baseline and can run alongside backend
investigation, provided it does not obscure W1 failures. W4 test harness
planning may begin earlier, but final end-to-end acceptance depends on the
corresponding W2 and Procurement workflows being stable. W5 must not enter
pilot/cutover while a relevant authorization, stock-posting, or data-isolation
gate is failing.

## 4. W1 — Stabilize backend quality gates

### W1.0 Establish a trustworthy baseline

- Record branch/commit, working-tree state, PHP/runtime and database versions,
  configured test database, and exact commands used.
- Re-run the backend suite and checks in a controlled, documented environment
  before editing. Preserve the current audit result as the comparison point:
  **3,445 passed and 21 failed** in the full Pest run.
- Confirm whether any failures arise from parallelism, shared state, stale
  fixtures, or environment configuration before classifying them as product
  defects.

**Acceptance:** A repeatable baseline exists and no failure is dismissed
without a named cause and evidence.

### W1.1 Triage and resolve all 21 Pest failures

Track each failing test by file, expected behavior, observed behavior, owner,
classification, correction, and retest result. Include all groups observed:

- Architecture/boundary checks: seven reported failures covering shared-kernel
  dependencies, controller/domain boundaries, command/DTO conventions, and
  cross-module imports.
- Clinical and Encounter API contract/expectation failures.
- Encounter transfer validation and Reception permission/date behavior.
- HR facility selection fixture/expectation mismatch.
- Authentication/session revocation behavior.
- Admin bootstrap/invitation and pending role-promotion behavior, including
  the `ScopeChecker` container-resolution failure.
- Staff reinstatement conflict behavior.

For tests that encode obsolete contracts, update the test only after verifying
the current contract against its implementation and intended behavior. For
product bugs, fix the owning domain and add/retain regression coverage.

**Acceptance:** Full Pest passes; no test is skipped, marked incomplete, or
weakened to conceal a failure. Any deliberately quarantined test has a tracked
owner, reason, expiry/review date, and explicit release decision.

### W1.2 Resolve PHPStan findings

- Triage all **242 reported errors** by diagnostic category and owning module.
- Fix incorrect types, nullability, property/return shapes, and missing
  iterable value types at their source.
- Verify Billing's reported `TariffHistoryEntry` namespace/return mismatch.
- Add narrow regression tests where a type issue represents incorrect runtime
  behavior.

**Acceptance:** The configured project PHPStan command passes at its current
level without broad suppressions, unsafe casts, or blanket ignores.

### W1.3 Resolve conformance failures

- Address all **18 reported failures**: the undocumented table prefix, the
  `aut_admin_bootstrap_tokens` forced-RLS finding, and 16 tenant-scoped tables
  without `version` columns or documented exemptions.
- For every table, establish whether it is tenant-scoped and mutable, and use
  the existing repository-standard RLS/versioning or exemption mechanism.
- Validate migrations and conformance against PostgreSQL with tenant/RLS
  context, including upgrade behavior for existing installations.

**Acceptance:** All conformance components pass. Every exemption is explicit,
justified, reviewed, and tested; no RLS failure is hidden by an unscoped
database connection.

### W1.4 Formatting and release risk

- Review the **29 Pint findings** against the established source baseline.
- Format only files changed as part of approved fixes, unless a broader
  formatting-only change is separately agreed.
- Revalidate the previously documented multi-facility staff-termination access
  concern in `SECURITY-REVIEW.md`; resolve or explicitly disposition it before
  production authorization work or pilot.

**W1 exit gate:** Pest, PHPStan, conformance, and relevant formatting checks
pass; the security follow-up has a documented disposition; and the result is
repeatable from a clean, identified test environment.

## 5. W2 — Complete Admin Phase 2 and facility operations UI

Work from the scope and ownership rules in
[admin-workspace-audit-and-phases.md](./admin-workspace-audit-and-phases.md).
Do not duplicate its domain workflows; update that plan as each agreed
deliverable is completed.

### W2.1 Finish Admin Phase 2 identity and organization administration

Deliver and verify the remaining approved workflows:

- Role and permission-bundle management using the Identity permission
  catalogue, domain opt-in delegation eligibility, versioned/audited policies,
  independent approval for expansion, and fail-closed behavior.
- User/staff identity linkage and scoped access assignment, including
  effective dates, grant, and revocation.
- Audited create/update/status/grant/revoke operations with safe before/after
  state where appropriate.
- Protections against self-escalation, invalid scope, unsafe last-admin
  removal, protected-role changes, and unauthorized privilege expansion.
- Capability and service-availability configuration only through domain-owned
  validation, dependency checks, and cache invalidation.
- Tests for tenant/facility/department scope, inheritance, policy revisions,
  approvals, revocation, audit records, and denial paths.

**Acceptance:** An authorized administrator can administer the approved
organization and staff access lifecycle from the UI without CLI-only
operations; unauthorized users cannot grant themselves or others excess
privileges; all changes are scoped and auditable.

### W2.2 Define the Phase 3 facility-operations slice before coding

Create a short, reviewed setting register before implementation. For every
setting, record the owning domain, existing write path, permission, facility
scope, validation, audit behavior, dependencies, effect on in-flight work, and
the one UI location that will own administration.

At minimum, explicitly decide treatment of:

- Queue definitions, rooms, room/staff assignments, and queue routing.
- Appointment types/resources/schedules and their effect on existing bookings
  and slot holds.
- Bed/Ward and operating-room setup, where those operational workflows are in
  scope and the current command lifecycle has been verified.
- Service availability and dependency status, without presenting
  configuration as clinical authorization.

Do not create duplicate Admin and operational-workspace write paths. Keep
queue registration/routing and other operational transactions in their
domain-owned modules where appropriate.

**Acceptance:** The slice is approved, each control has one authoritative
writer and clear disable/update semantics, and no unverified command is
surfaced as an administration workflow.

### W2.3 Implement and test the agreed Phase 3 slice

- Build only the controls in the approved setting register.
- Use domain services/commands and existing permission, audit, lifecycle, and
  facility-scope conventions.
- Preserve active bookings, tickets, scheduled cases, and historical
  approvals when settings change.
- Show dependencies and missing setup clearly; never imply clinical approval
  from technical configuration alone.
- Test authorized and denied access, facility boundaries, audit history,
  dependency failures, safe updates, and impact on active work.

**W2 exit gate:** Phase 2 exit criteria in the Admin plan pass, and every
Phase 3 control in the agreed slice passes its domain and UI tests with a
single authoritative write path.

## 6. W3 — Split frontend pages into lazy-loaded route chunks

### Implementation

- Replace the eager page glob in `resources/js/app.tsx` with an asynchronous
  page resolver compatible with Inertia and Vite's generated import map.
- Preserve the existing `Patient/Register` to `Patients/Register` alias,
  explicit missing-page error behavior, page typing, and Carbon provider.
- Ensure page modules load on navigation rather than all being included in the
  initial application bundle.
- Do not add manual chunk rules or new dependencies unless measured output
  demonstrates they are needed.

### Verification and measurable targets

- Run frontend TypeScript, ESLint, Vitest, and production build checks.
- Test direct visits, internal navigation, the aliased route, and a missing
  page contract.
- Record baseline and post-change initial JS/CSS sizes and largest route chunk.
- Confirm the build no longer packages every page into one eager application
  bundle. Review any remaining chunk warnings and document why they are
  acceptable or make a focused follow-up.

**W3 exit gate:** All frontend checks pass; Inertia route resolution and direct
loads work; measured build output confirms page-level splitting and provides
size evidence rather than relying only on Vite's warning text.

## 7. W4 — Add isolated-database browser E2E coverage

### W4.1 Safe test infrastructure

- Provide a dedicated disposable E2E database and explicit test credentials.
- Make the Playwright server use that database, not a reused developer server
  or database.
- Add a preflight assertion that identifies the database/environment and
  refuses to run state-mutating tests against an unapproved target.
- Make fixture setup deterministic and cleanup scoped to the test-owned data.
- Document local and CI setup, including how to provision and reset the test
  database safely.

**Acceptance:** A deliberately misconfigured target causes a clear failure
before any test writes data; the suite can start a fresh app/database and
repeatably seed only test fixtures.

### W4.2 Required end-to-end journeys

Add browser tests for all of these; do not count unit/API tests as substitutes:

1. **Department-scoped Inventory:** authenticate as a department-scoped
   pharmacist; verify only authorized department locations are available,
   stock lookup works for the mapped location, and a facility-wide user retains
   the intended broader view.
2. **Procurement receiving and finance:** receive a multi-line PO; verify
   correct PO-line matching, one GRN, accepted quantities posted to stock,
   invoice metadata/matching and variance visibility, and no stock mutation
   from invoice edits.
3. **Supplier return:** post an eligible return from a receipt; verify an
   immutable return record and correct stock reduction, with invalid/excess
   quantities rejected.
4. **Admin identity/access:** complete an authorized scoped grant/revoke or
   approval journey; verify an unauthorized or out-of-scope action is denied
   and the audit outcome is visible to an authorized reviewer.

Use real browser navigation and visible user interactions for the critical
steps. Use API/database assertions only as complementary checks of persisted
invariants.

**W4 exit gate:** All four journeys pass against the isolated test target in
CI and locally; authorization-denial and tenant/facility isolation cases are
included; the existing smoke/auth journeys remain passing.

## 8. W5 — Complete Procurement operational rollout

Follow Phase 4 of
[private-hospital-procurement-receiving-plan.md](./private-hospital-procurement-receiving-plan.md).
This workstream is operational acceptance, not an assumption that a passing
build means the workflow is ready for real stock.

### W5.1 Load and receiving acceptance

- Run the planned 500-line fixture against the documented performance targets.
- Demonstrate a representative 300-line delivery can be completed unaided by
  a store clerk.
- Confirm correct PO line, quantities, batch/expiry rules, accepted/rejected
  quantities, single GRN, print output, and resulting stock balances.
- Record timing, defects, reconciliation, and sign-off; fix and retest any
  wrong-line, missing-line, duplicate-posting, or stock-integrity defect.

### W5.2 Training and readiness

- Produce the clerk guide, finance guide, and GRN sample required by the
  Procurement plan.
- Train named store and finance users using the actual workflow.
- Confirm permissions, location assignments, supplier/item setup, opening
  stock, support contacts, and rollback procedure before cutover.

### W5.3 Pilot and cutover

- Run the planned one-week parallel pilot with agreed users and a controlled
  facility scope.
- Compare PO, GRN, and stock records throughout; do not silently edit posted
  history to make totals match.
- Record discrepancies and owners; stop cutover on unresolved material stock,
  authorization, or audit discrepancies.
- Exercise and document rollback before production cutover.

### W5.4 Post-go-live reconciliation

- Produce and review the daily PO vs GRN vs stock reconciliation for two weeks.
- Track each variance through investigation, correction using approved
  compensating workflows, and closure.
- Obtain store/finance owner sign-off and record residual risks.

**W5 exit gate:** Load and unaided UAT targets pass; required materials/training
are complete; the one-week pilot and rollback test are accepted; and the
two-week daily reconciliation has no unresolved material discrepancy.

## 9. Cross-workstream final release gate

Do not mark this plan complete until every row is signed off:

| Gate | Required evidence | Status |
|---|---|---|
| W1 Backend stability | Full Pest, PHPStan, conformance, and relevant formatting results; security follow-up disposition | In progress — last full Pest: 3,462 passed / 4 failed (architecture boundaries); Organization Admin controller has no direct DB facade or Organization model imports; Clinical and Pharmacy boundary extractions verified (25 tests / 341 assertions); Patient search details use a protected Patient API reader (34 tests / 141 assertions); HR staff-photo assignment lookup uses the HR API/reader (4 tests / 31 assertions); Patient registration drafts use Patient application/reader/writer surfaces (35 tests / 429 assertions); Authentication facility-invitation resend lookup now uses an Authentication reader (11 tests / 161 assertions); focused PHPStan and Pint pass for these slices; latest architecture run still has four failing rules: direct DB use in five other domain controllers plus remaining domain/domain, domain/platform, and platform/domain imports; full Pest pending; project PHPStan baseline 242 errors; conformance 18 failures; no Git provenance |
| W2 Admin workflows | Phase 2 exit criteria and approved Phase 3 setting register/UI acceptance | In progress — scope register approved; implementation open |
| W3 Frontend loading | Type/lint/test/build results, route checks, before/after bundle measurements | In progress — tests pass but runner exits on worker timeout |
| W4 Browser acceptance | Isolated database proof and all four required E2E journeys passing | In progress — safe harness passes; domain journeys remain |
| W5 Procurement rollout | Load/UAT, training, pilot/rollback, reconciliation, and owner sign-off | Not started |

For each gate, record the run date, command or procedure, environment, result,
evidence location, unresolved issues, and responsible reviewer. A failed or
unrun gate remains open; it must not be inferred complete from another gate.

## 10. Completion report format

At completion, report each W1–W5 workstream as **Done**, **Blocked**, or
**Not done**, with evidence and remaining risks. Include all failed, skipped,
or quarantined checks and their disposition. Do not describe the project as
fully complete while any required workstream or final gate remains open.

## 11. Execution log

### 2026-10-23

- **W1 — In progress / baseline limitation:** The workspace has no Git
  repository, so branch, commit, and working-tree provenance cannot be
  established here. The existing full Pest baseline was confirmed at 3,445
  passing and 21 failing tests. PHPStan still reports 242 errors,
  `afyanova:conformance` reports 18 failures, and Pint reports 29 files.
  No PHP fixes or broad formatting changes have been made yet; classify and
  fix these after a trustworthy source baseline is available.
- **W2 — Scope approved; implementation open:** The user chose the full setup
  and readiness scope. Updated
  `admin-workspace-audit-and-phases.md` with an ownership register for
  Queueing, Scheduling, Bed/Ward, Theatre, and Organization. Admin is the
  entry/readiness surface; owning domains retain a single authoritative
  writer. No Admin backend or UI implementation has been made yet.
- **W3 — Implementation done; verification gate open:** Replaced eager
  Inertia page loading with asynchronous page imports. The production build
  now emits 50 dynamic page imports; the app entry is 14,355 bytes versus the
  prior 1,559,595-byte monolithic app JS bundle. TypeScript and ESLint pass.
  All 555 Vitest tests passed in a serial run, but Vitest exited non-zero with
  one worker `onTaskUpdate` timeout. A second low-output forked-worker run
  reproduced that runner error after all 555 tests passed, so W3 remains open.
  The system health route was also missing its
  matching page; added the page and corrected its browser test to exercise
  `/system/health`.
- **W4 — Harness working; required domain journeys still open:** Playwright
  now refuses a non-testing environment or database name without a `_test` or
  `_testing` suffix, uses port 8011 rather than reusing the developer server,
  and uses persistent isolated test sessions. Its setup seeds the verified
  test database without running a destructive migration reset. All 9 current
  browser smoke tests pass against the isolated app/database. The required
  scoped Inventory, Procurement receiving/invoice/return, and Admin access
  browser journeys have not yet been added.
- **W1 — Initial fixes/revalidation:** Added the missing `ScopeChecker` import
  in the failing Admin security test; the exact previously failing test now
  passes (1 test, 7 assertions). This is not a substitute for rerunning the
  full backend suite, and the remaining failures have not yet been resolved.
  Refined the Clinical vitals query-set and Encounter API contract tests to
  reflect active patient-list/attention-board consumers while removing an
  unused cross-domain Encounter API method; those 9 targeted tests pass.
  Rechecked PHPStan (242 errors), conformance (18 failures), and Pint (29
  files); all remain open. The full-suite baseline has not been rerun after
  these fixes.
  Revalidated the previously documented HIGH staff-termination finding:
  current code revokes ordinary assignments tenant-wide, revokes sessions,
  audits per facility, and retains privileged-role changes for approval. The
  multi-facility regression test passed in the full backend run; updated
  `SECURITY-REVIEW.md` to reflect the resolved finding and remaining policy
  test gaps.
- **W5 — Not started:** Load testing, staff UAT, training, pilot/cutover, and
  post-go-live reconciliation require operational participants and remain
  open.
- **W1 — Full-suite rerun (2026-10-23):** Reran `vendor/bin/pest --compact`
  against the verified `afyanovav4_test` database after the targeted
  corrections: **3,449 passed, 17 failed** (20,211 assertions; 590.99s).
  Remaining groups are seven architecture-boundary failures; two Encounter
  transfer failures; three Reception persona/list failures; two
  authentication/session failures; two platform facility-administration
  failures; and one staff-reinstatement conflict. No failures were skipped.
  Full PHPStan remains at 242 errors; conformance reports 18 failures (one
  undocumented table prefix, one missing forced-RLS policy, and 16 version
  column findings); Pint reports 29 files. No Git repository is present, so
  changes remain without branch/commit provenance.
- **W3 — Vitest timeout isolation (2026-10-23):** Confirmed the
  `QueueBoard.test.tsx` file passes all 18 tests but independently reproduces
  the worker `onTaskUpdate` error (91.49s test time); its isolated first
  render test passes. Individual action-dialog tests take up to about 24s.
  A one-thread full-suite attempt stalled and was stopped; it did not provide
  a passing alternative. The full 555-test suite remains unverified as a
  clean runner exit.
- **W1 — Continued failure triage (2026-10-23):** The latest complete
  `vendor/bin/pest --compact` run finished with **3,459 passed and 7 failed**
  (20,348 assertions; 660.64s): six architecture-boundary failures and one
  Reception metrics expectation. The Reception test expected yesterday's
  carried-forward encounter to count in today's metrics, contrary to the
  chosen date-scoped metrics contract; that test now passes focused
  verification (1 test, 18 assertions). The staff termination/reinstatement
  feature file also passes (10 tests, 115 assertions); its fixture now
  restores authenticated facility context after testing blocked login.
  Moved the shell dashboard controller and presenter from `App\Shared` into
  the application HTTP presentation layer, where cross-domain orchestration
  belongs; the shared-kernel architecture test now passes (4 assertions),
  and dashboard feature tests pass (7 tests, 50 assertions). The architecture
  suite previously reported **20 passed / 5 failed**. One of those failures
  was direct Identity access to Organization capability storage; that access
  is now routed through `OrganizationApi`, and its ownership rule passes.
  Remaining architecture issues include controller/domain-model boundaries
  and cross-module dependencies in domain/platform modules. PHPStan remains
  at 242 errors overall; the relocated dashboard files pass focused PHPStan.
  Pint passes on selected changed files. The complete Pest suite has not yet
  been rerun after these corrections.
- **W1 — Organization capability boundary (2026-10-23):** Added a
  tenant-scoped, batched enabled-capability read to `OrganizationApi`, backed
  by its `CapabilityReader`. Replaced direct table/Infrastructure-reader
  access in Identity role administration, Authentication bootstrap and
  platform administration, and HR staff access. The capability-table
  ownership architecture test passes. Organization capability, Identity
  role-promotion, ordinary staff-access, bootstrap, platform facility
  administration, and HR workflow tests pass (**47 tests / 807 assertions**
  for the last combined run; a separate capability/security run passed
  **38 tests / 461 assertions**). The remaining cross-domain import
  architecture test still fails on a wider legacy dependency set; its
  reported offenders fell from 52 to 48, so boundary remediation remains
  open. Focused PHPStan reports no errors in the new Organization contract,
  reader, or API service; existing diagnostics remain in the larger Identity,
  Authentication, and HR files. A full Pest rerun was started but interrupted
  before a result was captured; the last completed full result remains
  **3,459 passed / 7 failed**.
- **W1 — Authorized complete backend rerun (2026-10-23):** Ran
  `vendor/bin/pest --compact` to completion against the isolated test
  database: **3,462 passed, 4 failed** (20,352 assertions; 723.43s). The
  Reception and staff reinstatement failures are no longer present. All four
  remaining failures are architecture checks: controllers importing domain
  models, domain-to-domain imports outside Contracts, domain-to-platform
  imports outside Contracts, and platform-to-domain imports outside
  Contracts. The follow-up architecture run confirms three other previously
  failing rules now pass, including the shared-kernel and Organization
  capability ownership boundaries. The Identity direct capability-table
  violation is resolved. Pint passes on the selected capability-boundary
  files. W1 remains open pending these architecture boundaries, PHPStan
  (242 baseline errors), and conformance (18 baseline failures).
- **W1 — Controller/domain-model boundary cleanup (2026-10-23):** Removed
  five direct domain-model imports from HTTP controllers without relaxing the
  architecture rule. Clinical authoring-context values now use a Clinical
  enum; Procurement workspace status filtering uses the requisition status
  enum; patient-photo lookup goes through `PatientRepository`; supplier-return
  posting now returns its generated document number from the command handler
  rather than rereading the aggregate in the controller. Focused behavior
  tests pass: **52 tests / 680 assertions** across Clinical procedure and
  Procurement HTTP workflows, **5 patient-photo tests / 27 assertions**, and
  **14 goods-receipt tests / 148 assertions**. Focused PHPStan passes for the
  enum/controller edits and supplier-return files; Pint passes for these
  changes. The controller architecture gate still has other violations:
  direct model imports remain in HR, Patient sticky-note, Inventory invoice
  export, Organization administration, and Identity administration
  controllers. Current direct-import count in those controllers was 11 before
  the follow-up refactors below;
  full Pest and architecture suites need rerun after this cleanup.
- **W1 — Follow-up controller boundary cleanup (2026-10-23):** Removed
  direct domain-model use from HR assignment approval by dispatching the
  existing `AssignStaff` command, moved sticky-note serialization and lookup
  behind its Patient repository/presenter, and changed supplier invoice CSV
  generation to use Inventory's protected `GetGoodsReceipt` query and its
  contract DTO. Identity administrative-role lookups now go through
  `IdentityApi` and a narrow `ProtectedRoleReference` DTO instead of exposing
  the `Role` model to its controller. HR assignment policy now explicitly
  allows the existing identity-assignment approvers to dispatch that command.
  Also made the HR facility-selection test fixture's sort expectation
  deterministic. Five previously offending controller imports (HR,
  Patient, Inventory, Identity) are gone; only five direct model imports
  remain, all in Organization's `AdminOrganizationController`. Its five
  model types are used for authorized department, unit, facility, room, and
  capability writes, so the next step is to move those owning-domain writes
  out of the controller rather than weaken the architecture check.
  Organization, Identity role promotion, Inventory receipt/export, Patient
  sticky-note, HR facility, and HR lifecycle tests pass in focused runs:
  **58 tests / 927 assertions**. Focused PHPStan and Pint pass for the newly
  added contracts, reader, presenter, policy, and controller paths. The
  controller architecture gate still fails on those five Organization
  model imports. Inventory also still imports four Procurement domain models
  in its receipt handlers; these are the next cross-domain boundary to route
  through `ProcurementApi`. The full suite has not been rerun since these
  changes.
- **W1 — Inventory/Procurement contract boundary (2026-10-24):** Replaced
  Inventory receipt-handler imports of Procurement `PurchaseOrder` and
  `PoLine` models with a protected, facility-scoped `ProcurementApi` query and
  narrow receipt-reference DTOs. The API returns only PO identity, currency,
  status, and line identity/item/unit/price facts needed for receipt
  validation and invoice matching; it does not expose the Procurement
  aggregates. Added API coverage for matching, unknown PO numbers, and denial
  when the caller lacks Procurement access.
  Procurement API, Inventory pack/receiving, and goods-receipt/invoice tests
  pass (**24 tests / 243 assertions**); focused PHPStan on the changed query
  and handler files and Pint pass. The architecture gate still reports four
  rule failures: Organization controller orchestration, remaining
  domain-to-domain imports, domain-to-platform imports, and
  platform-to-domain imports. The Inventory→Procurement imports no longer
  appear among them. Full Pest, project-wide PHPStan, and conformance remain
  open; no completion is inferred from this targeted pass.
- **W1 — Organization model persistence boundary (2026-10-24):** Added an
  Organization-owned admin API backed by an Infrastructure writer and routed
  department, unit, facility, room, and facility-capability model writes
  through it. Removed all direct Organization domain-model imports from
  `AdminOrganizationController` without changing its authorization,
  transaction, validation, or audit behavior. Focused Organization admin and
  structure tests pass (**12 tests / 84 assertions**); focused PHPStan and
  Pint pass. The architecture gate now reports the remaining Organization
  controller issue as direct `DB` facade usage rather than model access; its
  read queries, scoped precondition checks, and transaction coordination still
  need to move behind the owning application/reader/writer surfaces. The
  domain-to-domain, domain-to-platform, and platform-to-domain architecture
  failures also remain. No full Pest rerun has been completed.
- **W1 — Organization Admin controller boundary (2026-10-24):** Moved
  Organization workspace reads, scoped lookup/precondition checks, facility
  and room creation, capability state changes, structure updates/status
  changes, transactions, and audit writes behind the Organization admin API,
  reader, and writer. Removed direct DB facade use and stale query helpers
  from `AdminOrganizationController`; authorization, validation, dependency
  checks, row locking, and audit metadata remain in place. Organization
  security/admin and structure suites pass (**19 tests / 111 assertions**),
  focused PHPStan reports no errors, and Pint passes. A full architecture
  boundary run still has four failures, but they are attributable to other
  controllers/modules and legacy cross-module imports; the Organization
  controller itself has no DB facade or Organization model imports. Full
  Pest, project PHPStan, and conformance remain open.
- **W1 — Clinical and Pharmacy controller boundary follow-up (2026-10-24):**
  Moved Clinical protocol approval/revocation transactions and audits to an
  Infrastructure writer exposed through `ClinicalApi`; moved procedure
  material reads to a scoped Clinical reader/API; and replaced the Clinical
  procedure-work controller's Queueing model/query/assignment access with
  Queueing contract DTO/API methods. Moved Pharmacy paper-prescription batch
  transaction orchestration from its controller to an Application service
  and Infrastructure writer, and changed the Patient API's external
  pharmacy-only registration input to a scalar sex code rather than a
  Patient-domain enum. Clinical procedure, protocol-governance, Encounter
  check-in, and Pharmacy presentation tests pass (**25 tests / 341
  assertions**); focused PHPStan and Pint pass. The architecture gate was
  rerun: it still reports four failing boundary rules. Direct DB use remains
  in nine other domain controllers; cross-module imports remain in domain to
  domain, domain to platform, and platform to domain paths. No architecture
  rule was weakened. Full Pest, project PHPStan, and conformance remain open.
- **W1 — Patient search and HR staff-photo controller cleanup (2026-10-24):**
  Moved Patient search DOB precision, photo presence, and facility-registration
  enrichment behind a Patient API backed by the Patient reader. The API
  verifies authenticated facility scope and `patient.patients.read`; the
  search result DTO remains PHI-minimal, and the HTTP controller no longer
  queries Patient tables directly. Patient search/API suites pass (**34
  tests / 141 assertions**), focused PHPStan and Pint pass. Moved HR's active
  facility-assignment photo-document lookup behind the HR reader/API while
  retaining the controller's facility and permission checks; the HR staff
  photo suite passes (**4 tests / 31 assertions**) and focused PHPStan passes.
  The architecture gate was rerun; it still reports four failing rules. The
  direct-controller-DB offenders are now six other controllers; domain to
  domain, domain to platform, and platform to domain imports remain. Full
  Pest, project PHPStan, and conformance remain open.
- **W1 — Patient registration draft persistence boundary (2026-10-24):**
  Moved encrypted draft lookup/expiry, save/upsert, and deletion out of
  `PatientRegistrationController` into Patient Application and Infrastructure
  reader/writer classes. The controller retains field allowlisting,
  validation, authorization, and user/facility scoping; encrypted-at-rest
  payloads, seven-day expiry, and deletion after successful registration are
  preserved. Registration draft and Patient workspace suites pass (**35
  tests / 429 assertions**); focused PHPStan and Pint pass. The architecture
  gate was rerun and still has four failing rules; controller DB offenders
  are now six other controllers. Cross-module import violations and full
  Pest, project PHPStan, and conformance remain open.
- **W1 — Authentication facility-invitation resend lookup (2026-10-24):**
  Moved the tenant-scoped join for an expired facility invitation from the
  HTTP controller into an Authentication Infrastructure reader, returning a
  narrow Authentication application DTO. The controller still enforces the
  unused/expired/unverified/active eligibility checks and delegates the
  resend through the existing administration service. The facility
  administration suite passes (**11 tests / 161 assertions**), focused
  PHPStan and Pint pass, and the controller has no direct DB facade use. A
  concurrent test invocation initially failed before tests ran because the
  configured PostgreSQL test account rejected authentication; an immediate
  standalone retry passed. The latest architecture run still has four
  failing rule groups; direct DB use remains in five other domain
  controllers, alongside the three cross-module import groups. Full Pest,
  project PHPStan, and conformance remain open.
- **W1 — Platform Super Admin bootstrap persistence boundary (2026-10-24):**
  Moved bootstrap token eligibility checks, row locking, activation, password
  update, token consumption, transaction, and audit recording from the HTTP
  controller into an Authentication Application service and Infrastructure
  writer. The controller retains request validation and redirect behavior.
  Platform Super Admin tests pass (**7 tests / 97 assertions**), focused
  PHPStan passes, and Pint passes after import-order formatting. The
  architecture gate still reports four failing rules: direct DB access in
  four remaining domain controllers (HR facility people search, HR facility
  staff, Authentication admin bootstrap, and Identity admin role promotion),
  plus domain-to-domain, domain-to-platform, and platform-to-domain imports
  outside Contracts. Full Pest, project PHPStan, and conformance remain open.
- **W1 — Identity role-promotion page organization reads (2026-10-24):**
  Began the staged refactor of the large role-promotion controller by moving
  its tenant-scoped active-facility and organization reads behind the
  Organization Admin API and reader. Role-promotion tests pass (**11 tests /
  165 assertions**); PHPStan passes for the Organization API files, and Pint
  passes. Focused PHPStan including the full controller reports 14 diagnostics
  in other controller sections not changed by this slice. This is an
  incremental extraction only: the controller still has Identity, HR, and
  Authentication persistence to move, so the architecture gate has not been
  rerun and W1 remains open. Full Pest, project PHPStan, and conformance also
  remain open.
- **W1 — Identity role-promotion catalog and assignment reads (2026-10-24):**
  Moved the role-availability and active facility staff-manager assignment
  reads from the role-promotion controller into the Identity API and
  Infrastructure role reader. Tenant and facility scoping and ordering are
  preserved; the page still formats the returned assignments for its view.
  The role-promotion suite passes (**11 tests / 165 assertions**), focused
  PHPStan for the Identity contract/service/reader passes, and Pint passes.
  The controller still needs its DB facade for other endpoints and reads;
  this is not a complete controller boundary fix. Full architecture gate,
  Pest, project PHPStan, and conformance remain open.
- **W1 — HR facility people-search staff results (2026-10-24):**
  Moved the active, facility-scoped staff search query and result projection
  into an HR Application service and Infrastructure reader. Existing
  permission-dependent account-email inclusion, effective-date filters,
  result ordering, and five-result limit are preserved. The HR staff workflow
  suite passes (**9 tests / 316 assertions**), focused PHPStan including the
  controller passes, and Pint passes. The controller still performs separate
  account, Identity, Authentication, Organization, and HR relationship reads,
  so the DB-facade architecture failure remains. Full architecture gate,
  Pest, project PHPStan, and conformance remain open.
- **W1 — HR facility staff directory reads (2026-10-24):**
  Moved the active facility summary lookup behind the Organization Admin API
  and the staff directory plus pending assignment-request count behind an HR
  Application service and Infrastructure reader. The HR workflow suite
  passes (**9 tests / 316 assertions**), PHPStan passes for the new
  Organization/HR reader and service files, and Pint passes. Focused PHPStan
  over the full, still-large FacilityStaffController reports seven diagnostics
  in other controller sections. The controller retains numerous unrelated DB
  operations, including staff detail, assignment workflows, and lifecycle
  actions, so it still fails the controller boundary rule. Full architecture
  gate, Pest, project PHPStan, and conformance remain open.
- **W1 — HR facility people-search/account-detail persistence boundary
  (2026-10-26):** Removed direct DB access from
  `FacilityPeopleSearchController`. Account matching now composes tenant- and
  facility-scoped Authentication, Identity, HR, and Organization contract
  reads; account details and relationship checks are likewise delegated to
  those owning-module APIs. The focused people-search tests pass (**2 tests /
  49 assertions**), focused PHPStan passes, Pint passes, and the controller
  has no DB facade use. The full HR workflow file had 8 passing tests and one
  failure at an existing redirect assertion for staff invitation submission:
  it expects the staff index while the controller redirects to the created
  staff access section. The controller architecture rule still fails because
  other controllers retain direct DB usage. Full architecture gate, Pest,
  project PHPStan, and conformance remain open.
- **W1 — Authentication admin-bootstrap completion boundary (2026-10-24):**
  Reduced the HTTP controller to request validation and response handling;
  the bootstrap flow now delegates through an Authentication Application
  service to an Infrastructure writer. The writer retains tenant resolution,
  token and account eligibility checks, facility-role authorization,
  provisioning, transactional assignment activation, password update, token
  consumption, audit events, and approval-pending status. Tenant bootstrap,
  facility invitation, and staff access regression suites pass (**38 tests /
  491 assertions**); focused PHPStan and Pint pass, and the controller has no
  DB facade use. The controller-only architecture rule still fails due to
  remaining direct DB use in three other controllers: HR facility people
  search, HR facility staff, and Identity role promotions. The refreshed full
  architecture gate still has four failing rule groups; module-to-module
  offender counts are 34 domain-to-domain imports, 13 domain-to-platform
  imports, and 15 platform-to-domain imports. The bootstrap-specific
  Identity application imports are now behind the Identity Contracts API.
  Full Pest, project PHPStan, and conformance remain open.
- **W1 — Platform audit tenant-filter options boundary (2026-10-26):**
  Moved the tenant dropdown query out of `PlatformAuditController` and into
  the existing shared `PlatformAuditReader`, which already owns platform
  audit reads. The platform governance audit viewer test passes (**1 test /
  28 assertions**), focused PHPStan and Pint pass, and the controller has no
  direct DB facade use. The controller-only architecture rule still fails
  because other controllers retain direct DB access. A fresh controller scan
  found 12 remaining DB-using controllers across HR, Identity, and Platform
  Queueing. Full Pest, project PHPStan, conformance, and the full architecture
  gate remain open.
- **W1 — HR staff profile and assignment-request read boundary (2026-10-26):**
  Moved the staff profile/assignment view query, current-assignment check,
  pending invitation-role requests, employment summary, termination blockers,
  and pending/rejected assignment-request projections into the HR directory
  reader and Application service. The existing HR workflow suite reports
  **8 passed / 1 failed**; its sole failure is the previously observed staff
  invitation redirect expectation (`hr.staff.index`) versus the existing
  access-section redirect. Pint and focused PHPStan for the reader/service
  pass. The large controller still contains assignment writes and decisions,
  lifecycle transactions, account linking/invitation writes, and remaining
  reference-data queries; therefore this is not a complete controller
  boundary fix, and the architecture gate remains open.
- **W1 — HR assignment decision and resubmission boundary (2026-10-26):**
  Added an HR Application service with dedicated assignment-request reader
  and writer for approval/rejection and rejected-request resubmission. The
  service now owns row locking, transaction boundaries, assignment dispatch,
  audit recording, status updates, and field reset behavior. Organization
  department/unit eligibility is checked through `OrganizationAdminApi`,
  including a contract method that verifies an active unit belongs to the
  selected department. Assignment-request creation persistence also now uses
  the HR writer. Follow-up cleanup moved facility, department, unit, cadre,
  position, and invitation-role catalog reads from the controller into their
  owning Organization, HR, and Identity API/readers. Focused staff
  facility-selection and creation/assignment approval, resubmission, and
  self-approval regressions pass (**4 tests / 220 assertions**); Pint and
  focused PHPStan for the new services, readers, writer, and Organization API
  implementation pass. Controller PHPStan is down to three previously
  observed diagnostics in untouched sections (one boolean expression and
  two invitation role-list types). The controller still has 14 `DB::`
  transaction/query occurrences, mostly staff creation, lifecycle,
  onboarding, account linkage, and staff-specific precondition reads. The
  full controller rule and complete architecture gate remain open.
- **W1 — HR lifecycle and remaining staff lookup boundaries (2026-10-26):**
  Moved the HR suspend/reinstate/terminate transaction workflows into an HR
  Application service. Identity lifecycle effects are now called through a
  dedicated `LinkedStaffLifecycleApi` contract, preserving the tenant-wide
  access lock, suspension/revocation rules, constrained restoration, and the
  atomic transaction with HR commands. Extracted HR staff onboarding,
  employment-start, person-edit, active-cadre, and account-link eligibility
  reads into the HR directory reader/service; active verified account lookup
  now goes through the Authentication `AccountDirectoryApi`. Facility staff
  manager-role provisioning is likewise delegated through Identity's API.
  Updated the invitation workflow regression to assert the existing intended
  redirect to the new staff access section and its current access-approval
  guidance, replacing a stale expectation of the staff index and obsolete
  message. The complete HR staff workflow suite now passes (**9 tests / 316
  assertions**), and focused Pint/PHPStan pass across all touched files,
  including the controller. The controller's direct database calls are down
  to two transaction wrappers: initial staff creation and account invitation
  plus linking. W1 and the architecture gate remain open until those
  workflows are extracted and the gate is rerun.
