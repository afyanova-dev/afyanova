<?php

declare(strict_types=1);

namespace App\Domains\HR\Presentation\Http\Controller;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\HR\Application\FacilityPeopleSearchService;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class FacilityPeopleSearchController
{
    public function search(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityPeopleSearchService $peopleSearch,
        OrganizationAdminApi $organizationAdminApi,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $permissions = $this->assertCanSearch($actor, $facilityId, $scopeChecker);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);
        $term = trim($validated['q']);
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
        $today = now()->toDateString();

        $employees = [];
        $canReadStaff = array_intersect(['hr.staff.read', 'hr.staff.manage'], $permissions) !== []
            || $this->canReviewAssignments($permissions);
        $canReadUsers = in_array('identity.users.read', $permissions, true);
        if ($canReadStaff) {
            $employees = array_map(
                static fn (array $row): array => [
                    'type' => 'employee',
                    'id' => $row['id'],
                    'name' => trim(implode(' ', array_filter([$row['firstName'], $row['middleName'], $row['lastName']]))),
                    'detail' => $row['staffNumber'],
                    'status' => $row['status'],
                    'accountEmail' => $row['accountEmail'],
                    'photoUrl' => $row['photoDocumentId'] !== null
                        ? route('hr.staff.photo', ['staff' => $row['id']])
                        : null,
                    'href' => route('hr.staff.show', ['staff' => $row['id']]),
                ],
                $peopleSearch->activeStaffMatches(
                    $context->tenantId(),
                    $facilityId,
                    $pattern,
                    $today,
                    $canReadUsers,
                ),
            );
        }

        $users = [];
        if ($canReadUsers) {
            $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
            abort_unless($facility !== null, 404);
            $users = array_map(static fn (array $row): array => [
                'type' => 'user',
                'id' => $row['id'],
                'name' => $row['name'],
                'detail' => $row['email'],
                'status' => ! $row['emailVerified'] ? 'SETUP PENDING' : $row['status'],
                'accountEmail' => null,
                'photoUrl' => null,
                'href' => route('hr.users.show', ['user' => $row['id']]),
            ],
                $peopleSearch->matchingFacilityAccounts(
                    $context->tenantId(),
                    $facilityId,
                    $facility['organizationId'],
                    $pattern,
                ),
            );
        }

        return response()->json(['results' => [...$employees, ...$users]]);
    }

    public function show(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        IdentityApi $identity,
        FacilityPeopleSearchService $peopleSearch,
        OrganizationAdminApi $organizationAdminApi,
        string $user,
    ): Response {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $permissions = $this->assertCanSearch($actor, $facilityId, $scopeChecker);
        abort_unless(in_array('identity.users.read', $permissions, true), 403);
        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);

        $profile = $peopleSearch->facilityAccountProfile(
            $context->tenantId(),
            $facilityId,
            $facility['organizationId'],
            $user,
        );
        abort_unless($profile !== null, 404);

        $assignments = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'role' => FacilityUserRoleCatalog::label($row['role']) ?? $row['roleName'],
            'scope' => $row['scope'],
            'status' => $row['status'],
            'effectiveFrom' => $row['effectiveFrom'],
            'effectiveTo' => $row['effectiveTo'],
            'isEffective' => $row['isEffective'],
            'isLifecycleSuspended' => $row['isLifecycleSuspended'],
        ],
            $profile['assignments'],
        );
        $canReadAccessHistory = in_array('identity.assignments.read', $permissions, true)
            || array_diff(['hr.staff.manage', 'hr.staff_user_link.manage'], $permissions) === [];
        $accessHistory = $canReadAccessHistory
            ? $identity->facilityAccountAccessHistory(
                $context->tenantId(),
                $facilityId,
                $facility['organizationId'],
                $user,
            )
            : [];
        $requests = $profile['requests'];
        $linkedStaff = $profile['linkedStaff'];

        return Inertia::render('HR/Users/Show', [
            'facility' => ['id' => $facility['id'], 'name' => $facility['name']],
            'account' => [
                'id' => $profile['account']['id'],
                'name' => $profile['account']['name'],
                'email' => $profile['account']['email'],
                'status' => ! $profile['account']['emailVerified'] ? 'SETUP PENDING' : $profile['account']['status'],
                'createdAt' => $profile['account']['createdAt'],
            ],
            'assignments' => $assignments,
            'accessHistory' => $accessHistory,
            'requests' => $requests,
            'linkedStaff' => $linkedStaff === null ? null : [
                'id' => $linkedStaff['id'],
                'staffNumber' => $linkedStaff['staffNumber'],
                'name' => trim(implode(' ', array_filter([
                    $linkedStaff['firstName'],
                    $linkedStaff['middleName'],
                    $linkedStaff['lastName'],
                ]))),
            ],
            'canManageStaff' => array_diff(
                ['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage'],
                $permissions,
            ) === [],
            'canReviewAssignments' => array_diff(
                ['admin.workspace.access', 'identity.assignments.manage'],
                $permissions,
            ) === [] || array_diff(['hr.staff.manage', 'hr.assignment.manage'], $permissions) === [],
            'canManagePositions' => in_array('hr.cadre_position.manage', $permissions, true),
        ]);
    }

    /**
     * @return list<string>
     */
    private function assertCanSearch(User $actor, string $facilityId, ScopeChecker $scopeChecker): array
    {
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        abort_unless(
            array_intersect(['hr.staff.read', 'hr.staff.manage', 'identity.users.read'], $permissions) !== [],
            403,
            'You are not authorized to search facility people.',
        );

        return $permissions;
    }

    /**
     * Assignment reviewers (e.g. Facility Admins) may browse the staff
     * directory and open staff records read-only, so header search must
     * surface employees to them too — mirroring
     * FacilityStaffController::canReviewAssignments. The employee href
     * points at hr.staff.show, which admits the same reviewer path.
     *
     * @param  list<string>  $permissions
     */
    private function canReviewAssignments(array $permissions): bool
    {
        return array_diff(['admin.workspace.access', 'identity.assignments.manage'], $permissions) === []
            || array_diff(['hr.staff.manage', 'hr.assignment.manage'], $permissions) === [];
    }

    private function activeFacilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();
        abort_unless(is_string($facilityId) && $facilityId !== '', 409, 'Select an active facility before searching facility people.');

        return $facilityId;
    }
}
