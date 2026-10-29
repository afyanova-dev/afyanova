<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\Slot;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: Slot::class)]
final readonly class GetAvailableSlots
{
    public function __construct(
        public string $facilityId,
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public ?string $resourceId = null,
        public ?string $appointmentTypeId = null,
    ) {}
}
