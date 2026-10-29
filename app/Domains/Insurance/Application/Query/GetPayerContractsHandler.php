<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerContractSummary;
use App\Domains\Insurance\Domain\PayerContractReader;

final class GetPayerContractsHandler
{
    public function __construct(private readonly PayerContractReader $reader) {}

    /**
     * @return list<PayerContractSummary>
     */
    public function __invoke(GetPayerContracts $query): array
    {
        return $this->reader->forPayer($query->payerId, $query->status);
    }
}
