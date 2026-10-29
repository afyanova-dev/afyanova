<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use App\Domains\Authentication\Contracts\AccountDirectoryApi;
use App\Domains\HR\Contracts\HRApi;
use App\Domains\HR\Infrastructure\Reader\FacilityPeopleSearchReader;
use App\Domains\Identity\Contracts\IdentityApi;

final class FacilityPeopleSearchService
{
    public function __construct(
        private readonly FacilityPeopleSearchReader $reader,
        private readonly AccountDirectoryApi $accounts,
        private readonly IdentityApi $identity,
        private readonly HRApi $hr,
    ) {}

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     status: string,
     *     firstName: string,
     *     middleName: ?string,
     *     lastName: string,
     *     photoDocumentId: ?string,
     *     accountEmail: ?string
     * }>
     */
    public function activeStaffMatches(
        string $tenantId,
        string $facilityId,
        string $pattern,
        string $today,
        bool $includeAccountEmail,
    ): array {
        return $this->reader->activeStaffMatches(
            $tenantId,
            $facilityId,
            $pattern,
            $today,
            $includeAccountEmail,
        );
    }

    /**
     * @return list<array{id: string, name: string, email: string, status: string, emailVerified: bool}>
     */
    public function matchingFacilityAccounts(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $pattern,
    ): array {
        $candidateIds = [
            ...$this->identity->facilityRelatedAccountIds($tenantId, $facilityId, $organizationId),
            ...$this->accounts->facilityInvitationUserIds($tenantId, $facilityId),
            ...$this->hr->facilityRelatedAccountIds($tenantId, $facilityId),
        ];

        return $this->accounts->searchFacilityAccounts(
            $tenantId,
            $pattern,
            array_values(array_unique($candidateIds)),
            $this->hr->activeFacilityAccountIds($tenantId, $facilityId),
        );
    }

    /**
     * @return array{
     *     account: array{id: string, name: string, email: string, status: string, emailVerified: bool, createdAt: string},
     *     assignments: list<array{id: string, role: string, roleName: string, scope: string, status: string, effectiveFrom: ?string, effectiveTo: ?string, isEffective: bool, isLifecycleSuspended: bool, canManageIndividually: bool}>,
     *     requests: list<array{role: string, status: string, reason: string, requestedAt: string}>,
     *     linkedStaff: array{id: string, staffNumber: string, firstName: string, middleName: ?string, lastName: string}|null
     * }|null
     */
    public function facilityAccountProfile(
        string $tenantId,
        string $facilityId,
        string $organizationId,
        string $userId,
    ): ?array {
        $account = $this->accounts->tenantAccount($tenantId, $userId);
        if ($account === null) {
            return null;
        }

        $linkedStaff = $this->hr->linkedStaffForAccount($tenantId, $facilityId, $userId);
        $isRelated = $this->identity->accountHasFacilityRelationship(
            $tenantId,
            $facilityId,
            $organizationId,
            $userId,
        ) || $this->accounts->facilityInvitationExists($tenantId, $facilityId, $userId)
            || $linkedStaff !== null;
        if (! $isRelated) {
            return null;
        }

        return [
            'account' => $account,
            'assignments' => $this->identity->facilityAccountAssignments(
                $tenantId,
                $facilityId,
                $organizationId,
                $userId,
            ),
            'requests' => $this->identity->facilityAccountPromotionRequests($tenantId, $facilityId, $userId),
            'linkedStaff' => $linkedStaff,
        ];
    }
}
