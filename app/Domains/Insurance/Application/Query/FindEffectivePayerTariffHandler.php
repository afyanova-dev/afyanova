<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerTariffSummary;
use App\Domains\Insurance\Domain\PayerTariffReader;

final class FindEffectivePayerTariffHandler
{
    public function __construct(private readonly PayerTariffReader $reader) {}

    public function __invoke(FindEffectivePayerTariff $query): ?PayerTariffSummary
    {
        return $this->reader->effectiveTariffForPayer(
            $query->payerId,
            $query->serviceCategory,
            $query->onDate,
            $query->facilityId,
        );
    }
}
