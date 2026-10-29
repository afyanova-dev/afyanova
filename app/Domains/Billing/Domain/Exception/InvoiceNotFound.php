<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `GetInvoice` named an invoice absent from the current tenant scope
 * (ADR-190 D-D). Maps to HTTP 404.
 */
final class InvoiceNotFound extends DomainException
{
    public static function id(string $invoiceId): self
    {
        return new self(sprintf('Invoice [%s] not found.', $invoiceId));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
