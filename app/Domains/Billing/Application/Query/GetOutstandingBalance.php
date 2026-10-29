<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read a Financial Account's derived outstanding balance
 * (20-billing-and-payments-domain.md §83, §84; ADR-187 D-E). `view` gates on
 * `billing.access`. The figure is `Σ(DEBIT) − Σ(CREDIT)` over the ledger,
 * computed on read — never stored.
 */
#[ProtectedQuery(ability: 'view', subjectType: FinancialAccount::class)]
final readonly class GetOutstandingBalance
{
    public function __construct(public string $financialAccountId)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetOutstandingBalance::$financialAccountId must be a UUID (IC §3).');
        }
    }
}
