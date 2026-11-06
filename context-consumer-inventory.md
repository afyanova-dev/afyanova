# Phase 0 — Facility-Context Consumer Inventory

Phase 0 deliverable of
[`facility-context-scope-alignment-plan.md`](./facility-context-scope-alignment-plan.md).
Classifies every surface that consumes the request context contract so no
implementation can assume null facility means "all facilities", or pick a
facility implicitly.

**Status: complete (this document).** Verification method and remaining
per-domain test-evidence pass are recorded below.

## Method

- Source scan: the entire `app/` tree was scanned for `TenantContext`,
  `facilityId()`, `requireFacilityId()`, `isGeneral()`,
  `active_facility_id`, `facility_context_mode`, `auth.activeFacility`, and
  `contextMode` usage (2026-10-18). Every hit was classified against the
  contract; representative files listed below. A full hit-by-hit listing is
  reproducible with one grep (recorded in the plan's Phase 0 step 2).
- The context contract is read-only for consumers:

  | API | Meaning |
  |---|---|
  | `TenantContext::tenantId()` | Tenant from the principal, never the client |
  | `TenantContext::facilityId()` | Active facility or `null` (General/unresolved) |
  | `TenantContext::requireFacilityId()` | Throws unless a facility is selected |
  | `TenantContext::isGeneral()` | Explicit General context |

- Spot-checks confirmed every unguarded-looking read routes through a shared
  fail-closed helper (`BaseQueueActionController::facilityId()`,
  `BaseAppointmentActionController::facilityId()`,
  `FacilityPeopleSearchController::activeFacilityId()`): each aborts 403/409
  when no facility is selected.

## Contract surfaces (owner: shared/platform — aligned)

| Surface | Classification | Notes |
|---|---|---|
| `app/Shared/Tenancy/TenantContext.php` | context contract | Immutable; General cannot carry a facility. |
| `app/Shared/Tenancy/ResolveTenantContext.php` | context resolver | Defaults to General for general-scope users; enters a facility-only user's sole current assignment automatically (never a revoked one); drops stale/revoked/malformed selections fail-closed; anyone else resolves unresolved. |
| `app/Shared/Tenancy/TenantContextScope.php` | non-HTTP contract | Console/jobs rebind an explicit scope; no HTTP session leak. |
| `app/Shared/Http/Middleware/HandleInertiaRequests.php` | General-aware | Shares `contextMode` (`general`/`facility`/`unresolved`), `activeFacility`, and `canUseGeneral` to the client; General adds organization-scope permissions. Client never infers scope from a missing `activeFacility`. |
| `app/Shared/Http/DashboardController.php`, `DashboardPresenter.php` | General-aware | Identifies General; omits facility-only widgets instead of zero-shaped fallbacks. |
| `app/Shared/Http/AdminAuditController.php` | Mixed | Organization-scoped admins see tenant-wide with explicit facility filters; facility admins are filtered to authorized facilities with facility identity retained. |
| `app/Shared/Http/Middleware/RequireAdminWorkspace.php`, `RequireAdminTwoFactorSetup.php` | Tenant-wide | No facility dependency. |
| `app/Models/User.php`, `app/Shared/Authorization/BasePolicy.php` | General-aware | Effective permissions re-evaluated per active context; `inActiveFacility()` requires target == active facility. |
| `app/Shared/Bus/*`, `WriteAuditRow`, `AuditLogger`, `OutboxWriter`, `TenantAwareJob` | Explicit scope | Background/attribution scope is explicit and audit rows reflect the persisted scope (tenant-wide vs facility-local). |

## Domain surfaces

**Legend** — *Facility-owned (fail-closed)*: no active facility ⇒ 403/409; the
facility is derived from context, never a client parameter. *General-aware*:
valid in General with explicit mode/target rules. *Tenant-wide*: independent of
facility context.

| Domain | HTTP surfaces | Application/command surfaces | Classification | Evidence |
|---|---|---|---|---|
| Inventory | `InventoryWorkspaceController`, `DefineStockLocation*Controller`, `SetStockLocationActiveController` | `DefineStockLocationTypeHandler`, `DefineStockLocationHandler`, `SetStockLocationActiveHandler`, receive/adjustment/reservation/consumption handlers | **General-aware (migrated)** — catalogue mutations General-only; location types scope by context; stock/locations facility-derived from context, forged targets rejected | `tests/Feature/Domains/Inventory/*`, plan Phase 4 |
| Patient | `PatientRegistrationController`, `PatientChartController`, `PatientSearch*Controller`, `PatientGlanceController`, `PatientUpdateController`, `MarkPatientDeceasedController` | `RegisterPatientHandler`, `SearchPatientsHandler` (`requireFacilityId`) | Facility-owned (fail-closed) | `tests/Feature/Domains/Patient/Presentation/PatientSearchApiTest.php` proves General-context page/API requests are denied and a submitted facility filter cannot widen the active-facility search. |
| Encounter / Reception | `Reception*Controller`, `EncounterTransferController` | `CreateEncounterHandler` (`requireFacilityId`); encounter detail, facility/patient lists, counts, and lifecycle policies enforce active-facility context or an explicitly authorized General target | Facility-owned (fail-closed) | `GetEncounterTest.php` proves a user assigned to A and B cannot read A while B is active; `ListFacilityEncountersTest.php` proves facility, patient, and count queries deny that mismatch; `RecordArrivalTest.php` proves a cross-context write is denied; Reception tests prove no-context denial and active-facility-only workspace rows. |
| Scheduling / Appointments | `Appointment*Controller` + base `facilityId()` helper | `AddToWaitlistHandler`, `BookAppointmentHandler` (null-guarded) | Facility-owned (fail-closed) | `AppointmentsBoardTest.php` proves calendar denial without selected facility; `AppointmentActionsTest.php` proves a cross-facility appointment action returns 404. |
| Queueing (Platform) | `*Queue*Controller`, `Clinician*Controller`, `AttentionBoardController` + base `facilityId()` helper | `RecordPatientMovementHandler` (null-guarded) | Facility-owned (fail-closed) | `QueueDetailTest.php` proves no-context board denial and cross-facility queue IDs return 404; `QueueFacilityScopeTest.php` proves forged cross-facility command placement is rejected. |
| Pharmacy | `PharmacyController`, `Prescription/Transcription/Dispensing/OrderController` (null-guarded) | creation, lifecycle policies, detail reads, queues, and patient prescription history enforce current-context facility scope; transcription requires the active facility; dispensing also enforces location-order-facility match (`DispensingFacilityMismatch`) | Facility-owned (fail-closed) | `PharmacyPresentationTest.php` proves workspace denial without selected facility; `PharmacyOrderTest.php` proves cross-context create/read/search/write denial and active-facility patient history; `DispenseTest.php` proves dispensing/pickup detail isolation and cross-context denial; `PickupTest.php` proves pickup and reversal denial across contexts. |
| Billing | `CashierController`, `CashSessionController`, `CatalogController`, `InvoiceController`, `PaymentController`, `BillableEventController` (null-guarded) | financial-account open/close enforce current-context scope; account detail and ledger reads use tenant-level `billing.access`; payment capture may settle an account owned by another facility, but collection attribution requires the active facility; tariff/catalog consoles retain tenant-wide scope | Mixed — facility-scoped account lifecycle; tenant-wide billing reads and operations; explicit cross-facility payment collection; tenant-wide catalogue/console | `FinancialAccountTest.php` proves cross-context account creation and closure are denied; `PaymentTest.php` proves missing collection context is rejected and a B collection is attributed to B while the ledger remains owned by A; `CashierPresentationTest.php` proves workspace denial without active facility; `PharmacyClearanceJourneyTest.php` covers billing-to-pharmacy clearance. |
| HR | `FacilityStaffController`, `FacilityPeopleSearchController`, `StaffPhotoController` (null-guarded, permissions re-checked in the active facility) | staff-user link and unlink policies require an active assignment or pending placement in the active facility | Facility-owned (fail-closed) | `AssignmentsAndRegistrationsTest.php` proves the link lifecycle succeeds in-scope and cross-facility link/unlink attempts are denied; `FacilityStaffWorkflowTest.php` covers active-facility search, General-context facility selection, and onboarding with a pending placement. Full HR run: 78 passed (918 assertions); the self-approval fixture now creates the active assignment required by the link policy. |
| Clinical | `ProcedureWorkController`, `AuthorizeEncounterProcedureController`, `ManageProcedureProtocolApprovalsController`, `NursingQueueAssessmentGuard` (null-guarded) | facility-owned reads, writes, policies, and patient-history lists enforce the active facility; General permits explicitly authorized target facilities; allergy reads remain tenant-wide per ADR-141 | Facility-owned (fail-closed), with tenant-wide allergy facts | `GetDiagnosisTest.php` proves A records are hidden while B is active; `GetPatientProblemsTest.php` proves patient history is active-facility-limited; `CreateClinicalNoteTest.php` proves cross-context amendments are denied; `NursingDocumentationHttpTest.php` covers General-context write denial. |
| CareCoordination | — | `CreateReferralHandler`, `OpenCareEpisodeHandler` (`requireFacilityId`); facility-owned reads, writes, and worklists validate the active facility or an explicitly authorized General target; patient referral and episode history remain tenant-wide per spec | Mixed — facility-owned referral/episode workflows and tenant-wide patient history queries | No HTTP surface is inventoried; `CareCoordinationQueriesTest.php` proves referral detail/worklist reads deny another active facility; `CreateReferralTest.php` and `FollowUpTest.php` prove facility-scoped writes are denied; feature and RLS tests cover workflows and patient history. |
| Laboratory | — | `RecordQcRunHandler` (`requireFacilityId`); facility-owned reads, results, specimens, QC, and worklists validate the active facility or an explicitly authorized General target; patient lab-order history remains tenant-wide per spec | Mixed — facility-owned laboratory workflows and tenant-wide patient order history | No HTTP surface is inventoried; `LabOrderTest.php` proves order detail/worklist reads and order create/accept writes deny a different active facility; feature tests cover laboratory workflows and patient history. |
| Organization | `FacilityStructureController` (null-guarded), `AdminOrganizationController` | facility structure reads require active context; administrative writes use an explicit target facility and re-check target permissions | Facility-owned / tenant-wide admin surfaces | `FacilityStructureApiTest.php` proves no-context denial; `AdminStructureManagementTest.php` and `AdminCapabilityManagementTest.php` prove cross-facility denial and target-facility audit attribution; `ConfigurationAuditTest.php` proves target-facility attribution for organization commands. |
| Audit | `AdminAuditController`, `PlatformAuditController` | tenant audit reads are limited to the active facility for facility-scoped users; organization-scoped administrators retain organization audit scope; platform audit uses the separately authorized cross-tenant scope | Mixed — facility, organization, and platform-admin scopes | `AdminAuditViewerTest.php` proves active-facility filtering, cross-facility denial, organization-scoped access, permission/MFA checks, and safe metadata; `PlatformSuperAdminTest.php` proves privileged cross-tenant audit access and metadata filtering. |
| Procurement | `ProcurementWorkspaceController` and requisition/order/supplier routes | commands use explicit facility scope; supplier master is tenant-wide | Mixed — supplier master is General-readable; requests and orders require a facility | `TenantAdminOversightHttpTest.php` proves the General supplier view and facility-selection state; `ProcurementOrdersHttpTest.php` proves active-facility order filtering. |
| BedWard | — | facility-owned reads validate the active facility (or an authorized explicit General target); commands use explicit facility scope | Facility-owned; selected-facility reads cannot cross sites | `BedWardQueriesTest.php` proves bed-ID and explicit-facility queries deny a different active facility; BedWard feature tests cover the existing workflows. |
| Radiology | — | facility-owned reads validate the active facility; patient-order reads query only facilities authorized in the current context | Facility-owned; selected-facility reads cannot cross sites | `ImagingOrderTest.php` proves order-ID, facility-list, and patient-order reads do not expose another active facility; Radiology feature tests cover the existing workflows. |
| Insurance | — | payer, plan, member-coverage, and authorization records are tenant-wide; claims, adjudications, remittances, and facility-specific payer contracts enforce current-context reads, with lists limited to facilities authorized in that context; tenant-wide payer contracts remain visible | Mixed — facility-owned reimbursement records and facility-specific payer contracts; tenant-wide insurance reference, coverage, and payer-contract records | `ClaimTest.php` proves cross-facility claim detail/readiness denial and patient/payer list isolation; `AdjudicationAndRemittanceTest.php` proves adjudication/remittance denial and search isolation; `PayerContractTest.php` proves facility-specific contract and tariff denial while retaining tenant-wide contracts. |
| Theatre | — | commands carry explicit facility or facility-owned aggregates; reads verify active-facility context or an explicitly authorized General target | Facility-owned; selected-facility reads cannot cross sites | `OperativeNoteTest.php` proves both note-by-ID and note-by-case reads deny a different active facility; Theatre feature tests cover the existing workflows. |
| Identity/RBAC | tenant-wide | `idn_*` grants re-evaluated per context | Tenant-wide | `tests/Feature/Identity/*` |
| Documents | Internal service API | Document, version, grant, and retention repositories enforce the active facility; capability-token reads bypass that filter only for the signed document (and signed version for downloads) | Facility-owned; tenant-only or unresolved context cannot widen document access | `DocumentsTenancyRlsTest.php` proves cross-facility and no-facility reads are hidden, writes require the active facility, and facility-owned grants/retention cannot be changed from another facility; `DualPathAccessAndCapabilityTokenTest.php` preserves valid explicit token access. |
| Notifications | Internal service API and event listeners | Delivery records and recipient preferences are tenant-scoped; event listeners establish event scope and `DispatchNotificationJob` captures it through `TenantAwareJob` | Tenant-wide delivery data; facility scope retained for facility-originated work | `OutboxRelayTest.php`, notification feature tests, and `PharmacyClearanceJourneyTest.php` cover queued listener dispatch, notification behavior, and cross-domain delivery. |
| Reporting | Console commands | Definitions are seeded per tenant; projection rebuild supports an explicit facility or deliberate tenant-wide rebuild | Tenant-wide definitions; projection rebuild is explicit tenant scope with optional facility scope | `RebuildProjectionsCommand.php` and `SeedReportingDefinitionsCommand.php` establish tenant context per iteration and pass the selected facility into the builders; projection feature tests cover rebuild behavior. |
| Outbox listeners and jobs | `OutboxRelay`, `DispatchListenerJob`, `DispatchNotificationJob` | Relay carries a valid event `facilityId` into the queued listener job; events without a facility remain tenant/General scoped; registered listeners receive the facility captured by `TenantAwareJob` | Explicit event scope; tenant-wide events remain tenant-wide | `OutboxRelayTest.php` proves facility context survives relay dispatch; cross-domain receipt, pharmacy, billing, and notification feature tests pass. |

## Console and asynchronous scope audit (Phase 5)

- All 34 console commands were classified. Tenant- or facility-targeted commands
  establish `TenantContextScope`; platform bootstrap uses `SystemContext`;
  facility-invitation expiry delegates to a tenant-scoped service; schema
  conformance is global and read-only.
- Scheduled lifecycle sweeps enumerate tenants and establish a fresh tenant
  context for each pass. Facility-specific invitation events and notifications
  retain the invitation's explicit facility. Reporting rebuilds accept an
  optional explicit facility; definitions remain tenant-wide.
- Facility-targeted organization and Inventory administration use explicit
  tenant/facility arguments and record the target facility. Identity and
  catalog administration is explicitly tenant-wide and does not inherit an
  HTTP session context.
- `patient:clear` is a destructive tenant/global maintenance command, not a
  facility-scoped delete: `--facility` resolves one tenant by exact facility
  name; `--tenant` selects a tenant; `--all` is global. It requires `--force`
  outside dry-run. `ClearPatientsCommandTest.php` covers the facility-name
  resolution and ambiguous-name denial.

## Verified behaviors (exit criteria for Phase 0)

1. **No surface treats a missing facility as "all facilities."** Every
   facility-owned read/write aborts (403/409) or hides facility data in
   General/unresolved context; General reads are either tenant-level or
   explicitly labelled (Dashboard, Admin Audit).
2. **No surface auto-selects a facility implicitly.** Only `ResolveTenantContext`
   resolves context, and only from the server-validated session; the sole
   defaults are General for general-scope users and the single *currently
   valid* assignment for facility-only users.
3. **No client facility parameter widens scope.** Targets are validated
   against active assignments (`FacilityContextController`,
   `ScopeChecker::selectableFacilities`); in a selected facility the active
   facility must equal the target (`BasePolicy::inActiveFacility`,
   Inventory handlers).
4. **Fail-closed** on missing tenant, invalid context, unauthorized target,
   and missing facility on facility-required operations — covered by
   `tests/Feature/Security/TenantContextTest.php` (13 tests, including
   stale-revoked selection, malformed selection, and facility-mode flag
   without selection).

## Remaining work (owned by Phases 5–6 of the plan)

- Phase 5 is complete: facility-bound workspaces, console commands, scheduled
  tasks, registered outbox listeners, and queued notification delivery have
  explicit scope and regression evidence.
- The login-persistence decision is resolved: users entitled to General start
  in General after each successful login. The login event clears any prior
  `active_facility_id` and `facility_context_mode`; in-session facility
  switching remains available, and facility-only users with one assignment
  still enter that facility automatically.
- Phase 6 verification covers the real login flow, stale/revoked context,
  switching, direct facility isolation, direct HTTP denial, audit attribution,
  and a facility workspace deep link after switching. The full backend suite
  passed after the deep-link addition with 3,533 tests and 21,319 assertions.
  The context and authentication test files also passed independently with
  30 tests and 222 assertions.
- Broader quality-gate results: frontend lint and TypeScript checks pass.
  The context-related production auth provider passes focused PHPStan and the
  changed test passes focused Pint. Project-wide Pint reports formatting
  issues across project files;
  PHPStan reports 165 errors. Application conformance reports eight missing
  `version` columns against the default local PostgreSQL database, but
  migration status shows five pending migrations that add those columns; rerun
  conformance on an approved, fully migrated disposable PostgreSQL database
  before treating these as schema defects. Vitest reports 607 passing tests
  but exits with two worker-update timeout errors, including on an isolated
  rerun, so its run is not fully clean.
- Remaining Phase 6 work is to resolve or disposition these quality-gate
  failures, update operational documentation/release notes where applicable,
  and obtain operational-owner sign-offs for UAT, training, pilot, and
  reconciliation.
