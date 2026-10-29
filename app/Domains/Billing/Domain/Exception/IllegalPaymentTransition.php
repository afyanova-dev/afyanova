<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A payment command was dispatched against a record in a status that does not
 * permit it (20-billing-and-payments-domain.md §297; ADR-191 B5-C). Maps to
 * HTTP 409.
 */
final class IllegalPaymentTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from payment status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_payment_transition';
    }
}
