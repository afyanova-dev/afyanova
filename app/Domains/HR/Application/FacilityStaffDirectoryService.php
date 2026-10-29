<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use App\Domains\HR\Infrastructure\Reader\FacilityStaffDirectoryReader;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class FacilityStaffDirectoryService
{
    public function __construct(private readonly FacilityStaffDirectoryReader $reader) {}

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     status: string,
     *     phone: ?string,
     *     email: ?string,
     *     positionTitle: ?string,
     *     departmentName: ?string,
     *     unitName: ?string
     * }>
     */
    public function activeStaff(string $tenantId, string $facilityId, string $today): array
    {
        return $this->reader->activeStaff($tenantId, $facilityId, $today);
    }

    /**
     * @return LengthAwarePaginator<int|string, array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     status: string,
     *     phone: string|null,
     *     email: string|null,
     *     positionTitle: string|null,
     *     departmentName: string|null,
     *     unitName: string|null,
     *     linkedUserId: string|null,
     *     linkedUserEmail: string|null,
     *     accountState: string,
     *     invitationStatus: string|null,
     *     invitationExpiresAt: string|null,
     *     facilityId: string
     * }>
     */
    public function staffDirectory(
        string $tenantId,
        string $facilityId,
        string $today,
        string $status = 'ACTIVE',
        string $search = '',
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->reader->staffDirectory($tenantId, $facilityId, $today, $status, $search, $page, $perPage);
    }

    /** @return array<string, int> */
    public function staffStatusCounts(string $tenantId, string $facilityId, string $today): array
    {
        return $this->reader->staffStatusCounts($tenantId, $facilityId, $today);
    }

    public function expiredInvitationCount(string $tenantId, string $facilityId): int
    {
        return $this->reader->expiredInvitationCount($tenantId, $facilityId);
    }

    public function activeCadreExists(string $tenantId, string $cadreId): bool
    {
        return $this->reader->activeCadreExists($tenantId, $cadreId);
    }

    /** @return array{status: string, primaryCadreId: ?string, primaryPositionId: ?string}|null */
    public function onboardableStaff(string $tenantId, string $facilityId, string $staffId, string $today): ?array
    {
        return $this->reader->onboardableStaff($tenantId, $facilityId, $staffId, $today);
    }

    public function canStartEmployment(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $employmentId,
        string $today,
    ): bool {
        return $this->reader->canStartEmployment($tenantId, $facilityId, $staffId, $employmentId, $today);
    }

    public function startableDraftEmploymentId(string $tenantId, string $staffId, string $joinedDate): ?string
    {
        return $this->reader->startableDraftEmploymentId($tenantId, $staffId, $joinedDate);
    }

    public function editablePersonId(string $tenantId, string $facilityId, string $staffId, string $today): ?string
    {
        return $this->reader->editablePersonId($tenantId, $facilityId, $staffId, $today);
    }

    public function hasFacilityAssignment(string $tenantId, string $facilityId, string $staffId): bool
    {
        return $this->reader->hasFacilityAssignment($tenantId, $facilityId, $staffId);
    }

    public function linkedUserId(string $tenantId, string $staffId): ?string
    {
        return $this->reader->linkedUserId($tenantId, $staffId);
    }

    public function isEligibleForAccountLink(string $tenantId, string $facilityId, string $staffId, string $today): bool
    {
        return $this->reader->isEligibleForAccountLink($tenantId, $facilityId, $staffId, $today);
    }

    /** @return array{id: string, firstName: string, middleName: ?string, lastName: string}|null */
    public function staffEligibleForInvitation(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $today,
    ): ?array {
        return $this->reader->staffEligibleForInvitation($tenantId, $facilityId, $staffId, $today);
    }

    public function pendingAssignmentRequestCount(string $tenantId, string $facilityId): int
    {
        return $this->reader->pendingAssignmentRequestCount($tenantId, $facilityId);
    }

    public function activeStaffCount(string $tenantId, string $facilityId, string $today): int
    {
        return $this->reader->activeStaffCount($tenantId, $facilityId, $today);
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     status: string,
     *     phone: ?string,
     *     email: string,
     *     positionTitle: ?string
     * }>
     */
    public function expiredSetupInvitations(string $tenantId, string $facilityId, string $today): array
    {
        return $this->reader->expiredSetupInvitations($tenantId, $facilityId, $today);
    }

    /** @return array<string, mixed>|null */
    public function staffProfile(string $tenantId, string $facilityId, string $staffId, string $today): ?array
    {
        return $this->reader->staffProfile($tenantId, $facilityId, $staffId, $today);
    }

    public function hasCurrentFacilityAssignment(string $tenantId, string $facilityId, string $staffId, string $today): bool
    {
        return $this->reader->hasCurrentFacilityAssignment($tenantId, $facilityId, $staffId, $today);
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<array{id: string, roleKey: string}>
     */
    public function pendingInvitationAccessRequests(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $actorId,
        array $roleKeys,
    ): array {
        return $this->reader->pendingInvitationAccessRequests(
            $tenantId,
            $facilityId,
            $userId,
            $actorId,
            $roleKeys,
        );
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<array{
     *     id: string,
     *     staffId: string,
     *     staffNumber: string,
     *     staffName: string,
     *     accountEmail: string,
     *     roleKey: string,
     *     reason: string,
     *     requestedAt: string,
     *     requesterName: string
     * }>
     */
    public function pendingFacilityInvitationRoleApprovals(
        string $tenantId,
        string $facilityId,
        string $actorId,
        array $roleKeys,
    ): array {
        return $this->reader->pendingFacilityInvitationRoleApprovals(
            $tenantId,
            $facilityId,
            $actorId,
            $roleKeys,
        );
    }

    /** @return array<string, mixed>|null */
    public function latestEmployment(string $tenantId, string $staffId): ?array
    {
        return $this->reader->latestEmployment($tenantId, $staffId);
    }

    /** @return array{activeAssignments: int, openEmployments: int, futurePublishedShifts: int} */
    public function terminationBlockers(string $tenantId, string $staffId, string $today): array
    {
        return $this->reader->terminationBlockers($tenantId, $staffId, $today);
    }

    /** @return array{id: string, cadreId: string}|null */
    public function activePosition(string $tenantId, string $positionId): ?array
    {
        return $this->reader->activePosition($tenantId, $positionId);
    }

    /**
     * @return list<array{id: string, code: string, title: string, cadreId: string}>
     */
    public function activePositions(string $tenantId): array
    {
        return $this->reader->activePositions($tenantId);
    }

    /** @return list<array{id: string, code: string, name: string, category: string}> */
    public function activeCadres(string $tenantId): array
    {
        return $this->reader->activeCadres($tenantId);
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     scope: string,
     *     departmentName: ?string,
     *     unitName: ?string,
     *     effectiveFrom: string
     * }>
     */
    public function pendingAssignmentRequests(string $tenantId, string $facilityId): array
    {
        return $this->reader->pendingAssignmentRequests($tenantId, $facilityId);
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     departmentId: ?string,
     *     unitId: ?string,
     *     departmentName: ?string,
     *     unitName: ?string,
     *     effectiveFrom: string,
     *     decisionReason: ?string
     * }>
     */
    public function rejectedAssignmentRequests(string $tenantId, string $facilityId, string $actorId): array
    {
        return $this->reader->rejectedAssignmentRequests($tenantId, $facilityId, $actorId);
    }
}
