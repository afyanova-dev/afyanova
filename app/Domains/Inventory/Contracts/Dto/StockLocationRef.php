<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

/**
 * The NARROW cross-domain view of one Stock Location — only what another domain
 * needs to reference a location and check its facility (ADR-184 D-G). NOT the
 * internal `StockLocationSummary` (which leaks `locationType` / `parentLocationId`
 * / `version`). First consumer: Pharmacy `DispenseHandler` (the
 * "location.facility == the order's facility" assertion — §81 cross-facility
 * dispensing is deferred).
 */
final readonly class StockLocationRef
{
    public function __construct(
        public string $locationId,
        public string $facilityId,
        public string $code,
        public string $name,
        public bool $active,
    ) {}
}
