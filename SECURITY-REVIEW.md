# AfyaNova Administration Security Review

## Scope and limitation

This review revalidates the previously documented staff-termination concern.
The workspace still has no Git metadata, so a diff-based review and change
provenance cannot be established.

## Revalidated finding

### Resolved — Tenant-wide staff termination access revocation

The previously documented HIGH finding is resolved in the current workspace.
`FacilityStaffController::terminateStaff()` dispatches tenant-wide HR
termination and calls `LinkedStaffAccessService::revokeAllAccessForStaff()`
inside the same transaction. That service revokes ordinary active/suspended
assignments across the tenant, revokes tracked sessions, records
facility-attributed audit entries, and leaves privileged assignments for
independent approval rather than silently changing elevated roles.

The multi-facility regression test
`it revokes all facility access tenant-wide when linked staff is terminated`
in `tests/Feature/Security/StaffTerminationRevocationTest.php` verifies both
facility assignments, session revocation, audit attribution, and isolation
from unrelated and other-tenant accounts. It passed in the full Pest run
(3,445 passed, 21 other tests failed).

**Evidence:**

- `app/Domains/HR/Presentation/Http/Controller/FacilityStaffController.php`,
  `terminateStaff()`
- `app/Domains/Identity/Application/LinkedStaffAccessService.php`,
  `revokeAllAccessForStaff()`
- `tests/Feature/Security/StaffTerminationRevocationTest.php`, multi-facility
  termination regression

## Partial finding / policy decision

Suspension changes the tenant-wide Staff lifecycle state. The current
implementation blocks the linked account tenant-wide, suspends ordinary
assignments across facilities, and leaves privileged assignments for
independent review. Confirm the intended privileged-access policy and continue
testing suspend/reinstate behavior across facilities.

## Other inspected areas

- Ordinary onboarding in the inspected HR path requires HR permissions and creates an HR placement request; HR staff-account linking does not grant access.
- HR Manager permissions alone do not include identity assignment management.
- Elevated role approvals block target self-approval and enforce scoped authority. Requester self-approval is blocked except for a Facility Admin invitation created by that Tenant Admin (`request_origin = FACILITY_INVITATION`); Tenant Admin promotions, manual Facility Admin promotions, and revocations still require a distinct administrator.
- Platform Super Admin setup uses a one-time token and prevents bootstrap if an active platform administrator already exists.
- The full backend suite still has unrelated failures; inviter authorization
  changes before invitation acceptance and immediate session invalidation
  following every access-change path remain follow-up test gaps.

## Merge recommendation

The former multi-facility termination blocker is resolved in the inspected
source. This is not a diff-based review: the workspace still has no Git
metadata, and the full backend quality gate remains open.
