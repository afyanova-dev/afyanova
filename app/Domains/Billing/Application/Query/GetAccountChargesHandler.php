<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Domain\ChargeReader;

final class GetAccountChargesHandler
{
    public function __construct(private readonly ChargeReader $reader) {}

    /**
     * @return list<ChargeSummary>
     */
    public function __invoke(GetAccountCharges $query): array
    {
        return $this->reader->forAccount($query->financialAccountId);
    }
}
