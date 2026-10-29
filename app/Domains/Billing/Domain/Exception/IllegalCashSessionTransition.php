<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A cash-session command was dispatched against a record in a status that does not permit it (20-billing-and-payments-domain.md §154; ADR-191 C6). Maps to HTTP 409.
 */
final class IllegalCashSessionTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from cash session status [%s].',
            $action, $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_cash_session_transition';
    }
}
