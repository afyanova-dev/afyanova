# Admin Workspace: Backend Audit and Phased Plan

**Status:** Audit baseline documented; Platform Super Admin operations delivered; broader Admin implementation remains in progress  
**Last reviewed:** 2026-10-25  
**Purpose:** Establish an evidence-based, domain-owned plan for a complete
facility-aware Admin workspace before building its screens.

## Working principles

- Admin is a coherent entry point to configuration; it is not a generic editor
  over domain tables.
- The domain that owns a setting remains responsible for its validation,
  invariants, authorization, persistence, and audit event.
- Every management workflow must define tenant/facility scope, permissions,
  dependencies, audit semantics, and the operational effect of changes.
- Facility type and care level may inform recommendations but must not silently
  become permanent workflow authorization rules.
- Disabling a capability must not delete records, approvals, or work in
  progress. Each setting must explicitly define how it affects new and active
  work.
- A registered provider, model, migration, permission, or command is not by
  itself proof of a complete operational workflow.
- Keep clinical policy approval distinct from technical configuration. Admin
  tooling must not invent or infer clinical eligibility criteria.

## Audit scope and confidence

This baseline reviewed the registered providers, discovered routes and
Inertia-rendered pages, domain models and migrations for configuration-shaped
data, console management commands, permission registries, and audit writers.
It is a static code audit, not a behavioral test of every business command in
every module. Statuses below describe observed management surfaces, not overall
module quality or clinical readiness.

The application explicitly registers **18 business domains and 6 platform
modules** in `bootstrap/providers.php`.

### Status definitions

- **Operational UI:** a web page and write paths are present for at least part
  of the domain. Verify permission and audit completeness before treating it as
  production-ready administration.
- **Scattered UI:** some configuration is exposed in a workflow workspace, but
  there is no unified administration experience.
- **Console-only:** configuration or management entry points are primarily
  Artisan commands.
- **Backend foundation:** relevant models, migrations, or permissions exist,
  but this audit found no corresponding management UI.
- **Mixed / verify:** the domain has some management UI or commands, but the
  full settings surface and readiness need further per-domain review.
- **Not in initial Admin scope:** operational business activity should remain
  in its domain workspace unless a separate configuration need is established.

## Configuration and administration inventory

| Area / module | Evidence observed | Current surface and gaps | Initial Admin disposition |
|---|---|---|---|
| **Shared audit** | `database/migrations/2026_09_01_000010_create_shared_audit_log_table.php`; `app/Shared/Audit/AuditLogger.php` | Tenant-isolated, append-only log and writers exist. No central audit search API or Admin viewer was found. Audit-write coverage is uneven across configuration commands. | Cross-cutting foundation: protected, scoped audit viewer and coverage register. |
| **Identity and RBAC** | `app/Domains/Identity/Domain/Model/`; Identity console commands | Roles, permissions, scoped access assignments, and staff identity are modeled. Management is console-based; no Identity routes/pages were found. Assignment grant/revoke writes audit records; role create/grant/status and staff-link updates do not. These CLI commands establish tenant context but do not enforce the application permission registry; production UI must use server-side authorization. | Core Admin area: roles, permission bundles, user assignments, scopes, and staff-account links, after authorization and audit gaps are addressed. |
| **Organization and facilities** | `app/Domains/Organization/Domain/Model/`; Organization console commands; `app/Domains/Organization/routes.php` | Organization/facility hierarchy, facility types, care levels, capabilities, dependencies, and services are modeled. Facility and structure commands remain console-based, and the existing HTTP endpoint is a read-only structure API. Facility, structure, and capability command writes now record target-facility audit events in the same transaction as the mutation. The commands remain operator tools and do not enforce application permissions. | Core Admin area: organization/facility structure and capability configuration. Preserve explicit capability policy; do not turn type/rank into hidden workflow rules. |
| **Authentication and sessions** | `app/Domains/Authentication/routes.php`; `app/Domains/Authentication/Presentation/Http/Controller/SessionsController.php` | Login, verification, session handling, and facility context are user-facing. Session settings are not equivalent to facility configuration. | Mostly platform/account operations; include only administrative security controls after a separate scope review. |
| **Queueing and service operations** | `app/Platform/Queueing/routes.php`; `ServiceQueuesController.php`; queue room, staff, and procedure routing controllers | Queue/room administration, room-staff assignment, procedure routing, and protocol governance are in Service Queues. Room, staff, and procedure-routing endpoints enforce `queue.definition.manage` (procedure mapping also requires `clinical.procedures.read`), validate active-facility scope, mutate tables directly, and now write target-facility audit events in the same transaction. | Phase: facility operations configuration. Decide which controls remain in Service Queues versus Admin; avoid duplicate sources of truth. |
| **Clinical procedures and protocols** | `app/Domains/Clinical/Console/DefineProcedureCommand.php`; `ManageProcedureProtocolApprovalsController.php`; procedure approval migrations | Tenant-wide procedure catalogue authoring is console-based. Facility-scoped protocol approvals have a web flow, version binding, and audit events. Approval is per procedure/facility; direct-service capability eligibility is not yet a distinct configurable gate. | Governed clinical configuration. Add a facility capability/policy only after technical workflow and clinical approval requirements are agreed. Future catalogue entries must not become directly serviceable by default. |
| **Laboratory** | `app/Domains/Laboratory/Console/DefineLabTestCommand.php`; `DefineLabPanelCommand.php`; `lab_*` migrations | Test/panel definitions have command-line setup. No Laboratory management route/page was found in this audit. | Domain catalogue phase, subject to validation, permission, audit, and operational readiness review. |
| **Radiology** | `app/Domains/Radiology/Console/DefineImagingServiceCommand.php`; `DefineModalityCommand.php`; `rad_*` migrations | Modality and imaging-service definitions have command-line setup. No Radiology management route/page was found. | Domain catalogue phase, only for configured services and after workflow ownership is confirmed. |
| **Inventory** | `app/Domains/Inventory/Console/DefineItemCommand.php`; `DefineStockLocationCommand.php`; `inv_*` migrations | Item and stock-location definitions have command-line setup; stock operations are separate business workflows. | Catalogue/configuration phase. Keep receipts, adjustments, reservations, and stock movements in Inventory workflows. |
| **Billing** | `app/Domains/Billing/routes.php`; `CatalogController.php`; `ConfigureFinancialControlCommand.php` | Billing catalog has a web surface. Catalogue commands implement `Auditable` and `AuthorizesAgainst`; the command bus invokes authorization and audit middleware. `billing.catalog.manage` is enforced in request/controller and policy paths. Financial-control thresholds are console-configured and audited. Billing routes/controllers exist, but `resources/js/pages/` did not contain all pages requested by Billing controllers at audit time. | Mixed: move genuine configuration into a coherent workflow; keep cashier, payments, invoices, and adjustments in Billing. Resolve page-contract gaps separately. |
| **Procurement** | `app/Domains/Procurement/Console/ConfigureEvaluationCommand.php`; procurement models and migrations | Evaluation settings are command-configured and audited. Procurement also has transactional purchasing workflows which are not global settings. | Configuration phase for evaluation policy and master data; keep requisitions, RFQs, POs, receipts, and contracts in Procurement workflows. |
| **Insurance** | `app/Domains/Insurance/Authorization/permissions.php`; `ins_*` models and migrations; application commands | Payers, plans, coverage rules, tariffs, contracts, authorizations, claims, and adjudications are represented. Reviewed insurance command classes implement `Auditable` and `AuthorizesAgainst`; the `CommandBus` applies those middleware. No Insurance routes/pages were found, so a user-facing configuration path is absent in the inspected surface. | Per-domain scope review required before UI. Distinguish master-data setup from claims/adjudication business processing and verify effective dating and contract lifecycle. |
| **HR and workforce** | `app/Domains/HR/Domain/Model/`; HR permission registry and migrations | Staff, employments, assignments, registrations, shifts, rosters, attendance, and leave have backend representations. No HR route file, HR console command set, or HR audit writer was found in the reviewed paths. | Separate workforce phase. Clarify HR administrator responsibilities, sensitive-data access, audit needs, and staff-identity linkage before UI work. |
| **Scheduling** | `app/Domains/Scheduling/routes.php`; scheduling policies and permissions | Web routes cover appointment calendar, booking, confirmation, cancellation, rescheduling, arrival, and no-show. Resource, appointment-type, and schedule permissions/policies exist, but no configuration routes were found. | Facility operations phase: resources, appointment types, schedules, and availability configuration; keep booking and arrival in Scheduling/Front Desk. |
| **Pharmacy** | `app/Domains/Pharmacy/routes.php`; pharmacy workflow controllers and models | Prescription, order, dispensing, and pickup operations have route/controller coverage. No pharmacy page was present in the discovered page list at audit time, so verify the end-to-end frontend contract. | Keep dispensing/order work in Pharmacy. Review formulary/catalog configuration separately before adding it to Admin. |
| **Bed/Ward and Theatre** | `app/Domains/BedWard/` and `app/Domains/Theatre/` models, commands, migrations, and permissions | Domain structures and operational commands exist. This audit did not find complete Admin configuration pages. | Later facility-operations phase; distinguish ward/room/bed/theatre setup from admissions, reservations, cases, and care delivery. |
| **Care coordination** | `app/Domains/CareCoordination/` models and commands | Referral, episode, handover, and follow-up records represent operational care workflows, not general facility settings. | Not initial Admin scope except for future explicitly identified program/referral configuration. |
| **Documents, integrations, notifications, reporting** | Registered platform providers; integration/document/notification/reporting migrations and permissions | Platform configuration exists to varying degrees; a full operator and secret-management review was not performed. Reporting definitions have backend/console support. | Separate platform-operations phase. Require strict secret handling, platform-vs-tenant scope, and operational ownership before UI exposure. |
| **Patient and encounter** | Patient, encounter, and reception routes/controllers | Primarily patient-care and visit operations. No general configuration workspace was identified in this audit. | Keep registration, chart, check-in, and encounter actions in their owning operational workspaces. |

## Phase 0 configuration register (initial evidence)

This is the working register for deciding Admin scope. Permission names below
are registered keys where verified; a registered key does not by itself prove
that every command/controller enforces it. Audit notes describe static
code-path inspection, not proof that every mutation is covered.

| Configuration item / owner | Scope and current write surface | Permission / audit evidence | Lifecycle, gap, and recommended workspace |
|---|---|---|---|
| Organization and facility records — Organization | Tenant/organization-scoped facility; `facility_type`, `care_level`, ownership, status. Console `ManageFacilityCommand`; create and limited name/status update. | `organization.facilities.read/manage`; no audit write found. CLI has no app-permission check; tenant scope is established by the operator-supplied tenant ID. | Type/care level are descriptive, not authorization. Command does not update type/level. Define closure/suspension effects and validate organization/facility lifecycle. Admin / Organization. |
| Departments, units, rooms — Organization | Facility-scoped; console `ManageStructureCommand`, create-oriented `firstOrCreate`. | `organization.structure.read/manage`; no audit write found. CLI has no app-permission check; facility/tenant scope is established from command options and tenant context. | Full rename/archive lifecycle and effects on references need definition. Validate parent-child scopes and required department/unit references. Admin / Organization; coordinate queue setup. |
| Facility capabilities and services — Organization | Capability registry is platform reference data; enablement/service availability is facility-scoped. Console `ManageCapabilityCommand`. | `organization.capabilities.read/manage`; validates registry/dependencies, audits change, flushes `FacilityCapabilities` cache. Service availability writer/UI coverage needs further review. | Disabling must preserve records/approvals and define in-flight effects. Capability is distinct from permission and clinical approval. Admin / Organization with domain policy. |
| Role definitions and permission bundles — Identity | Tenant-scoped roles/role-permission links; global permission catalogue. Console `ManageRoleCommand` for create, grant, and status. | `identity.roles.read/manage`; role create/grant/status has no apparent audit write or app-permission check in the CLI command. Grant validates permission keys against the registry. | Define safeguards against self-escalation, unsafe last-admin removal, invalid keys, and locking out administrators. Admin / Identity. |
| User-to-role assignments — Identity | Tenant-scoped assignments with organization/facility/department/unit scope and effective dates. Console `ManageAssignmentCommand` grant/revoke. | `identity.assignments.read/manage`; grant/revoke writes audit events; CLI has no app-permission check and limited input validation, so is not a production UI authorization boundary. | Define revocation, scope inheritance, effective-date validation, cross-scope validation, and session effects; UI must authorize server-side and validate references. Admin / Identity. |
| User/staff identity link — Identity + HR | Tenant-scoped auth-user-to-staff link; console `ManageStaffIdentityCommand`; HR separately models staff and employment. | `identity.users.read/manage`, `identity.staff.read/manage`; staff-link command has no apparent audit write or app-permission check. | Decide source of truth and lifecycle to avoid duplicate or mismatched identities; validate user belongs to selected tenant. Admin / Identity/HR after ownership decision. |
| Authentication, account lifecycle, initial admin — Authentication/Identity | Tenant users and session facility context. Demo credentials/roles are created by `DatabaseSeeder`. Fortify supplies login, password-reset, and email-verification flows; no tenant-admin invitation or production bootstrap route was found. | Identity includes `identity.users.read/manage`. Seeder creates `admin@afyanova.local` with shared development password `password`; this is not production bootstrap/recovery. | User-approved direction: one-time audited deployment command; require an operator and tenant, send an expiring password-setup/email-verification link, never use a shared password, and refuse bootstrap once a tenant admin exists. Recovery, account suspension, and session invalidation still need design. Admin / Identity; session controls stay in Authentication. |
| Queue definitions, rooms, room staff, procedure routing — Queueing | Facility-scoped queue registration is mapped to `RegisterQueueCommand`, but no HTTP route/caller was found; room/staff/procedure mapping web endpoints write directly to join tables in Service Queues. | `RegisterQueueCommand` implements `AuthorizesAgainst` but not `Auditable`. Web endpoints enforce `queue.definition.manage`; procedure mapping also checks `clinical.procedures.read`; scope and reference validation exist, but no audit events were found in queueing code. | Add audit coverage for registration and direct routing writes; test replacement semantics and in-flight work. Keep one authoritative write path. User-approved later phase: Admin facility setup or Service Queues. |
| Procedure catalogue — Clinical | Tenant-wide versioned definitions; console `DefineProcedureCommand`. | `clinical.procedures.read/request/perform/complete/amend` cover workflow; no catalogue-manage permission identified. Definition create/update commands audit. | Catalogue insertion must not imply order authorization or direct nursing approval; revisions invalidate stale version-bound approvals. Admin / governed Clinical catalogue. |
| Facility procedure protocol approval — Clinical | Facility + procedure + definition version; web approve/revoke flow. | `clinical.procedures.protocol_manage` and `.read`; approval/revocation are audited; revocation requires reason. | Revocation blocks future direct protocol use; in-flight handling needs definition. Separate from capability, queue mapping, room assignment, and user permission. Clinical governance linked from facility admin. |
| Direct nursing procedure capability — Organization + Clinical | No dedicated facility capability/admin control found. Existing facility capability framework supports dependency validation. | No dedicated permission/audit action/lifecycle record found for direct nursing eligibility. | Must connect facility policy to procedure approval and Reception. Facility type is not the rule. Disable should stop new requests, preserve history, and define in-flight behavior. Admin / capabilities + Clinical governance. |
| Lab tests and panels — Laboratory | Tenant catalogue; console `DefineLabTestCommand` and `DefineLabPanelCommand`. | Operational lab permissions exist; catalogue-specific manage key not identified in reviewed registry. Definition commands audit. | Review active/version changes and panel/order references before deactivation. Admin / Laboratory catalogue; results/QC stay in Laboratory. |
| Imaging modalities and services — Radiology | Tenant catalogue; console `DefineModalityCommand` and `DefineImagingServiceCommand`. | Operational radiology permissions exist; catalogue-specific management key not identified. Commands audit. | Review dependencies and existing orders/studies on deactivation. Admin / Radiology catalogue; orders/reports stay in Radiology. |
| Inventory items and stock locations — Inventory | Tenant-scoped catalogues; console `DefineItemCommand` and `DefineStockLocationCommand`. | Operational inventory permissions exist; catalogue-specific manage key needs verification. Reviewed commands audit. | Preserve batches, balances, movements, and reservations on deactivation. Admin / Inventory catalogue; stock transactions stay in Inventory. |
| Billing catalogue, tariffs, item maps — Billing | Tenant-wide service definitions and effective-dated tenant/facility tariff scopes; Billing Catalog web routes/controllers. Item maps are separate catalogue commands. | `billing.catalog.manage`; catalogue mutations dispatch `Auditable` commands through `AuthorizeCommand` and `WriteAuditRow` bus middleware. Catalog forms/controllers enforce the permission; policy checks tenant context. Item-map HTTP exposure was not established in this review. | Tariffs preserve history; service inactivation does not delete tariff rows. Verify item-map mutation surface and metadata sufficiency. Keep issued financial records out of generic settings. Billing Catalog or linked Admin view. |
| Financial dual-control thresholds — Billing | Tenant-scoped; console `ConfigureFinancialControlCommand`. | Writes `billing.financial_control_configured`; HTTP/admin authorization and UI not found. | Null threshold means always dual-control. Decide treatment of pending approvals when changed. Admin / Billing controls. |
| Procurement evaluation settings — Procurement | Tenant-scoped; console `ConfigureEvaluationCommand`. | Command writes audit event; web settings UI and permission coverage not found. | Define versioning and snapshots for active RFQs/evaluations. Admin / Procurement controls; transactions stay in Procurement. |
| Insurance payer/plan/coverage/tariff configuration — Insurance | Payer, plan, coverage rule, tariff, and contract commands are modeled; no Insurance route file was found. | Reviewed Insurance application command classes implement `Auditable` and `AuthorizesAgainst`; `CommandBus` supplies authorization/audit middleware. No user-facing configuration route/page found. | Confirm all configuration mutations dispatch through this bus; validate effective-date/contract semantics. Admin / Insurance configuration; claims stay operational. |
| Workforce configuration — HR | Commands/handlers for staff, cadre, position, employment, registration, user links, shifts, rosters, attendance, and leave are mapped in `HRServiceProvider`; no HR route, console command, or in-app dispatch caller was found. | HR permission keys are registered. Reviewed command objects implement `AuthorizesAgainst` but not `Auditable`; no HR `AuditLogger` writer was found. | Add audit contracts before UI. Differentiate access to sensitive identity/employment/credential data and preserve effective history. HR/Admin boundary to decide. |
| Scheduling resources and schedules — Scheduling | `RegisterSchedulableResource`, `DefineAppointmentType`, `DefineSchedule`, and `AddScheduleException` command handlers are mapped; route file covers booking and appointment operations, not configuration; no in-app configuration dispatch caller was found. | Relevant `sch.*.manage` permissions/policies exist. Reviewed scheduling commands implement `AuthorizesAgainst` but not `Auditable`; no scheduling audit writer was found. | Add audit coverage and define effects on existing bookings/held slots; no silent move/delete. Admin / Scheduling setup; bookings remain operational. |
| Bed/Ward setup — BedWard | Ward, room, and bed setup command objects/handlers and policies exist; no BedWard route file found. | `CreateWard`, `CreateRoom`, `CreateBed`, `UpdateWard`, `DecommissionWard`, and related commands implement `Auditable` and `AuthorizesAgainst`; no HTTP management surface found. | Confirm intended setup commands are registered/dispatched and check decommission behavior against occupied beds/reservations before UI. Admin setup; admissions/care events stay operational. |
| Operating-room setup — Theatre | Operating-room model and `CreateOperatingRoom` command/policy exist; no Theatre route file found. | `CreateOperatingRoom` implements `Auditable` and `AuthorizesAgainst`; permission key is `theatre.rooms.manage`. No HTTP management surface found. | Confirm lifecycle/retirement commands and occupied/scheduled case handling before UI. Admin setup; theatre cases stay operational. |
| Integrations, documents, notifications, reporting — Platform | Registered platform services; mapped integration commands include connector/webhook/credential management; documents and notifications include templates/retention/access functions. Only platform route files found were Queueing and Attention; no in-app caller for the inspected integration setup commands was found. | Static scan found no `Auditable`, `AuthorizesAgainst`, or `AuditLogger` contracts in Integration, Documents, Notifications, or Reporting subtrees. No configuration routes found there. Trusted caller/dispatch restrictions, authorization, audit, and secret redaction must be traced before exposure. | Define platform-vs-tenant scope, secret storage/rotation/redaction, retention, and outage effects before UI. Later platform-operations area. |
| Care coordination, patient/encounter, pharmacy operations — owning domains | Operational records and workflows exist. | Primarily transaction/care permissions, not general configuration. | Keep patient care and business transactions in domain workspaces; identify any true master-data needs separately. |

