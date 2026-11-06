<?php

declare(strict_types=1);

namespace App\Domains\Identity\Presentation\Http\Controller;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Identity\Application\AdminRolePromotionService;
use App\Domains\Identity\Application\FacilityStaffManagerRoleProvisioner;
use App\Domains\Identity\Application\LinkedStaffAccessService;
use App\Domains\Identity\Application\LinkedStaffGrantEligibility;
use App\Domains\Identity\Contracts\FacilityStaffManagerRoleDefinition;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AdminRolePromotionController
{
    public function index(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationPermissionScope $organizationPermissions,
        IdentityApi $identityApi,
        OrganizationAdminApi $organizationAdminApi,
        OrganizationApi $organizationApi,
        AdminRolePromotionService $adminRolePromotionService,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            $user->can('identity.assignments.read')
                || $organizationPermissions->allows($user, 'identity.assignments.read'),
            403,
            'Unauthorized.',
        );
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'status' => ['nullable', 'in:ALL,PENDING,APPROVED,REJECTED,CANCELLED'],
        ]);
        $statusFilter = $validated['status'] ?? 'ALL';

        $facilityIds = $scopeChecker->selectableFacilities($user);
        $facilityRows = $organizationAdminApi->activeFacilities($context->tenantId(), $facilityIds);
        $enabledCapabilities = $organizationApi->enabledCapabilitiesForFacilities(
            array_column($facilityRows, 'id'),
        );
        $facilities = array_map(
            static fn (array $facility): array => [
                'id' => $facility['id'],
                'organizationId' => $facility['organizationId'],
                'name' => $facility['name'],
                'capabilities' => $enabledCapabilities[$facility['id']] ?? [],
            ],
            $facilityRows,
        );
        $organizationIds = $adminRolePromotionService->organizationIdsFor(
            $user,
            array_values(array_unique(array_column($facilities, 'organizationId'))),
            ['admin.workspace.access', 'identity.assignments.read'],
            $scopeChecker,
        );
        $facilityAdminInviteFacilities = array_values(array_filter(
            $facilities,
            fn (array $facility): bool => $scopeChecker->withinFacilityScope($user, $facility['id'])
                && in_array('tenant_admin', $scopeChecker->effectiveRoles($user, $facility['id']), true)
                && in_array('admin.workspace.access', $scopeChecker->effectivePermissions($user, $facility['id']), true)
                && in_array('identity.assignments.manage', $scopeChecker->effectivePermissions($user, $facility['id']), true),
        ));
        $canInviteFacilityAdmins = $facilityAdminInviteFacilities !== [];
        $staffAccessFacilities = array_values(array_filter(
            $facilities,
            fn (array $facility): bool => $adminRolePromotionService->canManageFacilityStaffAccess(
                $user,
                $facility['id'],
                $scopeChecker,
            ),
        ));
        $unavailableRoleKeys = $identityApi->unavailableFacilityRoleKeys(
            $context->tenantId(),
            array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
        );
        // Offers come from the closed role catalog — never from which rows
        // happen to exist (provisioners guarantee rows at grant). A key is
        // offered when no deactivated/mis-scoped row exists and some
        // staff-accessible facility enables its required capability.
        $availableStaffRoleKeys = array_keys(FacilityUserRoleCatalog::STAFF_ROLES);
        sort($availableStaffRoleKeys);
        $availableStaffRoleKeys = array_values(array_filter(
            $availableStaffRoleKeys,
            fn (string $roleKey): bool => ! in_array($roleKey, $unavailableRoleKeys, true)
                && collect($staffAccessFacilities)->contains(
                    fn (array $facility): bool => FacilityUserRoleCatalog::isAvailableAtFacility(
                        $roleKey,
                        $facility['capabilities'],
                    ),
                ),
        ));
        $invitableRoleKeys = $canInviteFacilityAdmins ? [FacilityUserRoleCatalog::ADMINISTRATOR] : [];
        if ($staffAccessFacilities !== []) {
            $invitableRoleKeys = [
                ...$invitableRoleKeys,
                FacilityUserRoleCatalog::HR_MANAGER,
                ...$availableStaffRoleKeys,
            ];
        }
        $staffAccessFacilityIds = array_column($staffAccessFacilities, 'id');
        $staffFacilityNames = array_column($staffAccessFacilities, 'name', 'id');
        $facilityCapabilities = array_column($facilities, 'capabilities', 'id');
        $staffManagerAssignments = [];
        $staffManagerAssignments = array_map(
            static fn (array $assignment): array => [
                'id' => $assignment['id'],
                'facilityId' => $assignment['facilityId'],
                'facilityName' => $staffFacilityNames[$assignment['facilityId']] ?? 'Facility',
                'userName' => $assignment['userName'],
                'userEmail' => $assignment['userEmail'],
            ],
            $identityApi->activeFacilityRoleAssignments(
                $context->tenantId(),
                FacilityStaffManagerRoleDefinition::KEY,
                $staffAccessFacilityIds,
            ),
        );
        $invitationFacilityIds = array_values(array_unique([
            ...array_column($facilityAdminInviteFacilities, 'id'),
            ...$staffAccessFacilityIds,
        ]));
        $facilityAdminInviteIds = array_column($facilityAdminInviteFacilities, 'id');
        $facilityInvitations = $adminRolePromotionService->facilityInvitations(
            tenantId: $context->tenantId(),
            facilityIds: $invitationFacilityIds,
            facilityAdminInviteIds: $facilityAdminInviteIds,
            staffAccessFacilityIds: $staffAccessFacilityIds,
            facilityCapabilities: $facilityCapabilities,
        );

        $promotionIndexData = $adminRolePromotionService->promotionIndexData(
            tenantId: $context->tenantId(),
            facilityIds: $facilityIds,
            organizationIds: $organizationIds,
            statusFilter: $statusFilter,
            page: (int) ($validated['page'] ?? 1),
        );
        $statusCounts = $promotionIndexData['statusCounts'];
        $paginator = $promotionIndexData['paginator'];

        $organizations = $organizationAdminApi->activeOrganizationsByIds(
            $context->tenantId(),
            $organizationIds,
        );
        $facilityNames = [];
        foreach ($facilities as $facility) {
            $facilityNames[$facility['id']] = $facility['name'];
        }
        $organizationNames = [];
        foreach ($organizations as $organization) {
            $organizationNames[$organization['id']] = $organization['name'];
        }
        $users = $promotionIndexData['users'];
        $promotions = array_map(
            fn (object $promotion): array => $this->promotionData(
                $promotion,
                $users,
                $facilityNames,
                $organizationNames,
            ),
            $paginator->items(),
        );

        return Inertia::render('Admin/Identity/RolePromotions', [
            'currentUserId' => (string) $user->getKey(),
            'facilities' => $facilities,
            'organizations' => $organizations,
            'promotions' => $promotions,
            'total' => $paginator->total(),
            'statusCounts' => $statusCounts,
            'statusFilter' => $statusFilter,
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'canRequest' => $user->can('identity.assignments.manage')
                || $organizationPermissions->allows($user, 'identity.assignments.manage'),
            'canInviteFacilityAdmins' => $canInviteFacilityAdmins,
            'canInviteFacilityUsers' => $canInviteFacilityAdmins || $staffAccessFacilities !== [],
            'facilityAdminInviteFacilities' => $facilityAdminInviteFacilities,
            'invitableFacilityRoles' => FacilityUserRoleCatalog::options($invitableRoleKeys),
            'staffAccessFacilities' => $staffAccessFacilities,
            'staffManagerAssignments' => $staffManagerAssignments,
            'facilityInvitations' => $facilityInvitations,
            'ordinaryRoleOptions' => FacilityUserRoleCatalog::options($availableStaffRoleKeys),
            'ordinaryAssignments' => $adminRolePromotionService->ordinaryAssignments(
                $context,
                $staffAccessFacilityIds,
                $staffFacilityNames,
            ),
        ]);
    }

    public function grantFacilityStaffManager(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffManagerRoleProvisioner $roleProvisioner,
        LinkedStaffGrantEligibility $grantEligibility,
        AdminRolePromotionService $promotions,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $promotions->grantFacilityStaffManager(
            tenantId: $context->tenantId(),
            facilityId: $validated['facility_id'],
            email: $validated['email'],
            actor: $actor,
            scopeChecker: $scopeChecker,
            roleProvisioner: $roleProvisioner,
            grantEligibility: $grantEligibility,
            audit: $audit,
        );

        return $this->redirectToRoleApprovals($request)->with('status', 'Facility staff-management access granted.');
    }

    public function revokeFacilityStaffManager(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AdminRolePromotionService $promotions,
        AuditLogger $audit,
        string $assignment,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $promotions->revokeFacilityStaffManager(
            tenantId: $context->tenantId(),
            assignmentId: $assignment,
            actor: $actor,
            scopeChecker: $scopeChecker,
            audit: $audit,
        );

        return $this->redirectToRoleApprovals($request)->with('status', 'Facility staff-management access revoked.');
    }

    public function store(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        OrganizationPermissionScope $organizationPermissions,
        IdentityApi $identityApi,
        AdminRolePromotionService $adminRolePromotionService,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless(
            $actor->can('identity.assignments.manage')
                || $organizationPermissions->allows($actor, 'identity.assignments.manage'),
            403,
            'Unauthorized.',
        );

        $validated = $request->validate([
            'role_key' => ['required', Rule::in(['tenant_admin', 'facility_admin'])],
            'target_user_id' => ['nullable', 'uuid', 'required_without:target_email'],
            'target_email' => ['nullable', 'email', 'max:255', 'required_without:target_user_id'],
            'organization_id' => ['nullable', 'uuid'],
            'facility_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $tenantId = $context->tenantId();
        abort_if(
            isset($validated['target_user_id'], $validated['target_email']),
            422,
            'Provide either an account email or a target account identifier, not both.',
        );
        $targetUserId = $validated['target_user_id']
            ?? $adminRolePromotionService->accountIdByEmail($tenantId, $validated['target_email']);
        abort_unless(is_string($targetUserId), 422, 'No account with that email address is available in this tenant.');
        $roleKey = $validated['role_key'];
        $organizationId = null;
        $facilityId = null;

        if ($roleKey === 'tenant_admin') {
            $activeOrganizations = $adminRolePromotionService->activeOrganizationIds($tenantId);
            abort_unless(
                count($activeOrganizations) === 1
                    && ($validated['organization_id'] ?? null) === $activeOrganizations[0]
                    && ($validated['facility_id'] ?? null) === null,
                422,
                'Tenant Admin promotions require the tenant’s sole active organization and no facility.',
            );
            $organizationId = $activeOrganizations[0];
        } else {
            abort_unless(
                ($validated['facility_id'] ?? null) !== null
                    && ($validated['organization_id'] ?? null) === null,
                422,
                'Facility Admin promotions require one facility and no organization selector.',
            );
            $facility = $adminRolePromotionService->activeFacility($tenantId, $validated['facility_id']);
            abort_unless($facility !== null, 422, 'The selected facility is unavailable.');
            $facilityId = $facility['id'];
            $organizationId = $facility['organizationId'];
        }

        $adminRolePromotionService->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
        abort_if((string) $actor->getKey() === $targetUserId, 422, 'Administrators cannot request their own promotion.');

        $role = $identityApi->protectedAdministrativeRoleByKey(
            (string) $tenantId,
            $roleKey,
            $roleKey === 'tenant_admin' ? 'ORG' : 'FACILITY',
        );
        abort_unless($role !== null, 409, 'The protected administrative role is not available.');

        $adminRolePromotionService->requestPromotion(
            tenantId: (string) $tenantId,
            organizationId: $organizationId,
            facilityId: $facilityId,
            targetUserId: $targetUserId,
            role: $role,
            reason: $validated['reason'],
            actor: $actor,
            audit: $audit,
        );

        return $this->redirectToRoleApprovals($request)->with('status', 'Promotion request submitted for independent approval.');
    }

    public function approve(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        LinkedStaffGrantEligibility $grantEligibility,
        AuditLogger $audit,
        OrganizationPermissionScope $organizationPermissions,
        IdentityApi $identityApi,
        AdminRolePromotionService $adminRolePromotionService,
        string $promotion,
        ?string $staff = null,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $isHrInvitationApproval = $request->routeIs('hr.staff.access-requests.approve');
        $hrStaffId = $isHrInvitationApproval ? $staff : null;
        abort_unless(! $isHrInvitationApproval || $hrStaffId !== null, 404);
        if ($isHrInvitationApproval) {
            $adminRolePromotionService->authorizeFacilityInvitationApproval(
                context: $context,
                scopeChecker: $scopeChecker,
                actor: $actor,
                promotionId: $promotion,
                staffId: $hrStaffId,
            );
        } else {
            abort_unless(
                $actor->can('identity.assignments.manage')
                    || $organizationPermissions->allows($actor, 'identity.assignments.manage'),
                403,
                'Unauthorized.',
            );
        }

        $selfApproved = $adminRolePromotionService->approvePromotion(
            context: $context,
            actor: $actor,
            scopeChecker: $scopeChecker,
            grantEligibility: $grantEligibility,
            audit: $audit,
            identityApi: $identityApi,
            promotionId: $promotion,
        );

        $status = $selfApproved ? 'Invited facility access approved.' : 'Administrative access change approved.';

        return $isHrInvitationApproval
            ? redirect()->route(
                $request->input('return_to') === 'access-requests'
                    ? 'hr.staff.access-requests'
                    : 'hr.staff.show',
                $request->input('return_to') === 'access-requests' ? [] : ['staff' => $hrStaffId],
            )->with('status', $status)
            : $this->redirectToRoleApprovals($request)->with('status', $status);
    }

    public function reject(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        OrganizationPermissionScope $organizationPermissions,
        AdminRolePromotionService $adminRolePromotionService,
        string $promotion,
        ?string $staff = null,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $isHrInvitationWithdrawal = $request->routeIs('hr.staff.access-requests.reject');
        abort_unless(! $isHrInvitationWithdrawal || $staff !== null, 404);
        if (! $isHrInvitationWithdrawal) {
            abort_unless(
                $actor->can('identity.assignments.manage')
                    || $organizationPermissions->allows($actor, 'identity.assignments.manage'),
                403,
                'Unauthorized.',
            );
        }
        $validated = $request->validate([
            'decision_reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $withdrawn = $adminRolePromotionService->rejectPromotion(
            context: $context,
            scopeChecker: $scopeChecker,
            actor: $actor,
            promotionId: $promotion,
            decisionReason: $validated['decision_reason'],
            isHrInvitationWithdrawal: $isHrInvitationWithdrawal,
            staffId: $staff,
            audit: $audit,
        );

        return ($isHrInvitationWithdrawal
            ? redirect()->route('hr.staff.access-requests')
            : $this->redirectToRoleApprovals($request))->with(
                'status',
                $withdrawn ? 'Administrative access request withdrawn.' : 'Administrative access change rejected.',
            );
    }

    /**
     * Grant a supported ordinary facility role to an existing verified user.
     *
     * Ordinary roles only (FacilityUserRoleCatalog::STAFF_ROLES). Elevated
     * roles (tenant_admin, facility_admin, facility_staff_manager) must use
     * their existing promotion/grant workflows. Immediate ACTIVE grant with
     * audit; no second approval. HR user-to-staff links never grant access.
     */
    public function grantFacilityOrdinary(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        LinkedStaffGrantEligibility $grantEligibility,
        OrganizationApi $organizationApi,
        IdentityApi $identityApi,
        AuditLogger $audit,
        AdminRolePromotionService $adminRolePromotionService,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
            'role_key' => ['required', 'string', Rule::in(array_keys(FacilityUserRoleCatalog::STAFF_ROLES))],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $roleKey = $validated['role_key'];

        $facility = $adminRolePromotionService->activeFacility(
            $context->tenantId(),
            $validated['facility_id'],
        );
        abort_unless($facility !== null, 422, 'The selected facility is unavailable.');
        $facilityId = $facility['id'];
        $organizationId = $facility['organizationId'];
        $adminRolePromotionService->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
        $enabledCapabilities = $organizationApi->enabledCapabilitiesForFacilities([$facilityId])[$facilityId] ?? [];
        abort_unless(
            FacilityUserRoleCatalog::isAvailableAtFacility(
                $roleKey,
                $enabledCapabilities,
            ),
            422,
            'The selected role requires a capability that is not enabled at this facility.',
        );

        // User-correctable conflicts return inline (`errors.email` in the
        // grant workspace) + a bottom-right toast (`flash.error`) — never a
        // 409 exception page and never the page-top alert.
        $conflictResponse = static fn (string $message): RedirectResponse => back()
            ->withErrors(['email' => $message])
            ->with('error', $message);

        try {
            $conflict = $adminRolePromotionService->grantOrdinaryFacilityRole(
                context: $context,
                actor: $actor,
                organizationId: $organizationId,
                facilityId: $facilityId,
                targetEmail: $validated['email'],
                roleKey: $roleKey,
                reason: $validated['reason'],
                scopeChecker: $scopeChecker,
                grantEligibility: $grantEligibility,
                identityApi: $identityApi,
                audit: $audit,
            );
            if ($conflict !== null) {
                return $conflictResponse($conflict);
            }
        } catch (ValidationException $e) {
            $message = (string) (is_array($e->errors()['email'] ?? null)
                ? ($e->errors()['email'][0] ?? 'This grant can no longer be completed.')
                : ($e->errors()['email'] ?? 'This grant can no longer be completed.'));

            return back()->withErrors($e->errors())->with('error', $message);
        }

        $roleLabel = FacilityUserRoleCatalog::label($roleKey) ?? $roleKey;

        return $this->redirectToRoleApprovals($request)->with('status', "{$roleLabel} access granted at the selected facility.");
    }

    public function ordinaryGrantTargets(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AdminRolePromotionService $adminRolePromotionService,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $accounts = $adminRolePromotionService->ordinaryGrantTargets(
            actor: $actor,
            tenantId: $context->tenantId(),
            facilityId: $validated['facility_id'],
            search: $validated['search'],
            context: $context,
            scopeChecker: $scopeChecker,
        );

        return response()->json(['data' => $accounts]);
    }

    /**
     * Revoke an ordinary facility assignment. Admin/HR-manager assignments
     * must use their existing workflows. Immediate REVOKED + session
     * revocation; never restores automatically.
     */
    public function revokeFacilityOrdinary(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        LinkedStaffAccessService $access,
        AuditLogger $audit,
        AdminRolePromotionService $adminRolePromotionService,
        string $assignment,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $adminRolePromotionService->revokeOrdinaryFacilityRole(
            context: $context,
            scopeChecker: $scopeChecker,
            actor: $actor,
            assignmentId: $assignment,
            reason: $validated['reason'],
            access: $access,
            audit: $audit,
        );

        return $this->redirectToRoleApprovals($request)->with('status', 'Facility access revoked. It takes effect on the next authorization check; device sessions were revoked.');
    }

    /**
     * Return to the role-approvals page exactly as the actor left it. The
     * referer includes the active tab, status filter, and page number; falling
     * back to the named route keeps direct/test requests working.
     */
    private function redirectToRoleApprovals(Request $request): RedirectResponse
    {
        $fallback = route('admin.identity.role-promotions');
        $referer = $request->headers->get('referer');

        if (is_string($referer) && $referer !== '') {
            $base = rtrim($fallback, '/');
            if ($referer === $base || str_starts_with($referer, $base.'?')) {
                return redirect()->to($referer);
            }
        }

        return redirect()->to($fallback);
    }

    /**
     * @param  array<string, array{name: string, email: string}>  $users
     * @param  array<string, string>  $facilityNames
     * @param  array<string, string>  $organizationNames
     * @return array{
     *     id: string,
     *     facilityId: string|null,
     *     targetUserId: string,
     *     roleKey: string,
     *     scopeType: string,
     *     scopeName: string|null,
     *     action: string,
     *     status: string,
     *     reason: string,
     *     decisionReason: string|null,
     *     requestedBy: string,
     *     requestOrigin: string|null,
     *     decidedBy: string|null,
     *     createdAt: string
     * }
     */
    private function promotionData(
        object $promotion,
        array $users,
        array $facilityNames,
        array $organizationNames,
    ): array {
        $values = get_object_vars($promotion);
        $target = $users[(string) $values['target_user_id']] ?? null;
        $requester = $users[(string) $values['requested_by']] ?? null;
        $decider = $values['decided_by'] === null ? null : ($users[(string) $values['decided_by']] ?? null);
        $scopeId = $values['scope_type'] === 'ORG'
            ? (string) $values['organization_id']
            : ($values['facility_id'] === null ? null : (string) $values['facility_id']);
        $scopeNames = $values['scope_type'] === 'ORG' ? $organizationNames : $facilityNames;

        return [
            'id' => (string) $values['id'],
            'facilityId' => $values['facility_id'] === null ? null : (string) $values['facility_id'],
            'targetUserId' => (string) $values['target_user_id'],
            'targetName' => $target['name'] ?? null,
            'targetEmail' => $target['email'] ?? null,
            'roleKey' => (string) $values['role_key'],
            'scopeType' => (string) $values['scope_type'],
            'scopeName' => $scopeId === null ? null : ($scopeNames[$scopeId] ?? null),
            'action' => isset($values['action']) ? (string) $values['action'] : 'PROMOTE',
            'status' => (string) $values['status'],
            'reason' => (string) $values['reason'],
            'decisionReason' => $values['decision_reason'] === null ? null : (string) $values['decision_reason'],
            'requestedBy' => (string) $values['requested_by'],
            'requestedByName' => $requester['name'] ?? null,
            'requestedByEmail' => $requester['email'] ?? null,
            'requestOrigin' => $values['request_origin'] === null ? null : (string) $values['request_origin'],
            'decidedBy' => $values['decided_by'] === null ? null : (string) $values['decided_by'],
            'decidedByName' => $decider['name'] ?? null,
            'createdAt' => CarbonImmutable::parse($values['created_at'])->toIso8601String(),
        ];
    }
}
