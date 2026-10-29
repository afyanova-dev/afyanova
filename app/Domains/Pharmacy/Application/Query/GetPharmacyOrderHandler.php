<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\PharmacyOrderSummary;
use App\Domains\Pharmacy\Domain\PharmacyOrderReader;

final class GetPharmacyOrderHandler
{
    public function __construct(private readonly PharmacyOrderReader $reader) {}

    public function __invoke(GetPharmacyOrder $query): PharmacyOrderSummary
    {
        return $this->reader->summary($query->pharmacyOrderId);
    }
}
