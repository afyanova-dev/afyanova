<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Domain\ChargeReader;

final class GetChargeHandler
{
    public function __construct(private readonly ChargeReader $reader) {}

    public function __invoke(GetCharge $query): ChargeSummary
    {
        return $this->reader->summary($query->chargeId);
    }
}
