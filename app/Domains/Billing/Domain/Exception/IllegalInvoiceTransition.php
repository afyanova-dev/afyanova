<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An invoice command was dispatched against a record in a status that does not
 * permit it (20-billing-and-payments-domain.md §296; ADR-190 D-A). Maps to
 * HTTP 409.
 */
final class IllegalInvoiceTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from invoice status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_invoice_transition';
    }
}
