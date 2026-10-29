<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Domains\Identity\Contracts\Dto\ProtectedRoleReference;

/**
 * The ONLY Identity surface other modules may import (IC §2.1). Reads only in
 * PHASE 3. Grows per consumer (IC §26).
 */
interface IdentityApi
{
    public function isStaff(string $userId): bool;

    public function userExists(string $userId): bool;

    /**
     * The HR staff reference linked to the user, if the user is staff.
     * Used to scope provider-attributed reads (e.g. "my appointments").
     */
    public function staffRefForUser(string $userId): ?string;

    /**
     * The user's effective permission keys in a scope context — the same
     * evaluation the authorization Gate uses.
     *
     * @return list<string>
     */
    public function effectivePermissions(string $userId, ?string $facilityId): array;

    public function protectedAdministrativeRoleByKey(
        string $tenantId,
        string $roleKey,
        string $scopeType,
    ): ?ProtectedRoleReference;

    public function protectedAdministrativeRoleById(
        string $tenantId,
        string $roleId,
        string $roleKey,
    ): ?ProtectedRoleReference;

    /**
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    public function unavailableFacilityRoleKeys(string $tenantId, array $roleKeys): array;

    /**
     * @param  list<string>  $facilityIds
     * @return list<array{id: string, facilityId: string, userName: string, userEmail: string}>
     */
    public function activeFacilityRoleAssignments(
        string $tenantId,
        string $roleKey,
        array $facilityIds,
    ): array;

    public function assertEligibleForAccountSetup(string $tenantId, string $userId): void;

    public function activeProtectedFacilityRoleId(string $tenantId, string $roleKey): ?string;

    public function provisionAccountSetupFacilityRole(
        string $tenantId,
        string $actorId,
        string $facilityId,
        string $roleKey,
    ): string;

    public function provisionAccountSetupTenantAdmin(string $tenantId, string $reason): string;

    public function provisionFacilityStaffManagerRole(string $tenantId, string $actorId, string $facilityId): string;

    /** @return list<string> */
    public function facilityRelatedAccountIds(string $tenantId, string $facilityId, string $organizationId): array;

    public function accountHasFacilityRelationship(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): bool;

    /**
     * @return list<array{id: string, role: string, roleName: string, scope: string, status: string, effectiveFrom: ?string, effectiveTo: ?string, isEffective: bool, isLifecycleSuspended: bool, canManageIndividually: bool}>
     */
    public function facilityAccountAssignments(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array;

    /**
     * @return list<array{role: string, status: string, reason: string, requestedAt: string}>
     */
    public function facilityAccountPromotionRequests(string $tenantId, string $facilityId, string $userId): array;

    /**
     * @return list<array{action: string, role: string, actorName: string, reason: ?string, occurredAt: string}>
     */
    public function facilityAccountAccessHistory(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): array;
}
