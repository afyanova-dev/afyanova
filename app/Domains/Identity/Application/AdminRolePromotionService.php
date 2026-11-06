<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Identity\Contracts\Dto\ProtectedRoleReference;
use App\Domains\Identity\Contracts\FacilityStaffManagerRoleDefinition;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdminRolePromotionService
{
    /**
     * @param  list<string>  $facilityIds
     * @param  list<string>  $organizationIds
     * @return array{
     *     statusCounts: array<string, int>,
     *     paginator: LengthAwarePaginator<int, object>,
     *     users: array<string, array{name: string, email: string}>
     * }
     */
    public function promotionIndexData(
        string $tenantId,
        array $facilityIds,
        array $organizationIds,
        string $statusFilter,
        int $page,
    ): array {
        $query = DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId);
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
            ], 'page', $page);

        $userIds = [];
        $platformActorIds = [];
        foreach ($paginator->items() as $promotion) {
            $userIds[] = $promotion->target_user_id;
            $userIds[] = $promotion->requested_by;
            $userIds[] = $promotion->decided_by;
            $platformActorIds[] = $promotion->requested_by;
            $platformActorIds[] = $promotion->decided_by;
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        $platformActorIds = array_values(array_unique(array_filter($platformActorIds)));

        $users = DB::table('users')
            ->where(function ($query) use ($tenantId, $userIds, $platformActorIds): void {
                $query
                    ->where(fn ($tenantUsers) => $tenantUsers
                        ->where('tenant_id', $tenantId)
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

        return [
            'statusCounts' => $statusCounts,
            'paginator' => $paginator,
            'users' => $users,
        ];
    }

    /**
     * @param  list<string>  $facilityIds
     * @param  list<string>  $facilityAdminInviteIds
     * @param  list<string>  $staffAccessFacilityIds
     * @param  array<string, list<string>>  $facilityCapabilities
     * @return list<array{id: string, userName: string, userEmail: string, roles: list<string>, facilityName: string, status: string, createdAt: string, canResend: bool}>
     */
    public function facilityInvitations(
        string $tenantId,
        array $facilityIds,
        array $facilityAdminInviteIds,
        array $staffAccessFacilityIds,
        array $facilityCapabilities,
    ): array {
        if ($facilityIds === []) {
            return [];
        }

        return array_values(DB::table('aut_admin_bootstrap_tokens as invitation')
            ->join('users', function ($join) use ($tenantId): void {
                $join->on('users.id', '=', 'invitation.user_id')
                    ->where('users.tenant_id', '=', $tenantId);
            })
            ->join('org_facilities as facility', function ($join) use ($tenantId): void {
                $join->on('facility.id', '=', 'invitation.facility_id')
                    ->where('facility.tenant_id', '=', $tenantId);
            })
            ->where('invitation.tenant_id', $tenantId)
            ->whereIn('invitation.facility_id', $facilityIds)
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
                    && $invitation->user_status === 'ACTIVE';
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
            ->all());
    }

    /**
     * @param  list<string>  $candidateOrganizationIds
     * @param  list<string>  $requiredPermissions
     * @return list<string>
     */
    public function organizationIdsFor(
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

    public function canManageFacilityStaffAccess(
        User $user,
        string $facilityId,
        ScopeChecker $scopeChecker,
    ): bool {
        if (! $scopeChecker->withinFacilityScope($user, $facilityId)) {
            return false;
        }

        $effectivePermissions = $scopeChecker->effectivePermissions($user, $facilityId);

        return in_array('admin.workspace.access', $effectivePermissions, true)
            && in_array('identity.assignments.manage', $effectivePermissions, true);
    }

    public function authorizeFacilityInvitationApproval(
        TenantContext $context,
        ScopeChecker $scopeChecker,
        User $actor,
        string $promotionId,
        string $staffId,
    ): string {
        $invitationRequest = DB::table('idn_admin_role_promotion_requests as request')
            ->join('hr_staff_user_links as link', function ($join) use ($context, $staffId): void {
                $join->on('link.user_id', '=', 'request.target_user_id')
                    ->where('link.tenant_id', '=', $context->tenantId())
                    ->where('link.staff_id', '=', $staffId);
            })
            ->where('request.tenant_id', $context->tenantId())
            ->where('request.id', $promotionId)
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
            ->where('id', $promotionId)
            ->first(['requested_by']);
        abort_unless($requestRow !== null, 404);
        abort_if(
            (string) $requestRow->requested_by !== (string) $actor->getKey(),
            403,
            'Only the administrator who sent this invitation may approve the access request.',
        );

        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canManageIdentity = in_array('admin.workspace.access', $permissions, true)
            && in_array('identity.assignments.manage', $permissions, true);
        $canManageHrInvitations = in_array('hr.staff.manage', $permissions, true)
            && in_array('hr.staff_user_link.manage', $permissions, true);
        abort_unless($canManageIdentity || $canManageHrInvitations, 403, 'Unauthorized.');

        return $facilityId;
    }

    /**
     * @param  list<string>  $facilityIds
     * @param  array<string, string>  $facilityNames
     * @return list<array{id: string, facilityId: string, facilityName: string, userName: string, userEmail: string, roleKey: string, roleLabel: string, status: string}>
     */
    public function ordinaryAssignments(
        TenantContext $context,
        array $facilityIds,
        array $facilityNames,
    ): array {
        if ($facilityIds === []) {
            return [];
        }

        $ordinaryKeys = array_keys(FacilityUserRoleCatalog::STAFF_ROLES);

        return array_values(
            DB::table('idn_access_assignments as assignment')
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
                ->all(),
        );
    }

    /** @return list<string> */
    public function activeOrganizationIds(string $tenantId): array
    {
        return array_values(
            DB::table('org_organizations')
                ->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
        );
    }

    /** @return array{id: string, organizationId: string}|null */
    public function activeFacility(string $tenantId, string $facilityId): ?array
    {
        $facility = DB::table('org_facilities')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->where('id', $facilityId)
            ->first(['id', 'organization_id']);

        return $facility === null
            ? null
            : ['id' => (string) $facility->id, 'organizationId' => (string) $facility->organization_id];
    }

    public function accountIdByEmail(string $tenantId, string $email): ?string
    {
        $accountId = User::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->value('id');

        return is_string($accountId) ? $accountId : null;
    }

    public function assertCanManageTarget(
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

    /**
     * @return list<array{id: string, name: string, email: string}>
     */
    public function ordinaryGrantTargets(
        User $actor,
        string $tenantId,
        string $facilityId,
        string $search,
        TenantContext $context,
        ScopeChecker $scopeChecker,
    ): array {
        $facility = $this->activeFacility($tenantId, $facilityId);
        abort_unless($facility !== null, 404);
        $this->assertCanManageTarget(
            $actor,
            $facility['organizationId'],
            $facility['id'],
            $context,
            $scopeChecker,
        );

        $pattern = '%'.mb_strtolower(trim($search)).'%';

        return array_values(
            DB::table('users')
                ->where('tenant_id', $tenantId)
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
                ->all(),
        );
    }

    public function grantOrdinaryFacilityRole(
        TenantContext $context,
        User $actor,
        string $organizationId,
        string $facilityId,
        string $targetEmail,
        string $roleKey,
        string $reason,
        ScopeChecker $scopeChecker,
        LinkedStaffGrantEligibility $grantEligibility,
        IdentityApi $identityApi,
        AuditLogger $audit,
    ): ?string {
        $target = User::query()
            ->where('tenant_id', $context->tenantId())
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($targetEmail))])
            ->first();
        abort_unless(
            $target instanceof User && $target->isActive() && $target->email_verified_at !== null,
            422,
            'No active, verified account with that email exists in this tenant.',
        );

        if ($grantEligibility->isBlockedByHrLifecycle((string) $target->tenant_id, (string) $target->getKey())) {
            return 'Access cannot be granted while linked staff is suspended or terminated.';
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
            return 'This user already has the selected role at this facility.';
        }

        DB::transaction(function () use (
            $context,
            $actor,
            $organizationId,
            $facilityId,
            $roleKey,
            $target,
            $reason,
            $grantEligibility,
            $identityApi,
            $audit,
        ): void {
            try {
                $eligibleTarget = $grantEligibility->lockEligibleTarget(
                    (string) $context->tenantId(),
                    (string) $target->getKey(),
                );
            } catch (HttpException $exception) {
                throw ValidationException::withMessages([
                    'email' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Access cannot be granted while linked staff is suspended or terminated.',
                ]);
            }
            if (! $eligibleTarget->isActive() || $eligibleTarget->email_verified_at === null) {
                throw ValidationException::withMessages([
                    'email' => 'The target account is no longer eligible.',
                ]);
            }

            $roleId = $identityApi->provisionFacilityRole(
                (string) $context->tenantId(),
                (string) $actor->getKey(),
                $facilityId,
                $roleKey,
            );
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
                reason: trim($reason),
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

        return null;
    }

    public function revokeOrdinaryFacilityRole(
        TenantContext $context,
        ScopeChecker $scopeChecker,
        User $actor,
        string $assignmentId,
        string $reason,
        LinkedStaffAccessService $access,
        AuditLogger $audit,
    ): void {
        DB::transaction(function () use (
            $context,
            $scopeChecker,
            $actor,
            $assignmentId,
            $reason,
            $access,
            $audit,
        ): void {
            $row = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $context->tenantId())
                ->where('assignment.id', $assignmentId)
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
            abort_unless(
                array_key_exists((string) $row->role_key, FacilityUserRoleCatalog::STAFF_ROLES),
                422,
                'Only ordinary facility roles can be revoked here. Administrative roles use the approval workflow.',
            );
            $facilityId = (string) $row->facility_id;
            $organizationId = $row->organization_id === null ? null : (string) $row->organization_id;
            abort_unless($organizationId !== null, 422, 'The assignment scope is no longer valid.');
            $this->assertCanManageTarget($actor, $organizationId, $facilityId, $context, $scopeChecker);
            abort_if(
                $row->status !== 'ACTIVE' && $row->status !== 'SUSPENDED',
                409,
                'This access assignment is no longer active.',
            );

            $access->revokeAssignment(
                tenantId: $context->tenantId(),
                assignmentId: (string) $row->id,
                actorId: (string) $actor->getKey(),
                reason: trim($reason),
                audit: $audit,
            );
        });
    }

    public function approvePromotion(
        TenantContext $context,
        User $actor,
        ScopeChecker $scopeChecker,
        LinkedStaffGrantEligibility $grantEligibility,
        AuditLogger $audit,
        IdentityApi $identityApi,
        string $promotionId,
    ): bool {
        return DB::transaction(function () use (
            $context,
            $actor,
            $scopeChecker,
            $grantEligibility,
            $audit,
            $identityApi,
            $promotionId,
        ): bool {
            $requestRow = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $promotionId)
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

            abort_if($isRequester && ! $selfApproved, 403, 'A different administrator must approve this request.');
            abort_if($isTarget, 403, 'The target cannot approve their own access request.');

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

                return $selfApproved;
            }

            $target = $grantEligibility->lockEligibleTarget(
                (string) $context->tenantId(),
                (string) $requestRow->target_user_id,
            );
            abort_unless($target->isActive() && $target->email_verified_at !== null, 409, 'The target account is no longer eligible.');

            $assignmentExists = DB::table('idn_access_assignments')
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
            abort_if($assignmentExists, 409, 'The target already has this administrative role in the requested scope.');

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
                        ? $this->invitingAdminApprovalMode($actor, $facilityId, $scopeChecker)
                        : 'INDEPENDENT_ADMIN',
                    'self_approved' => $selfApproved,
                    'request_origin' => $requestRow->request_origin === null ? null : (string) $requestRow->request_origin,
                ],
                targetType: 'user',
                targetId: (string) $target->getKey(),
            );

            return $selfApproved;
        });
    }

    public function rejectPromotion(
        TenantContext $context,
        ScopeChecker $scopeChecker,
        User $actor,
        string $promotionId,
        string $decisionReason,
        bool $isHrInvitationWithdrawal,
        ?string $staffId,
        AuditLogger $audit,
    ): bool {
        return DB::transaction(function () use (
            $context,
            $scopeChecker,
            $actor,
            $promotionId,
            $decisionReason,
            $isHrInvitationWithdrawal,
            $staffId,
            $audit,
        ): bool {
            $requestRow = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $promotionId)
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
                        ->where('staff_id', $staffId)
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

            abort_if($isRequester && ! $withdrawn, 403, 'A different administrator must decide this request.');
            abort_if($isTarget, 403, 'The target cannot decide their own access request.');

            if (! $withdrawn) {
                $this->assertCanManageTarget(
                    $actor,
                    (string) $requestRow->organization_id,
                    $facilityId,
                    $context,
                    $scopeChecker,
                );
            }

            DB::table('idn_admin_role_promotion_requests')
                ->where('id', $requestRow->id)
                ->update([
                    'status' => 'REJECTED',
                    'decision_reason' => $decisionReason,
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
                reason: $decisionReason,
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

            return $withdrawn;
        });
    }

    private function allowsInvitationRequesterDecision(
        \stdClass $requestRow,
        User $actor,
        ?string $facilityId,
        ScopeChecker $scopeChecker,
    ): bool {
        $requestData = get_object_vars($requestRow);
        $roleKey = (string) ($requestData['role_key'] ?? '');
        if (! in_array($roleKey, [
            FacilityUserRoleCatalog::ADMINISTRATOR,
            FacilityUserRoleCatalog::HR_MANAGER,
            ...array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
        ], true)
            || ($requestData['action'] ?? 'PROMOTE') !== 'PROMOTE'
            || ($requestData['scope_type'] ?? null) !== 'FACILITY'
            || ($requestData['request_origin'] ?? null) !== 'FACILITY_INVITATION'
            || $facilityId === null) {
            return false;
        }

        if (! $scopeChecker->withinFacilityScope($actor, $facilityId)) {
            return false;
        }

        $invitationExists = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $actor->tenant_id)
            ->where('user_id', $requestData['target_user_id'] ?? null)
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
        if (! $invitationExists) {
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

    public function requestPromotion(
        string $tenantId,
        string $organizationId,
        ?string $facilityId,
        string $targetUserId,
        ProtectedRoleReference $role,
        string $reason,
        User $actor,
        AuditLogger $audit,
    ): void {
        $promotionId = (string) Str::uuid7();

        DB::transaction(function () use (
            $tenantId,
            $organizationId,
            $facilityId,
            $targetUserId,
            $role,
            $reason,
            $actor,
            $audit,
            $promotionId,
        ): void {
            $target = User::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($targetUserId)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $target instanceof User && $target->isActive() && $target->email_verified_at !== null,
                422,
                'The target account must be active and email-verified.',
            );

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
                'reason' => $reason,
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
                reason: $reason,
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
    }

    public function grantFacilityStaffManager(
        string $tenantId,
        string $facilityId,
        string $email,
        User $actor,
        ScopeChecker $scopeChecker,
        FacilityStaffManagerRoleProvisioner $roleProvisioner,
        LinkedStaffGrantEligibility $grantEligibility,
        AuditLogger $audit,
    ): void {
        $facility = $this->activeFacility($tenantId, $facilityId);
        abort_unless($facility !== null, 422, 'The selected facility is unavailable.');

        $this->assertCanManageFacilityStaffAccess($actor, $facilityId, $scopeChecker);

        $target = User::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();
        abort_unless(
            $target instanceof User && $target->isActive() && $target->email_verified_at !== null,
            422,
            'No active, verified account with that email exists in this tenant.',
        );
        abort_unless(
            $scopeChecker->withinFacilityScope($target, $facilityId),
            422,
            'The user must already have an active access assignment at the selected facility.',
        );

        DB::transaction(function () use (
            $tenantId,
            $actor,
            $facilityId,
            $facility,
            $target,
            $roleProvisioner,
            $grantEligibility,
            $audit,
        ): void {
            $eligibleTarget = $grantEligibility->lockEligibleTarget(
                $tenantId,
                (string) $target->getKey(),
            );
            abort_unless(
                $eligibleTarget->isActive() && $eligibleTarget->email_verified_at !== null,
                409,
                'The target account is no longer eligible.',
            );
            $roleId = $roleProvisioner->provision(
                tenantId: $tenantId,
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
            );
            $alreadyAssigned = DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $eligibleTarget->getKey())
                ->where('role_id', $roleId)
                ->where('facility_id', $facilityId)
                ->where('status', 'ACTIVE')
                ->exists();
            abort_if($alreadyAssigned, 409, 'This user already has facility staff-management access.');

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $eligibleTarget->getKey(),
                'role_id' => $roleId,
                'organization_id' => $facility['organizationId'],
                'facility_id' => $facilityId,
                'status' => 'ACTIVE',
                'is_primary' => false,
                'granted_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $audit->recordFor(
                tenantId: $tenantId,
                action: 'identity.facility_staff_manager.granted',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: ['role' => FacilityStaffManagerRoleDefinition::KEY],
                targetType: 'user',
                targetId: (string) $eligibleTarget->getKey(),
            );
        });
    }

    public function revokeFacilityStaffManager(
        string $tenantId,
        string $assignmentId,
        User $actor,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
    ): void {
        DB::transaction(function () use ($tenantId, $actor, $scopeChecker, $audit, $assignmentId): void {
            $row = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $tenantId)
                ->where('assignment.id', $assignmentId)
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
                ->where('tenant_id', $tenantId)
                ->where('id', $row->id)
                ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $audit->recordFor(
                tenantId: $tenantId,
                action: 'identity.facility_staff_manager.revoked',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: ['role' => FacilityStaffManagerRoleDefinition::KEY],
                targetType: 'user',
                targetId: (string) $row->user_id,
            );
        });
    }

    private function assertCanManageFacilityStaffAccess(
        User $actor,
        string $facilityId,
        ScopeChecker $scopeChecker,
    ): void {
        abort_unless(
            $scopeChecker->withinFacilityScope($actor, $facilityId)
                && array_diff(
                    ['admin.workspace.access', 'identity.assignments.manage'],
                    $scopeChecker->effectivePermissions($actor, $facilityId),
                ) === [],
            403,
            'You are not authorized to manage facility staff-management access.',
        );
    }
}
