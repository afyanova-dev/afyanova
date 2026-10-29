<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Models\User;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminWorkspaceController
{
    public function index(
        Request $request,
        OrganizationPermissionScope $organizationPermissions,
        ScopeChecker $scopeChecker,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('Admin/Index', [
            'canManageOrganization' => $user->can('organization.facilities.read')
                || $organizationPermissions->allows($user, 'organization.facilities.read'),
            'canReviewRoleRequests' => $user->can('identity.assignments.read')
                || $organizationPermissions->allows($user, 'identity.assignments.read'),
            'canReviewAudit' => $user->can('audit.events.read')
                || $organizationPermissions->allows($user, 'audit.events.read'),
            'canManageAssignments' => $user->can('identity.assignments.manage')
                || $organizationPermissions->allows($user, 'identity.assignments.manage'),
            'canManageProcurementLimits' => $this->holdsProcurementLimits($user, $scopeChecker),
        ]);
    }

    /**
     * The limits page is per facility: a Tenant Admin holds the key everywhere
     * they can act, a Facility Admin only where they are assigned.
     */
    private function holdsProcurementLimits(User $user, ScopeChecker $scopeChecker): bool
    {
        foreach ($scopeChecker->selectableFacilities($user) as $facilityId) {
            if (in_array(
                'procurement.approval_limits.manage',
                $scopeChecker->effectivePermissions($user, $facilityId),
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    public function setupTwoFactor(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('Admin/TwoFactorSetup', [
            'setupPending' => $user->two_factor_secret !== null,
            'continueTo' => $request->routeIs('platform-admin.security.two-factor.setup')
                ? route('platform-admin.index')
                : route('admin.workspace.index'),
        ]);
    }
}
