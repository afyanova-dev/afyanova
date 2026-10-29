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
| Patient | `PatientRegistrationController`, `PatientChartController`, `PatientSearch*Controller`, `PatientGlanceController`, `PatientUpdateController`, `MarkPatientDeceasedController` | `RegisterPatientHandler`, `SearchPatientsHandler` (`requireFacilityId`) | Facility-owned (fail-closed) | `tests/Feature/Domains/Patient/*` |
| Encounter / Reception | `Reception*Controller`, `EncounterTransferController` | `CreateEncounterHandler` (`requireFacilityId`) | Facility-owned (fail-closed) | `tests/Feature/Domains/Encounter/*` |
| Scheduling / Appointments | `Appointment*Controller` + base `facilityId()` helper | `AddToWaitlistHandler`, `BookAppointmentHandler` (null-guarded) | Facility-owned (fail-closed) | `tests/Feature/Domains/Scheduling/*` |
| Queueing (Platform) | `*Queue*Controller`, `Clinician*Controller`, `AttentionBoardController` + base `facilityId()` helper | `RecordPatientMovementHandler` (null-guarded) | Facility-owned (fail-closed) | `tests/Feature/Platform/*`, `tests/Feature/CrossDomain/*` |
| Pharmacy | `PharmacyController`, `Prescription/Transcription/Dispensing/OrderController` (null-guarded) | `TranscribePaperPrescriptionHandler` (`requireFacilityId`); dispense handlers enforce location-order-facility match (`DispensingFacilityMismatch`) | Facility-owned (fail-closed) | `tests/Feature/Domains/Pharmacy/*` |
| Billing | `CashierController`, `CashSessionController`, `CatalogController`, `InvoiceController`, `PaymentController`, `BillableEventController` (null-guarded) | `RecordPaymentHandler` (null-guarded); tariff/catalog consoles run explicit scope | Facility-owned (fail-closed) + tenant-wide catalogue/console | `tests/Feature/Domains/Billing/*` |
| HR | `FacilityStaffController`, `FacilityPeopleSearchController`, `StaffPhotoController` (null-guarded, permission re-checked per facility) | handers operate on explicit person/facility pairs | Facility-owned (fail-closed) | `tests/Feature/Domains/HR/*` |
| Clinical | `ProcedureWorkController`, `AuthorizeEncounterProcedureController`, `ManageProcedureProtocolApprovalsController`, `NursingQueueAssessmentGuard` (null-guarded) | handlers validate via encounter facility | Facility-owned (fail-closed) | `tests/Feature/Domains/Clinical/*` |
| CareCoordination | — | `CreateReferralHandler`, `OpenCareEpisodeHandler` (`requireFacilityId`) | Facility-owned (fail-closed) | `tests/Feature/Domains/CareCoordination/*` |
| Laboratory | — | `RecordQcRunHandler` (`requireFacilityId`) | Facility-owned (fail-closed) | `tests/Feature/Domains/Laboratory/*` |
| Organization | `FacilityStructureController` (null-guarded), `AdminOrganizationController` | structure commands target the admin-selected facility | Facility-owned / tenant-wide admin surfaces | `tests/Feature/Organization/*` |
| BedWard, Theatre, Radiology, Procurement, Insurance | — | commands carry explicit facility or facility-owned aggregates | Facility-owned via aggregate identity; console explicit | `tests/Feature/Domains/*` |
| Identity/RBAC | tenant-wide | `idn_*` grants re-evaluated per context | Tenant-wide | `tests/Feature/Identity/*` |
| Notifications/Documents/Reporting | services, not HTTP | explicit scope parameters | Tenant-wide / explicit | — |

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

- Per-domain HTTP-denial regression evidence validated over the wire for the
  surfaces above (spot-checked by scan; not yet individually re-proven by
  test run for every domain).
- End-to-end two-facility isolation runs and stale-session migration decision
  (Phase 6 steps 3–7).
