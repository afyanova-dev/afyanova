# Facility Context and Operation Scope Alignment Plan

## Purpose

Align application behavior with the working-context rule:

> A user may work in **General** context or deliberately select a facility.
> General context permits tenant-wide work, and facility-owned work across
> authorized facilities only when its target facility is explicit. Once a
> facility is selected, every read and write is confined to that facility.
> A user's role grants permission; it does not silently override the active
> context.

This is a build plan, not a claim that the application currently follows this
rule. Implement the shared context contract first, then migrate Inventory as
the first end-to-end consumer, then audit and align all other facility-bound
workspaces. Do not change domain ownership or write directly to foreign-domain
tables to make a screen appear scoped.

## Implementation status

**In progress.** The first shared-context and Inventory slice has been
implemented:

- General, selected-facility, and unresolved context modes are represented in
  the request context and shared frontend props.
- Organization-authorized users default to General; a facility-only user with
  exactly one assignment is entered into that facility automatically, while
  users with several (or zero) assignments get an explicit state.
- The facility switcher can select General or any assigned facility, and the
  server validates both transitions.
- Inventory item catalogue mutations are General-only. Location-type creation
  follows the active context (tenant-wide in General, local in a facility);
  facility locations and stock operations remain facility-scoped.
- The Inventory workspace identifies General and does not show facility
  stock/location data as if it were tenant-wide. The Dashboard explains
  General and unresolved context.
- Tests cover context defaulting/switching and the migrated Inventory behavior.
- Phase 0 is complete: every context consumer is inventoried and classified in
  [`context-consumer-inventory.md`](./context-consumer-inventory.md); the scan
  confirmed no surface treats null facility as "all facilities" or auto-selects
  a facility, and stale/revoked/malformed session selections fail closed
  (`tests/Feature/Security/TenantContextTest.php` — including the
  revoked-assignment, malformed-id, and facility-mode-without-selection cases).
- The old Item Master HTTP tests that asserted role-derived item/catalogue
  writes while a facility was selected were replaced with context-based
  General-context tests (`tests/Feature/Domains/Inventory/ItemMasterControlledVocabularyTest.php`).

**Not complete:** stale and revoked-context regression cases are covered for
the shared context (see `tests/Feature/Security/TenantContextTest.php`);
migration of every other facility-bound workspace is inventoried in
[`context-consumer-inventory.md`](./context-consumer-inventory.md) but its
per-domain HTTP-denial evidence pass and full release verification remain
(Phases 5–6). Do not call application-wide facility-context alignment
complete until those items pass their phase exit criteria.

## Desired behavior

| Context | Reads | Writes | User experience |
|---|---|---|---|
| **General** | Tenant-level data and explicitly identified data across the user's authorized facility scope. Facility-specific aggregates must identify the facility or be labelled as an aggregate. | Tenant-wide operations require tenant/organization permission. Facility-owned operations require an explicit target facility and permission in that facility; no implicit facility may be guessed. | The header says **General**. The facility switcher can enter a facility. The user can see which operations are tenant-wide and which target a facility. |
| **Facility A** | Only Facility A data plus tenant-shared reference data required to work there. | Facility-owned writes can affect only Facility A. Tenant-wide writes are unavailable in this context; the user must switch to General. | The header says Facility A. Facility targets are fixed to A or are server-validated as A. |
| **Facility B** | Same rules as A, restricted to B. | Same rules as A, restricted to B. | The header says Facility B. |

**General is not a facility, not a wildcard sent by the client, and not an
authorization grant.** It is an explicit working context. Authorization is
still determined by tenant membership, active access assignments, effective
permissions, and domain policy. General must not permit access to facilities
or tenant data for which the user lacks permission.

For organization-level users who can work in General, login defaults to
General rather than silently selecting a facility. A facility-only user with
exactly one current assignment is entered into that facility automatically —
no manual switch is required. A facility-only user with zero or multiple
assignments resolves to an explicit select-facility state instead, and must
not gain organization-wide access from any default: General shows only what
their permissions allow and asks them to select an authorized facility before
opening a facility-only workspace. Auto-selection applies only to the sole
currently valid assignment; it never revives a revoked assignment.

## Current state and known mismatches