### Audit coverage snapshot

Observed audited configuration writes include facility capability changes,
Identity assignment grant/revoke, clinical procedure definition
create/update, clinical protocol approval/revocation, lab test/panel
definitions, radiology modality/service definitions, inventory item/location
definitions, financial-control settings, and procurement evaluation settings.
Billing catalogue commands are also audited: the relevant commands implement
`Auditable`, and `CommandBus` runs authorization and audit middleware. Service
catalogue and tariff changes use `billing.catalog.manage`; billing service and
tariff policies verify tenant context.
Reviewed Insurance configuration commands and BedWard/Theatre setup commands
implement `Auditable` and `AuthorizesAgainst`; the shared command bus provides
the corresponding middleware, but module route/UI coverage was not found. HR
and Scheduling command objects are mapped and implement `AuthorizesAgainst`
but not `Auditable`; no in-app configuration caller was found. Queue
`RegisterQueueCommand` is mapped, authorized, but not audited, with no route/
caller found. Platform configuration commands are mapped but have neither
contract; no in-app setup route/caller was found in the scanned paths.

Confirmed gaps in the inspected paths: Identity role create/grant/status and
staff-link mutations have no apparent audit write; Organization facility and
structure commands have no apparent audit write; queue room, room-staff, and
procedure-routing controllers mutate join tables directly without audit
events. The console tools do not enforce application permissions; they rely
on deployment/operator access and tenant context. Queue web endpoints do
enforce `queue.definition.manage` and validate active-facility scope.

Remaining detailed coverage checks before the relevant implementation phases:
confirm Insurance and BedWard/Theatre mutations use the command bus end to
end; inspect lifecycle constraints and validation for queued HR/Scheduling
commands; examine queue registration/routing lifecycle behavior; inspect
platform secret handling and trusted dispatch; verify any unregistered domain
writes. No central audit read API or Admin viewer was found. “Not found” is
not conclusive evidence of absence.

## Cross-cutting gaps and risks

1. **No unified Admin workspace:** `resources/js/layouts/AppLayout/navigation.ts`
   and the current page set contain no Admin workspace.
2. **Configuration ownership register:** the Phase 0 baseline covers known
   settings and explicitly flags unknown paths. Deeper per-module validation,
   lifecycle, and audit checks remain implementation-phase gates.
3. **CLI dependence:** Identity, Organization, clinical procedure catalogues,
   Laboratory, Radiology, Inventory, Billing controls, and Procurement settings
   have important CLI management entry points.
4. **Audit coverage varies:** confirmed gaps include Identity role/staff-link,
   Organization facility/structure, queue registration/routing, HR, and
   Scheduling configuration mutations. Insurance and BedWard/Theatre have
   auditable command objects but no management UI; platform configuration
   command objects lack authorization/audit contracts in the scanned paths.
5. **Authorization and scope need consistent treatment:** administrative
   screens must respect tenant and active-facility scope and use explicit
   permissions, not just hidden navigation.
6. **Workspace contract completeness varies:** backend routes do not guarantee
   a resolvable frontend page or complete user journey.
7. **Capability and approval are different:** facility capability says a
   facility operates a class of service; procedure approval says a specific
   procedure is allowed under a facility protocol. User authorization, queue
   routing, and room assignment are separate requirements as well.
8. **Disable semantics are unspecified in places:** each setting must state
   whether a change affects new work only, existing work, or both. Default
   expectation: stop new work but preserve records and safely handle in-flight
   work.
9. **Do not expose every model:** clinical records, financial transactions,
   inventory movements, claim decisions, and patient/encounter operations are
   not generic Admin settings.

## Phased implementation plan

Phases are ordered by dependency. A phase is complete only when its exit
criteria and tests pass and this document is updated. Use checklist items as
the progress record.

### Phase 0 — Configuration ownership and completeness register

**Status: [x] Complete — baseline approved 2026-10-08**

- [x] Enumerate the known candidate setting/master-data entities by owning module; flag areas needing targeted follow-up.
- [x] Record each known item’s tenant/facility/organization scope and active-facility
  behavior.
- [x] Record current read/write surface: UI, command, seeder, API, or absent.
- [x] Record registered permission names where verified and flag enforcement requiring targeted verification.
- [x] Record known validation, dependencies, version/concurrency behavior, and audit evidence; label missing evidence.
- [x] Record known disable/revoke behavior for new work, in-flight work, historical records, and cache invalidation; label unspecified behavior.
- [x] Classify each item as Admin-owned configuration, operational-workspace
  function, platform operation, or out of scope.
- [x] Focused static review of Identity, Organization, queue configuration,
  and Billing catalogue write paths; document confirmed audit/authorization
  evidence and gaps.
- [x] Inspect the demo administrator seeder and document that it is not a production bootstrap/recovery process.
- [x] Agree the production first-admin provisioning direction: one-time audited
  operator command, expiring setup/verification link, no shared password, and
  fail-closed behavior when a tenant admin already exists.
- [x] Record recovery, role-assignment, transaction, and audit-actor details as
  Phase 1 design gates rather than blocking the Phase 0 baseline.
- [x] Static route/permission/audit inventory for HR, Insurance, Scheduling,
  Bed/Ward, Theatre, and platform configuration; record found surfaces and
  gaps.
- [x] Move detailed write-path/lifecycle verification for deferred areas to
  their respective implementation phases as explicit prerequisites.
- [x] Review the proposed first-release scope with the user before designing
  the complete Admin navigation and permissions.

**Exit criteria:** Every candidate control has an owner, scope, permission,
audit, validation, lifecycle effect, and target workspace—or is explicitly
deferred. The user approved this baseline and the first-release scope.
Unverified module paths, bootstrap edge cases, and lifecycle effects are
recorded as gates in their owning implementation phases; they do not imply
that those features are already ready to implement.

### Phase 1 — Admin security, bootstrap, and audit foundation

**Status: Design baseline approved; implementation in progress**

- [x] Establish separate platform Super Admin, organization-wide Tenant Admin,
  and facility-scoped Facility Admin boundaries; build the Tenant Admin
  workspace first.
- [x] Confirm one healthcare organization per tenant for the first release,
  allowing tenant-level audit events to be attributed to that organization.
- [x] Define `tenant_admin` as an `ORG`-scoped role assigned to the tenant's
  sole healthcare organization; fail closed against any active/effective
  assignment for that organization.
- [x] Establish that Tenant Admin is a setup/access role, separate from clinical,
  cashier, dispensing, and other operational roles.
- [x] Approve the first Tenant Admin default scope: organization/facility/
  capability setup, Identity access administration, constrained role
  delegation, and audit viewing only.
- [x] Agree that Tenant Admin can assign approved operational bundles and
  create constrained custom roles; built-in role definitions remain protected.
- [x] Approve the Tenant Admin permission allowlist: setup/access administration
  and audit viewing only, with no clinical or operational permissions.
- [x] Define the delegation-policy model: explicit, versioned, audited Admin
  policy; additions require independent authorized approval; restrictions
  apply immediately but do not silently revoke existing assignments; only
  domain-opted-in permissions are selectable; self-grant and protected-role
  inheritance are prohibited. Catalog inventory reviewed all 23 registered
  permission lists. None currently declares delegation eligibility, so the
  safe-to-delegate catalog is empty until each owning domain explicitly opts
  in reviewed permission keys.
- [x] Add an authoritative protected-role marker for built-in bundles and
  block protected role grant/status changes through the Identity command.
- [ ] Enforce protected-bundle and constrained-delegation checks across the
  future Tenant Admin role-management workflow. Policy configuration,
  permission grants, and assignments must all be checked server-side against
  the approved policy revision.
- [x] Require a second authorized administrator's approval for every admin
  role promotion (`tenant_admin` and `facility_admin`); routine operational
  role assignment never includes admin roles.
- [x] Keep routine Identity-command grants of Tenant Admin, Facility Admin,
  and custom roles with effective Admin workspace access blocked.
- [x] Implement distinct, auditable request/approval records for admin-role
  promotion; require two different authorized administrators and no
  self-request or self-approval.
- [x] Allow the inviting Tenant Admin to approve or withdraw a Facility Admin
  invitation request (2026-10-25). The exception is limited to requests
  recorded with `request_origin = 'FACILITY_INVITATION'`; Tenant Admin
  promotions, manual Facility Admin promotions, revocations, and target
  self-approval remain subject to the independent-administrator rule.
- [x] Implement one-time initial-admin bootstrap through an operator command,
  expiring setup link, verified email, and pending assignment activation.
- [x] Add a separate tenantless platform Super Admin registry and one-time
  operator bootstrap; require confirmed 2FA before platform administration.
- [x] Allow an authenticated platform Super Admin to initiate the existing
  protected initial Tenant Admin invitation only for an active tenant with
  exactly one active organization and no effective Tenant Admin; preserve
  tenant audit attribution and the independent approval rule for later
  administrative-role promotion.
- [x] Provide a feature-organized Platform Admin tenant registry, tenant
  detail view, and guided tenant/organization/first-facility setup workflow.
  Create the structure atomically, then invite the initial Tenant Admin
  separately from the tenant detail view.
- [x] Show pending and expired Tenant Admin invitations in the tenant
  registry, including the invitee and expiry state.
- [x] Let Platform Super Admins suspend/reactivate tenants with an audited
  reason; suspension revokes tracked tenant sessions and preserves tenant
  data, while reactivation requires a fresh sign-in.
- [x] Provide a cross-tenant Platform audit workspace with tenant, action,
  actor, target, and date filters; expose only allowlisted metadata, and audit
  each viewer query.
- [x] Provide independently reviewed Platform Super Admin suspension,
  reactivation, and MFA-reset requests; prohibit self-request/self-review,
  preserve the last-active-admin safeguard, and invalidate prior platform
  sessions after approved changes.
- [ ] Define the operator-assisted recovery/replacement path when no effective
  Tenant Admin can approve a later administrative promotion. Per the
  2026-10-08 decision, defer implementation until the platform can authenticate
  and record an operator and an independent approver. The 2026-10-25 Facility
  Admin invitation exception resolves the single-admin deadlock for that
  onboarding path; this recovery item remains open for Tenant Admin promotions
  and other administrative changes.
- [ ] Implement out-of-band Platform Super Admin break-glass recovery only
  after operator identity and independent-approver authentication and
  attribution are available.
- [x] Make bootstrap creation, pending assignment, invitation, completion, and
  audit atomic; record the change reference without inventing a human actor.
  Keep the raw link out of command output and store only its hash.
- [x] Add explicit target-facility audit attribution to the command pipeline,
  including intentional null attribution for organization-level actions.
