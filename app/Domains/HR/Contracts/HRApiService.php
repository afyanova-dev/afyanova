<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts;

use App\Domains\HR\Contracts\Dto\OnShiftStaffSummary;
use App\Domains\HR\Contracts\Dto\StaffNotificationContactDto;
use App\Domains\HR\Contracts\Dto\StaffSummary;
use App\Domains\HR\Contracts\Dto\WorkforceAvailabilitySummary;
use App\Domains\HR\Domain\Exception\StaffNotFoundException;
use App\Domains\HR\Domain\RosterEntryReader;
use App\Domains\HR\Domain\StaffReader;
use App\Domains\HR\Domain\WorkforceAvailabilityReader;
use App\Domains\HR\Infrastructure\Reader\FacilityPeopleSearchReader;
use Carbon\CarbonImmutable;

/**
 * Concrete implementation of HRApi contract port (25-hr-and-workforce-domain.md §6; ADR-206).
 */
final class HRApiService implements HRApi
{
    public function __construct(
        private readonly StaffReader $staffReader,
        private readonly RosterEntryReader $rosterReader,
        private readonly WorkforceAvailabilityReader $workforceAvailabilityReader,
        private readonly FacilityPeopleSearchReader $peopleSearchReader,
    ) {}

    public function staffExists(string $staffId): bool
    {
        return $this->staffReader->exists($staffId);
    }

    public function getStaffSummary(string $staffId): ?StaffSummary
    {
        try {
            return $this->staffReader->summary($staffId);
        } catch (StaffNotFoundException) {
            return null;
        }
    }

    public function getStaffNotificationContact(string $staffId): ?StaffNotificationContactDto
    {
        return $this->staffReader->notificationContact($staffId);
    }

    public function getStaffPhotoDocumentId(
        string $tenantId,
        string $facilityId,
        string $staffId,
        CarbonImmutable $onDate,
    ): ?string {
        return $this->staffReader->photoDocumentIdForActiveFacilityAssignment(
            $tenantId,
            $facilityId,
            $staffId,
            $onDate->toDateString(),
        );
    }

    /**
     * @return list<WorkforceAvailabilitySummary>
     */
    public function getWorkforceAvailability(
        string $facilityId,
        ?string $unitId = null,
        ?CarbonImmutable $atTime = null
    ): array {
        return $this->workforceAvailabilityReader->getAvailability(
            facilityId: $facilityId,
            atTime: $atTime,
            unitId: $unitId,
        );
    }

    /**
     * @return list<OnShiftStaffSummary>
     */
    public function getOnShiftNow(
        string $facilityId,
        ?string $unitId = null,
        ?CarbonImmutable $atTime = null
    ): array {
        return $this->rosterReader->getOnShiftNow(
            facilityId: $facilityId,
            unitId: $unitId,
            atTime: $atTime,
        );
    }

    public function isStaffAvailable(string $staffId, CarbonImmutable $dateTime): bool
    {
        return $this->workforceAvailabilityReader->isStaffAvailable($staffId, $dateTime);
    }

    public function isStaffOnLeave(string $staffId, CarbonImmutable $date): bool
    {
        return $this->workforceAvailabilityReader->isStaffOnLeave($staffId, $date);
    }

    public function facilityRelatedAccountIds(string $tenantId, string $facilityId): array
    {
        return $this->peopleSearchReader->facilityRelatedAccountIds($tenantId, $facilityId);
    }

    public function activeFacilityAccountIds(string $tenantId, string $facilityId): array
    {
        return $this->peopleSearchReader->activeFacilityAccountIds($tenantId, $facilityId);
    }

    public function linkedStaffForAccount(string $tenantId, string $facilityId, string $userId): ?array
    {
        return $this->peopleSearchReader->linkedStaffForAccount($tenantId, $facilityId, $userId);
    }
}