The following existing behavior must be addressed; the list is not exhaustive
of all workspace consumers:

- `ResolveTenantContext` reads `active_facility_id` and auto-selects the only
  assigned facility if none was selected. It has no explicit General selection
  transition.
- `FacilitySwitcher` displays a facility or “Select facility” and only offers
  assigned facility choices. It cannot select General or clear the current
  facility.
- `FacilityContextController` accepts only a facility ID. It cannot safely
  accept a deliberate General selection.
- Inventory's workspace rejects null facility context, even though General is
  a valid tenant working context.
- Inventory's stock locations are facility-owned and currently derive their
  facility from `TenantContext`; that is the correct behavior in a selected
  facility.
- Inventory location types are created tenant-wide whenever the effective role
  includes `tenant_admin`, including when a facility is selected. This is not
  correct under this plan.
- Inventory item definitions and lifecycle operations are tenant-wide in the
  domain model. Their mutation controls and endpoints currently do not require
  General context. Keep item definitions tenant-shared, but allow their
  mutation only in General. Facility views may read shared active items.
- Current tests affirm tenant-wide Tenant Admin item/type operations while a
  facility is selected. Replace those expectations with context-based tests.
- The Dashboard correctly avoids facility count widgets when there is no
  active facility, but should identify General context and avoid implying that
  a facility aggregate belongs to one site.
- Other domains use `TenantContext::facilityId()` and facility-scoped
  permissions. Audit every such surface before claiming the application is
  fully aligned.

Relevant starting points:

- [`ResolveTenantContext`](./app/Shared/Tenancy/ResolveTenantContext.php)
- [`TenantContext`](./app/Shared/Tenancy/TenantContext.php)
- [`FacilityContextController`](./app/Domains/Authentication/Presentation/Http/Controller/FacilityContextController.php)
- [`FacilitySwitcher`](./resources/js/components/navigation/FacilitySwitcher.tsx)
- [`SwitchFacilityDialog`](./resources/js/components/navigation/SwitchFacilityDialog.tsx)
- [`HandleInertiaRequests`](./app/Shared/Http/Middleware/HandleInertiaRequests.php)
- [`DashboardController`](./app/Shared/Http/DashboardController.php)
- [`Dashboard`](./resources/js/pages/Dashboard/Index.tsx)
- [`InventoryCatalogPolicy`](./app/Domains/Inventory/Authorization/InventoryCatalogPolicy.php)
- [`InventoryWorkspaceController`](./app/Domains/Inventory/Presentation/Http/Controller/InventoryWorkspaceController.php)
- [`DefineStockLocationTypeController`](./app/Domains/Inventory/Presentation/Http/Controller/DefineStockLocationTypeController.php)
- [`DefineStockLocationTypeHandler`](./app/Domains/Inventory/Application/Command/DefineStockLocationTypeHandler.php)
- [`frontend-foundation-inventory-operations.md`](./frontend-foundation-inventory-operations.md)

## Scope and domain ownership

### Shared context and navigation

- Define General and selected-facility context in the server-side request
  contract, shared Inertia auth props, switcher, and relevant workspace
  navigation.
- A context switch must reload the destination page so permissions, workspace
  data, navigation visibility, and counts are recalculated. No stale facility
  props or authorization state may survive the switch.
- Preserve the distinction between *facility selection* and *authorization*.
  Only facilities returned by the user's active assignments may be selected.
- Do not add a client-supplied facility ID as an authority. In a selected
  facility, derive scope from the trusted context. In General, validate any
  explicit facility target server-side against tenant membership, assignment,
  and the permission required for that operation.

### Inventory-specific scope decisions

