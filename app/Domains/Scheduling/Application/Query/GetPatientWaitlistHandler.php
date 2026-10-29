<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use App\Domains\Scheduling\Domain\WaitlistEntryReader;

final readonly class GetPatientWaitlistHandler
{
    public function __construct(
        private WaitlistEntryReader $reader,
    ) {}

    /**
     * @return list<WaitlistEntrySummary>
     */
    public function __invoke(GetPatientWaitlist $query): array
    {
        return $this->reader->getPatientWaitlist($query->patientId, $query->facilityId);
    }
}
