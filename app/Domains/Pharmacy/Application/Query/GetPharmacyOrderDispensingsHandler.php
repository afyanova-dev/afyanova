<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;
use App\Domains\Pharmacy\Domain\DispensingReader;

final class GetPharmacyOrderDispensingsHandler
{
    public function __construct(private readonly DispensingReader $reader) {}

    /**
     * @return list<DispensingSummary>
     */
    public function __invoke(GetPharmacyOrderDispensings $query): array
    {
        return $this->reader->forOrder($query->pharmacyOrderId);
    }
}
