<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A financial-account command was dispatched against a record in a status that
 * does not permit it (20-billing-and-payments-domain.md §300; ADR-187 D-B).
 * Maps to HTTP 409.
 */
final class IllegalFinancialAccountTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            '%s is not legal from financial-account status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_financial_account_transition';
    }
}
