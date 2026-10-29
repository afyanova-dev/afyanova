<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one Medication Pickup (16-pharmacy-domain.md §29; ADR-186 D-F). `view`
 * gates on the coarse `pharmacy.access` (the `GetDispensing` precedent — RLS
 * isolates the tenant).
 */
#[ProtectedQuery(ability: 'view', subjectType: PharmacyOrder::class)]
final readonly class GetPickup
{
    public function __construct(public string $pickupId)
    {
        if (! Str::isUuid($pickupId)) {
            throw new InvalidArgumentException('GetPickup::$pickupId must be a UUID (IC §3).');
        }
    }
}
