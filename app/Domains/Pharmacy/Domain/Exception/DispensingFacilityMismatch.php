<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The stock location supplied to `Dispense` belongs to a different facility than
 * the Pharmacy Order (16-pharmacy-domain.md §81; ADR-184 D-H — cross-facility
 * dispensing is deferred). Maps to HTTP 409.
 */
final class DispensingFacilityMismatch extends DomainException
{
    public static function forLocation(string $locationId, string $locationFacilityId, string $orderFacilityId): self
    {
        return new self(sprintf(
            'Stock location [%s] is at facility [%s]; pharmacy order is at facility [%s] — cross-facility dispensing is not supported (§81 — ADR-184 D-H).',
            $locationId,
            $locationFacilityId,
            $orderFacilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_facility_mismatch';
    }
}
