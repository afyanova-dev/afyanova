<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Domain\FinancialAccountReader;

final class GetFinancialAccountHandler
{
    public function __construct(private readonly FinancialAccountReader $reader) {}

    public function __invoke(GetFinancialAccount $query): FinancialAccountSummary
    {
        return $this->reader->summary($query->financialAccountId);
    }
}
