<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AddInvoiceLine` named a charge that belongs to a different `FinancialAccount`
 * than the invoice (ADR-190 D-C). An invoice groups the charges of ONE account.
 * Maps to HTTP 409.
 */
final class ChargeNotForInvoiceAccount extends DomainException
{
    public static function mismatch(string $chargeId, string $invoiceId): self
    {
        return new self(sprintf(
            'Charge [%s] is not on the financial account of invoice [%s].',
            $chargeId,
            $invoiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'charge_not_for_invoice_account';
    }
}
