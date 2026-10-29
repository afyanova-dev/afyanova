<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The pharmacy-order queue at one facility (16-pharmacy-domain.md §186;
 * ADR-182 D-E). Optional status filter. `view` gates on `pharmacy.access`; the
 * handler re-checks the acting user's `pharmacy.access` scope at the facility
 * (the `GetStockAdjustments` acting-user pattern).
 */
#[ProtectedQuery(ability: 'view', subjectType: PharmacyOrder::class)]
final readonly class SearchPharmacyOrders
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchPharmacyOrders::$facilityId must be a UUID (IC §3).');
        }

        if ($status !== null && ! PharmacyOrderStatus::isValid(strtoupper(trim($status)))) {
            throw new InvalidArgumentException('SearchPharmacyOrders::$status must be a valid PharmacyOrderStatus or null.');
        }
    }
}
