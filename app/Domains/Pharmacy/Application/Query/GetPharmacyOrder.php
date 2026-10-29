<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one Pharmacy Order (16-pharmacy-domain.md §22; ADR-182 D-E).
 * `view` gates on the coarse `pharmacy.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: PharmacyOrder::class)]
final readonly class GetPharmacyOrder
{
    public function __construct(public string $pharmacyOrderId)
    {
        if (! Str::isUuid($pharmacyOrderId)) {
            throw new InvalidArgumentException('GetPharmacyOrder::$pharmacyOrderId must be a UUID (IC §3).');
        }
    }
}
