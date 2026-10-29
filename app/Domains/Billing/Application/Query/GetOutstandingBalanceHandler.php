<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\OutstandingBalanceSummary;
use App\Domains\Billing\Domain\FinancialAccountReader;

final class GetOutstandingBalanceHandler
{
    public function __construct(private readonly FinancialAccountReader $reader) {}

    public function __invoke(GetOutstandingBalance $query): OutstandingBalanceSummary
    {
        return $this->reader->outstandingBalance($query->financialAccountId);
    }
}
