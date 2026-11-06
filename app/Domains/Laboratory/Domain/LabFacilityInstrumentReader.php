<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabFacilityInstrumentSummary;

interface LabFacilityInstrumentReader
{
    /** @return list<LabFacilityInstrumentSummary> */
    public function forFacility(string $facilityId): array;

    public function activeForFacility(string $instrumentId, string $facilityId): LabFacilityInstrumentSummary;

    public function supportsTestDefinition(string $instrumentId, string $testDefinitionId): bool;

    public function supportsMethod(string $instrumentId, string $methodId): bool;
}
