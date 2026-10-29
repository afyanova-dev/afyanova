<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An invoice is not an insured invoice (payer_type !== 'INSURANCE') (ADR-204).
 * Maps to HTTP 422.
 */
final class InvoiceNotInsured extends DomainException
{
    public static function forInvoice(string $invoiceId, string $payerType): self
    {
        return new self(sprintf(
            'Invoice [%s] is not an insured invoice (payer_type is [%s], expected INSURANCE).',
            $invoiceId,
            $payerType,
        ));
    }

    public function errorCode(): string
    {
        return 'invoice_not_insured';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
