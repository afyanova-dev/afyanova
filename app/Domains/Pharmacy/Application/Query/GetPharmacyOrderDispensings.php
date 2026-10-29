<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The dispensing history for one Pharmacy Order, oldest first
 * (16-pharmacy-domain.md §23, §47, §127; ADR-184 D-I) — "what was actually
 * dispensed". `view` gates on the coarse `pharmacy.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: PharmacyOrder::class)]
final readonly class GetPharmacyOrderDispensings
{
    public function __construct(public string $pharmacyOrderId)
    {
        if (! Str::isUuid($pharmacyOrderId)) {
            throw new InvalidArgumentException('GetPharmacyOrderDispensings::$pharmacyOrderId must be a UUID (IC §3).');
        }
    }
}
