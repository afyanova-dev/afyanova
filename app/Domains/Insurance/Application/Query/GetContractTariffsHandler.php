<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerTariffSummary;
use App\Domains\Insurance\Domain\PayerTariffReader;

final class GetContractTariffsHandler
{
    public function __construct(private readonly PayerTariffReader $reader) {}

    /**
     * @return list<PayerTariffSummary>
     */
    public function __invoke(GetContractTariffs $query): array
    {
        return $this->reader->forContract($query->contractId, $query->serviceCategory);
    }
}
