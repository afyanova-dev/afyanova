<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An invoice facility does not match the claim facility (ADR-204).
 * Maps to HTTP 422.
 */
final class InvoiceFacilityMismatch extends DomainException
{
    public static function mismatch(string $invoiceId, string $invoiceFacilityId, string $claimFacilityId): self
    {
        return new self(sprintf(
            'Invoice [%s] belongs to facility [%s], but claim was requested for facility [%s].',
            $invoiceId,
            $invoiceFacilityId,
            $claimFacilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'invoice_facility_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