- [x] Wire facility, structure, capability, room, room-staff, and procedure
  routing writes to their explicit target-facility audit attribution; keep
  each audit insert atomic with its direct database mutation.
- [x] Establish backend authorization, active-facility validation, and
  server-side route guards for the Admin pages and writes implemented to date;
  every new management surface remains subject to the same gate.
- [x] Require confirmed 2FA for tenant- and facility-admin access at the
  backend boundary, with enrollment and recovery codes available at setup.
- [x] Audit use of a 2FA recovery code without recording the code itself.
- [ ] Implement audited, out-of-band operator recovery with session revocation
  and mandatory re-enrollment after operator and independent-approver
  authentication and attribution are available.
- [x] Add transactional audit events for Identity role lifecycle/permission
  changes, assignment grants/revocations, and staff-identity changes.
- [x] Audit current write paths for configuration actions implemented or
  planned for the early Admin foundations. Any future write surface must add
  scoped, atomic audit coverage and regression tests before release.
- [x] Add a protected audit read/query surface with tenant/facility filters,
  pagination, actor/action/target filters, and safe metadata rendering.
- [x] Add the audit viewer and navigation entry subject to its own permission.
- [x] Keep raw authentication telemetry out of the tenant Admin viewer unless
  a separately scoped security-operations requirement is approved.
- [x] Test tenant isolation, facility scoping, forbidden access, bounded
  filters, and sensitive metadata redaction.
- [x] Add a regression test for append-only audit storage.

**Implementation update (2026-10-08):** Registered dedicated
`admin.workspace.access` and `audit.events.read` permission keys; added
server-side, tenant-context-bound Admin routes; and implemented a guarded
two-factor enrollment route using the existing Fortify setup, QR, secret, and
confirmation endpoints. Admin entry redirects authorized users without
confirmed 2FA to enrollment, which requires recent password confirmation.
The user approved the exact setup-only Tenant Admin permission bundle.
Implemented a protected built-in-role marker, blocked protected role grants and
lifecycle changes and protected-role inheritance through the Identity command,
and added the one-time
`afyanova:admin:bootstrap` command. It requires an operator change reference,
exactly one active organization, creates the protected ORG-scoped role and a
PENDING assignment, emails a 60-minute fragment-based link while storing only
its hash, verifies the email and sets the password on redemption, activates
that assignment atomically, and audits issuance/completion with no fabricated
user actor. Same-user link rotation is supported with `--resend`; the previous
link becomes invalid. Admin 2FA enrollment now displays Fortify recovery codes
before confirmation and requires the administrator to attest that they saved
them. Recovery-code use is recorded in the append-only audit log without the
code value. Auditable commands can now opt into explicit target-facility
attribution; tests prove a different target facility is recorded correctly
and that organization-level actions retain a null facility even when an active
facility is selected.

The read-only Admin audit viewer now has a dedicated `audit.events.read`
server-side check, tenant-ID filtering, assigned-facility choices, active
facility defaults for facility-scoped readers, and organization-wide access
only for an active ORG-scoped assignment carrying the audit permission.
Date windows are limited to 90 days, page sizes to 100, and page numbers to
10,000. Results use stable descending timestamp/ID ordering and expose only
allowlisted Admin/security action namespaces and metadata keys; reasons, raw
metadata, clinical/patient events, platform rows, and `aut_auth_events` are not
returned. The viewer and permission-gated Admin navigation are implemented,
with focused tests for tenant and facility isolation, permission denial,
bounded filters, and metadata redaction.

The platform Super Admin bootstrap and onboarding flow is implemented as a
separate tenantless identity, with a one-time operator command, expiring
fragment-token invitation, token-hash-only storage, replay protection,
global platform audit, login routing, and a confirmed-2FA gate. The
platform-only console can invite the initial protected Tenant Admin through
the same 60-minute setup flow, subject to the one-active-organization and
no-existing-admin invariants; the action is attributed to the Super Admin in
the tenant audit log. It does not directly grant Facility Admin or bypass
two-person promotion. The approved local migration workflow applied the
pending Admin schema migrations. The local mail transport is log-only, so the
first setup link was not delivered; it was expired and system-audited.
Bootstrap commands now reject log/array and failover configurations that
could persist one-time secrets in logs. Configure a delivery mailer before
resending the pending invitation.

Facility, structure, and capability console writes now audit the exact affected
facility in the same transaction as each creation/update. Direct room, room-
staff, and procedure-queue routing writes now emit a scoped audit event with
the authenticated actor and a non-sensitive change count before their write
transaction commits. The viewer includes only this new
`queue.configuration.*` category. Focused regression tests cover all six write
paths and target facility attribution.

Identity role creation/grants/status changes, access-assignment grant/revoke,
and staff identity writes now append audit rows in the same transaction as
their mutations. Assignment events explicitly record the assigned facility;
staff-number values are not copied into audit metadata. Actor remains null for
console-driven operations because the CLI does not authenticate a human
operator. Existing database-isolation coverage verifies tenant audit rows
cannot be updated or deleted.

Admin configuration screens, complete delegated-role anti-escalation,
operator-attributed 2FA recovery, and audit coverage for all remaining planned
configuration actions remain outstanding; Phase 1 exit criteria remain open.

**Delegation-catalog review (2026-10-08):** Inspected all 23 module/shared
`Authorization/permissions.php` registries. They register permission keys as
plain strings and contain no explicit owner opt-in metadata for delegation.
Permission descriptions show that the catalog includes patient/clinical data
access, healthcare and financial operations, workforce and sensitive identity
management, security-sensitive actions, and technical configuration. A key
being registered—or being read-only—does not establish that it is safe to
delegate. Therefore, no permission is currently eligible for delegation.
Before any key is enabled, its owning domain must explicitly review and
declare that exact permission safe for the delegation use case. The policy
editor and role-management write paths must reject keys absent from that
owner-approved catalog.

**Phase 2 update (2026-10-08):** Verified the currently available database
tenant records before applying the topology constraint; no tenant had multiple
organization rows (one tenant checked, with one organization). Added a
migration preflight that counts organizations under each tenant's RLS context
and reports duplicate tenant IDs without applying the unique constraint when
cleanup is required. The resulting database unique constraint enforces at
most one organization per tenant, including inactive rows. Updated the
Identity authorization fixture to model two facilities under the single
organization, retaining coverage for organization-wide versus facility-only
grants. The bootstrap rejection regression now checks failure when there is no
active organization rather than constructing an invalid multi-organization
tenant. Organization/facility management UI and the remaining Phase 2
workflows are still outstanding. The promotion-request migration and this
topology migration are both discovered but remain pending in the currently
configured non-test database; apply them through the normal deployment
migration process.

**Phase 2 Admin UI update (2026-10-08):** Added a read-only Organization &
Facilities overview behind the Admin 2FA route guard and the
`organization.facilities.read` permission. The backend filters every facility
against effective permissions at that facility, so organization-scoped
administrators see their organization's facilities while facility-scoped
administrators see only assigned facilities. It reports facility status,
facility type/care level/ownership, and configured/enabled capability counts.
Navigation is permission-gated. The page explicitly identifies itself as
read-only; no mutation capability is implied. Focused tests cover organization
visibility, facility isolation, and permission denial.

**Current configuration audit coverage (2026-10-08):** Traced the currently
implemented Organization facility/structure/capability commands; Identity
role, permission, assignment, and staff-identity commands; queue-room, room-
staff, and procedure-routing endpoints; initial-admin bootstrap; and
administrative-role promotion writes. Their audit events are in the mutation
transaction and focused tests verify target facility, actor where available,
privacy allowlists, or intentionally unattributed CLI actors. The scoped
validation run passed **33 tests and 291 assertions** across these flows, the
Admin audit viewer, and database-enforced audit immutability. Organization
policy/operating-hours/service tables currently have no management write
surface; they remain future work, not silently treated as audited workflows.
Any future write path must be audited and tested before release.

Administrative role promotions now use tenant-scoped pending request records.
Only protected `tenant_admin` and `facility_admin` bundles may be requested.
The requester must hold Admin workspace and assignment-management authority
within the target organization/facility; the target account must be active and
email-verified; self-promotion is denied. A distinct authorized administrator
with confirmed 2FA must approve or reject, and rejection requires a reason.
Approval atomically marks the request and creates the scoped active assignment
with the approver recorded as `granted_by`; the request alone conveys no
access. Request, approval, and rejection each produce separate audit events.
The scoped request list is paginated at 25 records per page. Routine Identity
assignment grants remain blocked from admin roles.

**Facility Admin invitation approval update (2026-10-25):** A pending
`facility_admin` promotion created by the Facility Admin invitation flow now
records `request_origin = 'FACILITY_INVITATION'`. The inviting Tenant Admin may
approve or withdraw that specific request without a second administrator. The
target still cannot self-approve; `tenant_admin` promotions, manually requested
`facility_admin` promotions, and revocations still require a distinct
authorized administrator. Approval writes `self_approved`, `approval_mode`, and
`request_origin` to the audit metadata, and the UI shows an explicit warning
before the inviting Tenant Admin approves. A backfill migration marks matching
requests that were already pending, so the single-Tenant-Admin deadlock is
resolved without relaxing the higher-privilege Tenant Admin rule. The backfill
was applied to the development database, and the existing pending Facility
Admin request now carries the invitation origin.

Regression coverage verifies a facility-scoped administrator cannot view or
decide a promotion request belonging to another facility. The Identity Admin
routes use the authenticated/verified and Admin-workspace middleware gates,
with controller-level permission and target-scope checks retained on writes.
The promotion migration is discovered by the Identity service provider; it
remains pending in the currently configured database and must be applied by
the normal deployment migration step.

**Recovery decision (2026-10-08):** Defer out-of-band 2FA recovery until the
platform can authenticate and record both the operator and an independent
approver. Do not use a deployment-command actor string or ticket reference as
a substitute for authenticated attribution.

**Exit criteria:** Authorized administrators can inspect relevant audit
records; unauthorized users cannot browse or mutate Admin resources; bootstrap
does not create an unaudited privilege-escalation path.

#### Phase 1 design baseline

This is the implementation direction grounded in the current code; it is not
an authorization to skip the listed tests or to expose unfinished commands.

- **Tenant and facility context:** `ResolveTenantContext` derives tenant scope
  from the authenticated user, validates the session's active facility against
  current assignments, and establishes the database RLS transaction. Admin
  routes must use this context, not client-supplied tenant/facility IDs.
- **Administrator boundaries:** keep three authorities distinct. Platform
  **Super Admin** operates AfyaNova across tenants and is outside the current
  tenant Admin workspace. **Tenant Admin** manages one healthcare
  organization within its tenant, including its facilities. **Facility
  Admin** manages only the facility/facilities on its assignments. The user
  selected the Tenant Admin boundary for the first Admin workspace.
- **Tenant topology (first release):** one tenant represents exactly one
  healthcare organization. The user approved this first-release assumption so
  tenant-scoped audit events without a facility can be shown in that
  organization's audit view. Enforce the invariant in provisioning and
  administration; do not rely on convention if multi-organization tenancy is
  later introduced.
- **Tenant Admin assignment:** Identity's `AssignmentScopeType` supports
  `ORG`, `FACILITY`, `DEPARTMENT`, and `UNIT`, not a special `TENANT` role
  scope. Model the Tenant Admin permission bundle as an `ORG` role and grant
  it an assignment to the tenant's healthcare organization with no facility,
  department, or unit restriction. Verify the organization belongs to that
  tenant. Do not use a null organization assignment, which could unintentionally
  span every organization in a multi-organization tenant. Verify the seeded
  `facility_admin` role remains `FACILITY`-scoped and is never promoted by
  name or assumption.
- **Tenant Admin permission mapping (approved):** use the existing
  `identity.access`, `identity.users.read/manage`, `identity.roles.read/manage`,
  `identity.assignments.read/manage`, `identity.staff.read/manage`,
  `organization.access`, `organization.facilities.read/manage`,
  `organization.structure.read/manage`, and
  `organization.capabilities.read/manage`, plus a dedicated
  `admin.workspace.access` and `audit.events.read`. This grants setup/access
  administration only; do not add clinical, pharmacy, cashier, billing
  transaction, or other operational permissions by implication.
- **Delegated roles:** assigning approved operational bundles uses
  `identity.assignments.manage`, but role edits must be separately constrained.
  Add an explicit protected/built-in role marker or equivalent authoritative
  registry; role names/keys alone are not adequate protection. Tenant Admin
  cannot mutate protected bundle permission sets, grant protected
  administrative permissions to custom roles, or assign a bundle outside the
  active, versioned delegation policy and organization scope. Policy choices
  are limited to permissions explicitly opted in by their owning domain;
  there are no default delegable permissions. Policy expansions require
  independent authorized approval, while restrictions apply immediately.
  Restrictions block future assignments and role edits but do not silently
  change existing roles or assignments. Prevent self-grant and indirect
  privilege escalation, including by editing parent-role inheritance.
  Routine staff assignment must not include `tenant_admin` or
  `facility_admin`; the user requires a second authorized administrator's
  approval for any admin-role promotion. Implement this as a two-step workflow:
  request and approval by distinct authorized Tenant Admins; pending requests
  convey no access. Both steps require confirmed 2FA and produce separate audit
  events. When only one Tenant Admin remains, do not bypass the control; use
  operator-assisted recovery/provisioning.
- **Facility Admin assignment:** retain explicit facility scope and only the
  permissions required for facility operations. It must not manage tenant-wide
  role definitions, other organizations, or platform settings unless a
  separately approved permission grants that ability.
- **Setup versus operations:** Tenant Admin is not implicitly a clinician,
  nurse, cashier, dispenser, or other operational user. Give operational
  permissions through separate role assignments. The user approved letting
  Tenant Admin assign approved operational role bundles and create constrained
  custom roles, while keeping built-in role definitions protected. Define an
  allowlist and maximum delegable authority; reject self-grants or indirect
  escalation beyond the Tenant Admin's explicitly delegated scope.
- **Super Admin boundary:** do not implement platform-wide access by assigning
  an ordinary tenant role. Platform operations require a separate identity/
  authorization boundary, audited platform context, and controls against
  cross-tenant data access. The current Admin workspace does not provide it.
- **First-admin provisioning:** provide an operator-only, one-time command.
  It must fail closed if the healthcare organization already has an effective
  Tenant Admin assignment, create a pending account and organization-scoped
  assignment atomically, and use an expiring single-use email
  setup/verification mechanism rather than a shared or printed password.
  Persist only a token hash; consume it once, atomically, with expiry checks.
  The fail-closed check must join assignments to active roles and require
  `status=ACTIVE`, effective start/end bounds, and the correct organization
  scope. Create the account, protected role if absent, permission links, and
  assignment in one transaction.
  Record a tenant audit event without logging setup tokens or secrets. The
  codebase has no authenticated deployment-operator identity, so the event
  must identify the initiating console action and include a non-secret
  operator-supplied change/reference ID; do not pretend this is a verified
  user actor. Production deployment logs remain the operator identity source.
  Define account recovery before implementation.
- **Audit writes:** `shared_audit_log` is tenant-isolated and append-only.
  `CommandBus` wraps dispatch in `Tenancy::run` and runs authorization,
  idempotency, then audit middleware in that transaction. New administrative
  writes should use domain commands implementing `AuthorizesAgainst` and
  `Auditable`, so a failed mutation cannot leave a success-shaped audit trail
  or vice versa.
- **Audit target scope:** the current `WriteAuditRow` calls `AuditLogger`
  using request context; `AuditEntry` does not currently carry an explicit
  target facility through that middleware. For a Tenant Admin changing a
  specific facility while another facility is selected (or no facility is
  selected), the audit row must still identify the changed facility. Extend
  the audited-command contract to carry the affected facility (and use null
  only for genuinely tenant/organization-scoped actions); never mislabel the
  session's active facility as the mutation target.