| Inventory resource or action | General context | Selected-facility context |
|---|---|---|
| Item catalogue definitions and lifecycle | Tenant-wide read/write for authorized catalogue managers. | Shared catalogue remains readable as needed; create, deactivate, and reactivate are unavailable and rejected server-side. Switching to General is required. Do not pretend the existing tenant-level item is facility-owned. |
| Built-in location types (`WAREHOUSE`, `STORE`) | Available as shared built-ins. | Available as shared built-ins. |
| Custom location types | Tenant-wide type creation only for authorized tenant-level managers; mark and audit as tenant-wide. | Facility-local type creation only; a Tenant Admin acting in this context must not silently create a tenant-wide type. |
| Stock locations | Read across explicitly authorized facilities with facility identity visible. To create or change one, the user must choose an explicit authorized target facility. | Read and mutate only in the selected facility. Do not offer a facility selector that can escape the active facility. |
| Stock balances, receipts, ledger, adjustments, reservations | Cross-facility view may be offered only as an explicitly labelled, permission-scoped aggregate/drill-down. Facility mutations require an explicit authorized facility target. Do not combine balances in a way that loses facility identity. | Read and mutate only in the selected facility. |

Tenant-wide item definitions are references shared by facilities; their
visibility from a facility is not permission to mutate the tenant catalogue
from that facility. If the product later needs facility-specific item
availability, activation, or assortment, treat that as a separate domain and
data-model change. Do not simulate it with UI filters on tenant-wide items.

For a facility-owned operation launched in General, an explicit target
facility is mandatory. In particular, never create a location or record stock
without identifying its facility. Whether General initially offers
cross-facility writes or only cross-facility reads is a rollout decision:
implement writes only where each existing command, authorization policy, and
audit contract can safely enforce the explicit target and assigned-facility
permission. Otherwise show the user how to switch to the target facility.

## Implementation sequence

### Phase 0 — Inventory the context contract and consumers

**Status: complete — the inventory is published at
[`context-consumer-inventory.md`](./context-consumer-inventory.md). Every
consumer is classified (tenant-wide / facility-owned fail-closed /
General-aware); no surface treats null as "all facilities" or auto-selects a
facility. The per-domain test-evidence re-run belongs to Phase 5–6.**

1. Trace the request middleware order and confirm context resolution occurs
   after authentication and inside tenant RLS/tenancy scope.
2. Search all routes, controllers, policies, handlers, Inertia props, and
   frontend consumers of `TenantContext::facilityId()`,
   `auth.activeFacility`, `active_facility_id`, `effectivePermissions`, and
   `effectiveRoles`.
3. Record each surface as tenant-wide, facility-owned, or mixed. Include reads,
   create/update/deactivate actions, dashboards, search, export, background
   commands, and redirects.
4. Identify code that treats null facility as “no access,” code that treats it
   as “all facilities,” and code that auto-selects a facility. Verify the
   actual intended behavior at every boundary.
5. Keep this plan as the source of the desired context rule; update existing
   Inventory roadmap statements that grant tenant-wide behavior based solely
   on Tenant Admin role.

**Exit:** Every facility-context consumer is inventoried and has an explicit
scope classification and owner. No implementation assumes null means “all”
or picks a facility implicitly.

### Phase 1 — Implement an explicit, secure General context

1. Represent General as an explicit server-recognized context. Prefer a
   deliberate, typed context transition over magic facility IDs or accepting
   arbitrary request strings as scope.
2. Extend the context switch endpoint to support either:
   - selecting a facility after validating it against active facility
     assignments; or
   - selecting General after verifying tenant identity and establishing the
     user's permissions for General.
3. Persist the chosen mode in session, distinguishing General from an absent,
   stale, malformed, or revoked selection. A missing selection for users who
   may work in General resolves to General; a facility-only user with exactly
   one current assignment is entered into that facility automatically
   (persisted like a deliberate selection); anyone else resolves unresolved.
4. A facility-only user without General permissions must be presented a
   facility-selection state rather than being elevated by the General
   default. Their workspace requests must remain denied or show an explicit
   select-facility state as appropriate.
5. Keep `TenantContext` immutable for the request. Bind the validated context
   once in the existing middleware and do not let controllers or commands
   mutate it.
6. Update shared Inertia auth data with an explicit context mode/label as
   needed. Do not infer General from a missing `activeFacility` on the client;
   distinguish General from unresolved/invalid state.
7. Update the switcher to show General and all authorized facilities, current
   selection, and an accessible switch action. Provide a path back to General
   from every facility. Avoid presenting General to users who cannot use it.
8. Ensure switching reloads the page and re-computes permissions and data. Do
   not preserve stale workspace state across the switch.
