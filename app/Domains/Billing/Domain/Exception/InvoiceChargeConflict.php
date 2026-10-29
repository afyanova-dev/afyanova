<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A concurrent invoice claimed a charge between this handler's eligibility read
 * and its line insert — the partial unique index
 * `bill_invoice_lines_one_live_per_charge` rejected the line (ADR-190 D-C /
 * INV-3). The caller retries. Maps to HTTP 409.
 */
final class InvoiceChargeConflict extends DomainException
{
    public static function forCharge(string $chargeId): self
    {
        return new self(sprintf(
            'Charge [%s] was claimed by another invoice concurrently; retry.',
            $chargeId,
        ));
    }

    public function errorCode(): string
    {
        return 'invoice_charge_conflict';
    }
}