- **Audit access:** no audit-read permission or viewer currently exists.
  Add dedicated `audit.events.read` and `admin.workspace.access` permission
  keys rather than implying audit access from `identity.access`. Enforce both
  on the server. Tenant Admin can view this tenant's events because the
  first-release invariant is one organization per tenant; facility-scoped
  readers remain restricted to facilities reachable by their assignments.
  Tenant UI must never expose `tenant_id IS NULL` platform/system audit rows;
  those require a separate platform-operations authorization path.
- **Audit query and rendering:** support bounded date ranges and filters for
  action, actor, target, and authorized facility; paginate with a hard page
  limit and stable ordering. Render selected metadata fields only, omit
  secrets and PHI, and do not return raw JSON as an unrestricted audit
  payload. The viewer is read-only.
- **Audit content boundary:** use `shared_audit_log` for configuration,
  authorization, and designated security-sensitive audit events. Do not merge
  raw `aut_auth_events` (failed logins/IP telemetry) into the tenant Admin
  viewer by default; define a separate security-operations purpose and access
  policy if that is needed later.
- **Organization-scoped audit limitation:** `shared_audit_log` records tenant
  and optional facility, but no organization ID. The approved
  one-organization-per-tenant invariant resolves Tenant Admin attribution for
  facility-less events; enforce that invariant in provisioning and
  organization administration. If multi-organization tenants are introduced,
  revisit the audit schema/filter policy before release.
- **Multi-facility visibility:** Tenant Admin may see audit events for
  facilities in the assigned organization. Facility Admin defaults to the
  active facility; any cross-facility search must verify every selected
  facility against assignment scope. An absent active facility must not
  implicitly mean access to every facility.
- **MFA:** `User` persists `two_factor_confirmed_at`, and Fortify emits
  audited enable/disable events. The reviewed app has no Admin-specific 2FA
  gate or Admin enrollment/settings flow in the reviewed routes/pages;
  recovery-code use is currently telemetry-only, not written to the shared
  audit log. The user requires confirmed 2FA for Admin. Add a backend guard
  for every Admin route/write, make enrollment available before Admin access,
  audit recovery-code use and administrator recovery/reset, and deny access
  immediately when 2FA is disabled until re-confirmed.
- **MFA recovery:** use a separately authorized support/recovery workflow,
  verify the requester's identity out of band, record actor/approver/reason
  without codes or secrets, revoke active sessions, and require 2FA
  re-enrollment before restoring Admin access. Do not treat a Fortify recovery
  code as an administrator reset approval.
- **Implementation order:** (1) register the protected Tenant Admin bundle
  and dedicated Admin/audit permissions; (2) implement/test the one-time
  first-admin bootstrap; (3) implement Admin/2FA backend guards and enrollment/
  recovery; (4) add explicit target-facility audit attribution and close
  first-release mutation audit gaps; (5) expose the bounded read-only audit
  query and viewer. Do not expose Admin navigation before backend gates are
  active.

### Phase 2 — Organization, facility, and identity administration

**Status: In progress — tenant topology invariant established**

- [ ] Implement organization/facility/department/unit/room management using
  Organization domain services and validation.
  - [x] Add facility creation for organization-scoped administrators, with
    active reference-data validation and a transactional target-facility audit
    event. Status transitions, hierarchy management, and capability changes
    remain out of scope for this slice.
  - [x] Add facility-scoped department creation with per-facility permission
    checks and target-facility audit attribution. Unit and room management
    remain open.
  - [x] Add facility-scoped unit creation under an active department in the
    same facility, with transactional audit attribution. Room management
    remains open.
  - [x] Add room creation with optional active same-facility unit assignment,
    including facility-level rooms for sites that do not model units.
- [ ] Enforce one healthcare organization per tenant in tenant provisioning
  and organization administration; check existing data before adding a
  uniqueness constraint.
- [x] Enforce and test the approved one-organization-per-tenant invariant;
  a tenant-scoped preflight identifies legacy duplicates before the database
  uniqueness constraint is applied, and the constraint prevents subsequent
  organization creation that would make audit attribution ambiguous.
- [ ] Implement capability and service availability configuration with
  dependency checks and cache invalidation.
- [ ] Implement role and permission-bundle management using the Identity
  domain’s registered permission catalogue, including owner-opted-in
  delegation eligibility, versioned/audited policy revisions, independent
  approval for expansion, immediate restrictions, and fail-closed behavior.
- [ ] Implement user/staff identity linkage and scoped access assignments,
  including effective dates and revocation.
- [ ] Add audit events for create/update/status/grant/revoke operations,
  including before/after state where safe and useful.
- [ ] Prevent unsafe last-admin removal, self-escalation, invalid scope, or
  privilege expansion without the required authority.
- [ ] Test assignment inheritance, role inheritance, facility boundaries,
  capability dependencies, and audit records.

**Exit criteria:** A properly authorized administrator can configure a
facility and grant/revoke scoped staff access without CLI-only operations or
unreviewable changes.

### Phase 3 — Facility operational setup

**Status: Scope approved 2026-10-23; implementation not started**

**Approved direction:** Admin is the facility setup and readiness entry
point. It must not become a generic table editor. The domain that owns a
setting remains its only writer and enforces authorization, validation,
transactionality, lifecycle, audit, and effects on active work. The Admin
workspace may present readiness and route an administrator into the owning
domain's setup workflow.

#### Facility setup register

| Setting | Owner / authoritative writer | Current surface observed | Admin treatment and required gate |
|---|---|---|---|
| Queue definitions and status | Platform Queueing | Operational queue routes and `RegisterQueueCommand`; no verified Admin setup route | Show setup/readiness and link to a domain-owned workflow. Verify registration command authorization, audit, and lifecycle before adding a web write path. |
| Queue-to-room and room-staff routing | Platform Queueing | Existing `ManageQueueRoomsController`, `ManageQueueRoomStaffController`, and routes in `app/Platform/Queueing/routes.php` | Reuse the existing domain-owned path; first verify authorization/audit coverage, replacement behavior, facility scoping, and impact on in-flight tickets. Do not add a second writer in Admin. |
| Procedure-to-queue mapping | Platform Queueing, with Clinical catalogue validation | Existing `ManageProcedureQueueMappingsController` and route | Reuse the existing writer. Show mapping status only after verifying clinical eligibility, versioning, audit, and effect on authorized/in-flight procedures. Configuration must not itself authorize a procedure. |
| Appointment types, resources, schedules, and availability | Scheduling | Booking, calendar, and availability routes exist; no scheduling-configuration HTTP write route was found | Admin readiness may link to Scheduling. Build any required authoring UI through Scheduling-owned commands/services with effective dates and explicit effects on existing bookings and slot holds. |
| Wards, rooms, beds, and bed lifecycle | Bed/Ward | Domain commands and handlers exist; no web administration route/page was found | Admin readiness may link to a Bed/Ward-owned workflow. Verify command registration, permission policy, audit, capacity/occupancy effects, and safe decommission/block behavior before exposing writes. |
| Operating rooms and availability | Theatre | `CreateOperatingRoom` and other domain commands exist; no web configuration route/page was found | Admin readiness may link to a Theatre-owned workflow. Verify command dispatch, permission, audit, scheduling/case lifecycle, and safe changes to rooms with active or scheduled cases. |
| Facility capabilities and service availability | Organization | Capability model and `ManageCapabilityCommand` exist; current Organization HTTP route is read-only facility structure | Admin may show enabled/disabled state and dependencies. Any write remains Organization-owned and must validate dependencies, audit target facility, and invalidate relevant caches. Do not imply clinical authorization. |

**Implementation order:** (1) complete the surface/authorization/audit/lifecycle
review for each owner; (2) deliver safe domain-owned management surfaces for
the approved missing controls; (3) add an Admin setup/readiness page that
aggregates status and links to those surfaces; (4) add cross-domain readiness
tests. Do not expose a control as editable while its owner path or safety
semantics are unverified.

- [x] Inventory the agreed queue, room, room-staff, queue-routing, appointment,
  Bed/Ward, operating-room, and service-availability controls and record their
  current domain owner and web/command surface above.
- [x] Decide the Admin role: a single facility setup/readiness entry point
  linking to domain-owned workflows; no duplicate Admin write paths.
- [ ] Add authorization/audit to queue registration and routing writes; verify
  the current direct-table replacement behavior and impact on in-flight work.
- [ ] Verify Bed/Ward and operating-room setup command registration,
  authorization, audit, and occupancy/scheduled-case lifecycle before
  exposing setup UI.
- [ ] Verify Scheduling resource/type/schedule command dispatch, audit
  coverage, effective dating, and effects on existing bookings and slot holds.
- [ ] Present facility setup status and missing dependencies without implying
  that a service is clinically authorized merely because it is configured.
- [ ] Add audited management workflows and safe updates for the agreed
  operational settings.
- [ ] Test queue-room routing, staff authorization, active/in-flight ticket
  behavior, schedule availability, and facility scoping.

**Exit criteria:** An administrator can configure the agreed facility
operations end to end, with one authoritative write path per setting.

### Phase 4 — Governed clinical service configuration

**Status: [ ] Not started**

- [ ] Define the facility-level direct nursing procedure capability and its
  enable/suspend/disable lifecycle; do not derive permanent authorization from
  facility type or care-level rank.
- [ ] Keep per-procedure, version-bound, facility-scoped protocol approval
  separate from the facility capability.
- [ ] Keep procedure catalogue authoring, clinical approval, queue mapping,
  room setup, staff permissions, and direct check-in as distinct governed
  conditions.
- [ ] Make direct Reception check-in available only when every required gate
  is satisfied.
- [ ] Specify disable behavior: prevent new direct-service requests while
  preserving approvals, historical records, and safe handling of in-flight
  tickets.
- [ ] Verify clinician-first order flow remains available where direct service
  is disabled or not approved.
- [ ] Test all eligible/denied combinations, stale protocol versions, missing
  queues/rooms, permission failures, audit history, and disable/re-enable.

**Exit criteria:** Direct nurse-protocol service is configurable per facility
and per procedure; no catalogue entry becomes authorized by default; both
direct and clinician-first workflows are safe and tested.

### Phase 5 — Domain-owned catalogues and financial/supply controls

**Status: [ ] Not started**

- [ ] Review procedure, laboratory, radiology, pharmacy, billing, inventory,
  insurance, and procurement configuration entities against the Phase 0
  register.
- [ ] Trace Insurance setup command callers and validate authorization/audit
  behavior, effective dates, and contract changes before exposing configuration.
- [ ] For each selected entity, reuse domain validation and lifecycle
  operations rather than writing generic table CRUD.
- [ ] Bring agreed catalogue and control workflows out of CLI into a guarded
  UI only after permission, audit, versioning, and disable behavior are
  defined.
- [ ] Keep transactions—dispensing, payments, stock movements, claims,
  adjudications, requisitions, and purchase orders—in their operational
  modules.
- [ ] Validate cross-domain dependencies, such as service-to-billing maps,
  item-to-billing maps, stock locations, and queue mappings.
- [ ] Test each domain’s authorization, audit record, duplicate handling,
  concurrency, and effect on active work.

**Exit criteria:** Every catalogue/control added has a named domain owner,
safe domain write path, tests, and complete audit behavior.

### Phase 6 — Workforce and platform administration

**Status: [ ] Not started**

- [ ] Review HR management scope: personnel records, cadre/position,
  employment, professional registration, shifts, rosters, attendance, leave,
  and staff-account linkage.
- [ ] Add and verify audit events for HR configuration and personnel mutations;
  review command callers and sensitive-data authorization before exposing UI.
- [ ] Define special protection for identity, employment, registration, and
  other sensitive workforce data.
- [ ] Review platform operations for documents, integrations, notifications,
  reporting definitions, data retention, and monitoring.
- [ ] Define tenant/platform scope and secret storage/rotation rules before
  exposing integration or notification configuration.
- [ ] Establish explicit authorization and audit contracts for platform
  connector, webhook, credential, notification-template, document-retention,
  and reporting-definition mutations before adding user-facing routes.
- [ ] Add only the operational settings with an identified owner, permission,
  audit requirement, and support process.
- [ ] Test sensitive-data access, tenant isolation, credential redaction, and
  platform-versus-tenant administration boundaries.

**Exit criteria:** Included workforce/platform controls have explicit
ownership, security, audit, and support models; unrelated operational
functions remain in their modules.

### Phase 7 — Cross-module completeness and release hardening

**Status: [ ] Not started**

- [ ] Reconcile the Admin register against all registered modules and actual
  live write paths.
- [ ] Confirm every Admin write has backend authorization, validation,
  tenant/facility scoping, audit behavior, and tests.
- [ ] Confirm no generic Admin CRUD route bypasses domain policies or lifecycle
  rules.
- [ ] Verify audit search authorization, query limits, pagination, metadata
  redaction, and append-only behavior.
- [ ] Verify UI navigation visibility agrees with backend enforcement without
  treating hidden navigation as security.
- [ ] Verify configuration changes invalidate caches and do not corrupt
  in-flight workflows.
- [ ] Resolve or explicitly document backend/frontend page-contract gaps in
  any workspace that Admin depends on.
- [ ] Perform end-to-end tests for facility setup, staff access, service
  enablement, clinical protocol governance, and audit review.
- [ ] Update this plan with completed dates, test evidence, deferred items, and
  any approved scope changes.

**Exit criteria:** Admin behavior agrees with the register; configuration is
permissioned, scoped, auditable, tested, and operationally understandable.

## Decisions and dependencies to preserve

- **Clinical direct-service eligibility:** configurable per facility; not
  hard-coded from `facility_type` or `care_level`.
- **Per-procedure approval:** required in addition to any facility capability;
  approval remains version-bound and can be revoked.
- **Future catalogue entries:** adding a procedure does not automatically
  approve it for direct service.
- **Initial enablement:** do not enable a real-facility direct nursing
  procedure pathway until the relevant facility capability, procedure
  protocol, queue/room setup, permissions, and clinical governance are
  verified.
- **Workspace boundaries:** Admin configures policy and setup; operational
  workspaces perform clinical and business transactions.
- **Initial release scope (user-approved):** secure bootstrap/audit plus
  Organization, Facility, Identity, and capability management. Queue/
  scheduling setup and domain catalogues follow in later phases.
- **Admin boundary (user-approved):** the first Admin workspace is for a
  Tenant Admin managing one healthcare organization. Facility Admin remains
  facility-scoped; platform Super Admin is a separate future operations
  boundary, not part of tenant Admin.
- **Admin/operations separation (user-approved):** Tenant Admin manages
  organization setup and access but receives no clinical, cashier, dispensing,
  or other patient-care/business permissions solely by virtue of being an
  administrator.
- **Tenant Admin default bundle (user-approved scope):** organization,
  facility, and capability setup; user/staff access; constrained role
  delegation; and audit viewing. No operational permissions are implicit.
- **Role delegation (user-approved):** Tenant Admin may assign approved
  operational role bundles and create constrained custom roles. Built-in role
  definitions are protected. Permission allowlisting, scope checks, and
  anti-self-escalation rules are implementation gates.
- **Tenant topology (user-approved for first release):** exactly one
  healthcare organization per tenant; any later multi-organization change
  requires a new audit-attribution and access-scope design.
- **Initial admin provisioning (user-approved direction):** one-time audited
  deployment command requiring operator action, tenant scope, an expiring
  password-setup/email-verification link, no shared password, and refusal if
  a tenant admin already exists. Role identification, audit actor, and recovery
  semantics remain Phase 1 design work.
