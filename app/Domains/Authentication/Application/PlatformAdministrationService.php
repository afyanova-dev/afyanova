<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Application\Dto\FacilityInvitationForResend;
use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Authentication\Infrastructure\Reader\FacilityInvitationReader;
use App\Domains\Authentication\Infrastructure\Writer\FacilityInvitationEventWriter;
use App\Domains\Authentication\Mail\AdminBootstrapInvitation;
use App\Domains\Authentication\Support\FacilityInvitationEventType;
use App\Domains\Authentication\Support\FacilityInvitationStatus;
use App\Domains\Authentication\Support\OneTimeInvitationMailGuard;
use App\Domains\Identity\Application\BillingAdminRoleProvisioner;
use App\Domains\Identity\Application\CashierRoleProvisioner;
use App\Domains\Identity\Application\ClinicianRoleProvisioner;
use App\Domains\Identity\Application\FacilityStaffManagerRoleProvisioner;
use App\Domains\Identity\Application\LaboratoryTechnicianRoleProvisioner;
use App\Domains\Identity\Application\NurseRoleProvisioner;
use App\Domains\Identity\Application\PharmacistRoleProvisioner;
use App\Domains\Identity\Application\ReceptionistRoleProvisioner;
use App\Domains\Identity\Application\TenantAdminRoleProvisioner;
use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Domains\Organization\Contracts\Dto\InitialTenantStructure;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PlatformAdministrationService
{
    public function __construct(
        private readonly FacilityInvitationReader $facilityInvitations,
        private readonly FacilityInvitationEventWriter $invitationEvents,
    ) {}

    public function facilityInvitationForResend(string $tenantId, string $invitationId): ?FacilityInvitationForResend
    {
        return $this->facilityInvitations->forResend($tenantId, $invitationId);
    }

    public function pendingFacilityInvitationForAccount(
        string $tenantId,
        string $facilityId,
        string $userId,
    ): ?FacilityInvitationForResend {
        return $this->facilityInvitations->pendingForFacilityAccount($tenantId, $facilityId, $userId);
    }

    /** @return list<array<string, mixed>> */
    public function facilityInvitationsForWorklist(string $tenantId, string $facilityId): array
    {
        return $this->facilityInvitations->forFacility($tenantId, $facilityId);
    }

    /** @return list<array<string, mixed>> */
    public function invitationHistoryForStaff(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $userId,
    ): array {
        return $this->facilityInvitations->historyForStaff($tenantId, $facilityId, $staffId, $userId);
    }

    /**
     * @return list<array{
     *   id: string, name: string, slug: string, status: string,
     *   organizationCount: int, facilityCount: int,
     *   organizations: list<array{id: string, name: string, code: string, status: string}>,
     *   facilities: list<array{id: string, name: string, code: string, type: string, careLevel: string, ownershipType: string, status: string}>,
     *   hasActiveAdmin: bool, pendingAdminEmail: string|null,
     *   pendingAdminExpiresAt: string|null, pendingAdminInvitationExpired: bool, canInviteAdmin: bool
     * }>
     */
    public function tenants(): array
    {
        $tenants = DB::table('shared_tenants')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'status'])
            ->all();
        $result = [];

        foreach ($tenants as $tenant) {
            $structure = TenantContextScope::run((string) $tenant->id, null, static function () use ($tenant): array {
                $organizations = array_values(DB::table('org_organizations')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code', 'status'])
                    ->map(static fn (object $organization): array => [
                        'id' => (string) $organization->id,
                        'name' => (string) $organization->name,
                        'code' => (string) $organization->code,
                        'status' => (string) $organization->status,
                    ])
                    ->all());
                $facilities = array_values(DB::table('org_facilities')
                    ->orderBy('name')
                    ->get(['id', 'name', 'code', 'facility_type', 'care_level', 'ownership_type', 'status'])
                    ->map(static fn (object $facility): array => [
                        'id' => (string) $facility->id,
                        'name' => (string) $facility->name,
                        'code' => (string) $facility->code,
                        'type' => (string) $facility->facility_type,
                        'careLevel' => (string) $facility->care_level,
                        'ownershipType' => (string) $facility->ownership_type,
                        'status' => (string) $facility->status,
                    ])
                    ->all());
                $hasActiveAdmin = DB::table('idn_access_assignments as aa')
                    ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
                    ->where('aa.tenant_id', $tenant->id)
                    ->where('r.key', TenantAdminRoleDefinition::KEY)
                    ->where('r.status', 'ACTIVE')
                    ->where('aa.status', 'ACTIVE')
                    ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
                    ->exists();
                $pendingAdminInvitation = DB::table('aut_admin_bootstrap_tokens as token')
                    ->join('users', 'users.id', '=', 'token.user_id')
                    ->where('token.tenant_id', $tenant->id)
                    ->where('token.purpose', 'TENANT_ADMIN')
                    ->whereNull('token.facility_id')
                    ->whereNull('token.consumed_at')
                    ->first(['users.email', 'token.expires_at']);

                return [
                    'organizations' => $organizations,
                    'facilities' => $facilities,
                    'organization_count' => count($organizations),
                    'has_active_organization' => count($organizations) === 1
                        && $organizations[0]['status'] === 'ACTIVE',
                    'facility_count' => count($facilities),
                    'has_active_admin' => $hasActiveAdmin,
                    'pending_admin_email' => is_string($pendingAdminInvitation?->email) ? $pendingAdminInvitation->email : null,
                    'pending_admin_expires_at' => is_string($pendingAdminInvitation?->expires_at) ? $pendingAdminInvitation->expires_at : null,
                    'pending_admin_invitation_expired' => $pendingAdminInvitation !== null
                        && now()->greaterThan($pendingAdminInvitation->expires_at),
                ];
            });
            $result[] = [
                'id' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'slug' => (string) $tenant->slug,
                'status' => (string) $tenant->status,
                'organizationCount' => $structure['organization_count'],
                'facilityCount' => $structure['facility_count'],
                'organizations' => $structure['organizations'],
                'facilities' => $structure['facilities'],
                'hasActiveAdmin' => $structure['has_active_admin'],
                'pendingAdminEmail' => $structure['pending_admin_email'],
                'pendingAdminExpiresAt' => $structure['pending_admin_expires_at'],
                'pendingAdminInvitationExpired' => $structure['pending_admin_invitation_expired'],
                'canInviteAdmin' => $tenant->status === 'ACTIVE'
                    && $structure['has_active_organization']
                    && ! $structure['has_active_admin'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{type: 'tenant'|'facility', id: string, tenantId: string, name: string, tenantName: string, status: string}>
     */
    public function search(string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $pattern = '%'.$needle.'%';
        $results = [];

        $tenantMatches = DB::table('shared_tenants')
            ->where(function ($builder) use ($pattern): void {
                $builder->whereRaw('LOWER(name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$pattern]);
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'status']);

        foreach ($tenantMatches as $tenant) {
            $results[] = [
                'type' => 'tenant',
                'id' => (string) $tenant->id,
                'tenantId' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'tenantName' => (string) $tenant->name,
                'status' => (string) $tenant->status,
            ];
        }

        $tenants = DB::table('shared_tenants')->orderBy('name')->get(['id', 'name']);
        foreach ($tenants as $tenant) {
            $facilities = TenantContextScope::run((string) $tenant->id, null, static function () use ($pattern): array {
                return DB::table('org_facilities')
                    ->where(function ($builder) use ($pattern): void {
                        $builder->whereRaw('LOWER(name) LIKE ?', [$pattern])
                            ->orWhereRaw('LOWER(code) LIKE ?', [$pattern]);
                    })
                    ->orderBy('name')
                    ->limit(8)
                    ->get(['id', 'name', 'status'])
                    ->map(static fn (object $facility): array => [
                        'id' => (string) $facility->id,
                        'name' => (string) $facility->name,
                        'status' => (string) $facility->status,
                    ])
                    ->all();
            });

            foreach ($facilities as $facility) {
                $results[] = [
                    'type' => 'facility',
                    ...$facility,
                    'tenantId' => (string) $tenant->id,
                    'tenantName' => (string) $tenant->name,
                ];
            }
        }

        usort($results, static function (array $left, array $right) use ($needle): int {
            $leftPrefix = str_starts_with(mb_strtolower($left['name']), $needle) ? 0 : 1;
            $rightPrefix = str_starts_with(mb_strtolower($right['name']), $needle) ? 0 : 1;

            return [$leftPrefix, $left['name'], $left['type']]
                <=> [$rightPrefix, $right['name'], $right['type']];
        });

        return array_slice($results, 0, 8);
    }

    /**
     * @return array{
     *   facilityTypes: list<array{code: string, name: string}>,
     *   careLevels: list<array{code: string, name: string}>,
     *   ownershipTypes: list<array{code: string, name: string}>
     * }
     */
    public function facilityReferences(): array
    {
        return [
            'facilityTypes' => array_values(DB::table('org_facility_types')
                ->where('active', true)
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(static fn (object $row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])
                ->all()),
            'careLevels' => array_values(DB::table('org_care_levels')
                ->where('active', true)
                ->orderBy('rank')
                ->get(['code', 'name'])
                ->map(static fn (object $row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])
                ->all()),
            'ownershipTypes' => array_values(DB::table('org_ownership_types')
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(static fn (object $row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])
                ->all()),
        ];
    }

    /**
     * @return array{
     *   id: string, name: string, slug: string, status: string,
     *   organizationCount: int, facilityCount: int,
     *   organizations: list<array{id: string, name: string, code: string, status: string}>,
     *   facilities: list<array{id: string, name: string, code: string, type: string, careLevel: string, status: string}>,
     *   hasActiveTenantAdmin: bool, pendingAdminEmail: string|null,
     *   pendingAdminExpiresAt: string|null, pendingAdminInvitationExpired: bool, canInviteAdmin: bool
     * }|null
     */
    public function tenantDetails(string $tenantId): ?array
    {
        $tenant = DB::table('shared_tenants')->where('id', $tenantId)->first(['id', 'name', 'slug', 'status']);
        if ($tenant === null) {
            return null;
        }

        $structure = TenantContextScope::run($tenantId, null, static function () use ($tenantId): array {
            $organizations = array_values(DB::table('org_organizations')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'status'])
                ->map(static fn (object $organization): array => [
                    'id' => (string) $organization->id,
                    'name' => (string) $organization->name,
                    'code' => (string) $organization->code,
                    'status' => (string) $organization->status,
                ])
                ->all());
            $facilities = array_values(DB::table('org_facilities')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'facility_type', 'care_level', 'ownership_type', 'status'])
                ->map(static fn (object $facility): array => [
                    'id' => (string) $facility->id,
                    'name' => (string) $facility->name,
                    'code' => (string) $facility->code,
                    'type' => (string) $facility->facility_type,
                    'careLevel' => (string) $facility->care_level,
                    'ownershipType' => (string) $facility->ownership_type,
                    'status' => (string) $facility->status,
                ])
                ->all());

            $hasActiveAdmin = DB::table('idn_access_assignments as aa')
                ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
                ->where('aa.tenant_id', $tenantId)
                ->where('r.key', TenantAdminRoleDefinition::KEY)
                ->where('r.status', 'ACTIVE')
                ->where('aa.status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
                ->exists();
            $administrators = DB::table('idn_access_assignments as aa')
                ->join('idn_roles as role', 'role.id', '=', 'aa.role_id')
                ->join('users as user', 'user.id', '=', 'aa.user_id')
                ->leftJoinSub(
                    DB::table('hr_staff_user_links as link')
                        ->join('hr_staff as staff', function ($join): void {
                            $join->on('staff.id', '=', 'link.staff_id')
                                ->on('staff.tenant_id', '=', 'link.tenant_id');
                        })
                        ->where('link.tenant_id', $tenantId)
                        ->whereIn('staff.status', ['SUSPENDED', 'TERMINATED'])
                        ->select('link.user_id')
                        ->distinct(),
                    'hr_blocked',
                    'hr_blocked.user_id',
                    '=',
                    'user.id',
                )
                ->where('aa.tenant_id', $tenantId)
                ->whereNull('aa.facility_id')
                ->where('role.key', TenantAdminRoleDefinition::KEY)
                ->where('role.is_protected', true)
                ->where('role.status', 'ACTIVE')
                ->where('aa.status', 'ACTIVE')
                ->orderBy('user.name')
                ->get(['user.id', 'aa.id as assignment_id', 'user.name', 'user.email', 'user.status as account_status', 'hr_blocked.user_id as hr_blocked_user_id'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'assignmentId' => (string) $row->assignment_id,
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                    'accountStatus' => $row->hr_blocked_user_id !== null ? 'HR_BLOCKED' : (string) $row->account_status,
                ])
                ->all();
            $pendingAdminInvitation = DB::table('aut_admin_bootstrap_tokens as token')
                ->join('users', 'users.id', '=', 'token.user_id')
                ->where('token.tenant_id', $tenantId)
                ->where('token.purpose', 'TENANT_ADMIN')
                ->whereNull('token.facility_id')
                ->whereNull('token.consumed_at')
                ->first(['users.email', 'token.expires_at']);
            $pendingRevocations = DB::table('idn_admin_role_promotion_requests as request')
                ->join('users as user', 'user.id', '=', 'request.target_user_id')
                ->where('request.tenant_id', $tenantId)
                ->where('request.role_key', TenantAdminRoleDefinition::KEY)
                ->where('request.action', 'REVOKE')
                ->where('request.status', 'PENDING')
                ->orderBy('user.name')
                ->get(['request.id', 'user.name', 'user.email'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                ])
                ->all();
            $emergencyReviews = DB::table('idn_emergency_access_reviews as review')
                ->join('users as target', 'target.id', '=', 'review.target_user_id')
                ->join('users as initiator', 'initiator.id', '=', 'review.requested_by')
                ->where('review.tenant_id', $tenantId)
                ->where('review.status', 'PENDING')
                ->orderBy('review.created_at')
                ->get([
                    'review.id',
                    'review.reason',
                    'review.created_at',
                    'target.name as target_name',
                    'initiator.name as initiator_name',
                ])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'reason' => (string) $row->reason,
                    'createdAt' => (string) $row->created_at,
                    'targetName' => (string) $row->target_name,
                    'initiatorName' => (string) $row->initiator_name,
                ])
                ->all();

            return [
                'organizations' => $organizations,
                'facilities' => $facilities,
                'hasActiveTenantAdmin' => $hasActiveAdmin,
                'administrators' => array_values($administrators),
                'pendingRevocations' => array_values($pendingRevocations),
                'emergencyReviews' => array_values($emergencyReviews),
                'pendingAdminEmail' => is_string($pendingAdminInvitation?->email) ? $pendingAdminInvitation->email : null,
                'pendingAdminExpiresAt' => is_string($pendingAdminInvitation?->expires_at) ? $pendingAdminInvitation->expires_at : null,
                'pendingAdminInvitationExpired' => $pendingAdminInvitation !== null
                    && now()->greaterThan($pendingAdminInvitation->expires_at),
            ];
        });

        return [
            'id' => (string) $tenant->id,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'status' => (string) $tenant->status,
            'organizationCount' => count($structure['organizations']),
            'facilityCount' => count($structure['facilities']),
            'canInviteAdmin' => $tenant->status === 'ACTIVE'
                && count($structure['organizations']) === 1
                && $structure['organizations'][0]['status'] === 'ACTIVE'
                && ! $structure['hasActiveTenantAdmin'],
            ...$structure,
        ];
    }

    public function setTenantStatus(string $tenantId, string $status, string $actorId, string $reason): int
    {
        if (! in_array($status, ['ACTIVE', 'SUSPENDED'], true)) {
            throw new RuntimeException('Tenant status must be ACTIVE or SUSPENDED.');
        }

        return DB::transaction(function () use ($tenantId, $status, $actorId, $reason): int {
            $tenant = DB::table('shared_tenants')->where('id', $tenantId)->lockForUpdate()->first(['id', 'status']);
            if ($tenant === null) {
                throw new RuntimeException('The tenant no longer exists.');
            }
            if ($tenant->status === $status) {
                throw new RuntimeException('The tenant is already '.$status.'.');
            }

            return TenantContextScope::run($tenantId, null, function () use ($tenantId, $status, $actorId, $reason): int {
                $sessionsRevoked = $status === 'SUSPENDED'
                    ? DB::table('aut_sessions')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now(), 'updated_at' => now()])
                    : 0;

                DB::table('shared_tenants')->where('id', $tenantId)->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);

                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: $status === 'SUSPENDED' ? 'admin.tenant.suspended' : 'admin.tenant.reactivated',
                    actorId: $actorId,
                    targetType: 'tenant',
                    targetId: $tenantId,
                    reason: trim($reason),
                    metadata: $status === 'SUSPENDED' ? ['sessions_revoked' => $sessionsRevoked] : [],
                );

                return $sessionsRevoked;
            });
        });
    }

    /**
     * @return array{
     *   id: string, tenantId: string, organizationId: string, organizationName: string,
     *   name: string, code: string, type: string, careLevel: string,
     *   ownershipType: string, status: string,
     *   administrators: list<array{id: string, assignmentId: string, name: string, email: string, accountStatus: string}>,
     *   pendingInvitations: list<array{name: string, email: string, expiresAt: string, expired: bool}>,
     *   pendingPromotions: list<array{id: string, name: string, email: string, setupComplete: bool}>,
     *   pendingRevocations: list<array{id: string, name: string, email: string}>
     * }|null
     */
    public function facilityDetails(string $tenantId, string $facilityId): ?array
    {
        if (! DB::table('shared_tenants')->where('id', $tenantId)->exists()) {
            return null;
        }

        return TenantContextScope::run($tenantId, null, static function () use ($tenantId, $facilityId): ?array {
            $facility = DB::table('org_facilities as f')
                ->join('org_organizations as o', 'o.id', '=', 'f.organization_id')
                ->where('f.tenant_id', $tenantId)
                ->where('f.id', $facilityId)
                ->first([
                    'f.id', 'f.organization_id', 'o.name as organization_name',
                    'f.name', 'f.code', 'f.facility_type', 'f.care_level',
                    'f.ownership_type', 'f.status',
                ]);
            if ($facility === null) {
                return null;
            }

            $administrators = DB::table('idn_access_assignments as aa')
                ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
                ->join('users as u', 'u.id', '=', 'aa.user_id')
                ->leftJoinSub(
                    DB::table('hr_staff_user_links as link')
                        ->join('hr_staff as staff', function ($join): void {
                            $join->on('staff.id', '=', 'link.staff_id')
                                ->on('staff.tenant_id', '=', 'link.tenant_id');
                        })
                        ->where('link.tenant_id', $tenantId)
                        ->whereIn('staff.status', ['SUSPENDED', 'TERMINATED'])
                        ->select('link.user_id')
                        ->distinct(),
                    'hr_blocked',
                    'hr_blocked.user_id',
                    '=',
                    'u.id',
                )
                ->where('aa.tenant_id', $tenantId)
                ->where('aa.facility_id', $facilityId)
                ->where('r.key', 'facility_admin')
                ->where('r.is_protected', true)
                ->where('aa.status', 'ACTIVE')
                ->orderBy('u.name')
                ->where('r.status', 'ACTIVE')
                ->get(['u.id', 'aa.id as assignment_id', 'u.name', 'u.email', 'u.status as account_status', 'hr_blocked.user_id as hr_blocked_user_id'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'assignmentId' => (string) $row->assignment_id,
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                    'accountStatus' => $row->hr_blocked_user_id !== null ? 'HR_BLOCKED' : (string) $row->account_status,
                ])->all();
            $administrators = array_values($administrators);

            $pendingInvitations = DB::table('aut_admin_bootstrap_tokens as token')
                ->join('users as u', 'u.id', '=', 'token.user_id')
                ->where('token.tenant_id', $tenantId)
                ->where('token.facility_id', $facilityId)
                ->where('token.purpose', 'FACILITY_ADMIN')
                ->whereNull('token.consumed_at')
                ->orderBy('u.name')
                ->get(['u.name', 'u.email', 'token.expires_at'])
                ->map(static fn (object $row): array => [
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                    'expiresAt' => (string) $row->expires_at,
                    'expired' => now()->greaterThan($row->expires_at),
                ])->all();
            $pendingInvitations = array_values($pendingInvitations);

            $pendingRevocations = DB::table('idn_admin_role_promotion_requests as request')
                ->join('users as u', 'u.id', '=', 'request.target_user_id')
                ->where('request.tenant_id', $tenantId)
                ->where('request.facility_id', $facilityId)
                ->where('request.role_key', 'facility_admin')
                ->where('request.action', 'REVOKE')
                ->where('request.status', 'PENDING')
                ->orderBy('u.name')
                ->get(['request.id', 'u.name', 'u.email'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                ])->all();
            $pendingRevocations = array_values($pendingRevocations);
            $pendingPromotions = DB::table('idn_admin_role_promotion_requests as request')
                ->join('users as u', 'u.id', '=', 'request.target_user_id')
                ->leftJoin('aut_admin_bootstrap_tokens as token', function ($join) use ($tenantId, $facilityId): void {
                    $join->on('token.user_id', '=', 'u.id')
                        ->where('token.tenant_id', '=', $tenantId)
                        ->where('token.facility_id', '=', $facilityId)
                        ->where('token.purpose', '=', 'FACILITY_ADMIN');
                })
                ->where('request.tenant_id', $tenantId)
                ->where('request.facility_id', $facilityId)
                ->where('request.role_key', 'facility_admin')
                ->where('request.action', 'PROMOTE')
                ->where('request.status', 'PENDING')
                ->orderBy('u.name')
                ->get(['request.id', 'u.name', 'u.email', 'token.consumed_at'])
                ->map(static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'email' => (string) $row->email,
                    'setupComplete' => $row->consumed_at !== null,
                ])->all();
            $pendingPromotions = array_values($pendingPromotions);

            return [
                'id' => (string) $facility->id,
                'tenantId' => $tenantId,
                'organizationId' => (string) $facility->organization_id,
                'organizationName' => (string) $facility->organization_name,
                'name' => (string) $facility->name,
                'code' => (string) $facility->code,
                'type' => (string) $facility->facility_type,
                'careLevel' => (string) $facility->care_level,
                'ownershipType' => (string) $facility->ownership_type,
                'status' => (string) $facility->status,
                'administrators' => $administrators,
                'pendingInvitations' => $pendingInvitations,
                'pendingPromotions' => $pendingPromotions,
                'pendingRevocations' => $pendingRevocations,
            ];
        });
    }

    /**
     * @param  array{name: string, type: string, careLevel: string, ownershipType: string, status: string}  $input
     */
    public function updateFacility(
        string $tenantId,
        string $facilityId,
        array $input,
        string $actorId,
    ): void {
        TenantContextScope::run($tenantId, null, static function () use ($tenantId, $facilityId, $input, $actorId): void {
            DB::transaction(function () use ($tenantId, $facilityId, $input, $actorId): void {
                $facility = DB::table('org_facilities')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $facilityId)
                    ->lockForUpdate()
                    ->first();
                if ($facility === null) {
                    throw new RuntimeException('The facility was not found in this tenant.');
                }

                $changed = [];
                foreach ([
                    'name' => $input['name'],
                    'facility_type' => $input['type'],
                    'care_level' => $input['careLevel'],
                    'ownership_type' => $input['ownershipType'],
                    'status' => $input['status'],
                ] as $column => $value) {
                    if ((string) $facility->{$column} !== $value) {
                        $changed[$column] = $value;
                    }
                }
                if ($changed === []) {
                    return;
                }

                DB::table('org_facilities')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $facilityId)
                    ->update([...$changed, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: 'organization.facility.updated',
                    actorId: $actorId,
                    facilityId: $facilityId,
                    reason: 'Updated through the platform administration console.',
                    metadata: ['code' => (string) $facility->code, ...$changed],
                    targetType: 'facility',
                    targetId: $facilityId,
                );
            });
        });
    }

    /** @param string|list<string> $roleKey
     * @return array{email: string, userId: string, invitationId: string}
     */
    public function inviteFacilityUser(
        string $tenantId,
        string|array $roleKey,
        string $facilityId,
        string $name,
        string $email,
        string $changeReference,
        string $reason,
        User $actor,
        ScopeChecker $scopeChecker,
        ?string $staffId = null,
    ): array {
        if (! OneTimeInvitationMailGuard::canDeliver()) {
            throw new RuntimeException('The configured mail transport cannot deliver setup links without writing their secret URL to logs. Configure a delivery mailer before bootstrapping.');
        }

        $roleKeys = is_array($roleKey) ? $roleKey : [$roleKey];
        if ($roleKeys === []
            || count($roleKeys) > 3
            || array_filter($roleKeys, static fn (string $key): bool => FacilityUserRoleCatalog::label($key) === null) !== []
            || count(array_unique($roleKeys)) !== count($roleKeys)
            || (count($roleKeys) > 1 && array_filter(
                $roleKeys,
                static fn (string $key): bool => ! array_key_exists($key, FacilityUserRoleCatalog::STAFF_ROLES),
            ) !== [])) {
            throw new RuntimeException('The selected facility role cannot be invited.');
        }
        $primaryRoleKey = $roleKeys[0];

        $result = TenantContextScope::run($tenantId, null, function () use (
            $tenantId, $roleKeys, $primaryRoleKey, $facilityId, $name, $email, $changeReference, $reason, $actor, $scopeChecker, $staffId,
        ): array {
            abort_unless((string) $actor->tenant_id === $tenantId && $actor->isActive(), 403);
            $tenant = DB::table('shared_tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $facility = DB::table('org_facilities')
                ->where('tenant_id', $tenantId)
                ->where('id', $facilityId)
                ->lockForUpdate()
                ->first();
            if ($tenant === null || $tenant->status !== 'ACTIVE' || $facility === null || $facility->status !== 'ACTIVE') {
                throw new RuntimeException('An active tenant and facility are required to invite a facility user.');
            }
            $effectivePermissions = $scopeChecker->effectivePermissions($actor, $facilityId);
            $effectiveRoles = $scopeChecker->effectiveRoles($actor, $facilityId);
            $withinScope = $scopeChecker->withinFacilityScope($actor, $facilityId);
            $canManageIdentity = $withinScope
                && in_array('admin.workspace.access', $effectivePermissions, true)
                && in_array('identity.assignments.manage', $effectivePermissions, true);
            $canManageHR = $withinScope && in_array('hr.staff.manage', $effectivePermissions, true);
            $canInviteFacilityAdmin = $canManageIdentity
                && in_array('tenant_admin', $effectiveRoles, true);
            $canInviteStaffManager = ($canManageIdentity
                && array_intersect(['tenant_admin', 'facility_admin'], $effectiveRoles) !== [])
                || $canManageHR;
            $actorId = (string) $actor->getKey();
            foreach ($roleKeys as $selectedRoleKey) {
                if (($selectedRoleKey === FacilityUserRoleCatalog::ADMINISTRATOR && ! $canInviteFacilityAdmin)
                    || ($selectedRoleKey === FacilityUserRoleCatalog::HR_MANAGER && ! $canInviteStaffManager)
                    || (! in_array($selectedRoleKey, [
                        FacilityUserRoleCatalog::ADMINISTRATOR,
                        FacilityUserRoleCatalog::HR_MANAGER,
                    ], true) && ! $canManageIdentity && ! $canManageHR)) {
                    throw new RuntimeException('You are not authorized to invite the selected role for this facility.');
                }
                $enabledCapabilities = app(OrganizationApi::class)
                    ->enabledCapabilitiesForFacilities([$facilityId])[$facilityId] ?? [];
                if (! FacilityUserRoleCatalog::isAvailableAtFacility(
                    $selectedRoleKey,
                    $enabledCapabilities,
                )) {
                    throw new RuntimeException('The selected role requires a capability that is not enabled at this facility.');
                }
                if ($selectedRoleKey === FacilityUserRoleCatalog::ADMINISTRATOR) {
                    $this->ensureFacilityAdminRole($tenantId, $facilityId, $actorId, $reason);
                } elseif ($selectedRoleKey === FacilityUserRoleCatalog::HR_MANAGER) {
                    app(FacilityStaffManagerRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === 'laboratory_technician') {
                    app(LaboratoryTechnicianRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === FacilityUserRoleCatalog::CLINICIAN) {
                    app(ClinicianRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === 'nurse') {
                    app(NurseRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === 'receptionist') {
                    app(ReceptionistRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === 'pharmacist') {
                    app(PharmacistRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === 'cashier') {
                    app(CashierRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } elseif ($selectedRoleKey === 'billing_admin') {
                    app(BillingAdminRoleProvisioner::class)->provision($tenantId, $actorId, $facilityId);
                } else {
                    throw new RuntimeException(sprintf(
                        'No facility role provisioner is configured for [%s].',
                        $selectedRoleKey,
                    ));
                }
            }

            $pendingInvitation = DB::table('aut_admin_bootstrap_tokens as token')
                ->join('users as u', 'u.id', '=', 'token.user_id')
                ->where('token.tenant_id', $tenantId)
                ->where('token.facility_id', $facilityId)
                ->whereRaw('LOWER(u.email) = ?', [$email])
                ->where(function ($query) use ($primaryRoleKey): void {
                    $query->where(function ($legacy) use ($primaryRoleKey): void {
                        if ($primaryRoleKey === FacilityUserRoleCatalog::ADMINISTRATOR) {
                            $legacy->where('token.purpose', 'FACILITY_ADMIN')
                                ->whereNull('token.role_key');
                        } else {
                            $legacy->whereRaw('1 = 0');
                        }
                    })->orWhere(function ($generic) use ($primaryRoleKey): void {
                        $generic->where('token.purpose', 'FACILITY_ROLE')
                            ->where('token.role_key', $primaryRoleKey);
                    });
                })
                ->whereIn('token.status', [FacilityInvitationStatus::PENDING, FacilityInvitationStatus::EXPIRED])
                ->whereNull('token.consumed_at')
                ->lockForUpdate()
                ->first([
                    'token.id', 'token.expires_at', 'token.role_key', 'token.role_keys',
                    'token.status as invitation_status',
                    'u.id as user_id', 'u.email', 'u.email_verified_at', 'u.status as user_status',
                ]);
            if ($pendingInvitation !== null) {
                if ($pendingInvitation->email !== $email) {
                    throw new RuntimeException('A setup invitation is already pending; use the same email to resend it.');
                }
                if ($pendingInvitation->email_verified_at !== null || $pendingInvitation->user_status !== 'ACTIVE') {
                    throw new RuntimeException('The existing setup invitation is not eligible for resending.');
                }
                if (! in_array($pendingInvitation->invitation_status, [
                    FacilityInvitationStatus::PENDING,
                    FacilityInvitationStatus::EXPIRED,
                ], true)) {
                    throw new RuntimeException('The existing setup invitation cannot be resent.');
                }

                $userId = (string) $pendingInvitation->user_id;
                $invitationId = (string) Str::uuid7();
                $token = Str::random(64);
                $expiresAt = now()->addMinutes(60);
                $previousRoleKeys = $this->decodeInvitationRoles(
                    $pendingInvitation->role_keys ?? null,
                    $pendingInvitation->role_key ?? null,
                );

                DB::table('aut_admin_bootstrap_tokens')
                    ->where('id', $pendingInvitation->id)
                    ->whereIn('status', [FacilityInvitationStatus::PENDING, FacilityInvitationStatus::EXPIRED])
                    ->update([
                        'status' => FacilityInvitationStatus::SUPERSEDED,
                        'superseded_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('aut_admin_bootstrap_tokens')->insert([
                    'id' => $invitationId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'token_hash' => hash('sha256', $token),
                    'created_by_reference' => $changeReference,
                    'purpose' => 'FACILITY_ROLE',
                    'role_key' => $primaryRoleKey,
                    'role_keys' => json_encode($roleKeys, JSON_THROW_ON_ERROR),
                    'requested_by' => $actorId,
                    'facility_id' => $facilityId,
                    'request_reason' => $reason,
                    'status' => FacilityInvitationStatus::PENDING,
                    'sent_at' => now(),
                    'delivery_status' => 'QUEUED',
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->invitationEvents->record(
                    tenantId: $tenantId,
                    facilityId: $facilityId,
                    invitationId: (string) $pendingInvitation->id,
                    userId: $userId,
                    staffId: $staffId,
                    eventType: FacilityInvitationEventType::SUPERSEDED,
                    actorId: $actorId,
                    recipientEmail: $email,
                    roles: $previousRoleKeys,
                    expiresAt: (string) $pendingInvitation->expires_at,
                    reason: $reason,
                );
                $this->invitationEvents->record(
                    tenantId: $tenantId,
                    facilityId: $facilityId,
                    invitationId: $invitationId,
                    userId: $userId,
                    staffId: $staffId,
                    eventType: FacilityInvitationEventType::RESENT,
                    actorId: $actorId,
                    recipientEmail: $email,
                    roles: $roleKeys,
                    expiresAt: $expiresAt->toIso8601String(),
                    reason: $reason,
                    metadata: ['provisioning_reference' => $changeReference],
                );
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.facility_user.invitation_resent',
                    actorId: $actorId,
                    facilityId: $facilityId,
                    reason: $reason,
                    metadata: [
                        'provisioning_reference' => $changeReference,
                        'previous_invitation_id' => (string) $pendingInvitation->id,
                        'new_invitation_id' => $invitationId,
                    ],
                    targetType: 'user',
                    targetId: $userId,
                );

                return ['email' => $email, 'userId' => $userId, 'invitationId' => $invitationId, 'token' => $token];
            }
            if (DB::table('users')->where('email', $email)->exists()) {
                throw new RuntimeException('That email already belongs to an account.');
            }
            $userId = (string) Str::uuid7();
            $invitationId = (string) Str::uuid7();
            $token = Str::random(64);
            $expiresAt = now()->addMinutes(60);
            DB::transaction(function () use (
                $tenantId, $roleKeys, $primaryRoleKey, $facilityId, $name, $email, $changeReference, $reason, $actorId, $staffId, $userId, $invitationId, $token, $expiresAt, $canManageIdentity,
            ): void {
                DB::table('users')->insert([
                    'id' => $userId,
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(80)),
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('aut_admin_bootstrap_tokens')->insert([
                    'id' => $invitationId,
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'token_hash' => hash('sha256', $token),
                    'created_by_reference' => $changeReference,
                    'purpose' => 'FACILITY_ROLE',
                    'role_key' => $primaryRoleKey,
                    'role_keys' => json_encode($roleKeys, JSON_THROW_ON_ERROR),
                    'requested_by' => $actorId,
                    'facility_id' => $facilityId,
                    'request_reason' => $reason,
                    'status' => FacilityInvitationStatus::PENDING,
                    'sent_at' => now(),
                    'delivery_status' => 'QUEUED',
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->invitationEvents->record(
                    tenantId: $tenantId,
                    facilityId: $facilityId,
                    invitationId: $invitationId,
                    userId: $userId,
                    staffId: $staffId,
                    eventType: FacilityInvitationEventType::ISSUED,
                    actorId: $actorId,
                    recipientEmail: $email,
                    roles: $roleKeys,
                    expiresAt: $expiresAt->toIso8601String(),
                    reason: $reason,
                    metadata: ['provisioning_reference' => $changeReference],
                );
                // Direct-grant eligibility mirrors AdminBootstrap setup-time rule:
                // ordinary operational roles grant immediately when the inviter
                // holds identity-grant authority; otherwise an independent
                // identity admin must approve. Facility Admin and HR manager
                // always require approval for new invitations; the inviting
                // Tenant Admin may approve the Facility Admin request, while HR
                // manager requests still need an independent approver.
                $isDirectGrantEligible = $canManageIdentity
                    && array_filter(
                        $roleKeys,
                        static fn (string $selectedRoleKey): bool => ! array_key_exists($selectedRoleKey, FacilityUserRoleCatalog::STAFF_ROLES),
                    ) === [];
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.facility_user.invited',
                    actorId: $actorId,
                    facilityId: $facilityId,
                    reason: $reason,
                    metadata: [
                        'provisioning_reference' => $changeReference,
                        'roles' => $roleKeys,
                        'approval_required' => ! $isDirectGrantEligible,
                        'self_approval_allowed' => $roleKeys === [FacilityUserRoleCatalog::ADMINISTRATOR],
                    ],
                    targetType: 'user',
                    targetId: $userId,
                );
            });

            return ['email' => $email, 'userId' => $userId, 'invitationId' => $invitationId, 'token' => $token];
        });

        DB::afterCommit(function () use ($result, $tenantId, $staffId): void {
            try {
                Mail::to($result['email'])->send(new AdminBootstrapInvitation($result['token']));
                TenantContextScope::run($tenantId, null, function () use ($result, $tenantId, $staffId): void {
                    DB::table('aut_admin_bootstrap_tokens')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $result['invitationId'])
                        ->update(['delivery_status' => 'SENT', 'updated_at' => now()]);
                    $this->invitationEvents->record(
                        tenantId: $tenantId,
                        facilityId: $this->facilityIdForInvitation($tenantId, $result['invitationId']),
                        invitationId: $result['invitationId'],
                        userId: $result['userId'],
                        staffId: $staffId,
                        eventType: FacilityInvitationEventType::DELIVERY_SENT,
                        recipientEmail: $result['email'],
                    );
                });
            } catch (Throwable $exception) {
                Log::error('Facility setup invitation email delivery failed.', [
                    'tenant_id' => $tenantId,
                    'invitation_id' => $result['invitationId'],
                    'exception' => $exception,
                ]);
                TenantContextScope::run($tenantId, null, function () use ($result, $tenantId, $staffId): void {
                    DB::table('aut_admin_bootstrap_tokens')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $result['invitationId'])
                        ->update(['delivery_status' => 'FAILED', 'updated_at' => now()]);
                    $this->invitationEvents->record(
                        tenantId: $tenantId,
                        facilityId: $this->facilityIdForInvitation($tenantId, $result['invitationId']),
                        invitationId: $result['invitationId'],
                        userId: $result['userId'],
                        staffId: $staffId,
                        eventType: FacilityInvitationEventType::DELIVERY_FAILED,
                        recipientEmail: $result['email'],
                    );
                });

                throw $exception;
            }
        });

        return [
            'email' => $result['email'],
            'userId' => $result['userId'],
            'invitationId' => $result['invitationId'],
        ];
    }

    /**
     * Revoke an unused setup invitation. This does not delete the account or
     * the staff link; unlink is a separate, explicit recovery action.
     */
    public function revokeFacilityInvitation(
        string $tenantId,
        string $facilityId,
        string $invitationId,
        User $actor,
        string $reason,
        ?string $staffId = null,
    ): void {
        TenantContextScope::run($tenantId, null, function () use (
            $tenantId, $facilityId, $invitationId, $actor, $reason, $staffId,
        ): void {
            abort_unless((string) $actor->tenant_id === $tenantId && $actor->isActive(), 403);

            $invitation = DB::table('aut_admin_bootstrap_tokens as token')
                ->join('users as account', 'account.id', '=', 'token.user_id')
                ->where('token.tenant_id', $tenantId)
                ->where('token.facility_id', $facilityId)
                ->where('token.id', $invitationId)
                ->where('token.purpose', 'FACILITY_ROLE')
                ->lockForUpdate()
                ->first([
                    'token.id', 'token.user_id', 'token.expires_at', 'token.consumed_at',
                    'token.role_key', 'token.role_keys', 'token.status',
                    'account.email', 'account.email_verified_at',
                ]);
            if ($invitation === null) {
                throw new RuntimeException('The setup invitation was not found at this facility.');
            }
            if ($invitation->consumed_at !== null
                || $invitation->email_verified_at !== null
                || ! in_array((string) $invitation->status, [
                    FacilityInvitationStatus::PENDING,
                    FacilityInvitationStatus::EXPIRED,
                ], true)) {
                throw new RuntimeException('Only an unused, unaccepted setup invitation can be revoked.');
            }

            $actorId = (string) $actor->getKey();
            DB::table('aut_admin_bootstrap_tokens')
                ->where('id', $invitationId)
                ->whereIn('status', [FacilityInvitationStatus::PENDING, FacilityInvitationStatus::EXPIRED])
                ->update([
                    'status' => FacilityInvitationStatus::REVOKED,
                    'revoked_at' => now(),
                    'revoked_by' => $actorId,
                    'revocation_reason' => $reason,
                    'updated_at' => now(),
                ]);
            $this->invitationEvents->record(
                tenantId: $tenantId,
                facilityId: $facilityId,
                invitationId: $invitationId,
                userId: (string) $invitation->user_id,
                staffId: $staffId,
                eventType: FacilityInvitationEventType::REVOKED,
                actorId: $actorId,
                recipientEmail: (string) $invitation->email,
                roles: $this->decodeInvitationRoles($invitation->role_keys, $invitation->role_key),
                expiresAt: (string) $invitation->expires_at,
                reason: $reason,
                metadata: ['previous_status' => (string) $invitation->status],
            );
            app(AuditLogger::class)->recordFor(
                tenantId: $tenantId,
                action: 'identity.facility_user.invitation_revoked',
                actorId: $actorId,
                facilityId: $facilityId,
                reason: $reason,
                metadata: ['invitation_id' => $invitationId],
                targetType: 'user',
                targetId: (string) $invitation->user_id,
            );
        });
    }

    /** @return list<string> */
    private function decodeInvitationRoles(mixed $roleKeys, mixed $roleKey): array
    {
        $decoded = is_string($roleKeys) ? json_decode($roleKeys, true) : null;
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        return is_string($roleKey) && $roleKey !== '' ? [$roleKey] : [];
    }

    private function facilityIdForInvitation(string $tenantId, string $invitationId): ?string
    {
        $facilityId = DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->value('facility_id');

        return is_string($facilityId) ? $facilityId : null;
    }

    private function ensureFacilityAdminRole(
        string $tenantId,
        string $facilityId,
        string $actorId,
        string $reason,
    ): void {
        foreach (FacilityAdminRoleDefinition::PERMISSIONS as $permission) {
            if (! PermissionRegistry::has($permission)) {
                throw new RuntimeException("Required permission '{$permission}' is not registered.");
            }
        }

        $role = DB::table('idn_roles')
            ->where('tenant_id', $tenantId)
            ->where('key', FacilityAdminRoleDefinition::KEY)
            ->lockForUpdate()
            ->first();
        $roleWasCreated = $role === null;
        $roleProtectionWasRepaired = false;

        if ($role !== null) {
            if ($role->scope_type !== 'FACILITY'
                || $role->status !== 'ACTIVE'
                || $role->parent_role_id !== null) {
                throw new RuntimeException('The existing Facility Admin role structure differs from its approved definition.');
            }
            $existingPermissions = DB::table('idn_permission_role as pr')
                ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('pr.role_id', $role->id)
                ->pluck('p.key')
                ->sort()
                ->values()
                ->all();
            $unexpectedPermissions = array_diff($existingPermissions, FacilityAdminRoleDefinition::PERMISSIONS);
            if ($unexpectedPermissions !== []) {
                throw new RuntimeException('The existing Facility Admin role contains permissions outside its approved definition.');
            }
            $roleId = (string) $role->id;
            $missingPermissions = array_values(array_diff(FacilityAdminRoleDefinition::PERMISSIONS, $existingPermissions));
            if (! $role->is_protected) {
                DB::table('idn_roles')->where('id', $roleId)->update([
                    'is_protected' => true,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
                $roleProtectionWasRepaired = true;
            }
        } else {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId,
                'tenant_id' => $tenantId,
                'key' => FacilityAdminRoleDefinition::KEY,
                'name' => 'Facility Administrator',
                'status' => 'ACTIVE',
                'scope_type' => 'FACILITY',
                'is_protected' => true,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $missingPermissions = FacilityAdminRoleDefinition::PERMISSIONS;
        }

        foreach ($missingPermissions as $permissionKey) {
            $permissionId = DB::table('idn_permissions')->where('key', $permissionKey)->value('id');
            if ($permissionId === null) {
                $permissionId = (string) Str::uuid7();
                DB::table('idn_permissions')->insert([
                    'id' => $permissionId,
                    'key' => $permissionKey,
                    'layer' => str_ends_with($permissionKey, '.access') ? 'ACCESS' : 'ACTION',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($roleWasCreated || $roleProtectionWasRepaired || $missingPermissions !== []) {
            app(AuditLogger::class)->recordFor(
                tenantId: $tenantId,
                action: $roleWasCreated
                    ? 'identity.facility_admin.role_provisioned'
                    : 'identity.facility_admin.role_definition_reconciled',
                actorId: $actorId,
                facilityId: $facilityId,
                reason: $reason,
                metadata: [
                    'role' => FacilityAdminRoleDefinition::KEY,
                    'permissions_added' => $missingPermissions,
                    'protected_flag_repaired' => $roleProtectionWasRepaired,
                ],
                targetType: 'role',
                targetId: $roleId,
            );
        }
    }

    public function requestFacilityAdminRevocation(
        string $tenantId,
        string $facilityId,
        string $assignmentId,
        string $reason,
        string $actorId,
        ScopeChecker $scopeChecker,
    ): bool {
        return TenantContextScope::run($tenantId, null, function () use (
            $tenantId, $facilityId, $assignmentId, $reason, $actorId, $scopeChecker,
        ): bool {
            return DB::transaction(function () use (
                $tenantId, $facilityId, $assignmentId, $reason, $actorId, $scopeChecker,
            ): bool {
                $assignment = DB::table('idn_access_assignments as aa')
                    ->join('idn_roles as role', 'role.id', '=', 'aa.role_id')
                    ->join('users as u', 'u.id', '=', 'aa.user_id')
                    ->where('aa.tenant_id', $tenantId)
                    ->where('aa.facility_id', $facilityId)
                    ->where('aa.id', $assignmentId)
                    ->where('aa.status', 'ACTIVE')
                    ->where('role.key', 'facility_admin')
                    ->where('role.is_protected', true)
                    ->lockForUpdate()
                    ->first([
                        'aa.id', 'aa.user_id', 'aa.role_id', 'aa.organization_id',
                        'aa.status as assignment_status',
                    ]);
                if ($assignment === null || $assignment->assignment_status !== 'ACTIVE') {
                    throw new RuntimeException('The active Facility Admin assignment was not found.');
                }
                if (DB::table('idn_admin_role_promotion_requests')
                    ->where('tenant_id', $tenantId)
                    ->where('assignment_id', $assignmentId)
                    ->where('action', 'REVOKE')
                    ->where('status', 'PENDING')
                    ->exists()) {
                    throw new RuntimeException('A revocation request for this assignment is already pending.');
                }

                $candidateIds = DB::table('idn_access_assignments as aa')
                    ->join('idn_roles as role', 'role.id', '=', 'aa.role_id')
                    ->join('users as u', 'u.id', '=', 'aa.user_id')
                    ->where('aa.tenant_id', $tenantId)
                    ->where('aa.status', 'ACTIVE')
                    ->where('role.status', 'ACTIVE')
                    ->where('u.status', 'ACTIVE')
                    ->whereNotNull('u.email_verified_at')
                    ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
                    ->distinct()
                    ->pluck('aa.user_id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all();
                $otherAdmins = [];
                foreach (User::query()->where('tenant_id', $tenantId)->whereIn('id', $candidateIds)->get() as $candidate) {
                    if ((string) $candidate->getKey() === (string) $assignment->user_id) {
                        continue;
                    }
                    $permissions = $scopeChecker->effectivePermissions($candidate, $facilityId);
                    if (in_array('admin.workspace.access', $permissions, true)
                        && in_array('identity.assignments.manage', $permissions, true)) {
                        $otherAdmins[] = (string) $candidate->getKey();
                    }
                }

                if ($otherAdmins === []) {
                    DB::table('idn_access_assignments')->where('id', $assignmentId)->update([
                        'status' => 'REVOKED',
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                    app(AuditLogger::class)->recordFor(
                        tenantId: $tenantId,
                        action: 'identity.facility_admin.emergency_revoked',
                        actorId: $actorId,
                        facilityId: $facilityId,
                        reason: $reason,
                        metadata: [
                            'assignment' => $assignmentId,
                            'sole_facility_administrator_recovery' => true,
                        ],
                        targetType: 'assignment',
                        targetId: $assignmentId,
                    );

                    return true;
                }

                $id = (string) Str::uuid7();
                DB::table('idn_admin_role_promotion_requests')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'organization_id' => $assignment->organization_id,
                    'facility_id' => $facilityId,
                    'target_user_id' => $assignment->user_id,
                    'role_id' => $assignment->role_id,
                    'role_key' => 'facility_admin',
                    'scope_type' => 'FACILITY',
                    'action' => 'REVOKE',
                    'status' => 'PENDING',
                    'reason' => $reason,
                    'requested_by' => $actorId,
                    'assignment_id' => $assignmentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.facility_admin.revocation_requested',
                    actorId: $actorId,
                    facilityId: $facilityId,
                    reason: $reason,
                    metadata: ['request' => $id, 'assignment' => $assignmentId],
                    targetType: 'assignment',
                    targetId: $assignmentId,
                );

                return false;
            });
        });
    }

    public function emergencyRevokeTenantAdmin(
        string $tenantId,
        string $assignmentId,
        string $reason,
        string $actorId,
    ): void {
        TenantContextScope::run($tenantId, null, function () use (
            $tenantId, $assignmentId, $reason, $actorId,
        ): void {
            DB::transaction(function () use (
                $tenantId, $assignmentId, $reason, $actorId,
            ): void {
                $role = DB::table('idn_roles')
                    ->where('tenant_id', $tenantId)
                    ->where('key', TenantAdminRoleDefinition::KEY)
                    ->where('scope_type', 'ORG')
                    ->where('is_protected', true)
                    ->where('status', 'ACTIVE')
                    ->first(['id']);
                if ($role === null) {
                    throw new RuntimeException('The protected Tenant Admin role is unavailable.');
                }

                $organizationId = DB::table('org_organizations')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'ACTIVE')
                    ->value('id');
                if (! is_string($organizationId)) {
                    throw new RuntimeException('The tenant has no active organization.');
                }

                $assignments = DB::table('idn_access_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('organization_id', $organizationId)
                    ->whereNull('facility_id')
                    ->where('role_id', $role->id)
                    ->where('status', 'ACTIVE')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'user_id']);
                $assignment = $assignments->first(
                    static fn (object $row): bool => (string) $row->id === $assignmentId,
                );
                if ($assignment === null) {
                    throw new RuntimeException('The active Tenant Admin assignment was not found.');
                }
                if (DB::table('idn_admin_role_promotion_requests')
                    ->where('tenant_id', $tenantId)
                    ->where('assignment_id', $assignmentId)
                    ->where('action', 'REVOKE')
                    ->where('status', 'PENDING')
                    ->exists()) {
                    throw new RuntimeException('A revocation request for this assignment is already pending.');
                }

                DB::table('idn_access_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $assignmentId)
                    ->update([
                        'status' => 'REVOKED',
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                DB::table('idn_emergency_access_reviews')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'assignment_id' => $assignmentId,
                    'target_user_id' => $assignment->user_id,
                    'requested_by' => $actorId,
                    'reason' => $reason,
                    'status' => 'PENDING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sessionsRevoked = DB::table('aut_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $assignment->user_id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);

                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.tenant_admin.emergency_revoked',
                    actorId: $actorId,
                    reason: $reason,
                    metadata: [
                        'assignment' => $assignmentId,
                        'scope' => 'ORG',
                        'emergency' => true,
                        'post_incident_review_required' => true,
                        'sessions_revoked' => $sessionsRevoked,
                    ],
                    targetType: 'assignment',
                    targetId: $assignmentId,
                );

            });
        });
    }

    public function reviewEmergencyTenantAdminRevocation(
        string $tenantId,
        string $reviewId,
        string $reviewerId,
        string $reviewNotes,
    ): void {
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $reviewId, $reviewerId, $reviewNotes): void {
            DB::transaction(function () use ($tenantId, $reviewId, $reviewerId, $reviewNotes): void {
                $review = DB::table('idn_emergency_access_reviews')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $reviewId)
                    ->lockForUpdate()
                    ->first();
                if ($review === null || $review->status !== 'PENDING') {
                    throw new RuntimeException('The emergency access review is unavailable or already complete.');
                }
                if ((string) $review->requested_by === $reviewerId) {
                    throw new RuntimeException('A different platform administrator must review this emergency action.');
                }

                DB::table('idn_emergency_access_reviews')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $reviewId)
                    ->update([
                        'status' => 'REVIEWED',
                        'reviewed_by' => $reviewerId,
                        'review_notes' => $reviewNotes,
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);
                app(AuditLogger::class)->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.tenant_admin.emergency_revocation_reviewed',
                    actorId: $reviewerId,
                    reason: $reviewNotes,
                    metadata: [
                        'emergency_review' => $reviewId,
                        'assignment' => (string) $review->assignment_id,
                        'initiated_by' => (string) $review->requested_by,
                        'status' => 'REVIEWED',
                    ],
                    targetType: 'assignment',
                    targetId: (string) $review->assignment_id,
                );
            });
        });
    }

    /**
     * @param  array{tenantName: string, tenantSlug: string, organizationName: string, organizationCode: string, facilityName: string, facilityCode: string, facilityType: string, careLevel: string, ownershipType: string}  $input
     * @return array{tenantId: string, organizationId: string, facilityId: string}
     */
    public function createTenantWithInitialFacility(
        array $input,
        string $actorId,
        OrganizationApi $organizationApi,
    ): array {
        return DB::transaction(function () use ($input, $actorId, $organizationApi): array {
            if (DB::table('shared_tenants')->where('slug', $input['tenantSlug'])->exists()) {
                throw new RuntimeException('That tenant URL slug is already in use.');
            }

            $tenantId = (string) Str::uuid7();
            DB::table('shared_tenants')->insert([
                'id' => $tenantId,
                'name' => $input['tenantName'],
                'slug' => $input['tenantSlug'],
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(AuditLogger::class)->recordPlatform(
                action: 'platform.tenant.created',
                actorId: $actorId,
                targetType: 'tenant',
                targetId: $tenantId,
                reason: 'Created through platform Super Admin tenant setup.',
                metadata: ['slug' => $input['tenantSlug']],
            );

            $structure = TenantContextScope::run($tenantId, null, function () use ($input, $actorId, $organizationApi, $tenantId): array {
                $organizationApi->provisionInitialTenantStructure(new InitialTenantStructure(
                    organizationName: $input['organizationName'],
                    organizationCode: $input['organizationCode'],
                    facilityName: $input['facilityName'],
                    facilityCode: $input['facilityCode'],
                    facilityType: $input['facilityType'],
                    careLevel: $input['careLevel'],
                    ownershipType: $input['ownershipType'],
                    actorId: $actorId,
                ));

                $organizationId = (string) DB::table('org_organizations')->value('id');
                $facilityId = (string) DB::table('org_facilities')->value('id');
                $this->ensureFacilityAdminRole(
                    tenantId: $tenantId,
                    facilityId: $facilityId,
                    actorId: $actorId,
                    reason: 'Provisioned as part of platform tenant setup.',
                );
                app(TenantAdminRoleProvisioner::class)->provision(
                    tenantId: $tenantId,
                    actorId: $actorId,
                    reason: 'Provisioned as part of platform tenant setup.',
                );

                return [
                    'organizationId' => $organizationId,
                    'facilityId' => $facilityId,
                ];
            });

            return ['tenantId' => $tenantId, ...$structure];
        });
    }

    /**
     * @return array{email: string, token: string}
     */
    public function inviteInitialTenantAdmin(
        string $tenantId,
        string $name,
        string $email,
        string $changeReference,
        string $actorId,
        bool $resend,
    ): array {
        if (! OneTimeInvitationMailGuard::canDeliver()) {
            throw new RuntimeException('The configured mail transport cannot deliver setup links without writing their secret URL to logs. Configure a delivery mailer before bootstrapping.');
        }

        $result = TenantContextScope::run($tenantId, null, function () use (
            $tenantId,
            $name,
            $email,
            $changeReference,
            $actorId,
            $resend,
        ): array {
            $tenant = DB::table('shared_tenants')->where('id', $tenantId)->lockForUpdate()->first();
            if ($tenant === null || $tenant->status !== 'ACTIVE') {
                throw new RuntimeException('An active tenant is required.');
            }

            $organizations = DB::table('org_organizations')->where('tenant_id', $tenantId)->get(['id', 'status']);
            $organization = $organizations->first();
            if ($organizations->count() !== 1 || $organization === null || $organization->status !== 'ACTIVE') {
                throw new RuntimeException('Initial Tenant Admin setup requires exactly one active organization.');
            }

            $activeAdminExists = DB::table('idn_access_assignments as aa')
                ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
                ->where('aa.tenant_id', $tenantId)
                ->where('r.key', TenantAdminRoleDefinition::KEY)
                ->where('r.status', 'ACTIVE')
                ->where('aa.status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
                ->exists();
            if ($activeAdminExists) {
                throw new RuntimeException('An effective Tenant Admin already exists.');
            }

            $pending = DB::table('aut_admin_bootstrap_tokens as t')
                ->join('users as u', 'u.id', '=', 't.user_id')
                ->where('t.tenant_id', $tenantId)
                ->where('t.purpose', 'TENANT_ADMIN')
                ->whereNull('t.facility_id')
                ->whereNull('t.consumed_at')
                ->lockForUpdate()
                ->get(['t.id', 't.user_id', 'u.email', 'u.email_verified_at', 'u.status']);
            if ($pending->count() > 1) {
                throw new RuntimeException('Multiple pending initial-admin invitations require operator review.');
            }
            $invitation = $pending->first();
            if ($invitation !== null && ($invitation->email !== $email || ! $resend)) {
                throw new RuntimeException('A pending invitation already exists; resend it using the same email.');
            }
            if ($invitation !== null && ($invitation->email_verified_at !== null || $invitation->status !== 'ACTIVE')) {
                throw new RuntimeException('The pending invitation is not eligible for resending.');
            }
            if ($invitation === null && $resend) {
                throw new RuntimeException('No pending invitation exists to resend.');
            }
            if ($invitation === null && DB::table('users')->where('email', $email)->exists()) {
                throw new RuntimeException('That email already belongs to an account.');
            }

            $roleId = app(TenantAdminRoleProvisioner::class)->provision(
                tenantId: $tenantId,
                actorId: $actorId,
                reason: 'Provisioned during initial Tenant Admin invitation.',
            );

            if ($invitation !== null) {
                $pendingAssignments = DB::table('idn_access_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $invitation->user_id)
                    ->where('role_id', $roleId)
                    ->where('status', 'PENDING')
                    ->whereNull('facility_id')
                    ->where('organization_id', $organization->id)
                    ->count();
                if ($pendingAssignments > 1) {
                    throw new RuntimeException('The pending invitation has no matching Tenant Admin assignment.');
                }
                if ($pendingAssignments === 0) {
                    DB::table('idn_access_assignments')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'user_id' => $invitation->user_id,
                        'role_id' => $roleId,
                        'organization_id' => $organization->id,
                        'status' => 'PENDING',
                        'is_primary' => true,
                        'version' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $userId = $invitation->user_id ?? (string) Str::uuid7();
            if ($invitation === null) {
                DB::table('users')->insert([
                    'id' => $userId,
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(80)),
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('idn_access_assignments')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'organization_id' => $organization->id,
                    'status' => 'PENDING',
                    'is_primary' => true,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $token = Str::random(64);
            $tokenHash = hash('sha256', $token);
            $expiresAt = now()->addMinutes(60);
            if ($invitation === null) {
                DB::table('aut_admin_bootstrap_tokens')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'created_by_reference' => $changeReference,
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('aut_admin_bootstrap_tokens')->where('id', $invitation->id)->update([
                    'token_hash' => $tokenHash,
                    'created_by_reference' => $changeReference,
                    'expires_at' => $expiresAt,
                    'updated_at' => now(),
                ]);
            }

            app(AuditLogger::class)->recordFor(
                tenantId: $tenantId,
                action: $invitation === null
                    ? 'admin.tenant_admin.bootstrap_invited'
                    : 'admin.tenant_admin.bootstrap_invitation_resent',
                actorId: $actorId,
                targetType: 'user',
                targetId: $userId,
                reason: 'Platform Super Admin provisioning reference: '.$changeReference,
                metadata: ['provisioning_reference' => $changeReference, 'initiated_by_platform_admin' => true],
            );

            return ['email' => $email, 'token' => $token];
        });

        Mail::to($result['email'])->send(new AdminBootstrapInvitation($result['token']));

        return $result;
    }
}
