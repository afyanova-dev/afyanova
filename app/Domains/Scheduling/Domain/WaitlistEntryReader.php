<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;

/**
 * Read-side query port for Waitlist Entries.
 */
interface WaitlistEntryReader
{
    public function getById(string $id): ?WaitlistEntrySummary;

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function getFacilityWaitlist(
        string $facilityId,
        ?string $status = null,
        ?string $appointmentTypeId = null,
        ?string $resourceId = null
    ): array;

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function getPatientWaitlist(string $patientId, ?string $facilityId = null): array;
}
