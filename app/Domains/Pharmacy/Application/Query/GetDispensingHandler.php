<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;
use App\Domains\Pharmacy\Domain\DispensingReader;

final class GetDispensingHandler
{
    public function __construct(private readonly DispensingReader $reader) {}

    public function __invoke(GetDispensing $query): DispensingSummary
    {
        return $this->reader->summary($query->dispensingId);
    }
}
