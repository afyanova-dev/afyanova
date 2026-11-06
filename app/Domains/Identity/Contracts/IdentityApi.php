<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Domains\Identity\Contracts\Dto\ProtectedRoleReference;

/**
 * The ONLY Identity surface other modules may import (IC §2.1). Grows per
 * consumer (IC §26).
 */
interface IdentityApi
{
    public function isStaff(string $userId): bool;

    public function linkStaffIdentity(
        string $tenantId,
        string $userId,
        string $staffRef,
        string $staffNumber,
    ): void;

    public function unlinkStaffIdentity(string $tenantId, string $userId): void;

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

    /**
     * Laboratory technicians eligible for the tenant-wide catalog editor grant.
     *
     * @return list<array{userId: string, name: string, email: string, isEditor: bool}>
     */
    public function laboratoryCatalogEditorCandidates(string $tenantId): array;

    public function isActiveLaboratoryTechnician(string $tenantId, string $userId): bool;

    public function isTenantAdmin(string $tenantId, string $userId): bool;

    public function grantLaboratoryCatalogEditor(string $tenantId, string $actorId, string $userId): void;

    public function revokeLaboratoryCatalogEditor(string $tenantId, string $actorId, string $userId): void;

    /**
     * @return array{
     *   data: list<array{
     *     id: string,
     *     name: string,
     *     key: string,
     *     scope: string,
     *     status: string,
     *     isProtected: bool,
     *     permissions: list<array{key: string, layer: string}>
     *   }>,
     *   total: int,
     *   currentPage: int,
     *   lastPage: int,
     *   perPage: int
     * }
     */
    public function roleCatalog(string $tenantId, int $page = 1, int $perPage = 50): array;

    public function provisionAccountSetupFacilityRole(
        string $tenantId,
        string $actorId,
        string $facilityId,
        string $roleKey,
    ): string;

    public function provisionFacilityRole(
        string $tenantId,
        string $actorId,
        string $facilityId,
        string $roleKey,
    ): string;

    public function provisionAccountSetupTenantAdmin(string $tenantId, string $reason): string;

    public function provisionTenantAdmin(string $tenantId, string $actorId, string $reason): string;

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

    /**
     * Add the configured permission grants for roles and remove explicitly
     * denied grants from roles that do not include them in their definition.
     *
     * @param  array<string, list<string>>  $rolePermissions
     * @param  list<string>  $removeUnauthorizedPermissions
     * @return array{
     *   missingRoles: list<string>,
     *   changes: list<array{roleKey: string, permissionKey: string, operation: 'attached'|'detached'}>
     * }
     */
    public function synchronizeRolePermissions(
        string $tenantId,
        array $rolePermissions,
        bool $dryRun,
        array $removeUnauthorizedPermissions = [],
    ): array;
}
