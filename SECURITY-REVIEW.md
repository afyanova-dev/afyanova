# AfyaNova Administration Security Review

## Scope and limitation

This review revalidates the previously documented staff-termination concern.
The repository has Git metadata, but the worktree contains extensive unrelated
changes. This is a focused revalidation, not a clean-base diff review.

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
from unrelated and other-tenant accounts. It passed in the latest focused
rerun (**1 test / 16 assertions**) and in the full backend run
(**3,533 tests / 21,326 assertions**).

**Evidence:**

- `app/Domains/HR/Presentation/Http/Controller/FacilityStaffController.php`,
  `terminateStaff()`
- `app/Domains/Identity/Application/LinkedStaffAccessService.php`,
  `revokeAllAccessForStaff()`
- `tests/Feature/Security/StaffTerminationRevocationTest.php`, multi-facility
  termination regression

## Privileged assignment policy — retain pending independent review

On HR suspension, immediately block the linked account tenant-wide, revoke
tracked sessions, and suspend ordinary assignments across facilities.
Privileged assignments remain recorded as active until an independently
authorized identity administrator approves a revocation. HR lifecycle
permissions alone must not change elevated administrator roles. This preserves
separation of duties and an auditable identity-approval path while the linked
account is blocked from login and its sessions are invalidated.

The regression test
`it suspends linked access tenant-wide and restores only suspended assignments`
in `tests/Feature/Security/StaffTerminationRevocationTest.php` verifies
tenant-wide login blocking, session revocation, ordinary access suspension,
pending privileged-revocation requests, and guarded reinstatement. It passes
(**1 test / 46 assertions**).

## Other inspected areas

- Ordinary onboarding in the inspected HR path requires HR permissions and creates an HR placement request; HR staff-account linking does not grant access.
- HR Manager permissions alone do not include identity assignment management.
- Elevated role approvals block target self-approval and enforce scoped authority. Requester self-approval is blocked except for a Facility Admin invitation created by that Tenant Admin (`request_origin = FACILITY_INVITATION`); Tenant Admin promotions, manual Facility Admin promotions, and revocations still require a distinct administrator.
- Platform Super Admin setup uses a one-time token and prevents bootstrap if an active platform administrator already exists.
- The inviter-authority bootstrap regression confirms setup is refused without saving the invitee's password or verifying the account after the inviter loses access.
- Active tracked sessions are verified as revoked after ordinary assignment revocation, HR assignment suspension and revocation, and independently approved Facility Admin revocation.
- The latest full backend suite passes (**3,533 tests / 21,326 assertions**).

## Merge recommendation

The former multi-facility termination blocker is resolved in the inspected
source and regression test. The privileged-assignment suspension policy is
explicitly documented and covered by regression tests. Follow-up test gaps
for those inviter and session-revocation scenarios are now covered by focused
regressions. This is still a focused revalidation, not a clean-base diff
review; project-wide Pint findings pass.
