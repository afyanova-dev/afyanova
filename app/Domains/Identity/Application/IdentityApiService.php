<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Identity\Contracts\ClinicianRoleDefinition;
use App\Domains\Identity\Contracts\Dto\ProtectedRoleReference;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Identity\Infrastructure\Reader\RoleReader;
use App\Domains\Identity\Infrastructure\Reader\StaffIdentityReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Support\Facades\DB;

final class IdentityApiService implements IdentityApi
{
    public function __construct(
        private readonly StaffIdentityReader $staff,
        private readonly RoleReader $roles,
        private readonly ScopeChecker $scope,
        private readonly FacilityStaffManagerRoleProvisioner $facilityStaffManagers,
        private readonly LinkedStaffGrantEligibility $grantEligibility,
        private readonly NurseRoleProvisioner $nurses,
        private readonly ClinicianRoleProvisioner $clinicians,
        private readonly PharmacistRoleProvisioner $pharmacists,
        private readonly LaboratoryTechnicianRoleProvisioner $laboratoryTechnicians,
        private readonly ReceptionistRoleProvisioner $receptionists,
        private readonly CashierRoleProvisioner $cashiers,
        private readonly BillingAdminRoleProvisioner $billingAdmins,
        private readonly TenantAdminRoleProvisioner $tenantAdmins,
    ) {}

    public function isStaff(string $userId): bool
    {
        return $this->staff->isStaff($userId);
    }

    public function staffRefForUser(string $userId): ?string
    {
        return $this->staff->forUser($userId)?->staff_ref;
    }

    public function userExists(string $userId): bool
    {
        return User::query()->whereKey($userId)->exists();
    }

    public function effectivePermissions(string $userId, ?string $facilityId): array
    {
        $user = User::query()->findOrFail($userId);

        return $this->scope->effectivePermissions($user, $facilityId);
    }

    public function protectedAdministrativeRoleByKey(
        string $tenantId,
        string $roleKey,
        string $scopeType,
    ): ?ProtectedRoleReference {
        return $this->roles->protectedAdministrativeRoleByKey($tenantId, $roleKey, $scopeType);
    }

    public function protectedAdministrativeRoleById(
        string $tenantId,
        string $roleId,
        string $roleKey,
    ): ?ProtectedRoleReference {
        return $this->roles->protectedAdministrativeRoleById($tenantId, $roleId, $roleKey);
    }

    public function unavailableFacilityRoleKeys(string $tenantId, array $roleKeys): array
    {
        return $this->roles->unavailableFacilityRoleKeys($tenantId, $roleKeys);
    }

    public function activeFacilityRoleAssignments(
        string $tenantId,
        string $roleKey,
        array $facilityIds,
    ): array {
        return $this->roles->activeFacilityRoleAssignments($tenantId, $roleKey, $facilityIds);
    }

    public function assertEligibleForAccountSetup(string $tenantId, string $userId): void
    {
        $this->grantEligibility->lockEligibleTarget($tenantId, $userId);
    }

    public function activeProtectedFacilityRoleId(string $tenantId, string $roleKey): ?string
    {
        return $this->roles->activeProtectedFacilityRoleId($tenantId, $roleKey);
    }

    public function provisionAccountSetupFacilityRole(
        string $tenantId,
        string $actorId,
        string $facilityId,
        string $roleKey,
    ): string {
        return match ($roleKey) {
            'nurse' => $this->nurses->provision($tenantId, $actorId, $facilityId),
            ClinicianRoleDefinition::KEY => $this->clinicians->provision($tenantId, $actorId, $facilityId),
            'pharmacist' => $this->pharmacists->provision($tenantId, $actorId, $facilityId),
            'laboratory_technician' => $this->laboratoryTechnicians->provision($tenantId, $actorId, $facilityId),
            'receptionist' => $this->receptionists->provision($tenantId, $actorId, $facilityId),
            'cashier' => $this->cashiers->provision($tenantId, $actorId, $facilityId),
            'billing_admin' => $this->billingAdmins->provision($tenantId, $actorId, $facilityId),
            default => throw new \RuntimeException("Unknown staff role '{$roleKey}'."),
        };
    }

    public function provisionAccountSetupTenantAdmin(string $tenantId, string $reason): string
    {
        return $this->tenantAdmins->provision($tenantId, null, $reason);
    }

    public function provisionFacilityStaffManagerRole(string $tenantId, string $actorId, string $facilityId): string
    {
        return DB::transaction(fn (): string => $this->facilityStaffManagers->provision(
            $tenantId,
            $actorId,
            $facilityId,
        ));
    }

    public function facilityRelatedAccountIds(string $tenantId, string $facilityId, string $organizationId): array
    {
        return $this->roles->facilityRelatedAccountIds($tenantId, $facilityId, $organizationId);
    }

    public function accountHasFacilityRelationship(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): bool {
        return $this->roles->accountHasFacilityRelationship($tenantId, $facilityId, $organizationId, $userId);
    }

    public function facilityAccountAssignments(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array {
        return $this->roles->facilityAccountAssignments($tenantId, $facilityId, $organizationId, $userId);
    }

    public function facilityAccountPromotionRequests(string $tenantId, string $facilityId, string $userId): array
    {
        return $this->roles->facilityAccountPromotionRequests($tenantId, $facilityId, $userId);
    }

    public function facilityAccountAccessHistory(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array {
        return $this->roles->facilityAccountAccessHistory($tenantId, $facilityId, $organizationId, $userId);
    }
}