9. Add tests for direct POST tampering, unassigned facility IDs, revoked or
   stale session context, logout/login behavior, General selection, facility
   switching, and users with zero, one, or multiple facility assignments.

**Status: Initial slice implemented; complete the remaining cases listed in
the implementation status and test matrix.**

### Phase 2 — Make authorization and operation targeting context-aware

1. Define/reuse policy helpers for:
   - tenant-level permission in General;
   - permission in the selected facility;
   - permission for an explicit facility target while in General;
   - rejecting tenant-wide mutation in a selected-facility context;
   - rejecting facility-target mismatch in a selected-facility context.
2. Enforce the rule in command authorization and domain/application handlers,
   not only Form Requests, controllers, or hidden buttons.
3. Make authorization checks use current effective role/permission for the
   target scope. Never use `tenant_admin` role identity alone to choose write
   scope.
4. For facility-owned commands in General, require an explicit facility ID
   only when cross-facility operation is intentionally supported. Validate
   the target's tenant, active assignment, and target-facility permission.
   If the existing command contract cannot safely support it, require the
   user to switch context instead.
5. For facility-owned commands in a selected facility, derive target facility
   from `TenantContext`; if a submitted target is retained for compatibility,
   reject it unless it exactly matches the active facility.
6. Guard direct HTTP requests, queued/async paths, and command-bus calls against
   context bypass. Keep service/console entry points under their existing
   explicit tenant/facility scope contracts.
7. Preserve fail-closed behavior for missing tenant, invalid context,
   unauthorized target, and missing facility on facility-required operations.
   Return repository-standard 403/404/validation feedback; do not convert
   failures to empty successful reads.

**Status: Inventory catalog mutations and facility operations are context
enforced. Audit and migrate the remaining domains before meeting this exit.**

### Phase 3 — Align shared UI, navigation, and Dashboard

1. Show a persistent General or facility label in the header. The label must
   update after a switch and be accessible to screen readers.
2. Make context-dependent actions explicit: identify tenant-wide changes and
   facility-targeted changes. In General, require a facility selection for a
   facility-owned action if cross-facility writes are enabled.
3. In a selected facility, hide or disable tenant-wide mutation controls and
   explain that switching to General is required. Backend rejection remains
   mandatory.
4. In General, do not display facility-specific figures as if they were
   facility-neutral. Either show authorized tenant-wide data, show a clearly
   labelled cross-facility aggregate with facility identity/drill-down, or
   omit the data with an explicit selection prompt.
5. Keep the Dashboard's current behavior of omitting facility-specific widgets
   in General unless a properly authorized aggregate is deliberately added.
   Identify General context in the Dashboard/header, and avoid zero-shaped
   fallbacks for unavailable facility reads.
6. Ensure contextual links preserve the chosen mode only when appropriate;
   the server remains authoritative after navigation.
7. Add UI tests for General, selected facility, switching back to General,
   empty/unresolved states, hidden controls, and clear scope messaging.

**Status: Dashboard, switcher, and Inventory have been aligned. Audit remaining
shared navigation and workspace surfaces before meeting this exit.**

### Phase 4 — Migrate Inventory end to end

1. Update `InventoryWorkspaceController` to support General without fabricating
   a facility ID or returning 403 solely because General is selected.
2. In General, separate tenant-level catalogue pages from facility-owned
   stock/location work:
   - allow authorized tenant-level item catalogue management;
   - make shared items readable where needed;
   - show an explicit facility picker or route to select a facility for
     location/stock actions;
   - where cross-facility reads are supported, include facility identity and
     filter only to facilities the user may read.
3. In a selected facility, restrict locations, balances, receipts, ledgers,
   adjustments, and reservation data to that facility. All queries must scope
   by trusted active facility, not a query parameter alone.
4. Keep item definitions tenant-scoped. Move create/deactivate/reactivate
   actions to General-only both in the UI and server policy/command path.
   Preserve the current safety rule that items cannot be deactivated while
   stock is on hand or reserved at any facility; make cross-facility impact
   visible when relevant.
