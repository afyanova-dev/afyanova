<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts;

use App\Domains\HR\Contracts\Dto\OnShiftStaffSummary;
use App\Domains\HR\Contracts\Dto\StaffNotificationContactDto;
use App\Domains\HR\Contracts\Dto\StaffSummary;
use App\Domains\HR\Contracts\Dto\WorkforceAvailabilitySummary;
use Carbon\CarbonImmutable;

/**
 * Public contract port for HR & Workforce domain (25-hr-and-workforce-domain.md §6; ADR-206).
 *
 * Provides cross-domain access to verified staff operational facts with zero PII leakage.
 */
interface HRApi
{
    public function staffExists(string $staffId): bool;

    public function getStaffSummary(string $staffId): ?StaffSummary;

    public function getStaffNotificationContact(string $staffId): ?StaffNotificationContactDto;

    public function getStaffPhotoDocumentId(
        string $tenantId,
        string $facilityId,
        string $staffId,
        CarbonImmutable $onDate,
    ): ?string;

    /**
     * @return list<WorkforceAvailabilitySummary>
     */
    public function getWorkforceAvailability(
        string $facilityId,
        ?string $unitId = null,
        ?CarbonImmutable $atTime = null
    ): array;

    /**
     * @return list<OnShiftStaffSummary>
     */
    public function getOnShiftNow(
        string $facilityId,
        ?string $unitId = null,
        ?CarbonImmutable $atTime = null
    ): array;

    public function isStaffAvailable(string $staffId, CarbonImmutable $dateTime): bool;

    public function isStaffOnLeave(string $staffId, CarbonImmutable $date): bool;

    /** @return list<string> */
    public function facilityRelatedAccountIds(string $tenantId, string $facilityId): array;

    /** @return list<string> */
    public function activeFacilityAccountIds(string $tenantId, string $facilityId): array;

    /** @return array{id: string, staffNumber: string, firstName: string, middleName: ?string, lastName: string}|null */
    public function linkedStaffForAccount(string $tenantId, string $facilityId, string $userId): ?array;
}
