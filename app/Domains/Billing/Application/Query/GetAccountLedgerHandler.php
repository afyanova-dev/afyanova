<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\AccountLedgerEntry;
use App\Domains\Billing\Domain\FinancialAccountReader;

final class GetAccountLedgerHandler
{
    public function __construct(private readonly FinancialAccountReader $reader) {}

    /**
     * @return list<AccountLedgerEntry>
     */
    public function __invoke(GetAccountLedger $query): array
    {
        // Surface a NotFound for an unknown account rather than an empty list.
        $this->reader->summary($query->financialAccountId);

        return $this->reader->ledgerEntries($query->financialAccountId, $query->limit);
    }
}
