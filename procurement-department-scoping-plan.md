# Procurement Department Scoping Plan

## Purpose

Make purchase requisitions respect the user's authorized department scope while
keeping the permission model manageable. This document records the chosen
model, implementation status, and remaining rollout work.

## Implementation status

The requisition department-scope behavior described below is implemented and
covered by backend and UI regression tests. The request form and server now use
effective Identity assignments; no per-department Procurement permissions were
added.

The local DSK Dispensary account was reviewed and, with approval, its active
`pharmacist` assignment was narrowed from facility-wide to Pharmaceutical using
the supported Identity grant/revoke command. The old broad assignment is
revoked; the effective Procurement permissions now exist only in Pharmaceutical
and not at facility-wide or Laboratory scope. Other facilities' assignments
still need review.

## Recommended model

Separate **what a user may do** from **which department's records they may
access**:

- Permissions describe tasks, such as creating a requisition, approving one,
  or creating a purchase order. Reuse the existing Procurement task
  permissions; do not create a separate permission for every department.
- An effective Identity access assignment in the active facility provides the
  user's scope. A department-scoped assignment applies to that department. A
  facility-scoped assignment with no department is an intentional
  facility-wide grant.
- A user with one department scope creates requests for that department; a
  user with several authorized department scopes may choose among those
  departments. A facility-wide user with the relevant create permission may
  create on behalf of any department in that facility.
- Department-level staff see and act on requests within their authorized
  department scope. Facility-wide procurement reviewers may see and act across
  departments when their task permissions allow it.
- Existing separation-of-duties rules remain in force: the request creator
  cannot approve their own request.
- The server enforces scope for list, detail, and mutation routes. Hiding a
  department or row in React is not an authorization control.

Do not infer department authorization from a role name such as "Pharmacist".
Use active, effective access assignments for the current facility. HR staff
placement may be useful as a suggested default, but it must not grant access
unless the authorization model explicitly says so.

## Implemented behavior

- The request form receives only active departments the actor may request for.
- A single department scope is preselected and locked; multiple department
  scopes allow selection only among those departments.
- A facility-wide requester with the create permission can select any active
  facility department or the existing no-department option.
- Creation validates the actor's department scope on the server and rejects
  unauthorized, inactive, unknown, and cross-facility department values.
- The requisition list and request actions are filtered and authorized against
  the requisition's department on the server.
- Department-scoped create and approve permissions are honored, while the
  existing creator-cannot-approve-own-request rule remains in force.
- The selected department is included in requisition audit metadata.
- Procurement supplier data is not loaded when opening Requests, so unrelated
  supplier permissions do not block a properly scoped request workspace.
- No per-department permission keys were added.

## Implementation plan

### 1. Establish the scope contract — complete

- Use effective access assignments for the active facility to determine the
  user's authorized department scope for each Procurement task.
- Treat an explicit facility-wide assignment as facility-wide access; do not
  silently broaden a department-scoped assignment.
- Define how a user with multiple assignments combines scopes: department
  assignments allow their union; a matching facility-wide assignment allows
  the entire facility.
- If a user has no valid department or facility-wide assignment for a task,
  deny that task even if stale client state or a forged department ID is sent.

### 2. Scope request creation — complete

- Provide the form with only departments the current actor may request for.
- For one authorized department, preselect it and omit other departments from
  the picker (or display the department as read-only).
- For multiple authorized departments, allow selection only among those
  departments.
- For an authorized facility-wide requester, allow any department in the
  facility and the existing facility-wide/no-department option.
- Validate the submitted department against the actor's effective scope on the
  server. Reject unauthorized, unknown, inactive, or cross-facility values
  without writing a requisition.
- Record the actor and selected department in the existing audit trail.

### 3. Scope request visibility and actions — complete

- Return only requisitions visible to the actor's effective scope.
- Apply the same scope checks to opening a requisition and to submit, approve,
  reject, cancel, and create-order actions; a guessed requisition ID must not
  bypass the list filter.
- Department-scoped requesters/reviewers see requests for their authorized
  departments. Facility-wide users see the facility queue only when their
  corresponding Procurement task permissions permit the operation.
- Preserve the different-actor approval rule and the department-based purchase
  order approval-limit resolution.

### 4. Keep the permission model intentional — complete

- Reuse existing task permissions and apply them at the assignment's scope.
- Do not add per-department permission keys.
- Add a distinct permission only if the product requires a facility-wide user
  who can create requests but must not create on behalf of other departments.
  If required, name and document that capability explicitly and test it
  separately from ordinary department-scoped creation.
- Keep facility-wide Procurement visibility as an explicit assignment/configuration,
  not an accidental consequence of being able to open the Procurement module.

### 5. Verify existing assignments and rollout — in progress

- Inspect the active user's effective assignment at the facility before
  enabling department filtering. Do not guess a department from the role.
- Review Pharmacy, Procurement, and approver assignments in each facility.
- Correct missing or overly broad assignments through the supported Identity
  administration flow; do not patch user behavior with frontend defaults.
- Provide a clear empty/blocked state when a user has Procurement access but
  no effective assignment for the selected operation.
- The active DSK user's assignments were inspected and corrected through the
  supported Identity command flow. The assignment change is audited and was
  verified to grant Procurement access/create only in Pharmaceutical.

## Acceptance criteria

- A Pharmaceutical-scoped requester can create only a Pharmaceutical request
  and cannot forge a Laboratory or Administration department in the POST.
- A user assigned to multiple departments can choose only among those
  departments.
- An explicitly facility-wide requester can create for the departments allowed
  by the policy.
- Department-scoped users cannot see another department's requisition in the
  list, details, or action endpoints.
- An authorized facility-wide reviewer can see the facility queue and perform
  only actions allowed by their task permissions.
- Cross-facility access remains denied.
- The creator still cannot approve their own request.
- Department-based approval limits and the approved-request-to-order flow keep
  using the requisition's validated department.
- Feature tests cover department-scoped creation, list visibility, and
  cross-department actions; UI tests confirm the picker reflects authorized
  choices. The focused Procurement feature and UI suites pass.

## Follow-up

1. Review Pharmacy, Procurement, and approver assignments in each facility;
   update them through Identity administration where needed.
2. Department scope currently governs requisitions. Purchase-order creation
   continues to require the existing facility-level order permission; a
   department-scoped buyer flow would need a separate, explicit extension.
3. Walk through request → second-person approval → purchase order → Inventory
   receipt with authorized test accounts. Do not submit a real request without
   confirming its item, quantity, and purpose.
