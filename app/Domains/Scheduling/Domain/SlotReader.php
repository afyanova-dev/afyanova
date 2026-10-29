<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Contracts\Dto\SlotSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side reader port for Slot.
 */
interface SlotReader
{
    public function findById(string $id): ?SlotSummary;

    /**
     * @return list<SlotSummary>
     */
    public function getAvailableSlots(
        string $facilityId,
        ?string $resourceId,
        ?string $appointmentTypeId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
    ): array;
}
