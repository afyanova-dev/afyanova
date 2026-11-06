<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Facility-scoped operational instrument reference.
 */
final readonly class LabFacilityInstrumentSummary
{
    /**
     * @param  list<array{methodId: string, methodCode: string, methodName: string, methodVersion: int}>  $supportedMethods
     */
    public function __construct(
        public string $instrumentId,
        public string $facilityId,
        public string $identifier,
        public string $name,
        public ?string $manufacturer,
        public ?string $model,
        public ?string $serialNumber,
        public ?string $inventoryItemId,
        public ?string $inventoryCode,
        public ?string $inventoryName,
        public string $status,
        public int $version,
        public array $supportedMethods,
    ) {}
}
