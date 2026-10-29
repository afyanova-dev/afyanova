<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An invoice was not found via BillingApi (ADR-204).
 * Maps to HTTP 404.
 */
final class InvoiceNotFound extends DomainException
{
    public static function id(string $invoiceId): self
    {
        return new self(sprintf('Billing invoice [%s] not found.', $invoiceId));
    }

    public function errorCode(): string
    {
        return 'invoice_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
