<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one Stock Location catalog entry
 * (ADR-170 D-D / D-M). NOT an event payload.
 */
final readonly class StockLocationSummary
{
    public function __construct(
        public string $locationId,
        public string $facilityId,
        public ?string $departmentId,
        public string $code,
        public string $name,
        public string $locationType,
        public ?string $parentLocationId,
        public bool $active,
        public int $version,
    ) {}
}
