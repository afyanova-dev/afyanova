<?php

declare(strict_types=1);

namespace App\Domains\Identity\Presentation\Http\Controller;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Identity\Application\BillingAdminRoleProvisioner;
use App\Domains\Identity\Application\CashierRoleProvisioner;
use App\Domains\Identity\Application\ClinicianRoleProvisioner;
use App\Domains\Identity\Application\FacilityStaffManagerRoleProvisioner;
use App\Domains\Identity\Application\LaboratoryTechnicianRoleProvisioner;
use App\Domains\Identity\Application\LinkedStaffAccessService;
use App\Domains\Identity\Application\LinkedStaffGrantEligibility;
use App\Domains\Identity\Application\NurseRoleProvisioner;
use App\Domains\Identity\Application\PharmacistRoleProvisioner;
use App\Domains\Identity\Application\ReceptionistRoleProvisioner;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $organizationIds = $this->organizationIdsFor(
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
            fn (array $facility): bool => $this->canManageFacilityStaffAccess(
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
        $facilityInvitations = [];
        if ($invitationFacilityIds !== []) {
            $facilityInvitations = DB::table('aut_admin_bootstrap_tokens as invitation')
                ->join('users', function ($join) use ($context): void {
                    $join->on('users.id', '=', 'invitation.user_id')
                        ->where('users.tenant_id', '=', $context->tenantId());
                })
                ->join('org_facilities as facility', function ($join) use ($context): void {
                    $join->on('facility.id', '=', 'invitation.facility_id')
                        ->where('facility.tenant_id', '=', $context->tenantId());
                })
                ->where('invitation.tenant_id', $context->tenantId())
                ->whereIn('invitation.facility_id', $invitationFacilityIds)
                ->whereIn('invitation.purpose', ['FACILITY_ROLE', 'FACILITY_ADMIN'])
                ->orderByDesc('invitation.created_at')
                ->limit(100)
                ->get([
                    'invitation.id',
                    'invitation.facility_id',
                    'invitation.role_key',
                    'invitation.role_keys',
                    'invitation.purpose',
                    'invitation.expires_at',
                    'invitation.consumed_at',
                    'invitation.created_at',
                    'facility.name as facility_name',
                    'users.name as user_name',
                    'users.email as user_email',
                    'users.email_verified_at',
                    'users.status as user_status',
                ])
                ->map(static function (object $invitation) use (
                    $facilityAdminInviteIds,
                    $staffAccessFacilityIds,
                    $facilityCapabilities,
                ): array {
                    $roleKeys = is_string($invitation->role_keys)
                        ? json_decode($invitation->role_keys, true)
                        : null;
                    if (! is_array($roleKeys) || $roleKeys === []) {
                        $roleKeys = [
                            (string) ($invitation->role_key
                                ?? ($invitation->purpose === 'FACILITY_ADMIN'
                                    ? FacilityUserRoleCatalog::ADMINISTRATOR
                                    : '')),
                        ];
                    }
                    $roleLabels = array_values(array_filter(array_map(
                        static fn ($key): ?string => is_string($key)
                            ? FacilityUserRoleCatalog::label($key)
                            : null,
                        $roleKeys,
                    )));
                    $expiresAt = CarbonImmutable::parse((string) $invitation->expires_at);
                    $status = $invitation->consumed_at !== null
                        ? 'SETUP_COMPLETE'
                        : (now()->greaterThanOrEqualTo($expiresAt) ? 'EXPIRED' : 'AWAITING_SETUP');
                    $canResend = $status === 'EXPIRED'
                        && $invitation->email_verified_at === null
                        && $invitation->user_status === 'ACTIVE'
                        && $roleKeys !== [];
                    foreach ($roleKeys as $roleKey) {
                        if (! is_string($roleKey) || FacilityUserRoleCatalog::label($roleKey) === null) {
                            $canResend = false;
                            break;
                        }
                        if (! FacilityUserRoleCatalog::isAvailableAtFacility(
                            $roleKey,
                            $facilityCapabilities[(string) $invitation->facility_id] ?? [],
                        )) {
                            $canResend = false;
                            break;
                        }
                        $canManageFacilityRoles = in_array((string) $invitation->facility_id, $facilityAdminInviteIds, true)
                            || in_array((string) $invitation->facility_id, $staffAccessFacilityIds, true);
                        if (($roleKey === FacilityUserRoleCatalog::ADMINISTRATOR
                                && ! in_array((string) $invitation->facility_id, $facilityAdminInviteIds, true))
                            || ($roleKey !== FacilityUserRoleCatalog::ADMINISTRATOR && ! $canManageFacilityRoles)) {
                            $canResend = false;
                            break;
                        }
                    }

                    return [
                        'id' => (string) $invitation->id,
                        'userName' => (string) $invitation->user_name,
                        'userEmail' => (string) $invitation->user_email,
                        'roles' => $roleLabels,
                        'facilityName' => (string) $invitation->facility_name,
                        'status' => $status,
                        'createdAt' => (string) $invitation->created_at,
                        'canResend' => $canResend,
                    ];
                })
                ->all();
        }

        $query = DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $context->tenantId());
        if ($facilityIds === [] && $organizationIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where(function ($query) use ($facilityIds, $organizationIds): void {
                if ($facilityIds !== []) {
                    $query->whereIn('facility_id', $facilityIds);
                }
                if ($organizationIds !== []) {
                    $method = $facilityIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('organization_id', $organizationIds);
                }
            });
        }

        $statusCounts = ['ALL' => (clone $query)->count()];
        foreach (['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED'] as $status) {
            $statusCounts[$status] = (clone $query)->where('status', $status)->count();
        }
        $filteredQuery = clone $query;
        if ($statusFilter !== 'ALL') {
            $filteredQuery->where('status', $statusFilter);
        }

        $paginator = $filteredQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25, [
                'id',
                'facility_id',
                'organization_id',
                'target_user_id',
                'role_key',
                'scope_type',
                'action',
                'status',
                'reason',
                'decision_reason',
                'requested_by',
                'decided_by',
                'request_origin',
                'created_at',
            ], 'page', (int) ($validated['page'] ?? 1));

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
        $userIds = collect($paginator->items())
            ->flatMap(static fn (object $promotion): array => [
                $promotion->target_user_id,
                $promotion->requested_by,
                $promotion->decided_by,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $platformActorIds = collect($paginator->items())
            ->flatMap(static fn (object $promotion): array => [
                $promotion->requested_by,
                $promotion->decided_by,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $users = DB::table('users')
            ->where(function ($query) use ($context, $userIds, $platformActorIds): void {
                $query
                    ->where(fn ($tenantUsers) => $tenantUsers
                        ->where('tenant_id', $context->tenantId())
                        ->whereIn('id', $userIds))
                    ->orWhere(function ($platformUsers) use ($platformActorIds): void {
                        $platformUsers
                            ->whereNull('tenant_id')
                            ->whereIn('id', $platformActorIds)
                            ->whereExists(function ($admins): void {
                                $admins
                                    ->selectRaw('1')
                                    ->from('platform_super_admins')
                                    ->whereColumn('platform_super_admins.user_id', 'users.id')
                                    ->where('platform_super_admins.status', 'ACTIVE');
                            });
                    });
            })
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(static fn (object $user): array => [(string) $user->id => [
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ]])
            ->all();
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
            'ordinaryAssignments' => $this->ordinaryAssignments($context, $staffAccessFacilityIds, $staffFacilityNames),
        ]);
    }

    public function grantFacilityStaffManager(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffManagerRoleProvisioner $roleProvisioner,
        LinkedStaffGrantEligibility $grantEligibility,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $facility = DB::table('org_facilities')
            ->where('tenant_id', $context->tenantId())
            ->where('status', 'ACTIVE')
            ->where('id', $validated['facility_id'])
            ->first(['id', 'organization_id']);
        abort_unless($facility !== null, 422, 'The selected facility is unavailable.');
        $facilityId = (string) $facility->id;
        $this->assertCanManageFacilityStaffAccess($actor, $facilityId, $scopeChecker);

        $target = User::query()
            ->where('tenant_id', $context->tenantId())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($validated['email']))])
            ->first();
        abort_unless($target instanceof User && $target->isActive() && $target->email_verified_at !== null, 422, 'No active, verified account with that email exists in this tenant.');
        abort_unless(
            $scopeChecker->withinFacilityScope($target, $facilityId),
            422,
            'The user must already have an active access assignment at the selected facility.',
        );

        DB::transaction(function () use ($actor, $context, $facilityId, $facility, $target, $roleProvisioner, $grantEligibility, $audit): void {
            $eligibleTarget = $grantEligibility->lockEligibleTarget(
                (string) $context->tenantId(),
                (string) $target->getKey(),
            );
            abort_unless($eligibleTarget->isActive() && $eligibleTarget->email_verified_at !== null, 409, 'The target account is no longer eligible.');
            $roleId = $roleProvisioner->provision(
                tenantId: $context->tenantId(),
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
            );
            $alreadyAssigned = DB::table('idn_access_assignments')
                ->where('tenant_id', $context->tenantId())
                ->where('user_id', $eligibleTarget->getKey())
                ->where('role_id', $roleId)
                ->where('facility_id', $facilityId)
                ->where('status', 'ACTIVE')
                ->exists();
            abort_if($alreadyAssigned, 409, 'This user already has facility staff-management access.');

            $assignmentId = (string) Str::uuid7();
            DB::table('idn_access_assignments')->insert([
                'id' => $assignmentId,
                'tenant_id' => $context->tenantId(),
                'user_id' => $eligibleTarget->getKey(),
                'role_id' => $roleId,
                'organization_id' => (string) $facility->organization_id,
                'facility_id' => $facilityId,
                'status' => 'ACTIVE',
                'is_primary' => false,
                'granted_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $audit->recordFor(
                tenantId: $context->tenantId(),
                action: 'identity.facility_staff_manager.granted',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: ['role' => FacilityStaffManagerRoleDefinition::KEY],
                targetType: 'user',
                targetId: (string) $eligibleTarget->getKey(),
            );
        });

        return $this->redirectToRoleApprovals($request)->with('status', 'Facility staff-management access granted.');
    }

    public function revokeFacilityStaffManager(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        string $assignment,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        DB::transaction(function () use ($actor, $context, $scopeChecker, $audit, $assignment): void {
            $row = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $context->tenantId())
                ->where('assignment.id', $assignment)
                ->where('role.key', FacilityStaffManagerRoleDefinition::KEY)
                ->lockForUpdate()
                ->first([
                    'assignment.id',
                    'assignment.user_id',
                    'assignment.facility_id',
                    'assignment.status',
                ]);
            abort_unless($row !== null && $row->facility_id !== null, 404);
            $facilityId = (string) $row->facility_id;
            $this->assertCanManageFacilityStaffAccess($actor, $facilityId, $scopeChecker);
            abort_if($row->status !== 'ACTIVE', 409, 'This facility staff-management assignment is no longer active.');

            DB::table('idn_access_assignments')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $row->id)
                ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $audit->recordFor(
                tenantId: $context->tenantId(),
                action: 'identity.facility_staff_manager.revoked',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: ['role' => FacilityStaffManagerRoleDefinition::KEY],
                targetType: 'user',
                targetId: (string) $row->user_id,
            );
        });

        return $this->redirectToRoleApprovals($request)->with('status', 'Facility staff-management access revoked.');
    }

    public function store(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        OrganizationPermissionScope $organizationPermissions,
        IdentityApi $identityApi,
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
        $targetUserId = $validated['target_user_id'] ?? User::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($validated['target_email']))])
            ->value('id');
        abort_unless(is_string($targetUserId), 422, 'No account with that email address is available in this tenant.');
        $roleKey = $validated['role_key'];
        $organizationId = null;
        $facilityId = null;

        if ($roleKey === 'tenant_admin') {
            $activeOrganizations = DB::table('org_organizations')
                ->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();
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
            $facility = DB::table('org_facilities')
                ->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')
                ->where('id', $validated['facility_id'])
                ->first(['id', 'organization_id']);
            abort_unless($facility !== null, 422, 'The selected facility is unavailable.');
            $facilityId = (string) $facility->id;
            $organizationId = (string) $facility->organization_id;
        }

        $this->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
        abort_if((string) $actor->getKey() === $targetUserId, 422, 'Administrators cannot request their own promotion.');

        $role = $identityApi->protectedAdministrativeRoleByKey(
            (string) $tenantId,
            $roleKey,
            $roleKey === 'tenant_admin' ? 'ORG' : 'FACILITY',
        );
        abort_unless($role !== null, 409, 'The protected administrative role is not available.');

        $promotionId = (string) Str::uuid7();
        DB::transaction(function () use (
            $tenantId,
            $organizationId,
            $facilityId,
            $validated,
            $role,
            $actor,
            $promotionId,
            $audit,
            $targetUserId,
        ): void {
            $target = User::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($targetUserId)
                ->lockForUpdate()
                ->first();
            abort_unless($target instanceof User && $target->isActive() && $target->email_verified_at !== null, 422, 'The target account must be active and email-verified.');

            $scopeQuery = DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $target->getKey())
                ->where('role_id', $role->id)
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()));
            if ($facilityId === null) {
                $scopeQuery->where('organization_id', $organizationId)->whereNull('facility_id');
            } else {
                $scopeQuery->where('facility_id', $facilityId);
            }
            abort_if($scopeQuery->exists(), 409, 'The target already has this administrative role in the requested scope.');

            $pendingQuery = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $tenantId)
                ->where('target_user_id', $target->getKey())
                ->where('role_id', $role->id)
                ->where('status', 'PENDING');
            if ($facilityId === null) {
                $pendingQuery->where('organization_id', $organizationId)->whereNull('facility_id');
            } else {
                $pendingQuery->where('facility_id', $facilityId);
            }
            abort_if($pendingQuery->exists(), 409, 'A promotion request for this role and scope is already pending.');

            DB::table('idn_admin_role_promotion_requests')->insert([
                'id' => $promotionId,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'facility_id' => $facilityId,
                'target_user_id' => $target->getKey(),
                'role_id' => $role->id,
                'role_key' => $role->key,
                'scope_type' => $role->scopeType,
                'status' => 'PENDING',
                'reason' => $validated['reason'],
                'requested_by' => $actor->getKey(),
                'request_origin' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->recordFor(
                tenantId: $tenantId,
                action: 'identity.admin_role_promotion.requested',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                reason: $validated['reason'],
                metadata: [
                    'promotion_request' => $promotionId,
                    'role' => $role->key,
                    'scope' => $role->scopeType,
                    'status' => 'PENDING',
                    'request_origin' => null,
                    'self_approval_allowed' => false,
                ],
                targetType: 'user',
                targetId: (string) $target->getKey(),
            );
        });

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
        string $promotion,
        ?string $staff = null,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $isHrInvitationApproval = $request->routeIs('hr.staff.access-requests.approve');
        $hrStaffId = $isHrInvitationApproval ? $staff : null;
        abort_unless(! $isHrInvitationApproval || $hrStaffId !== null, 404);
        if ($isHrInvitationApproval) {
            $invitationRequest = DB::table('idn_admin_role_promotion_requests as request')
                ->join('hr_staff_user_links as link', function ($join) use ($context, $hrStaffId): void {
                    $join->on('link.user_id', '=', 'request.target_user_id')
                        ->where('link.tenant_id', '=', $context->tenantId())
                        ->where('link.staff_id', '=', $hrStaffId);
                })
                ->where('request.tenant_id', $context->tenantId())
                ->where('request.id', $promotion)
                ->where('request.requested_by', $actor->getKey())
                ->where('request.request_origin', 'FACILITY_INVITATION')
                ->where('request.action', 'PROMOTE')
                ->where('request.status', 'PENDING')
                ->whereIn('request.role_key', array_keys(FacilityUserRoleCatalog::STAFF_ROLES))
                ->first(['request.facility_id']);
            abort_unless($invitationRequest !== null && is_string($invitationRequest->facility_id), 404);
            $facilityId = (string) $invitationRequest->facility_id;
            abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
            $requestRow = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $promotion)
                ->first(['requested_by']);
            abort_unless($requestRow !== null, 404);
            abort_if((string) $requestRow->requested_by !== (string) $actor->getKey(), 403, 'Only the administrator who sent this invitation may approve the access request.');
            $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
            $canManageIdentity = in_array('admin.workspace.access', $permissions, true)
                && in_array('identity.assignments.manage', $permissions, true);
            $canManageHrInvitations = in_array('hr.staff.manage', $permissions, true)
                && in_array('hr.staff_user_link.manage', $permissions, true);
            abort_unless($canManageIdentity || $canManageHrInvitations, 403, 'Unauthorized.');
        } else {
            abort_unless(
                $actor->can('identity.assignments.manage')
                    || $organizationPermissions->allows($actor, 'identity.assignments.manage'),
                403,
                'Unauthorized.',
            );
        }

        $selfApproved = false;
        DB::transaction(function () use ($actor, $context, $scopeChecker, $grantEligibility, $audit, $promotion, $identityApi, &$selfApproved): void {
            $requestRow = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $promotion)
                ->lockForUpdate()
                ->first();
            abort_unless($requestRow !== null, 404);
            abort_unless($requestRow->status === 'PENDING', 409, 'This promotion request has already been decided.');

            $organizationId = (string) $requestRow->organization_id;
            $facilityId = $requestRow->facility_id === null ? null : (string) $requestRow->facility_id;
            $isRequester = (string) $actor->getKey() === (string) $requestRow->requested_by;
            $isTarget = (string) $actor->getKey() === (string) $requestRow->target_user_id;
            $selfApproved = $isRequester
                && $this->allowsInvitationRequesterDecision($requestRow, $actor, $facilityId, $scopeChecker);

            abort_if(
                $isRequester && ! $selfApproved,
                403,
                'A different administrator must approve this request.',
            );
            abort_if(
                $isTarget,
                403,
                'The target cannot approve their own access request.',
            );

            if (! $selfApproved) {
                $this->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
            }

            $role = $identityApi->protectedAdministrativeRoleById(
                (string) $context->tenantId(),
                (string) $requestRow->role_id,
                (string) $requestRow->role_key,
            );
            abort_unless($role !== null, 409, 'The protected administrative role is no longer active.');

            if (($requestRow->action ?? 'PROMOTE') === 'REVOKE') {
                $isOrganizationAdminRevocation = $role->key === 'tenant_admin'
                    && $facilityId === null
                    && $role->scopeType === 'ORG';
                $isFacilityAdminRevocation = $role->key === 'facility_admin'
                    && $facilityId !== null
                    && $role->scopeType === 'FACILITY';
                abort_unless(
                    ($isOrganizationAdminRevocation || $isFacilityAdminRevocation)
                        && $requestRow->assignment_id !== null,
                    409,
                    'The revocation request is incomplete or no longer valid.',
                );
                $target = User::query()
                    ->where('tenant_id', $context->tenantId())
                    ->whereKey($requestRow->target_user_id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($target instanceof User, 409, 'The target account is no longer available.');
                if (($requestRow->request_origin ?? null) === 'HR_LIFECYCLE') {
                    $staffStillRequiresReview = DB::table('hr_staff_user_links as link')
                        ->join('hr_staff as staff', function ($join): void {
                            $join->on('staff.id', '=', 'link.staff_id')
                                ->on('staff.tenant_id', '=', 'link.tenant_id');
                        })
                        ->where('link.tenant_id', $context->tenantId())
                        ->where('link.user_id', $requestRow->target_user_id)
                        ->where('link.staff_id', $requestRow->hr_staff_id)
                        ->whereIn('staff.status', ['SUSPENDED', 'TERMINATED'])
                        ->exists();
                    abort_unless($staffStillRequiresReview, 409, 'The linked staff member is no longer suspended or terminated.');
                }
                $assignment = DB::table('idn_access_assignments')
                    ->where('tenant_id', $context->tenantId())
                    ->where('id', $requestRow->assignment_id)
                    ->where('user_id', $requestRow->target_user_id)
                    ->where('role_id', $role->id)
                    ->when(
                        $facilityId === null,
                        fn ($query) => $query->where('organization_id', $organizationId)->whereNull('facility_id'),
                        fn ($query) => $query->where('facility_id', $facilityId),
                    )
                    ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
                    ->lockForUpdate()
                    ->first();
                abort_unless($assignment !== null, 409, 'The administrative assignment is no longer active.');

                DB::table('idn_access_assignments')
                    ->where('id', $assignment->id)
                    ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                DB::table('idn_admin_role_promotion_requests')
                    ->where('id', $requestRow->id)
                    ->update([
                        'status' => 'APPROVED',
                        'decided_by' => $actor->getKey(),
                        'decided_at' => now(),
                        'updated_at' => now(),
                    ]);
                $sessionsRevoked = DB::table('aut_sessions')
                    ->where('tenant_id', $context->tenantId())
                    ->where('user_id', $requestRow->target_user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
                $audit->recordFor(
                    tenantId: $context->tenantId(),
                    action: 'identity.admin_role_promotion.revocation_approved',
                    actorId: (string) $actor->getKey(),
                    facilityId: $facilityId,
                    reason: (string) $requestRow->reason,
                    metadata: [
                        'promotion_request' => (string) $requestRow->id,
                        'assignment' => (string) $assignment->id,
                        'role' => $role->key,
                        'status' => 'APPROVED',
                        'sessions_revoked' => $sessionsRevoked,
                    ],
                    targetType: 'assignment',
                    targetId: (string) $assignment->id,
                );

                return;
            }

            $target = $grantEligibility->lockEligibleTarget(
                (string) $context->tenantId(),
                (string) $requestRow->target_user_id,
            );
            abort_unless($target->isActive() && $target->email_verified_at !== null, 409, 'The target account is no longer eligible.');

            $assignment = DB::table('idn_access_assignments')
                ->where('tenant_id', $context->tenantId())
                ->where('user_id', $target->getKey())
                ->where('role_id', $role->id)
                ->where('status', 'ACTIVE')
                ->when(
                    $facilityId === null,
                    fn ($query) => $query->where('organization_id', $organizationId)->whereNull('facility_id'),
                    fn ($query) => $query->where('facility_id', $facilityId),
                )
                ->exists();
            abort_if($assignment, 409, 'The target already has this administrative role in the requested scope.');

            $assignmentId = (string) Str::uuid7();
            DB::table('idn_access_assignments')->insert([
                'id' => $assignmentId,
                'tenant_id' => $context->tenantId(),
                'user_id' => $target->getKey(),
                'role_id' => $role->id,
                'organization_id' => $organizationId,
                'facility_id' => $facilityId,
                'status' => 'ACTIVE',
                'is_primary' => (bool) ($requestRow->is_primary ?? false),
                'granted_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('idn_admin_role_promotion_requests')
                ->where('id', $requestRow->id)
                ->update([
                    'status' => 'APPROVED',
                    'decided_by' => $actor->getKey(),
                    'assignment_id' => $assignmentId,
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);

            $audit->recordFor(
                tenantId: $context->tenantId(),
                action: 'identity.admin_role_promotion.approved',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: [
                    'promotion_request' => (string) $requestRow->id,
                    'assignment' => $assignmentId,
                    'role' => $role->key,
                    'scope' => $role->scopeType,
                    'status' => 'APPROVED',
                    'approval_mode' => $selfApproved
                        ? ($this->invitingAdminApprovalMode($actor, $facilityId, $scopeChecker))
                        : 'INDEPENDENT_ADMIN',
                    'self_approved' => $selfApproved,
                    'request_origin' => $requestRow->request_origin === null ? null : (string) $requestRow->request_origin,
                ],
                targetType: 'user',
                targetId: (string) $target->getKey(),
            );
        });

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

        $withdrawn = false;
        DB::transaction(function () use ($actor, $context, $scopeChecker, $audit, $promotion, $staff, $validated, $isHrInvitationWithdrawal, &$withdrawn): void {
            $requestRow = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $promotion)
                ->lockForUpdate()
                ->first();
            abort_unless($requestRow !== null, 404);
            abort_unless($requestRow->status === 'PENDING', 409, 'This promotion request has already been decided.');

            $facilityId = $requestRow->facility_id === null ? null : (string) $requestRow->facility_id;
            $isRequester = (string) $actor->getKey() === (string) $requestRow->requested_by;
            $isTarget = (string) $actor->getKey() === (string) $requestRow->target_user_id;
            $withdrawn = $isRequester
                && $this->allowsInvitationRequesterDecision($requestRow, $actor, $facilityId, $scopeChecker);

            if ($isHrInvitationWithdrawal) {
                abort_unless($withdrawn && is_string($facilityId), 403, 'Only the invitation requester may withdraw this request.');
                abort_unless(
                    DB::table('hr_staff_user_links')
                        ->where('tenant_id', $context->tenantId())
                        ->where('staff_id', $staff)
                        ->where('user_id', $requestRow->target_user_id)
                        ->exists(),
                    404,
                );
                $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
                $canManageIdentity = in_array('admin.workspace.access', $permissions, true)
                    && in_array('identity.assignments.manage', $permissions, true);
                $canManageHrInvitations = in_array('hr.staff.manage', $permissions, true)
                    && in_array('hr.staff_user_link.manage', $permissions, true);
                abort_unless($canManageIdentity || $canManageHrInvitations, 403, 'Unauthorized.');
            }

            abort_if(
                $isRequester && ! $withdrawn,
                403,
                'A different administrator must decide this request.',
            );
            abort_if(
                $isTarget,
                403,
                'The target cannot decide their own access request.',
            );

            if (! $withdrawn) {
                $this->assertCanManageTarget($actor, (string) $requestRow->organization_id, $facilityId, $context, $scopeChecker);
            }

            DB::table('idn_admin_role_promotion_requests')
                ->where('id', $requestRow->id)
                ->update([
                    'status' => 'REJECTED',
                    'decision_reason' => $validated['decision_reason'],
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);

            $audit->recordFor(
                tenantId: $context->tenantId(),
                action: ($requestRow->action ?? 'PROMOTE') === 'REVOKE'
                    ? 'identity.admin_role_promotion.revocation_rejected'
                    : 'identity.admin_role_promotion.rejected',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                reason: $validated['decision_reason'],
                metadata: [
                    'promotion_request' => (string) $requestRow->id,
                    'role' => (string) $requestRow->role_key,
                    'scope' => (string) $requestRow->scope_type,
                    'status' => 'REJECTED',
                    'approval_mode' => $withdrawn ? 'INVITING_TENANT_ADMIN_WITHDRAWAL' : 'INDEPENDENT_ADMIN',
                    'self_rejected' => $withdrawn,
                    'request_origin' => $requestRow->request_origin === null ? null : (string) $requestRow->request_origin,
                ],
                targetType: 'user',
                targetId: (string) $requestRow->target_user_id,
            );
        });

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
        ClinicianRoleProvisioner $clinicianRoleProvisioner,
        LaboratoryTechnicianRoleProvisioner $laboratoryRoleProvisioner,
        PharmacistRoleProvisioner $pharmacistRoleProvisioner,
        NurseRoleProvisioner $nurseRoleProvisioner,
        ReceptionistRoleProvisioner $receptionistRoleProvisioner,
        CashierRoleProvisioner $cashierRoleProvisioner,
        BillingAdminRoleProvisioner $billingAdminRoleProvisioner,
        AuditLogger $audit,
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

        $facility = DB::table('org_facilities')
            ->where('tenant_id', $context->tenantId())
            ->where('status', 'ACTIVE')
            ->where('id', $validated['facility_id'])
            ->first(['id', 'organization_id']);
        abort_unless($facility !== null, 422, 'The selected facility is unavailable.');
        $facilityId = (string) $facility->id;
        $organizationId = (string) $facility->organization_id;
        $this->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
        $enabledCapabilities = $organizationApi->enabledCapabilitiesForFacilities([$facilityId])[$facilityId] ?? [];
        abort_unless(
            FacilityUserRoleCatalog::isAvailableAtFacility(
                $roleKey,
                $enabledCapabilities,
            ),
            422,
            'The selected role requires a capability that is not enabled at this facility.',
        );

        $target = User::query()
            ->where('tenant_id', $context->tenantId())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($validated['email']))])
            ->first();
        abort_unless($target instanceof User && $target->isActive() && $target->email_verified_at !== null, 422, 'No active, verified account with that email exists in this tenant.');

        // User-correctable conflicts return inline (`errors.email` in the
        // grant workspace) + a bottom-right toast (`flash.error`) — never a
        // 409 exception page and never the page-top alert.
        $conflictResponse = static fn (string $message): RedirectResponse => back()
            ->withErrors(['email' => $message])
            ->with('error', $message);

        if ($grantEligibility->isBlockedByHrLifecycle((string) $target->tenant_id, (string) $target->getKey())) {
            return $conflictResponse('Access cannot be granted while linked staff is suspended or terminated.');
        }

        $existingRoleId = DB::table('idn_roles')
            ->where('tenant_id', $context->tenantId())
            ->where('key', $roleKey)
            ->value('id');
        if (is_string($existingRoleId) && DB::table('idn_access_assignments')
            ->where('tenant_id', $context->tenantId())
            ->where('user_id', $target->getKey())
            ->where('role_id', $existingRoleId)
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->exists()) {
            return $conflictResponse('This user already has the selected role at this facility.');
        }

        try {
            DB::transaction(function () use (
                $context,
                $actor,
                $facilityId,
                $organizationId,
                $roleKey,
                $target,
                $validated,
                $grantEligibility,
                $audit,
                $clinicianRoleProvisioner,
                $laboratoryRoleProvisioner,
                $pharmacistRoleProvisioner,
                $nurseRoleProvisioner,
                $receptionistRoleProvisioner,
                $cashierRoleProvisioner,
                $billingAdminRoleProvisioner,
            ): void {
                try {
                    $eligibleTarget = $grantEligibility->lockEligibleTarget(
                        (string) $context->tenantId(),
                        (string) $target->getKey(),
                    );
                } catch (HttpException $e) {
                    throw ValidationException::withMessages([
                        'email' => $e->getMessage() !== ''
                            ? $e->getMessage()
                            : 'Access cannot be granted while linked staff is suspended or terminated.',
                    ]);
                }
                if (! $eligibleTarget->isActive() || $eligibleTarget->email_verified_at === null) {
                    throw ValidationException::withMessages([
                        'email' => 'The target account is no longer eligible.',
                    ]);
                }
                // Every ordinary role is provisioned on demand — the grant never
                // depends on which rows happen to exist.
                $provision = static fn (object $provisioner): string => $provisioner->provision(
                    (string) $context->tenantId(),
                    (string) $actor->getKey(),
                    $facilityId,
                );
                $roleId = match ($roleKey) {
                    'nurse' => $provision($nurseRoleProvisioner),
                    FacilityUserRoleCatalog::CLINICIAN => $provision($clinicianRoleProvisioner),
                    'pharmacist' => $provision($pharmacistRoleProvisioner),
                    'laboratory_technician' => $provision($laboratoryRoleProvisioner),
                    'receptionist' => $provision($receptionistRoleProvisioner),
                    'cashier' => $provision($cashierRoleProvisioner),
                    'billing_admin' => $provision($billingAdminRoleProvisioner),
                };
                $alreadyActive = DB::table('idn_access_assignments')
                    ->where('tenant_id', $context->tenantId())
                    ->where('user_id', $eligibleTarget->getKey())
                    ->where('role_id', $roleId)
                    ->where('facility_id', $facilityId)
                    ->where('status', 'ACTIVE')
                    ->exists();
                if ($alreadyActive) {
                    throw ValidationException::withMessages([
                        'email' => 'This user already has the selected role at this facility.',
                    ]);
                }

                $assignmentId = (string) Str::uuid7();
                DB::table('idn_access_assignments')->insert([
                    'id' => $assignmentId,
                    'tenant_id' => $context->tenantId(),
                    'user_id' => $eligibleTarget->getKey(),
                    'role_id' => $roleId,
                    'organization_id' => $organizationId,
                    'facility_id' => $facilityId,
                    'status' => 'ACTIVE',
                    'is_primary' => false,
                    'granted_by' => $actor->getKey(),
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $audit->recordFor(
                    tenantId: $context->tenantId(),
                    action: 'identity.facility_user.granted',
                    actorId: (string) $actor->getKey(),
                    facilityId: $facilityId,
                    reason: trim($validated['reason']),
                    metadata: [
                        'assignment' => $assignmentId,
                        'role' => $roleKey,
                        'scope' => 'FACILITY',
                        'status' => 'ACTIVE',
                    ],
                    targetType: 'user',
                    targetId: (string) $eligibleTarget->getKey(),
                );
            });
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
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $facility = DB::table('org_facilities')
            ->where('tenant_id', $context->tenantId())
            ->where('status', 'ACTIVE')
            ->where('id', $validated['facility_id'])
            ->first(['id', 'organization_id']);
        abort_unless($facility !== null, 404);
        $this->assertCanManageTarget(
            $actor,
            (string) $facility->organization_id,
            (string) $facility->id,
            $context,
            $scopeChecker,
        );

        $search = mb_strtolower(trim($validated['search']));
        $pattern = "%{$search}%";
        $accounts = DB::table('users')
            ->where('tenant_id', $context->tenantId())
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->where(function ($query) use ($pattern): void {
                $query->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$pattern]);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email'])
            ->map(static fn (object $account): array => [
                'id' => (string) $account->id,
                'name' => (string) $account->name,
                'email' => (string) $account->email,
            ])
            ->all();

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
        string $assignment,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        DB::transaction(function () use ($context, $scopeChecker, $actor, $validated, $assignment, $access, $audit): void {
            $row = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $context->tenantId())
                ->where('assignment.id', $assignment)
                ->lockForUpdate()
                ->first([
                    'assignment.id',
                    'assignment.user_id',
                    'assignment.facility_id',
                    'assignment.organization_id',
                    'assignment.status',
                    'role.key as role_key',
                ]);
            abort_unless($row !== null && $row->facility_id !== null, 404);
            abort_unless(array_key_exists((string) $row->role_key, FacilityUserRoleCatalog::STAFF_ROLES), 422, 'Only ordinary facility roles can be revoked here. Administrative roles use the approval workflow.');
            $facilityId = (string) $row->facility_id;
            $organizationId = $row->organization_id === null ? null : (string) $row->organization_id;
            abort_unless($organizationId !== null, 422, 'The assignment scope is no longer valid.');
            $this->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
            abort_if($row->status !== 'ACTIVE' && $row->status !== 'SUSPENDED', 409, 'This access assignment is no longer active.');

            $access->revokeAssignment(
                tenantId: $context->tenantId(),
                assignmentId: (string) $row->id,
                actorId: (string) $actor->getKey(),
                reason: trim($validated['reason']),
                audit: $audit,
            );
        });

        return $this->redirectToRoleApprovals($request)->with('status', 'Facility access revoked. It takes effect on the next authorization check; device sessions were revoked.');
    }

    /**
     * ACTIVE + SUSPENDED ordinary facility assignments in manageable scope.
     * Administrative and HR-manager assignments are excluded here on purpose:
     * they use their dedicated approval/grant workflows.
     *
     * @param  list<string>  $facilityIds
     * @param  array<string,string>  $facilityNames
     * @return list<array{id:string,facilityId:string,facilityName:string,userName:string,userEmail:string,roleKey:string,roleLabel:string,status:string}>
     */
    private function ordinaryAssignments(TenantContext $context, array $facilityIds, array $facilityNames): array
    {
        if ($facilityIds === []) {
            return [];
        }

        $ordinaryKeys = array_keys(FacilityUserRoleCatalog::STAFF_ROLES);

        return DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('users', function ($join) use ($context): void {
                $join->on('users.id', '=', 'assignment.user_id')
                    ->where('users.tenant_id', '=', $context->tenantId());
            })
            ->where('assignment.tenant_id', $context->tenantId())
            ->whereIn('assignment.facility_id', $facilityIds)
            ->whereIn('assignment.status', ['ACTIVE', 'SUSPENDED'])
            ->whereIn('role.key', $ordinaryKeys)
            ->orderBy('users.name')
            ->get([
                'assignment.id',
                'assignment.facility_id',
                'assignment.status',
                'role.key as role_key',
                'users.name as user_name',
                'users.email as user_email',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'facilityId' => (string) $row->facility_id,
                'facilityName' => $facilityNames[(string) $row->facility_id] ?? 'Facility',
                'userName' => (string) $row->user_name,
                'userEmail' => (string) $row->user_email,
                'roleKey' => (string) $row->role_key,
                'roleLabel' => FacilityUserRoleCatalog::label((string) $row->role_key) ?? (string) $row->role_key,
                'status' => (string) $row->status,
            ])
            ->all();
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
     * An invitation requester may decide only the facility-role request
     * created by that same invitation. Manual promotions, revocations, and
     * target self-approval still require the existing independent review.
     */
    private function allowsInvitationRequesterDecision(
        object $requestRow,
        User $actor,
        ?string $facilityId,
        ScopeChecker $scopeChecker,
    ): bool {
        $roleKey = (string) ($requestRow->role_key ?? '');
        if (! in_array($roleKey, [
            'facility_admin',
            FacilityUserRoleCatalog::HR_MANAGER,
            ...array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
        ], true)
            || ($requestRow->action ?? 'PROMOTE') !== 'PROMOTE'
            || ($requestRow->scope_type ?? null) !== 'FACILITY'
            || ($requestRow->request_origin ?? null) !== 'FACILITY_INVITATION'
            || $facilityId === null) {
            return false;
        }

        if (! $scopeChecker->withinFacilityScope($actor, $facilityId)) {
            return false;
        }

        $invitation = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $actor->tenant_id)
            ->where('user_id', $requestRow->target_user_id)
            ->where('facility_id', $facilityId)
            ->where('requested_by', $actor->getKey())
            ->whereNotNull('consumed_at')
            ->where(function ($query) use ($roleKey): void {
                $query->where(function ($legacy) use ($roleKey): void {
                    if ($roleKey === FacilityUserRoleCatalog::ADMINISTRATOR) {
                        $legacy->where('purpose', 'FACILITY_ADMIN')->whereNull('role_key');
                    } else {
                        $legacy->whereRaw('1 = 0');
                    }
                })->orWhere(function ($generic) use ($roleKey): void {
                    $generic->where('purpose', 'FACILITY_ROLE')
                        ->where(function ($query) use ($roleKey): void {
                            $query->where('role_key', $roleKey)
                                ->orWhereJsonContains('role_keys', $roleKey);
                        });
                });
            })
            ->exists();
        if (! $invitation) {
            return false;
        }

        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $roles = $scopeChecker->effectiveRoles($actor, $facilityId);
        $canManageIdentity = in_array('admin.workspace.access', $permissions, true)
            && in_array('identity.assignments.manage', $permissions, true);
        $canManageHr = in_array('hr.staff.manage', $permissions, true);

        return match ($roleKey) {
            FacilityUserRoleCatalog::ADMINISTRATOR => $canManageIdentity && in_array('tenant_admin', $roles, true),
            FacilityUserRoleCatalog::HR_MANAGER => ($canManageIdentity
                && array_intersect(['tenant_admin', 'facility_admin'], $roles) !== []) || $canManageHr,
            default => $canManageIdentity || $canManageHr,
        };
    }

    private function invitingAdminApprovalMode(
        User $actor,
        ?string $facilityId,
        ScopeChecker $scopeChecker,
    ): string {
        $roles = $facilityId === null ? [] : $scopeChecker->effectiveRoles($actor, $facilityId);

        return in_array('tenant_admin', $roles, true)
            ? 'INVITING_TENANT_ADMIN'
            : (in_array('facility_admin', $roles, true) ? 'INVITING_FACILITY_ADMIN' : 'INVITING_HR_MANAGER');
    }

    private function assertCanManageTarget(
        User $actor,
        string $organizationId,
        ?string $facilityId,
        TenantContext $context,
        ScopeChecker $scopeChecker,
    ): void {
        if ($facilityId === null) {
            $hasOrganizationAssignment = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $context->tenantId())
                ->where('assignment.user_id', $actor->getKey())
                ->where('assignment.organization_id', $organizationId)
                ->whereNull('assignment.facility_id')
                ->where('assignment.status', 'ACTIVE')
                ->where('role.scope_type', 'ORG')
                ->where('role.status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', now()))
                ->exists();
            abort_unless($hasOrganizationAssignment, 403, 'An organization-scoped administrator must perform this promotion.');
            $effectivePermissions = $scopeChecker->effectiveOrganizationPermissions($actor, $organizationId);
        } else {
            abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403, 'You are not assigned to manage this facility.');
            $effectivePermissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        }
        abort_unless(
            in_array('admin.workspace.access', $effectivePermissions, true)
                && in_array('identity.assignments.manage', $effectivePermissions, true),
            403,
            'You are not authorized to approve administrative roles in this scope.',
        );
    }

    private function assertCanManageFacilityStaffAccess(
        User $actor,
        string $facilityId,
        ScopeChecker $scopeChecker,
    ): void {
        abort_unless(
            $this->canManageFacilityStaffAccess($actor, $facilityId, $scopeChecker),
            403,
            'You are not authorized to manage facility staff-management access.',
        );
    }

    private function canManageFacilityStaffAccess(
        User $actor,
        string $facilityId,
        ScopeChecker $scopeChecker,
    ): bool {
        if (! $scopeChecker->withinFacilityScope($actor, $facilityId)) {
            return false;
        }

        $effectivePermissions = $scopeChecker->effectivePermissions($actor, $facilityId);

        return in_array('admin.workspace.access', $effectivePermissions, true)
            && in_array('identity.assignments.manage', $effectivePermissions, true);
    }

    /**
     * @param  list<string>  $candidateOrganizationIds
     * @param  list<string>  $requiredPermissions
     * @return list<string>
     */
    private function organizationIdsFor(
        User $user,
        array $candidateOrganizationIds,
        array $requiredPermissions,
        ScopeChecker $scopeChecker,
    ): array {
        $authorized = [];
        foreach ($candidateOrganizationIds as $organizationId) {
            $hasOrganizationAssignment = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.user_id', $user->getKey())
                ->where('assignment.organization_id', $organizationId)
                ->whereNull('assignment.facility_id')
                ->where('assignment.status', 'ACTIVE')
                ->where('role.scope_type', 'ORG')
                ->where('role.status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', now()))
                ->exists();
            if (! $hasOrganizationAssignment) {
                continue;
            }

            $facilityId = DB::table('org_facilities')
                ->where('organization_id', $organizationId)
                ->where('status', 'ACTIVE')
                ->value('id');
            if (! is_string($facilityId)) {
                continue;
            }

            $effectivePermissions = $scopeChecker->effectivePermissions($user, $facilityId);
            if (array_diff($requiredPermissions, $effectivePermissions) === []) {
                $authorized[] = $organizationId;
            }
        }

        return $authorized;
    }

    /**
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
