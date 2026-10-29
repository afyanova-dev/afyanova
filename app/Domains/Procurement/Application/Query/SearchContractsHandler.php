<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\ContractSummary;
use App\Domains\Procurement\Domain\ContractReader;

final class SearchContractsHandler
{
    public function __construct(private readonly ContractReader $reader) {}

    /**
     * @return list<ContractSummary>
     */
    public function __invoke(SearchContracts $query): array
    {
        return $this->reader->search($query->supplierId, $query->status);
    }
}
