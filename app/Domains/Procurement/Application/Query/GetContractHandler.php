<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\ContractSummary;
use App\Domains\Procurement\Domain\ContractReader;

final class GetContractHandler
{
    public function __construct(private readonly ContractReader $reader) {}

    public function __invoke(GetContract $query): ContractSummary
    {
        return $this->reader->summary($query->contractId);
    }
}
