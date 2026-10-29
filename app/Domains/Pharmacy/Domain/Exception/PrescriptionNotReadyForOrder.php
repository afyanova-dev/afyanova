<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreatePharmacyOrder` cited a prescription that is not `ACTIVE` — it has not
 * been accepted into the pharmacy, or it was cancelled (16-pharmacy-domain.md
 * §20; ADR-182 D-D). Maps to HTTP 409.
 */
final class PrescriptionNotReadyForOrder extends DomainException
{
    public static function status(string $prescriptionId, string $status): self
    {
        return new self(sprintf(
            'Prescription [%s] is [%s]; a Pharmacy Order can only be created from an ACTIVE prescription.',
            $prescriptionId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'prescription_not_ready_for_order';
    }
}
