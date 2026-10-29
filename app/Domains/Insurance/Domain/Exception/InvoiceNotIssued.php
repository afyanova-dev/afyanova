<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An invoice is not in ISSUED status (ADR-204).
 * Claims can only be captured from frozen, issued invoices.
 * Maps to HTTP 422.
 */
final class InvoiceNotIssued extends DomainException
{
    public static function forInvoice(string $invoiceId, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot capture claim from invoice [%s] in status [%s]; invoice must be ISSUED.',
            $invoiceId,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'invoice_not_issued';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
