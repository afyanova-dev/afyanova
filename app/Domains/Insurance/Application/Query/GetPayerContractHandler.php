<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerContractSummary;
use App\Domains\Insurance\Domain\PayerContractReader;

final class GetPayerContractHandler
{
    public function __construct(private readonly PayerContractReader $reader) {}

    public function __invoke(GetPayerContract $query): PayerContractSummary
    {
        return $this->reader->summary($query->contractId);
    }
}
