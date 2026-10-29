<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Infrastructure\Writer;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Authentication\Support\FacilityInvitationEventType;
use App\Domains\Authentication\Support\FacilityInvitationStatus;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminBootstrapCompletionWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ScopeChecker $scopeChecker,
        private readonly IdentityApi $identity,
        private readonly OrganizationApi $organization,
        private readonly FacilityInvitationEventWriter $invitationEvents,
    ) {}

    public function complete(
        string $tokenHash,
        string $password,
    ): bool {
        $tenantId = DB::table('aut_admin_bootstrap_tokens')
            ->where('token_hash', $tokenHash)
            ->value('tenant_id');
        if (! is_string($tenantId)) {
            throw ValidationException::withMessages([
                'token' => 'This setup link is invalid or has expired. Ask the administrator who invited you to send a new invitation.',
            ]);
        }

        $accessAwaitingApproval = false;
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $tokenHash, $password, &$accessAwaitingApproval): void {
            $invitation = DB::table('aut_admin_bootstrap_tokens')
                ->where('tenant_id', $tenantId)
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if ($invitation === null
                || $invitation->consumed_at !== null
                || in_array((string) ($invitation->status ?? FacilityInvitationStatus::PENDING), [
                    FacilityInvitationStatus::REVOKED,
                    FacilityInvitationStatus::SUPERSEDED,
                ], true)
                || ! now()->lt($invitation->expires_at)) {
                throw ValidationException::withMessages([
                    'token' => 'This setup link is invalid or has expired. Ask the administrator who invited you to send a new invitation.',
                ]);
            }

            $user = User::query()
                ->whereKey($invitation->user_id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();
            if (! ($user instanceof User && $user->isActive() && $user->email_verified_at === null)) {
                throw ValidationException::withMessages([
                    'token' => 'This account setup invitation can no longer be used. Contact the administrator who invited you.',
                ]);
            }

            if (in_array($invitation->purpose ?? 'TENANT_ADMIN', ['FACILITY_ADMIN', 'FACILITY_ROLE'], true)) {
                $facilityId = (string) $invitation->facility_id;
                $requestedBy = (string) $invitation->requested_by;
                $roleKey = (string) ($invitation->role_key ?? FacilityUserRoleCatalog::ADMINISTRATOR);
                $decodedRoleKeys = is_string($invitation->role_keys ?? null)
                    ? json_decode($invitation->role_keys, true)
                    : null;
                $roleKeys = is_array($decodedRoleKeys) ? array_values($decodedRoleKeys) : [$roleKey];
                abort_unless(
                    $roleKeys !== []
                        && count($roleKeys) <= 3
                        && array_filter($roleKeys, static fn ($key): bool => ! is_string($key)
                            || FacilityUserRoleCatalog::label($key) === null) === []
                        && count(array_unique($roleKeys)) === count($roleKeys)
                        && (count($roleKeys) === 1 || array_filter(
                            $roleKeys,
                            static fn (string $key): bool => ! array_key_exists($key, FacilityUserRoleCatalog::STAFF_ROLES),
                        ) === []),
                    403,
                    'The requested facility roles are invalid.',
                );
                $facility = DB::table('org_facilities')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $facilityId)
                    ->where('status', 'ACTIVE')
                    ->first(['id', 'organization_id']);
                abort_unless($facility !== null, 403, 'The invited facility is no longer active.');
                $enabledCapabilities = $this->organization
                    ->enabledCapabilitiesForFacilities([$facilityId])[$facilityId] ?? [];

                $inviter = User::query()
                    ->whereKey($requestedBy)
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'ACTIVE')
                    ->whereNotNull('email_verified_at')
                    ->first();
                $inviterRoles = $inviter instanceof User
                    ? $this->scopeChecker->effectiveRoles($inviter, $facilityId)
                    : [];
                $inviterPermissions = $inviter instanceof User
                    ? $this->scopeChecker->effectivePermissions($inviter, $facilityId)
                    : [];
                $withinFacilityScope = $inviter instanceof User
                    && $this->scopeChecker->withinFacilityScope($inviter, $facilityId);
                $canManageIdentity = $withinFacilityScope
                    && in_array('admin.workspace.access', $inviterPermissions, true)
                    && in_array('identity.assignments.manage', $inviterPermissions, true);
                $canManageHR = $withinFacilityScope && in_array('hr.staff.manage', $inviterPermissions, true);
                $isTenantAdmin = in_array('tenant_admin', $inviterRoles, true);
                $isFacilityAdmin = in_array('facility_admin', $inviterRoles, true);
                $legacyPlatformInvitation = DB::table('platform_super_admins')
                    ->where('user_id', $requestedBy)
                    ->where('status', 'ACTIVE')
                    ->exists();
                foreach ($roleKeys as $index => $roleKey) {
                    abort_unless(
                        FacilityUserRoleCatalog::isAvailableAtFacility(
                            $roleKey,
                            $enabledCapabilities,
                        ),
                        403,
                        'The requested role requires a capability that is no longer enabled at this facility.',
                    );
                    $isPrimaryRole = $index === 0;
                    $authorizedInviter = match ($roleKey) {
                        FacilityUserRoleCatalog::ADMINISTRATOR => $canManageIdentity && $isTenantAdmin,
                        FacilityUserRoleCatalog::HR_MANAGER => ($canManageIdentity && ($isTenantAdmin || $isFacilityAdmin))
                            || $canManageHR,
                        default => $canManageIdentity || $canManageHR,
                    };
                    if (! ($authorizedInviter
                        || ($roleKey === FacilityUserRoleCatalog::ADMINISTRATOR && $legacyPlatformInvitation))) {
                        throw ValidationException::withMessages([
                            'token' => 'The inviter no longer has permission to complete this access request. Your password was not saved; contact the inviter or your facility administrator to review and resend the invitation.',
                        ]);
                    }

                    $roleId = array_key_exists($roleKey, FacilityUserRoleCatalog::STAFF_ROLES)
                        ? $this->identity->provisionAccountSetupFacilityRole(
                            $tenantId,
                            $requestedBy,
                            $facilityId,
                            $roleKey,
                        )
                        : ($this->identity->activeProtectedFacilityRoleId($tenantId, $roleKey)
                            ?? abort(403, 'The requested protected facility role is unavailable.'));

                    // Ordinary operational roles can be granted directly by an
                    // identity administrator; HR-only requests remain two-person approvals.
                    $isOrdinaryRole = array_key_exists($roleKey, FacilityUserRoleCatalog::STAFF_ROLES);
                    $canGrantDirectly = $canManageIdentity && $isOrdinaryRole;

                    if ($canGrantDirectly) {
                        $this->identity->assertEligibleForAccountSetup($tenantId, (string) $user->getKey());
                        $existingActive = DB::table('idn_access_assignments')
                            ->where('tenant_id', $tenantId)
                            ->where('user_id', $user->getKey())
                            ->where('role_id', $roleId)
                            ->where('facility_id', $facilityId)
                            ->where('status', 'ACTIVE')
                            ->exists();
                        abort_if($existingActive, 409, 'This account already has the requested facility role.');

                        $assignmentId = (string) Str::uuid7();
                        DB::table('idn_access_assignments')->insert([
                            'id' => $assignmentId,
                            'tenant_id' => $tenantId,
                            'user_id' => $user->getKey(),
                            'role_id' => $roleId,
                            'organization_id' => (string) $facility->organization_id,
                            'facility_id' => $facilityId,
                            'status' => 'ACTIVE',
                            'is_primary' => $isPrimaryRole,
                            'granted_by' => $requestedBy,
                            'version' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $this->audit->recordFor(
                            tenantId: $tenantId,
                            action: 'identity.facility_user.granted',
                            actorId: $requestedBy,
                            facilityId: $facilityId,
                            reason: (string) $invitation->request_reason,
                            metadata: [
                                'assignment' => $assignmentId,
                                'role' => $roleKey,
                                'is_primary' => $isPrimaryRole,
                                'scope' => 'FACILITY',
                                'status' => 'ACTIVE',
                                'provisioning_reference' => $invitation->created_by_reference,
                            ],
                            targetType: 'user',
                            targetId: (string) $user->getKey(),
                        );
                    } else {
                        $accessAwaitingApproval = true;
                        $requestId = (string) Str::uuid7();
                        DB::table('idn_admin_role_promotion_requests')->insert([
                            'id' => $requestId,
                            'tenant_id' => $tenantId,
                            'organization_id' => (string) $facility->organization_id,
                            'facility_id' => $facilityId,
                            'target_user_id' => (string) $user->getKey(),
                            'role_id' => $roleId,
                            'role_key' => $roleKey,
                            'scope_type' => 'FACILITY',
                            'action' => 'PROMOTE',
                            'is_primary' => $isPrimaryRole,
                            'status' => 'PENDING',
                            'reason' => (string) $invitation->request_reason,
                            'requested_by' => $requestedBy,
                            'request_origin' => 'FACILITY_INVITATION',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $this->audit->recordFor(
                            tenantId: $tenantId,
                            action: 'identity.admin_role_promotion.requested',
                            actorId: $requestedBy,
                            facilityId: $facilityId,
                            reason: (string) $invitation->request_reason,
                            metadata: [
                                'promotion_request' => $requestId,
                                'role' => $roleKey,
                                'is_primary' => $isPrimaryRole,
                                'scope' => 'FACILITY',
                                'status' => 'PENDING',
                                'request_origin' => 'FACILITY_INVITATION',
                                'self_approval_allowed' => $canManageIdentity || $canManageHR,
                                'provisioning_reference' => $invitation->created_by_reference,
                            ],
                            targetType: 'user',
                            targetId: (string) $user->getKey(),
                        );
                    }
                }
            } else {
                $this->identity->assertEligibleForAccountSetup($tenantId, (string) $user->getKey());
                $roleId = $this->identity->provisionAccountSetupTenantAdmin(
                    $tenantId,
                    'Reconciled during one-time Tenant Admin account setup.',
                );
                $organization = DB::table('org_organizations')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'ACTIVE')
                    ->sole(['id']);
                $pendingAssignmentQuery = DB::table('idn_access_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $user->getKey())
                    ->where('role_id', $roleId)
                    ->where('status', 'PENDING')
                    ->where('organization_id', $organization->id)
                    ->whereNull('facility_id');
                $pendingAssignmentCount = (clone $pendingAssignmentQuery)->count();
                abort_if($pendingAssignmentCount > 1, 403, 'Duplicate pending Tenant Admin assignments require operator review.');
                if ($pendingAssignmentCount === 0) {
                    DB::table('idn_access_assignments')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'user_id' => $user->getKey(),
                        'role_id' => $roleId,
                        'organization_id' => $organization->id,
                        'status' => 'PENDING',
                        'is_primary' => true,
                        'version' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $pendingAssignmentQuery = DB::table('idn_access_assignments')
                        ->where('tenant_id', $tenantId)
                        ->where('user_id', $user->getKey())
                        ->where('role_id', $roleId)
                        ->where('status', 'PENDING')
                        ->where('organization_id', $organization->id)
                        ->whereNull('facility_id');
                }
                abort_unless(
                    $pendingAssignmentQuery->update(['status' => 'ACTIVE', 'updated_at' => now()]) === 1,
                    403,
                    'The pending Tenant Admin assignment is unavailable.',
                );
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->save();

            DB::table('aut_admin_bootstrap_tokens')
                ->where('id', $invitation->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now(),
                    'status' => FacilityInvitationStatus::CONSUMED,
                    'updated_at' => now(),
                ]);
            $this->invitationEvents->record(
                tenantId: $tenantId,
                facilityId: $invitation->facility_id === null ? null : (string) $invitation->facility_id,
                invitationId: (string) $invitation->id,
                userId: (string) $user->getKey(),
                staffId: null,
                eventType: FacilityInvitationEventType::CONSUMED,
                actorId: (string) $user->getKey(),
                recipientEmail: (string) $user->email,
                roles: is_string($invitation->role_keys ?? null)
                    ? array_values(array_filter(json_decode($invitation->role_keys, true) ?: [], 'is_string'))
                    : (is_string($invitation->role_key ?? null) ? [(string) $invitation->role_key] : []),
                expiresAt: (string) $invitation->expires_at,
                reason: 'Account setup completed by the invited user.',
            );

            $this->audit->recordFor(
                tenantId: $tenantId,
                action: ($invitation->purpose ?? 'TENANT_ADMIN') === 'TENANT_ADMIN'
                    ? 'admin.tenant_admin.bootstrap_completed'
                    : 'identity.account.setup_completed',
                facilityId: $invitation->facility_id === null ? null : (string) $invitation->facility_id,
                targetType: 'user',
                targetId: (string) $user->getKey(),
                reason: 'Operator provisioning reference: '.$invitation->created_by_reference,
                metadata: [
                    'provisioning_reference' => $invitation->created_by_reference,
                    'role' => $invitation->role_key ?? 'tenant_admin',
                ],
            );
        });

        return $accessAwaitingApproval;
    }
}
