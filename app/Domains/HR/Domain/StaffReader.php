<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\StaffDetail;
use App\Domains\HR\Contracts\Dto\StaffNotificationContactDto;
use App\Domains\HR\Contracts\Dto\StaffSummary;

/**
 * Read-side port for Staff queries (25-hr-and-workforce-domain.md §6; ADR-206).
 */
interface StaffReader
{
    public function exists(string $staffId): bool;

    public function summary(string $staffId): StaffSummary;

    public function detail(string $staffId): StaffDetail;

    public function notificationContact(string $staffId): ?StaffNotificationContactDto;

    public function photoDocumentIdForActiveFacilityAssignment(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $date,
    ): ?string;

    /**
     * @return list<StaffSummary>
     */
    public function search(?string $search = null, ?string $status = null, ?string $cadreId = null): array;
}