5. Change location-type creation:
   - General + authorized tenant-level manager => `facility_id = null`,
     shared tenant type and tenant-level audit attribution.
   - selected facility + authorized facility-level manager => selected
     `facility_id`, local type and facility audit attribution.
   - Tenant Admin in a selected facility must not be converted to tenant-wide
     scope based on role name. If they also have facility permission, create
     only a local type there; otherwise deny and instruct them to switch to
     General.
6. Keep built-in types shared and protected from shadowing. Preserve code
   uniqueness semantics, with explicit collision errors when tenant and local
   type scopes intersect.
7. Keep location creation and lifecycle facility-owned:
   - in selected context, facility is derived from context and cannot be
     changed in the form;
   - in General, either require switching to a facility or require an explicit
     target facility and enforce all target-scope checks.
8. Keep receiving, adjustment, approval/rejection, ledger, balances, and
   reservation reads/mutations facility-scoped. A General aggregate must not
   become an implicit write target.
9. Review navigation sections, panel props, status messages, redirects,
   pagination/sorting requests, empty states, and mutation refreshes for
   stale/ambiguous facility IDs.
10. Update `frontend-foundation-inventory-operations.md` to replace the old
    role-derived scope decisions and describe the new General/facility
    behavior.

**Status: Initial Inventory slice implemented and covered by focused tests.
Complete any uncovered mutation/query surfaces and run final regressions
before meeting this exit.**

### Phase 5 — Audit and align all other facility-bound workspaces

1. Use the Phase 0 inventory. For every domain, classify each operation as
   tenant-wide, facility-owned, or explicitly cross-facility.
2. For each facility-owned workspace, verify:
   - General has no misleading facility-only view or action;
   - selected facility scopes query, mutation, export, and audit consistently;
   - target facility cannot be supplied to bypass active context;
   - permission is re-evaluated for each target/context;
   - facility-specific counts, statuses, and links cannot leak across sites.
3. For explicit cross-facility reports, aggregate only authorized facilities,
   retain facility identity on rows, and label the aggregate.
4. For cross-facility administrative operations, require explicit target
   selection and an appropriate organization-level permission; do not derive
   global scope from role names alone.
5. Review Dashboard, patient operations, appointments, encounter queues,
   bed/ward, pharmacy, billing, HR, organization administration, audit views,
   and any API endpoints found by the inventory. Preserve domain ownership:
   Pharmacy does not administer Inventory; Inventory does not configure
   Billing; cross-domain reads/writes use published APIs/events.
6. Review background jobs, console commands, scheduled tasks, and event
   listeners. Their scope must be explicit and not accidentally inherit an
   HTTP user's selected facility.
7. Add domain-specific regression tests before calling each workspace aligned.

**Exit:** Every facility-bound surface in the inventory has an agreed scope,
server-side enforcement, correctly attributed audit trail, and test evidence.

### Phase 6 — Regression, security, and rollout verification

1. Run focused shared tenancy/context, authorization, Inertia, Inventory
   feature, and frontend switcher/workspace tests.
2. Run the relevant PHP static analysis/style and TypeScript lint/type-check
   commands, then the broader suite required by repository conventions.
3. Use at least two facilities in end-to-end tests. Create distinct data in A
   and B and prove General behavior, A isolation, B isolation, and switch
   reload behavior.
4. Inspect audit rows for tenant-wide versus facility attribution.
5. Validate denied requests directly over HTTP; do not rely on hidden UI
   controls as proof of authorization.
6. Verify the real login flow, stale/revoked facility access, switching to
   General and back, and deep links after each context transition.
7. Check existing users with persisted `active_facility_id` sessions. Decide
   whether to migrate/clear those session values or treat them as an explicit
   previous user selection; never silently retain an unintended facility
   selection for accounts meant to start in General.
8. Update operational docs and release notes with the context behavior and
   any required user training.

**Exit:** No known path reads or mutates an unintended facility, permission
state is recalculated on switches, and test evidence covers both permitted
and denied cases.

## Authorization and data-safety invariants

- Tenant identity comes from the authenticated principal and is enforced by
  the existing tenant/RLS boundary.
- Facility selection comes from server-validated session state, not a body,
  header, query parameter, or route parameter.
- A facility target in General is explicit and independently authorized; it
  does not change the request's working context.
- A target facility in selected context must equal the active facility.
- Organization/tenant role is not, by itself, permission to mutate from any
  context.
