<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PickupSummary;
use App\Domains\Pharmacy\Domain\PickupReader;

final class GetPickupHandler
{
    public function __construct(private readonly PickupReader $reader) {}

    public function __invoke(GetPickup $query): PickupSummary
    {
        return $this->reader->summary($query->pickupId);
    }
}
