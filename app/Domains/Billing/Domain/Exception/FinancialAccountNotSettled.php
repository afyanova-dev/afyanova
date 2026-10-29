<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CloseFinancialAccount` was dispatched against an account with a non-zero
 * outstanding balance (20-billing-and-payments-domain.md §84 / §301;
 * ADR-187 D-D). Closing must not hide an obligation — the balance must be
 * written off, refunded or settled first. Maps to HTTP 409.
 */
final class FinancialAccountNotSettled extends DomainException
{
    public static function outstanding(string $financialAccountId, string $outstanding): self
    {
        return new self(sprintf(
            'Financial Account [%s] cannot be closed — outstanding balance is [%s].',
            $financialAccountId,
            $outstanding,
        ));
    }

    public function errorCode(): string
    {
        return 'financial_account_not_settled';
    }
}