- **No implementation authorization implied:** this plan records the agreed
  audit-first direction. Begin implementation phase by phase after the
  preceding phase’s exit criteria are satisfied.

## Progress log

| Date | Phase / item | Status or evidence |
|---|---|---|
| 2026-10-08 | Initial backend audit and phased plan | Static audit baseline and eight phases documented; no code changes. |
| 2026-10-08 | Phase 0 configuration register | Approved baseline: module/control register records known scope, surface, permissions, audit evidence, lifecycle gaps, and recommended owner. Deep verification is a gate in the relevant later phases. |
| 2026-10-08 | Initial Admin release scope | User approved core setup first: secure bootstrap/audit, Organization, Facility, Identity, and capabilities; queue/scheduling and domain catalogues follow later. |
| 2026-10-08 | Phase 0 targeted write-path review | Inspected Identity, Organization, queue configuration, and Billing catalogue. Confirmed audit gaps in Identity role/staff-link, Organization facility/structure, and direct queue routing mutations; confirmed Billing catalogue command-bus authorization/audit and capability audit/cache behavior. Remaining domains and production bootstrap are open. |
| 2026-10-08 | Phase 0 deferred-module scan | Checked routes, command mappings, callers, permission keys, and audit declarations for HR, Insurance, Scheduling, BedWard, Theatre, and Platform. HR/Scheduling commands are mapped and authorized but not auditable, with no in-app configuration caller found; Insurance and BedWard/Theatre have auditable/authorized commands but no module route file; scanned platform configuration commands are mapped but have neither authorization nor audit markers, with no setup caller found. Detailed lifecycle and platform secret-handling checks remain open. |
| 2026-10-08 | Production first-admin provisioning direction | User approved a one-time audited deployment command with operator action, expiring password-setup/email-verification link, no shared password, and fail-closed behavior once an effective Tenant Admin assignment exists. The role is `tenant_admin` scoped to the tenant's sole organization; operator identity/recovery semantics remain Phase 1 implementation details. |
| 2026-10-08 | Phase 0 baseline approval | User approved closing Phase 0 with deeper module-specific checks retained as explicit prerequisites in their owning implementation phases. |
| 2026-10-08 | Phase 1 admin MFA requirement | User approved requiring confirmed 2FA for every Admin user, with backend enforcement and an audited recovery process. |
| 2026-10-08 | Tenant Admin topology | User approved one healthcare organization per tenant for the first release; Tenant Admin is organization-wide, Facility Admin remains facility-scoped, and Super Admin remains a separate platform authority. |
| 2026-10-08 | Tenant Admin operational boundary | User approved separating setup/access administration from clinical and business operations; operational permissions are assigned separately. |
| 2026-10-08 | Tenant role delegation | User approved assigning approved operational role bundles and allowing constrained custom roles, with protected built-in role definitions. Exact permission allowlist and anti-escalation rules remain implementation gates. |
| 2026-10-08 | Configurable delegation policy | User approved defining maximum delegable authority through an explicit, versioned, audited policy configurable in Admin. The policy must be fail-closed and bounded by permissions that cannot be delegated; implementation and domain opt-in remain gates. |
| 2026-10-08 | Delegation policy approval | User approved independent approval by a second authorized administrator for policy expansions; restrictive changes apply immediately. Existing assignments remain intact for explicit review/revocation, while new grants and role edits are blocked by the restrictive policy. |
| 2026-10-08 | Delegable permission catalog | User approved that only permissions explicitly opted in by their owning domains may be offered in the configurable delegation policy. No permission is eligible by default. |
| 2026-10-08 | Delegable permission inventory | Reviewed all 23 registered permission lists. None has explicit delegation opt-in metadata; the eligible catalog therefore remains empty until owning domains approve exact keys. Registration and read-only status alone are not sufficient evidence of safe delegation. |
| 2026-10-08 | Initial delegation policy scope | User chose to keep the eligible catalog empty until domains approve exact permissions; implement policy and role-grant paths fail-closed in the meantime. |
| 2026-10-08 | Phase 2 tenant topology invariant | Added a duplicate-data preflight and database uniqueness constraint for one organization per tenant; focused tests cover cross-tenant allowance and org-scope permission inheritance across facilities. |
| 2026-10-08 | Phase 2 organization overview | Added a permission-gated, read-only facility overview with effective-scope filtering, facility/capability summaries, and focused access-control tests. |
| 2026-10-08 | Phase 2 facility creation | Added an organization-scoped facility creation route and form; creation validates active type/care-level references, uses the sole active organization, defaults the new facility to ACTIVE without enabling capabilities, and records the actor/target audit event in the same transaction. Facility-scoped administrators are denied; lifecycle and capability workflows remain open. |
| 2026-10-08 | Phase 2 department creation | Added a facility-scoped department creation route and form; eligible active facilities are surfaced only where effective structure-manage authority applies, and the endpoint rechecks target facility status/scope before transactional creation and actor/target audit. Unit and room creation remain open. |
| 2026-10-08 | Phase 2 unit creation | Added a facility-scoped unit creation route and form, exposing active departments only under manageable active facilities. The endpoint verifies the department belongs to the selected facility and remains active before transactional creation and actor/target audit; room management remains open. |
| 2026-10-08 | Phase 2 room creation | Added room creation for manageable active facilities, allowing either direct facility-level rooms or an active unit in the same facility. The endpoint revalidates hierarchy/status transactionally and records actor/target audit attribution. |
| 2026-10-08 | Phase 1/2 authority model | Recorded Tenant Admin as `ORG`-scoped over the tenant's single healthcare organization (the schema has no `TENANT` assignment scope), Facility Admin as explicitly facility-scoped, and Super Admin outside tenant RBAC. Organization uniqueness and audit attribution must be enforced and tested. |
| 2026-10-08 | Phase 1 security detail | Verified the org schema currently permits multiple orgs per tenant, role schema has no protected/built-in marker, Fortify stores confirmed 2FA but no Admin guard was found, and MFA recovery-code use is telemetry-only. Recorded required invariant, protected role handling, server-side 2FA gate, and audited recovery design. |
| 2026-10-08 | Phase 1 design baseline | Recorded tenant-context/RLS boundaries, dedicated audit-read permission proposal, read-only scoped audit viewer requirements, one-time first-admin bootstrap constraints, and 2FA enforcement. Implementation remains not started. |
| 2026-10-08 | Tenant Admin promotion control | User approved requiring a second authorized administrator's approval for every administrative role promotion, including Tenant Admin and Facility Admin; operator-assisted recovery/provisioning handles the single-admin recovery case. |
| 2026-10-08 | Audit and MFA implementation constraints | Verified that the current audit middleware derives facility scope from active request context, not necessarily the affected facility; Admin audit must carry the true target facility. Confirmed Fortify recovery-code use is telemetry-only and no Admin-specific 2FA enrollment/gate is present; both are explicit Phase 1 requirements. |
| 2026-10-08 | Tenant Admin default scope | User approved a setup-only default bundle for organization/facility/capability setup, Identity access administration, constrained role delegation, and audit viewing; no clinical or business-operational permissions are implicit. |
| 2026-10-08 | Admin-role promotion workflow | User approved second-administrator approval for all administrative role promotions. Plan now requires a pending request that conveys no access, distinct approvers, confirmed 2FA, separate audit events, and operator-assisted recovery if one administrator remains. |
| 2026-10-08 | Phase 1 audit viewer | Added the permission-gated, tenant/facility-scoped read-only viewer with bounded filters, stable pagination, action/metadata allowlists, and no raw auth telemetry. Focused security tests pass; append-only regression coverage and Identity role/staff audit coverage remain open. |
| 2026-10-08 | Audit viewer scope verification | Review found the initial `procedures.*` action prefix also matched patient-care procedure events. Narrowed it to procedure-definition and protocol-approval configuration events, with a regression test proving completion events remain hidden. |
| 2026-10-08 | Phase 1 configuration audit wiring | Added transactional target-facility audit events for Organization facility/structure/capability commands and direct queue room/staff/procedure routing writes. Focused command and HTTP tests pass; Identity role/staff audit gaps remain in their later workflow scope. |
| 2026-10-08 | Phase 1 Identity audit and immutability | Identity role, assignment, and staff-identity mutation commands now audit atomically; actor attribution is not fabricated for CLI operations and staff numbers are excluded. Confirmed existing PostgreSQL RLS test rejects audit updates/deletes. |
| 2026-10-08 | Two-person administrative-role promotion | Added pending-only request records, scoped approval/rejection routes and UI, independent authorized approver checks, self-promotion/self-approval denial, atomic assignment activation, and separate audit events. Facility promotions are bound to an active target facility; Tenant Admin promotions are restricted to the sole active organization. Focused security tests pass. |
| 2026-10-08 | Platform Super Admin bootstrap and Tenant Admin onboarding | Added a one-time, token-hash-only operator bootstrap, tenantless platform registry, platform login/2FA gates, and a platform console to invite the initial protected Tenant Admin under existing topology and promotion controls. Added a feature-organized tenant registry/detail view and guided atomic tenant/organization/first-facility setup; the initial Tenant Admin invitation remains a separate detail-page action. Unified Platform Admin content width with the standard dashboard/clinical app content area, removing the wizard-only narrow cap. Focused backend, UI, PHPStan, TypeScript, and targeted ESLint checks pass. Approved local migrations are applied. Local mail is log-only, so the initial token was expired and audited; configure delivery mail before resending. |
| 2026-10-08 | Platform facility operations and governed Facility Admin access | Removed Platform Admin breadcrumbs to match clinical workspace navigation and styled tenant/facility names as unadorned links with hover affordance. Added facility detail/edit and ACTIVE/SUSPENDED/CLOSED lifecycle controls with tenant-scoped audit, Facility Admin bootstrap invitations that remain unprivileged until account setup and an independent tenant approval, approved Facility Admin revocation requests, and an audited immediate revocation exception only when no other authorized administrator can approve. Focused platform/admin-promotion tests, PHPStan, TypeScript, and targeted ESLint pass. |
| 2026-10-09 | Provisioning protected Facility Admin role for every tenant | Fixed invitations and setup failing for tenants without a seeded `facility_admin` role. The invitation workflow now creates the protected facility-scoped role from a fixed permission bundle, safely fills missing approved permissions on an otherwise valid role, repairs a legacy unprotected flag after validating scope/status/parent/permission contents, rejects unsafe role drift, and records role provisioning/reconciliation in the tenant audit log. Confirmed DSK's existing role is an active facility-scoped role with no unexpected permissions; its unprotected flag and two missing approved permissions are automatically repaired on the next invite attempt. Demo seeding and the runtime definition share one permission contract. End-to-end approval/revocation, legacy role repair, protected-role regression, PHPStan, and formatting checks pass. |
| 2026-10-09 | Permanent Facility Admin role provisioning and protected-role migration repair | Root-caused the stale `is_protected=false` state: the original migration updated `idn_roles` without a tenant context, so PostgreSQL FORCE RLS filtered the update to zero rows; the migration still completed. Platform-created tenants also did not provision a Facility Admin role until invitation time. New tenants now receive the exact protected role and permission bundle atomically during initial setup; invitation-time reconciliation remains a guarded compatibility repair for older tenants. Added a forward migration that reasserts protected flags while scoped into every tenant. Creation, legacy repair, approval/revocation tests, static analysis, and formatting pass. |
| 2026-10-09 | Tenant-owned Facility Admin onboarding | Moved routine Facility Admin setup invitations from Platform Admin facility details into Admin > Identity > Role approvals. The endpoint and UI require an active Tenant Admin with facility reach and the required permissions; completing setup revalidates that the inviter remains authorized, then creates an unprivileged pending promotion for independent approval. Removed the obsolete platform invitation route. Platform Super Admin retains the previously approved, audited emergency revocation path. Focused security tests, PHPStan, Pint, TypeScript, ESLint, and production build pass. |
| 2026-10-09 | Tenant Admin role provisioning and pending-invitation recovery | Added a shared, audited Tenant Admin role provisioner used by tenant creation, platform invitations, operator bootstrap/resend, and setup completion. New tenants now receive both protected admin roles; existing pending setup invitations can recover a missing role and pending assignment without granting access before setup completion. Tenant Admin invitation queries now exclude Facility Admin tokens. Added regression coverage for legacy pending invitations and tenant creation. |
| 2026-10-09 | Admin workspace entry points and account access diagnosis | Replaced the placeholder Admin overview with permission-filtered links to organization/facility management, role approvals, and audit; fixed the Admin two-factor setup continuation route. Verified `rameen.dsk.code@gmail.com` completed a Facility Admin setup invitation but has no active assignment: its Facility Admin promotion is still pending. DSK currently has no Tenant Admin assignment or pending Tenant Admin invitation. |
| 2026-10-09 | Pending-admin login guidance and Tenant Admin landing | Blocked sign-in and invalidated existing sessions when the user has only a pending administrative promotion, with a specific approval explanation. Organization-scoped Tenant Admin permissions now remain visible with no selected facility; Admin-only users are routed from the empty Dashboard to the Admin workspace and required 2FA setup. Focused authentication, Admin workspace, role-promotion, and facility-administration tests pass; PHPStan and Pint pass. |
| 2026-10-08 | Platform header actions and registry search | Added a direct Create tenant icon action and a debounced header search for tenants and facilities, with capped results, tenant context for facilities, and Inertia navigation. The protected, throttled search endpoint evaluates facility matches inside each tenant context; focused API/UI tests, PHPStan, TypeScript, ESLint, and production build pass. |
| 2026-10-18 | Billing catalog page and item-map HTTP exposure | Built the previously missing `Billing/Catalog` Inertia page (services, effective tariffs, immutable tariff history, and the pending-charge worklist) and closed the item-map HTTP gap noted in the configuration register: `MapItemToBillingService` / `DeactivateItemBillingMap` now have `billing.catalog.item-maps.store` / `.deactivate` routes and a UI worklist that reads medication identity through a new `InventoryApi::getItems`. Removed the temporary missing-page test guard. Billing/Inventory feature suites, the catalog Vitest suite, PHPStan, Pint, TypeScript, and ESLint pass. |
| 2026-10-18 | End-to-end financial clearance and pickup verification | Verified the complete receive → dispense → charge → dedicated invoice/payment or confirmed coverage → pickup journey against real stock and mappings; no production change was required. Added `tests/Feature/Domains/Billing/PharmacyClearanceJourneyTest.php` (9 tests) driving the real `BillingApi` through a real `Dispense`, outbox relay, and the `OnMedicationDispensed` listener: asynchronous-and-recoverable charge capture, re-delivery idempotency, the visible `ITEM_UNMAPPED` pending event and its mapping recovery, the dedicated one-charge invoice rule (a shared paid invoice stays blocked), `CONFIRMED` vs `UNKNOWN` insurance coverage, reversal cancelling the pickup and netting the charge, late pickup after a no-show, and the pharmacist/cashier/catalog role boundaries. Billing + Pharmacy + Insurance feature suites (302 tests) pass. |
| 2026-10-18 | Already-expired batch guard at receipt | Implemented the Phase 0 receipt policy's expiry guard: a `track_expiry` batch whose `expiry_date` precedes the receipt's effective date (`occurred_at`, default now) is now rejected — new domain exception `StockBatchAlreadyExpired`, enforced in `ReceiveStockRequest` and `ReceiveStockHandler`. The check uses the receipt date, not "today", so a valid backdated receipt still passes; a batch expiring on the receipt date itself is allowed. Added domain tests (`ReceiveStockTest`) and HTTP acceptance tests (rejected expired batch, accepted same-day batch). Inventory/Pharmacy/Billing/Insurance feature suites (395 tests), PHPStan (no new errors in Inventory), and Pint pass. |
| 2026-10-18 | Phase 0 receipt-source and opening-balance policy decided | Recorded the operational policy for stock receipts in the roadmap's Phase 0: the manual form offers `OPENING_BALANCE` (controlled go-live/stock-take setup with a count-sheet reason), `DONATION` (with donor ref), and `OTHER` (with reason), while `PROCUREMENT` is reserved for the Phase 7 PO path and is never free text; authority is `inventory.receipts.create` held by the Facility Admin only (not Tenant Admin or pharmacist); backdating, idempotency, and batch/expiry tracking rules are stated. Named the remaining open Phase 0 items (expiry-disposition, Procurement ownership, demo-data strategy) and marked Phase 2 complete. Documentation only — no production code changed. |
| 2026-10-18 | Phase 2 receiving HTTP acceptance coverage | Completed Phase 2's remaining acceptance tests for the receipt adapter (`tests/Feature/Domains/Inventory/ReceiveStockHttpAcceptanceTest.php`, 8 tests): retired and unknown item, retired and unknown location, facility mismatch (a location outside the actor's facility), no partial writes when a batch-tracked receipt omits its batch number, audit + reconciliation through the route, and transaction rollback of a failed receipt leaving the existing balance intact. The only Phase 2 remainder is the Phase 0 receipt-source / opening-stock policy decision (no code). Inventory/Pharmacy/Billing/Insurance feature suites (391 tests) and Pint pass. |
| 2026-10-18 | Item-master controlled vocabularies and reclassification guardrails ("now" slice) | Made item Type / Category / Base unit server-authoritative controlled vocabularies (`ItemType` / `ItemCategory` / `UnitOfMeasure`, set-validated — the `BillingServiceCategory` precedent), replacing free text on both write boundaries (HTTP `DefineInventoryItemRequest` + the `Item` aggregate asserts, which also covers the console command), with pickers fed by new workspace props. Presented the catalogue Name field as the generic (INN) name (NHIF reimburses by generic name) and added a non-blocking duplicate-name warning in the Add-item panel. Made Type and Base unit **immutable once the item has stock movements** (`InventoryItemReclassificationNotAllowed`, guarded via `StockLedgerReader::hasMovementsForItem`). No schema migration; Phase 2 receiving untouched. Design + Tanzania-fit rationale (TMDA/NEMLIT/NHIF) and the deferred Inv.0/Phase 8 work are recorded in `inventory-item-master-spec.md`. Added `tests/Feature/Domains/Inventory/ItemMasterControlledVocabularyTest.php` (9 tests). Inventory/Pharmacy/Billing/Insurance feature suites (383 tests), the inventory-catalogue Vitest suite (24 tests), PHPStan (no new errors), Pint, TypeScript, and ESLint pass. |
| 2026-10-18 | Phase 7 procurement receiving integration (PO-aware receiving) | Completed Phase 7 without a Procurement workspace: receiving starts from Inventory, with the purchase order offered as a validated Procurement selection (the PO lifecycle and reconciliation stay in Procurement). Added the first cross-domain Procurement surface — `ProcurementApi::getOpenPurchaseOrders(facilityId)` with narrow `OpenPurchaseOrderRef` / `OpenPurchaseOrderLineRef` DTOs, pure delegation to the existing `GetOpenPurchaseOrders` `#[ProtectedQuery]` reduced to OPEN STOCK lines with a computed remaining, plus its service and container binding. `ReceiveStockRequest` now admits `source_type=PROCUREMENT`, which requires a `po_ref` resolving to an open PO at the active facility with `item_id` on an open line, and prohibits `po_ref` on every other source (the `OnStockReceived` projection reconciles on `poRef` alone); the domain stays tolerant, so an unmatched `poRef` never blocks the movement. Granted `procurement.access` to the Facility Admin role (the receiving actor), exposed `openPurchaseOrders` to the Inventory workspace (empty and hidden without procurement scope), and added the PO picker to the receive panel, restricting the catalogue to the order's open lines and showing ordered/received/remaining. Over-receipt remains deliberately permitted (the pre-existing explicit rule). Added `ProcurementApiTest` (3 tests) and `ReceiveAgainstPurchaseOrderHttpTest` (6 tests); Inventory/Pharmacy/Billing/Insurance/Procurement feature suites (505 tests), the inventory-catalogue Vitest suite (27 tests), PHPStan (no new errors), Pint, TypeScript, and ESLint pass. Pre-existing Security-suite failures (a `FacilityFactory` second-org collision, a short-name `ScopeChecker` container resolution, and session-tracking) were verified unchanged by this work. |
| 2026-10-18 | Identity protected-role permission reconcile (`identity:sync-role-permissions`) | Closed the Phase 7 role follow-up. A role-definition change could not reach an already-provisioned tenant — `ManageRoleCommand` refuses protected bundles by design, and a real tenant cannot be re-seeded — so added `identity:sync-role-permissions`, the analogue of the existing `queue:sync-role-permissions` / `clinical:sync-role-permissions` commands: it attaches the permission keys carried by the Identity-owned `*RoleDefinition` bundles (facility_admin, tenant_admin, facility_staff_manager, receptionist, cashier, billing_admin, pharmacist, nurse, clinician, laboratory_technician) onto matching role rows, is additive only (never detaches), idempotent, `--tenant`-scoped with `--dry-run`, and audits each changed role as `identity.role.definition_reconciled`. Applied it to the demo tenant (`afyanova-demo`): the existing `facility_admin` role gained exactly `procurement.access` (27 → 28 permissions, layer `ACCESS`), verified in the database with the audit row. Added `tests/Feature/Security/IdentityRolePermissionsSyncTest.php` (4 tests: backfill of a newly-added canonical key, idempotency, dry-run writes nothing, audit metadata). Revert experiment confirmed the change adds no test failures: the Security suite is **31 failed / 112 passed** without the change and **31 failed / 116 passed** with it (the four new tests), the Arch boundaries set is unchanged at 7 pre-existing failures, and PHPStan reports no errors in the new/changed files. Documentation updated to state that existing tenants are reconciled through this command. |
| 2026-10-18 | Stock status and disposition policy decided (Phase 0) | Recorded the decision-ready Phase 0 policy for expired / near-expiry / damaged / quarantined stock, grounded in what the model actually supports: the ledger is the only truth about quantity and unusable stock is removed by an approved two-person `DECREASE` adjustment (`EXPIRY` / `DAMAGED` / `LOST` / `OTHER`, batch-scoped where the item is tracked) — never a balance edit or a negative receipt. Documented that expired stock is already unavailable (`eligible = false`) and blocked at receive/reserve/consume; that **quarantine is a handling instruction, not a system state**, because there is no batch status and no transfer command, so already-held stock cannot be moved into a quarantine location (a `QUARANTINE` location type may be defined for newly arriving suspect goods only); and that near-expiry (recommended 90-day window) is reporting-only. Listed the Phase 8 deliverables that make the states real (a batch/stock status extending the `eligible` flag; a built-in quarantine type plus transfers; a disposal workflow with evidence and witness; expiry/near-expiry reporting and alerts; recall support; dedicated quarantine/disposal permissions and reason codes) and recorded the four rollout parameters as decided (90-day near-expiry window; destruction witness required for controlled drugs; Phase 8 quarantine is a batch status **and** a location; damage is quarantined then reviewed, removed immediately only when obviously unrecoverable). The roadmap now marks the disposition policy resolved; the only remaining Phase 0 item is the demo/test-data strategy. Documentation only — no production code changed. |
| 2026-10-18 | Demo/test-data strategy decided — Phase 0 closed | Recorded the last Phase 0 decision: synthetic stock exists **only** in the `afyanova-demo` tenant (tenant isolation is the guarantee — no `is_demo` column will be added to production tables), is made visibly synthetic by a `DEMO-` item/location code prefix and a `[DEMO]` name prefix plus round quantities, and is created **only** through the receiving path (`ReceiveStock`, `source_type = OPENING_BALANCE`, with a reason naming the demo seed) — never a direct `inv_stock_balances`/`inv_stock_movements` write. Documented the repeatable setup path (`db:seed` for the demo tenant/roles/users, `inventory:define-item` as the Tenant Admin, `inventory:define-location` as the Facility Admin, then opening balances through the Inventory receive UI) and deliberately deferred a guarded one-command `inventory:seed-demo-stock` seeder (it needs a two-actor flow and is an operator convenience, not a go-live requirement; if ever built it must dispatch `ReceiveStock`). Automated tests build their own fixtures through the shared helpers and never depend on demo-tenant data. With this, every Phase 0 decision is recorded and the roadmap marks Phase 0 **Complete**. Documentation only — no production code changed. |
| 2026-10-18 | Shape B procurement — Step 1: backend readiness (permissions + single-actor approval limit) | The user chose Shape B ("the whole simple line together": department requests + purchase orders + suppliers) and this row lands its backend half **before any screen**. (1) **Single-actor approval limit** — new `config/procurement.php` key `approval.self_approval_limit` (env `PROCUREMENT_SELF_APPROVAL_LIMIT`, default `0` = off, i.e. today's behaviour unchanged) read by the new Application-layer `PurchaseOrderSelfApprovalPolicy`; `ApprovePurchaseOrderHandler` / `IssuePurchaseOrderHandler` now permit ONE person to approve **and** issue an order whose total is at or below the limit, while the §24 / §228 different-actor guards are untouched above it. The total comes from a new `PurchaseOrderReader::orderTotal()` (sum of non-cancelled line totals), and `Money::isPositive` was added so an empty order can never slip through a `0` limit. (2) **Permissions** — the Facility Admin role gains the eight procurement write keys (`suppliers.manage`, `requisitions.create` / `.approve`, `orders.create` / `.approve` / `.issue` / `.amend`, `emergency.manage`) so the one responsible person can actually run procurement; Laboratory Technician and Pharmacist gain `procurement.access` + `procurement.requisitions.create` so a department (lab, pharmacy) can raise a request — approval deliberately stays a different actor. (3) Reconciled the demo tenant `afyanova-demo` through `identity:sync-role-permissions` (facility_admin +8 keys, pharmacist +2, each audited as `identity.role.definition_reconciled`, verified in the database under RLS). Added `tests/Feature/Domains/Procurement/PurchaseOrderSelfApprovalLimitTest.php` (5 tests: off-by-default, exactly-at-limit, below-limit, above-limit, and the issue-guard relaxation). Evidence: the Procurement suite is **174 passed**, the Inventory suite **101 passed**, and the Security suite is **30 failed / 123 passed** with a failure set **byte-identical** to the same code with the role-definition change reverted (a controlled revert-and-diff experiment — no regressions); the two hardcoded laboratory-technician permission lists in `PlatformFacilityAdministrationTest` / `StaffAccessSimplificationTest` were updated for the two added keys. Pint and PHPStan are clean. The three screens (Requests, Purchase orders, Suppliers) are the next step; nothing is user-visible yet. |
| 2026-10-19 | Shape B procurement — Step 1b: approval limit is now per facility and per department | Corrected the one-size-fits-all money limit from Step 1. A single `.env` value could not describe reality (the user's examples: a dispensary approves around TZS 200,000–500,000, a health centre TZS 600,000–1,000,000, bigger facilities more, and departments differ), so `PROCUREMENT_SELF_APPROVAL_LIMIT` is **gone** and the limit now lives in the database per scope. New table `pcr_approval_policies` (`id`, `tenant_id`, `facility_id`, `department_ref` nullable, `self_approval_limit` money, `currency`, `version`; RLS; two partial unique indexes — one facility-wide row per facility, one row per facility + department). Resolution in the new Application `ApprovalLimitResolver`: **department row → facility row → facility-type default → `0` (off)**; `0` always means "never one person", never "unlimited". The department comes from the linked requisition's `requesting_department_ref` (opaque, no FK — §224), so a direct order is judged at the facility row. Facility-type defaults are a day-one proposal only, in `config/procurement.php`: DISPENSARY 500,000 / HEALTH_CENTRE 1,000,000 / HOSPITAL 3,000,000 / SPECIALIZED_HOSPITAL 5,000,000; a type with no entry is off. `OrganizationApi` gained one read-only method, `facilityType(string): ?string` (Contracts-only, one implementation) so Procurement can classify a facility without touching Organization tables. Interim authoring path — `procurement:configure-approval-limit --tenant=… --facility=… [--department=…] --limit=…`, plus `--clear` (fall back to the wider scope) and `--list` (rows + effective limit); every write is audited as `procurement.approval_limit_configured` / `.cleared`. `PurchaseOrderSelfApprovalPolicy` now takes the aggregate and resolves the effective limit; both handlers still apply the §24 / §228 guards above it. Tests: `PurchaseOrderSelfApprovalLimitTest` rewritten to 9 cases (type default, at/above limit, facility override, department override, direct-order fallback, `0` pins off, unknown type off, command write/audit/clear/list, issue guard). Verified: Procurement 178 passed, Inventory 108 passed, the 6 Security failures and 7 Arch failures are the pre-existing auth/session, platform-admin and cross-domain-import sets (none of the touched files appear in them), Pint and PHPStan clean. Demo tenant seeded through the command: `DEMO_DISP` facility row TZS 300,000; `DEMO_HOSP` LABORATORY department override TZS 500,000 over the TZS 3,000,000 facility default — both audited and read back under RLS. Still no user-facing screen; the Suppliers screen is next. |
| 2026-10-19 | Shape B procurement — Step 1c: approval limits become an Admin screen | Made the money limit editable by administrators instead of only by the server (the user's question: "Can i update this limit as admin? tenant for all facilities and facility admin for a specific [facility]? or are forever configured in hard code"). The values were already database rows, never code, so this step adds the missing authoring surface and the rule that decides who may use it. (1) **Permission** — one new key, `procurement.approval_limits.manage` (Procurement registry), granted to the protected `tenant_admin` and `facility_admin` roles; the demo tenant was reconciled with `identity:sync-role-permissions` (2 grants, audited). (2) **Who may edit what** — decided per facility, not by role name: the screen walks `ScopeChecker::selectableFacilities` and keeps only facilities where the user really holds the key, so a Tenant Admin (tenant-wide assignment) sees every facility while a Facility Admin sees only the facilities they are assigned to. The write path re-checks the same rule in a new `ApprovalPolicyPolicy::manage` (class-level, facility taken from the command), so forging another facility's id in the POST is denied by the bus even though the page would never offer it. (3) **Screen** — new Admin workspace area "Procurement limits" (`/workspaces/admin/procurement-limits`, Inertia `Admin/ProcurementLimits/Index`): one card per visible facility showing the facility type, the currently effective amount and whether it comes from a facility rule or the type starting point; a scope selector (whole facility, or one department) with an amount, Save, and Remove this rule (falls back to the wider scope). (4) **Write** — new `SetApprovalLimit` command + handler; `$limit === null` clears the scope (same as `--clear`), `0` still means "never one person"; a department must belong to the facility; audit actions are the same `procurement.approval_limit_configured` / `.cleared` the console command writes, so the trail reads identically whichever path was used. The console command stays as the break-glass/server path. Tests: new `ApprovalLimitAdministrationTest` (7 cases — facility admin sees only their facility and the HEALTH_CENTRE default of 1,000,000; sets + audits their own; is refused on another facility; tenant admin sees both facilities and may write either; a department limit is stored, shown and removed; a foreign department is rejected; the screen is 403 without the key). Verified: the Procurement feature suite **120 passed**, Pint clean, PHPStan clean for Procurement + the touched shared files, the full frontend suite **463 passed / 49 files** (the two vitest RPC timeouts are worker noise, no test failed) including 4 new page tests (facility-type fallback text, department switch, submit payload, Remove disabled without a rule), TypeScript clean apart from the pre-existing `inventory-catalogue.test.tsx` DOM typing errors, ESLint clean. Demo tenant checked under RLS: `admin@afyanova.local` (facility admin) can manage both of their facilities and `rameen.dsk.dev@gmail.com` (tenant admin) all three. The Suppliers screen is still next. |
| 2026-10-19 | Shape B procurement — Step 2: the Suppliers screen | Built the first of the three Shape B screens and the workspace that will hold the other two. (1) **Workspace** — new `/workspaces/procurement` with `?section=`, its own nav entry + app-menu item + icon, and a section list (`PROCUREMENT_SECTIONS`) that only ever holds screens that exist (Purchase orders and Requests are added there when they are built). Reading needs `procurement.access`, so a Pharmacist/Laboratory Technician can see suppliers but a Tenant Admin cannot enter the workspace. (2) **One status vocabulary** — the lifecycle table moved out of `SupplierLifecycle` into a new `Domain\ValueObject\SupplierStatus` (canonical list + allowed transitions), and `SupplierLifecycle` now delegates to it. The read DTO `SupplierSummary` gained `allowedStatuses`, filled by `EloquentSupplierReader`, so the screen offers exactly the moves the write guard will accept — no second copy of the rules in TypeScript. (3) **List** — `GetSuppliers` as-is (tenant-wide, no pagination), server-mapped to Inertia props; client-side search + status filter, tags for categories and status, contact and payment terms columns. (4) **Add supplier** — `POST /workspaces/procurement/suppliers` behind `procurement.suppliers.manage`; the form request upper-cases the code and splits/upper-cases/de-duplicates the comma-separated categories before the existing `RegisterSupplier` command sees them; a duplicate active code comes back as a form error on the panel, not a 500. (5) **Manage** — `PATCH /workspaces/procurement/suppliers/{id}/status` with a mandatory reason; `BLACKLISTED` is routed to the distinct `BlacklistSupplier` command, the panel warns that blacklisting is permanent, and a blacklisted supplier then shows "no further changes" with the submit disabled (the domain guard is what actually refuses it). (6) **Authorization** — reads on `procurement.access`, writes on `procurement.suppliers.manage` checked both on the Form Request and again by the bus policy; a cross-tenant supplier id is reported as not found and leaves the other tenant's row untouched. Tests: new `ProcurementSuppliersHttpTest` (8 tests — render + allowed transitions, 403 without access, register with normalisation + audit, duplicate code, write refused for a read-only user, status change with reason, blacklist terminal, cross-tenant isolation) and `procurement-suppliers.test.tsx` (5 tests — list, add panel payload, only-allowed statuses + blacklist warning + PATCH payload, blacklisted final state, no write actions without the key). Verified: Procurement feature suite **129 passed**, full frontend suite **472 passed / 50 files** (the one vitest worker `onTaskUpdate` timeout is harness noise, no test failed), navigation expectations updated for the new workspace, Pint/PHPStan clean for Procurement, TypeScript clean apart from the pre-existing `inventory-catalogue.test.tsx` DOM typing errors, ESLint clean. Demo tenant verified under RLS: Facility Administrator holds `procurement.access` + `procurement.suppliers.manage` (+ the other six write keys), Pharmacist holds `procurement.access` + `procurement.requisitions.create` only. Purchase orders is next. |
| 2026-10-19 | Shape B procurement — Step 3: the Purchase orders screen | Built the second Shape B screen: raise a direct order, then submit → approve → issue → receive (receiving stays in Inventory, Phase 7). (1) **The screen never guesses** — new Application `PurchaseOrderActionPolicy` returns the actions the CURRENT actor may take on one order by combining three authorities: the lifecycle, the permission at the ORDER'S facility (`ScopeChecker::effectivePermissions`, not the active context), and the §24 / §228 different-actor rule with the effective money limit. `PurchaseOrderLifecycle` gained `canSubmit` / `canApprove` / `canIssue` / `canAmend` / `canRecordReceipt` / `canCancel` so its `assertCanX` guards and the screen share one table instead of two copies of the rules; `PurchaseOrderSelfApprovalPolicy` gained `permitsOrder(id, facility, requisition)` (the aggregate method delegates to it) so a read DTO can ask the same question without loading the aggregate. The controller passes `allowedActions`, `selfApprovalPermitted` and the facility's `effectiveApprovalLimit`; the UI renders buttons from that list only. (2) **Create** — `POST /workspaces/procurement/orders` (`procurement.orders.create`) raises a DRAFT direct order: active supplier, priority (ROUTINE/URGENT), optional notes, one or more stock lines (item from the active catalogue via `InventoryApi::getItems`, quantity, unit price). The server snapshots the item code/name/unit and computes the total; the panel shows a display-only running total and its button stays disabled until the draft is complete. Requisition / RFQ / contract sources are deliberately not accepted here — each needs its own screen. (3) **Transitions** — `POST …/orders/{id}/submit|approve|issue` and `POST …/orders/{id}/cancel` (reason mandatory), all routed through the existing commands and bus policies so a forged id or a missing key is still refused server-side; a domain refusal comes back as a form error, not a 500. The expanded row shows the lines with received quantities, notes and a plain-English "what happens next" line — including "you raised this, someone else must approve it" when the creator is above the limit. (4) **Nav** — `?section=orders` added to `PROCUREMENT_SECTIONS` (the section list still only holds built screens; `?section=requests` falls back to Suppliers until Requests exists). Tests: new `ProcurementOrdersHttpTest` (9 tests — render + allowed actions + limit, create with snapshot/total, refuse a non-active supplier, one person below the limit end-to-end, creator refused above the limit, second approver + third issuer above it, reason-mandatory cancel, reader sees no actions and is 403 on a write, orders isolated per facility/tenant) and `procurement-orders.test.tsx` (9 tests — list + limit, server-driven actions per row, transition payload, above-limit explanation, cancel payload, create payload, disabled-until-complete, add/remove lines, no writes for a reader). Verified: Procurement feature + unit suite **203 passed**, full frontend suite **483 passed / 51 files** (worker `onTaskUpdate` timeouts are harness noise, no test failed), navigation expectations updated, Pint and PHPStan clean for Procurement, ESLint clean, TypeScript showing only the pre-existing `inventory-catalogue.test.tsx` DOM errors plus unused-symbol warnings in `DockedPanel.tsx` (a shared component being edited outside this step). Requests is next. |
| 2026-10-19 | Shape B procurement — Step 4: the Requests screen (and the link into the order) | Built the third Shape B screen and closed the loop the user actually asked for: a department asks, someone else approves, and the order is judged at that department's money limit. (1) **Screen** — `?section=requests` lists the facility's requests (number, department, status, line count, who raised it, date) with search and a status filter; the expanded row shows the item lines, the justification, any rejection reason, and one plain-English sentence about what happens next. It is now the workspace default when a facility is active; a user with no active facility still lands on Suppliers because the supplier master is tenant-wide and requests are not. (2) **Raise** — `POST /workspaces/procurement/requests` behind `procurement.requisitions.create`: optional department ("No department (facility-wide)" is offered so a facility without departments is not blocked), priority, justification, and one or more stock lines from the active catalogue. The department is stored as the existing opaque ref, so the controller checks it against `OrganizationApi::departmentExists` at the active facility first — a ref from another facility would otherwise be accepted and silently never match a limit row. (3) **Decide** — submit, approve, reject (reason mandatory), cancel, wired to the existing commands. A new Application `PurchaseRequisitionActionPolicy` returns the `allowedActions` the CURRENT actor may take, from three authorities: the lifecycle, the permission at the request's own facility, and the different-actor rule. The money limit is deliberately NOT one of them — whoever raises a request can never approve it, at any amount, so a tiny dispensary still needs two people for the request itself; the one-person convenience starts at the purchase order. `RequisitionLifecycle` gained `canSubmit` / `canApprove` / `canReject` / `canCancel` so its assertions and the screen share one table. (4) **One list, not two** — the plan named `GetRequisitionApprovalQueue` for an approver worklist, but that query is just the SUBMITTED subset of the same reader, so the screen filters the single `SearchPurchaseRequisitions` list instead; two lists could disagree about the same request. (5) **Approved request → purchase order** — an APPROVED row offers "Create purchase order", which opens `?section=orders&request=<id>`. The controller prefills only when the request is APPROVED and belongs to the active facility (anything else opens an empty panel rather than half-trusted data), passing `initialRequest` (number, department, priority, justification, stock lines, and a count of lines this stock-only screen cannot order). The order panel opens prefilled, shows which request and department it is ordering for, and posts `requisition_id`; `StorePurchaseOrderRequest` accepts it, and the existing create handler stamps `sourcing_method=REQUISITION`. That is the exact point where the request's department reaches `ApprovalLimitResolver`. (6) **Tests** — new `ProcurementRequestsHttpTest` (9 tests: render with items/departments/actions; DRAFT snapshot; a foreign department refused with no row written; submit then approve by a different actor while the creator is refused; reject requires a reason; cancel; a reader sees no actions and is 403 on a write; prefill only for an approved request; and the decisive one — the same TZS 100,000 order is self-approvable as a direct order at the TZS 1,000,000 HEALTH_CENTRE default but not when it comes from a LAB request whose department limit is TZS 50,000), `procurement-requests.test.tsx` (9 tests: list, server-driven actions, transition payload, the approved-request hand-off, the self-approval explanation, create payload, reject requires a reason, cancel confirmation, no writes for a reader), `procurement-orders.test.tsx` +2 (prefilled panel and `requisition_id` payload, warning for lines this screen cannot order), and the navigation expectations. Verified: Procurement feature + unit suite **212 passed** (1030 assertions), the full frontend suite **494 passed / 52 files** (two vitest worker RPC timeouts are harness noise, no test failed), Pint, PHPStan and ESLint clean, TypeScript showing only the pre-existing `inventory-catalogue.test.tsx` DOM typing errors. Demo tenant checked read-only under RLS: DEMO_HOSP has 9 active departments including LABORATORY, the LABORATORY limit is TZS 500,000, DEMO_DISP is TZS 300,000, the Pharmacist holds `procurement.access` + `procurement.requisitions.create`, and the Facility Administrator holds 10 procurement keys — so request → approve → order → issue is demonstrable without creating new demo records or accounts. Deliberately left open: a Laboratory Technician demo persona (the Pharmacist can already raise a LAB request), a tenant-wide "apply to every facility" button on the limits page, and any relaxation of request self-approval. |
| 2026-10-19 | Phase 8 — expiry and near-expiry worklist (Inventory) | Built the first Phase 8 slice, chosen by the user over box-to-tablet conversion and low-stock alerts: expired medicine is the quietest loss in a hospital pharmacy, and until now the only way to find it was to open batches one by one. (1) **Read** — new `GetExpiringStock` query + handler and `StockBalanceReader::expiringForFacility()` return every batch with stock on hand whose expiry date is inside the decided **90-day** window, soonest first: item, batch, expiry date, exact `daysRemaining` (0 = expires today, negative = expired), location, on-hand quantity and unit. The expiry rule is not re-implemented — `daysRemaining` is display only and `expired` comes from `Batch::isExpired()`, the same rule the dispense gate uses. Non-expiry-tracked items, zero balances, batches beyond day 90 and other facilities are excluded; the 90th day is included. (2) **No new write path** — the section offers "Remove batch", which opens a panel pre-filled with the existing `POST /workspaces/inventory/adjustments` payload: `DECREASE`, `reason_code = EXPIRY`, the batch number, and the full remaining quantity, plus an optional note for how it was destroyed and who witnessed it. Nothing in the screen (or the reader) edits a balance; stock only moves when a different authorised person approves the request in Adjustments, and the existing self-approval guard still refuses the requester. A batch with a pending `EXPIRY` removal is shown as "Removal requested" instead of offering a duplicate. (3) **Screen** — new **Expiry** section after "Stock on hand" in the Inventory workspace, with summary tags (expired / within 30 days / in the window), a search box, a status filter, and the same honest empty state as the other sections; General context explains that expiry is facility-specific. (4) **Tests** — new `ExpiryWorklistHttpTest` (5 tests, 110 assertions: ordering and exact day counts incl. the end-of-day rule; the 90th-day boundary, non-expiry and other-facility exclusions; the create key required for a removal and the page requiring `inventory.access`; and the whole removal loop — request → requester refused → second approver removes 10 vials, on-hand becomes 0, the ADJUSTMENT movement is `OUT` and batch-scoped, the batch leaves the worklist and reconciles); new `inventory-expiry.test.tsx` (8 tests: rows and tags, expired filter, search, the exact removal payload incl. UUIDs, pending marker, no action without the key, General context, empty state); navigation expectations updated for the new section. Verified: the whole Inventory feature suite **106 passed** (937 assertions), the three touched frontend suites **65 passed**, Pint clean, PHPStan reporting no errors in the new files (the 16 errors in `EloquentStockBalanceReader` are the pre-existing `forLocation`/`forFacility` ones at lines ≤156), ESLint clean, and TypeScript showing only the pre-existing `inventory-catalogue.test.tsx` DOM typing errors. Deliberately still open: a real disposal record (method/quantity/witness) instead of a note, enforcement of the controlled-drug witness rule (the item master has no controlled flag), batch status + quarantine location, near-expiry alerts, and recall support. |
| 2026-10-19 | Phase 8 — controlled-medicine flag and enforced disposal witness | Finished the witness half of the Phase 0 disposition policy, so "destruction witness required for controlled drugs" is a rule the system enforces instead of a sentence in a note field. (1) **Item master** — `inv_items.is_controlled` (boolean, default false) is settable on the Add-item panel (a "Controlled medicine" block) and through `inventory:define-item --controlled=1` / `revise`; it is carried on `ItemSummary` and the workspace `items` props, and shown as a red **Controlled** tag beside the item type in the catalogue. It is deliberately a plain flag — TMDA/NEMLIT schedule classification stays with the deferred item-master work (recorded in `inventory-item-master-spec.md`). (2) **The rule** — new `inv_stock_adjustments.witness_name` (nullable, ROW-ONLY per ADR-136) plus a new domain exception `ControlledStockWitnessRequired`: a `DECREASE` adjustment on a controlled item is refused unless a witness name is supplied, in the handler (authoritative) **and** mirrored into a field error by `CreateStockAdjustmentRequest`. Every reduction of controlled stock is covered — disposal, loss, damage, count variance or a correction — while raising controlled stock and moving uncontrolled items are untouched, so no existing flow changed by default. The witness is required at request time and stored on the row; an item marked controlled *after* a request was raised does not retroactively block that pending request (the approver can reject and it must be re-raised) — recorded rather than silently re-checked. (3) **Screens** — the Expiry removal panel and the generic Request-adjustment panel both show a required **Witness** field when the selected item is controlled and the direction is DECREASE, and disable the submit until it is filled; the Adjustments worklist shows `Witness: <name>` inside the reason cell; the Expiry worklist marks controlled batches. (4) **Tests** — new `ControlledStockWitnessTest` (5 tests, 56 assertions: the flag round-trips through the catalogue; a witness-less removal is refused with a `witness_name` error and writes nothing; the domain still refuses it when the form is bypassed, including a whitespace-only name; controlled INCREASE and uncontrolled DECREASE need no witness; the expiry worklist marks the controlled batch), `inventory-expiry.test.tsx` +2 (witness required and posted for controlled stock, absent for the rest) and `inventory-catalogue.test.tsx` +2 (the catalogue tag, the required witness field with the submit correctly held disabled until both quantity and witness are present). Verified: the Inventory feature + unit suite **118 passed** (1014 assertions), Procurement + Pharmacy **207 passed** (1341 assertions), the frontend inventory suites **41 passed**, Pint clean, PHPStan reporting no new errors (the Inventory module's pre-existing findings are unchanged), ESLint clean, TypeScript showing only the pre-existing `inventory-catalogue.test.tsx` DOM typing errors. The migration was applied to the dev database (it was the only pending one). Still open in this area: a structured disposal record (method, linked staff identity witness), a controlled-substance schedule value, batch status + quarantine location, near-expiry alerts, and recall support. |
| 2026-10-19 | Phase 8 — item default buying unit and receiving in packs | Made the first half of the pack decision real: an item may declare one optional default pack (`pack_unit` + `pack_size`, e.g. 1 BOX = 100 CAPSULE) while the base unit stays the unit counted, dispensed and expired. `Item::define` / `revise` assert the pair (both or neither), a size above 1, and a pack unit different from the base unit; the migration adds `inv_items.pack_unit` / `pack_size` plus DB checks for the same rules, and the receiving ledger gains `entered_unit` / `entered_quantity` / `pack_size` as a per-movement snapshot. Receiving accepts the unit the delivery was actually counted in: the server **verifies** `entered_quantity × pack_size = quantity` (`Quantity::mul`, new `PackEntryInconsistent`) instead of deriving it, so a supplier's odd pack size is recorded honestly and the base quantity remains the only truth stock is held in. Partial packs are first class — 8 bottles from a 20-pack, or the base unit itself, need no special handling; the pack is a counting help and never a whole-pack requirement. Screens: the Add-item panel has an optional "Buying unit" block with a live "1 BOX = 100 TABLET" line; the catalogue shows the default under the base unit; the receive panel has a "Received in" selector defaulting to the item's pack unit with a live "3 BOX = 300.0000 CAPSULE in stock" hint, sending the pack record only when the pack unit is chosen; the ledger shows "3 BOX (300.0000 CAPSULE)". Tests: new `ItemPackAndReceivingTest` (5 tests, 61 assertions) and `inventory-pack.test.tsx` (4 tests). Verified: Inventory feature + unit **123 passed** (1075 assertions), Procurement + Pharmacy **207 passed** (1341 assertions), full frontend **511 passed / 54 files**, Pint clean, PHPStan at the Inventory baseline (61), ESLint clean. The migration was applied to the dev database. Deliberately deferred: packs at dispensing, supplier-specific pack catalogues, and costing/valuation conversion. |
| 2026-10-20 | Phase 8 — purchase orders in packs (Step 2) | Extended the pack to the order side, so the buyer records what the supplier actually sells instead of converting by hand. `pcr_po_lines` gains `unit` (the unit entered, backfilled to `uom`) and `pack_size`, and a **generated** `ordered_base_qty = ordered_qty × coalesce(pack_size, 1)` — one source of base truth. `ordered_qty` is now the quantity the buyer typed and `unit_price` is the price of one entered unit, so `line_total = ordered_qty × unit_price` stays exact: 3 BOX at 10,000 is 30,000, never 300 × 10,000 and never a divided, rounded per-capsule price. `ProcurementContextResolver` resolves the entered unit against the item (base unit, or the item's declared buying unit, defaulting to the item's pack size), refuses a unit the item neither stocks nor buys (`ProcurementLineSetInvalid::unknownUnit`), refuses a pack size on a base-unit line and a non-positive pack size, and computes the base quantity; contract- and award-sourced lines keep the contract/RFQ unit and never carry a pack. Receiving reconciliation, contract ceilings (`committedQuantityForContractLine`) and the open-PO references all moved to the base quantity, so a pack order and a base order reconcile identically; the receiving surface still shows the order record ("Ordered: 3.0000 BOX (300.0000 CAPSULE)") for display only. Screens: the order line editor gained an "Order in" selector (base unit, or the item's buying unit with its size), defaults to the buying unit when the item has one, shows a live "3 BOX = 300.0000 CAPSULE in stock" line, labels the price as the price of one chosen unit, and keeps the draft total in entered units; the expanded order row shows "3.0000 BOX (300.0000 CAPSULE)" with the price per unit. Partial packs work at ordering too (2.5 PACK of 20 = 50). Tests: new `PurchaseOrderPackTest` (4 tests, 36 assertions — pack order + base reconciliation + open-PO reference, default pack size and a 20-then-30 partial delivery, base-unit lines unchanged plus both refusals, and the HTTP create path incl. the form error), plus a new `procurement-orders.test.tsx` pack case. Verified: Procurement **151 passed** (928 assertions), Inventory feature **116 passed** (1054 assertions) and Inventory unit + Pharmacy **67 passed** (470 assertions), full frontend **512 passed / 54 files**, PHPStan **61** (the Inventory baseline; no Procurement findings), Pint clean, ESLint clean, TypeScript showing only the four pre-existing `inventory-catalogue.test.tsx` DOM errors. The migration was applied to the dev database (it was the only pending one). Still deferred: packs at dispensing and supplier-specific pack catalogues. |
| 2026-10-20 | Phase 8 — low-stock alerts (Running low) | Added the reorder side of stock visibility without adding a stock write path. (1) **Where the minimum lives** — a new `inv_item_levels` row per (`tenant`, `facility`, `item`) with `min_level numeric(18,4)`, a unique key, a `> 0` CHECK and RLS. It is deliberately **per facility**, not on the tenant-wide item master: the same item is reordered at completely different scales at a dispensary and a hospital (the approval-limit reasoning). No row = **not tracked**, so an untouched catalogue raises no false alarms; clearing a level deletes the row and the audit entry keeps the history. The level is in the item's BASE unit; the pack size is only a counting help, and partial packs are normal. The migration was applied to the dev database. (2) **Write** — new `SetItemLevel` command + handler, `POST /workspaces/inventory/item-levels`, audited as `inventory.item_level_set` / `inventory.item_level_cleared`. The new `InventoryCatalogPolicy::manageItemLevels` requires `inventory.items.manage` **in a facility context** (unlike the General-only item catalogue), and the bus re-checks the command's facility, so a General or forged-facility POST is refused. (3) **Read** — `StockBalanceReader::lowStockForFacility` plus `GetLowStock` / `GetItemLevels`; on-hand counts **usable** stock across the facility's locations, excluding expired batches by the same `Batch::isExpired()` rule the dispense gate uses, and reports the excluded quantity separately. An item is listed when usable stock is **at or below** the minimum (reaching the level is the reorder point), out of stock first and then the largest shortfall; retired items keep their level but are not listed. (4) **Screen** — new Inventory section **Running low** (`?section=low-stock`) with summary tags, search, an out-of-stock filter, and a panel that sets, changes or clears a level. The panel lists active items, pre-fills the facility's current level (so a change is never blind), and shows the pack beside the base-unit number ("150 TABLET ≈ 1.5 BOX (1 BOX = 100.0000 TABLET)"). Without `inventory.items.manage` the section is read-only; General context explains that minimums are per facility. Nothing on the screen moves stock — ordering still happens in Procurement. Tests: new `LowStockTest` (8 tests, 151 assertions — ordering and equality, expired exclusion, per-facility isolation, clearing + audit, permission, General context, validation, retired items) and `inventory-low-stock.test.tsx` (8 tests — list, filter, search, set with pack hint, change + stop tracking, both empty states, read-only, General context); the navigation expectation gained "Running low". Verified: Inventory feature **125 passed** (1211 assertions), Inventory unit + Pharmacy **67 passed** (470), Procurement **154 passed** (969), full frontend **521 passed / 55 files**, Pint clean, PHPStan **61** (the unchanged Inventory baseline; no findings in the new files), Arch unchanged at **7 failed / 18 passed**. Deferred: a suggested order quantity / supplier lead time, and push low-stock alerts (this is a pull worklist, like Expiry). |
| 2026-10-20 | Phase 8 — stock transfers between locations (Transfers) | Added `inv_stock_transfers` (one document: tenant, facility, `TRF-%08d` number, item, from/to location, optional batch, positive `quantity`, row-only reason, both movement ids, actor, `moved_at`, RLS) and `inv_transfer_number_counters`, both applied to the dev database. A transfer writes **two immutable ledger sides in one transaction** — `TRANSFER_OUT` at the source and `TRANSFER_IN` at the destination, both `source_type = TRANSFER` with the transfer id as `source_ref` — so the ledger still reconciles per location and the facility total is unchanged; no second approver is needed because nothing is gained or lost. `StockLedgerService::postTransfer` delegates to a new `StockBalance::applyTransfer`, which guards only the OUT side against negative stock and leaves reserved quantities alone, and records `StockTransferred` per side. The handler (`StockBalanceRepository` first, so the bus resolves `StockBalancePolicy`) resolves both active locations, refuses the same location or two facilities (`StockTransferNotAllowed`), re-checks `inventory.transfers.create` at the source facility, resolves a batch for batch-tracked items (unknown → NotFound, expired → `StockBatchExpired`), locks the two balances in a stable location order so opposite transfers cannot deadlock, then writes both movements, both balances and the document. The command rejects a future date, requires a reason beyond 24 hours back, and is audited as `inventory.stock_transferred`. (2) **Read** — `StockTransferReader::recentForFacility` + `GetStockTransfers`, and `StockBalanceReader::stockAtLocation` + `GetTransferSourceStock` for the panel's source list (loaded only when `?transfer_from=` is present). (3) **Screen** — new **Transfers** section after Running low with the document number/date, item, `MAIN → PHARMACY`, base quantity, batch and reason, plus search and an empty state; the **Move stock** panel picks the source first, lists only stock held there, chooses a batch (first usable one preselected, expired ones marked and refused), destination (excluding the source), base-unit quantity with the pack as a counting help, optional date and reason. A quantity above the source's available is refused in the panel and again by the server. Writes need `inventory.transfers.create`, granted to Facility Admin and Pharmacist by a new Identity migration and added to both role definitions. (4) **Tests** — new `StockTransferTest` (10 tests, 152 assertions: two sides + document + audit + reconciliation and facility total; per-location source list with batches and eligibility; over-available refusal with no writes; reserved stock protected (61 refused, 60 moved); same-location and cross-facility refusals; batch unknown/expired refusals and a named-batch move; permission; quantity/ID validation; facility isolation; General context) and `inventory-transfers.test.tsx` (8 tests: list, search, base-unit move with the pack hint, batch preselection + expired refusal, over-available refusal, source reload, read-only, General context); navigation expects "Transfers". Verified: Inventory feature **135 passed** (1363 assertions), Inventory unit + Pharmacy **67 passed** (470), Procurement **154 passed** (969), full frontend **529 passed / 56 files**, Pint clean, PHPStan reporting **no findings in the new files** (the 16 pre-existing `EloquentStockBalanceReader` findings are unchanged), ESLint clean, TypeScript showing only the four pre-existing `inventory-catalogue.test.tsx` DOM errors, Arch unchanged at **7 failed / 18 passed**. Deferred: cross-facility transfers, transfers in packs, and quarantine (needs batch status). |
| 2026-10-20 | Phase 8 — stock counts, Step 1 (record a physical count) | Added physical stock counts without adding a stock write path. (1) **Store** — new `inv_stock_counts` (tenant, facility, location, `CNT-%08d` number, `IN_PROGRESS` / `COMPLETED` / `DISCARDED`, notes, starter, `started_at`, `closed_at`, RLS), `inv_stock_count_lines` (one row per item and optional batch, `system_qty` snapshot, nullable `counted_qty`, `counted_by_user_id`, `counted_at`, unique on count+item+batch, RLS) and per-tenant `inv_count_number_counters`, all applied to the dev database. `StockCount` is the aggregate root and owns the lifecycle (`open` / `assertOpen` / `complete` / `discard`); a line is a plain row and computes `variance()` as counted − system, so no variance is stored and none can drift. (2) **The rules** — starting a count takes a **fixed snapshot** of what the system believes is at that location (one row per item and batch), and never touches a balance. Only **one count may be open at a location at a time** (`StockCountNotAllowed::alreadyInProgress`, backed by a partial unique index so a simultaneous double-start cannot slip through). A blank counted quantity means **not counted yet**; `0` is a real count, and the sheet never turns a blank into a false zero or a false shortage. The counter can add an item the system did not list (a surprise find): the line snapshots the **current** on-hand (usually 0), a batch-tracked item must name a batch that already exists (a count never creates a batch), and the same item+batch cannot appear twice. Completing is allowed with uncounted lines — the sheet records honestly that they were not counted — and both completing and discarding freeze the sheet and free the location for the next count. A count never changes stock: differences are corrected afterwards through the existing two-person adjustment (Step 2). (3) **Commands and reads** — `StartStockCount`, `SaveStockCountCounts`, `AddStockCountLine` and `CloseStockCount` with handlers under the command bus, audited as `inventory.stock_count_started` / `_recorded` / `_line_added` / `_completed` / `_discarded`; `GetStockCounts` and `GetStockCountSheet` provide the list and the sheet, the latter loaded only when `?section=counts&count_id=` is present so the ordinary page does not carry every line of every count. (4) **Screen** — new **Stock counts** section after Transfers with number/date, location, status, progress ("1 of 2 lines counted") and the number of differences, plus search and an empty state; the **count sheet** panel shows the frozen system quantity, an editable Counted box per line and a live difference, saves all lines in one POST (blank → null), adds a surprise find (naming a batch when the item tracks batches), completes, and discards behind an explicit confirmation. A closed count opens read-only. (5) **Permissions** — new `inventory.counts.manage` for starting, recording, adding and closing, granted to Facility Admin and Pharmacist by an Identity migration; reading uses `inventory.access`; `StockCountPolicy` re-checks the count's own facility, so a forged count id or a General context is refused. (6) **Tests and fixes** — new `StockCountTest` (7 tests, 146 assertions: snapshot per location and batch, no balance change and reconciliation intact, variance display and clearing, one-open-per-location, surprise finds with batch rules, complete/discard freezing, permission and validation) and `inventory-stock-counts.test.tsx` (10 tests: list, start, sheet load, live difference, save, add find, read-only, permission, discard confirmation, General context); navigation expects "Stock counts". Also fixed a real latent bug found while testing: `StockCountLine` and the existing `ItemLevel` used UUID primary keys on plain Eloquent models without `$incrementing = false` / `$keyType = 'string'`, so `->id` was cast to an integer (and `ItemLevel`'s 0-clears-and-deletes path could target `id = 1`). Verified: Inventory feature **142 passed** (1509 assertions), Procurement **154 passed** (969), Inventory + Pharmacy unit **21 passed** (99), full frontend **539 passed / 57 files**, Pint clean on the changed files (the pre-existing `AdminRolePromotionController` finding is unchanged), PHPStan reporting **no findings in the new files**, ESLint clean, TypeScript showing only the four pre-existing `inventory-catalogue.test.tsx` DOM errors, Arch unchanged at **7 failed / 18 passed**. Deferred: raising the adjustments from the variances (Step 2), counts spanning several locations at once, and counting in packs (the sheet is base units, with the pack available as a counting help). |
| 2026-10-25 | Facility Admin invitation self-approval exception | User approved letting the inviting Tenant Admin approve or withdraw a Facility Admin invitation request. The exception is scoped to `request_origin = FACILITY_INVITATION`, requires the actor to remain a Tenant Admin with facility reach, keeps target self-approval blocked, and leaves Tenant Admin promotions, manual Facility Admin promotions, and revocations under independent approval. Added the invitation-origin marker at setup completion, a backfill migration for already-pending requests, audit metadata (`self_approved`, `approval_mode`, `request_origin`), an explicit UI warning and withdraw action, and regression tests. Focused backend tests (43), the role-promotions Vitest suite (19), TypeScript, ESLint, Pint, and the production build pass; the full Vitest run has one unrelated structure failure from a concurrent test file under `pages/`. |