- Null/unresolved context is not equivalent to General unless the explicit
  General state has been validated and established.
- No balance or facility-owned row is directly edited to compensate for
  incorrect scoping; use the owning domain's command and audit path.
- Every successful operation reports the actual persisted scope. Every failed
  read/write remains visibly failed; never represent a denied or failed
  facility read as zero data or success.
- Audit attribution is tenant-level for tenant-wide operations and facility
  level for facility-owned operations. For a General-context action explicitly
  targeting a facility, record the target facility; retain the actor's
  General working context separately if the audit schema supports it.
- Switching contexts invalidates or reloads context-dependent client state.
- Permission checks must be repeated server-side at the point of each action.

## Required test matrix

### Shared context and switcher

- User with no facility assignments cannot select or view a facility.
- User entitled to General starts in General; no automatic facility
  selection occurs for them.
- Facility-only user with exactly one current assignment is entered into that
  facility automatically; with several assignments they resolve unresolved
  until they choose one explicitly.
- Facility-only user cannot use General to gain organization-wide permissions.
- Selecting General clears the active facility in trusted session state.
- Selecting A or B stores only a facility the user is currently assigned to.
- Forged, cross-tenant, inactive, revoked, malformed, or stale facility
  selections fail closed and cannot widen scope.
- Switch A -> B -> General -> A reloads the context, permissions, props,
  navigation, and facility-dependent data each time.
- Existing session state at login follows the documented persistence policy.

### Inventory

- General Tenant Admin creates a shared custom type; A and B can read it.
- Tenant Admin in A creates a local type only if authorized for local
  management; B cannot read it.
- Tenant Admin in A cannot create a shared type via form or direct HTTP call.
- Facility Admin in A cannot write a type or location for B.
- General location creation without an explicit target is rejected or routes
  the user to select a facility.
- General location creation with an explicit authorized target writes only
  that facility, if this capability is enabled.
- Selected A location create/deactivate/reactivate and stock operations affect
  only A; forged B targets are rejected.
- General item create/deactivate/reactivate is tenant-wide and permissioned.
- Item mutation in A or B is denied; shared active items remain readable.
- Item deactivation still checks balances/reservations in every facility and
  reports the blocking condition.
- General stock views, if present, clearly retain facility identity and cannot
  submit a mutation against an ambiguous aggregate.
- Query filters, pagination, sorting, partial Inertia reloads, redirects, and
  panel context cannot restore or leak another facility's data.
- Audit rows correctly distinguish tenant-wide, facility-local, and
  General-launched explicit-target operations.

### Other workspace consumers

- For each inventoried domain: permitted access in the correct context,
  forbidden access in the wrong context, cross-facility target tampering,
  proper aggregate labeling, correct audit attribution, and switch reload.
- Async jobs and console paths establish their own explicit scope and are not
  affected by a user's browser session.

## Completion checklist for the coding agent

- [x] Read this plan and the referenced context, authorization, Inventory, and
      workspace code before editing.
- [x] Complete and update the Phase 0 consumer inventory
      ([`context-consumer-inventory.md`](./context-consumer-inventory.md)).
- [ ] Preserve existing uncommitted/user changes; do not make unrelated
      changes.
- [ ] Implement phases in order. Do not bypass a failed phase's exit criteria.
- [ ] Update tests whenever behavior changes, including the old tests that
      assert role-derived tenant-wide writes in a selected facility.
- [ ] Update directly related documentation and this plan's status as work
      completes; do not mark a phase complete without test evidence.
- [ ] Run focused tests, type checks, lint/static analysis, and relevant
      integration tests.
- [ ] Report changed files, behavior changes, exact validation commands and
      results, remaining gaps, and any decisions that could not be safely
      inferred.

## Out of scope unless discovered as a required dependency

- Changing tenant ownership of the Inventory item catalogue.
- Introducing facility-specific item catalogues or item activation without a
  separate data-model/domain decision.
- Broad role redesign or granting permissions to make tests pass.
- Reworking stock receipt, adjustment, dispensing, billing, or procurement
  domain logic unrelated to context enforcement.
- Treating General as a new role, bypass, tenant selector, or universal access
  mode.
