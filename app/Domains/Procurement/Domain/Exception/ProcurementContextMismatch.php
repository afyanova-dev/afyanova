<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A purchase order and its cited requisition disagree on facility
 * (19-procurement-domain.md §47, §102, §103; ADR-171 D-G — F11; the Rad.1
 * `ImagingOrderContextMismatch` precedent). Maps to HTTP 409.
 */
final class ProcurementContextMismatch extends DomainException
{
    public static function requisitionFacility(string $poFacilityId, string $requisitionId, string $requisitionFacilityId): self
    {
        return new self(sprintf(
            'Purchase order facility [%s] does not match requisition [%s] facility [%s].',
            $poFacilityId,
            $requisitionId,
            $requisitionFacilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'procurement_context_mismatch';
    }
}
