<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Models\User;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $canManageOrganization = $user->can('organization.facilities.read')
            || $organizationPermissions->allows($user, 'organization.facilities.read');
        $canReviewRoleRequests = $user->can('identity.assignments.read')
            || $organizationPermissions->allows($user, 'identity.assignments.read');
        $canViewRoleCatalog = $user->can('identity.roles.read')
            || $organizationPermissions->allows($user, 'identity.roles.read');
        $canReviewAudit = $user->can('audit.events.read')
            || $organizationPermissions->allows($user, 'audit.events.read');
        $canManageAssignments = $user->can('identity.assignments.manage')
            || $organizationPermissions->allows($user, 'identity.assignments.manage');
        $canManageProcurementLimits = $this->holdsProcurementLimits($user, $scopeChecker);

        $totalFacilities = 0;
        $activeFacilities = 0;
        if ($canManageOrganization) {
            $facilityQuery = DB::table('org_facilities')
                ->where('tenant_id', $user->tenant_id);
            $totalFacilities = $facilityQuery->count();
            $activeFacilities = (clone $facilityQuery)->where('status', 'ACTIVE')->count();
        }

        $pendingApprovalsCount = 0;
        $recentPendingApprovals = [];
        if ($canReviewRoleRequests) {
            $pendingApprovalsQuery = DB::table('idn_admin_role_promotion_requests as request')
                ->leftJoin('org_facilities as facility', function ($join): void {
                    $join->on('facility.id', '=', 'request.facility_id')
                        ->on('facility.tenant_id', '=', 'request.tenant_id');
                })
                ->leftJoin('users as target_user', 'target_user.id', '=', 'request.target_user_id')
                ->where('request.tenant_id', $user->tenant_id)
                ->where('request.status', 'PENDING');

            $pendingApprovalsCount = (clone $pendingApprovalsQuery)->count();

            $recentPendingApprovals = (clone $pendingApprovalsQuery)
                ->orderByDesc('request.created_at')
                ->limit(5)
                ->get([
                    'request.id',
                    'request.role_key',
                    'target_user.email as account_email',
                    'request.created_at',
                    'request.action',
                    'facility.name as facility_name',
                ])
                ->map(static fn (object $req): array => [
                    'id' => (string) $req->id,
                    'roleKey' => (string) $req->role_key,
                    'roleLabel' => match ((string) $req->role_key) {
                        'tenant_admin' => 'Tenant Admin',
                        default => FacilityUserRoleCatalog::label((string) $req->role_key) ?? (string) $req->role_key,
                    },
                    'action' => (string) ($req->action ?? 'PROMOTE'),
                    'accountEmail' => $req->account_email ?? 'Staff member',
                    'facilityName' => $req->facility_name ?? 'Organization-wide',
                    'createdAt' => (string) $req->created_at,
                ])
                ->all();
        }

        $auditEventsCount = 0;
        if ($canReviewAudit) {
            $auditEventsCount = DB::table('shared_audit_log')
                ->where('tenant_id', $user->tenant_id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();
        }

        $manageableFacilitiesCount = $canManageProcurementLimits
            ? count($scopeChecker->selectableFacilities($user))
            : 0;

        return Inertia::render('Admin/Index', [
            'canManageOrganization' => $canManageOrganization,
            'canReviewRoleRequests' => $canReviewRoleRequests,
            'canViewRoleCatalog' => $canViewRoleCatalog,
            'canReviewAudit' => $canReviewAudit,
            'canManageAssignments' => $canManageAssignments,
            'canManageProcurementLimits' => $canManageProcurementLimits,
            'stats' => [
                'facilities' => $canManageOrganization ? [
                    'total' => $totalFacilities,
                    'active' => $activeFacilities,
                ] : null,
                'pendingApprovalsCount' => $pendingApprovalsCount,
                'auditEventsCount' => $auditEventsCount,
                'manageableFacilitiesCount' => $manageableFacilitiesCount,
            ],
            'pendingApprovals' => $recentPendingApprovals,
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
