<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabFacilityInstrumentSummary;
use App\Domains\Laboratory\Domain\LabFacilityInstrumentReader;
use App\Shared\Tenancy\TenantContext;

final class GetFacilityLabInstrumentsHandler
{
    public function __construct(
        private readonly LabFacilityInstrumentReader $instruments,
        private readonly TenantContext $context,
    ) {}

    /** @return list<LabFacilityInstrumentSummary> */
    public function __invoke(GetFacilityLabInstruments $query): array
    {
        return $this->instruments->forFacility($this->context->requireFacilityId());
    }
}
