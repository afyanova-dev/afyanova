<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\ValueObject\PickupStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The pickup queue at one facility (16-pharmacy-domain.md §29, §129; ADR-186
 * D-F). Optional status filter (typically READY_FOR_PICKUP). `view` gates on
 * `pharmacy.access`; the handler re-checks the acting user's scope at the
 * facility (the `SearchPharmacyOrders` acting-user pattern).
 */
#[ProtectedQuery(ability: 'view', subjectType: PharmacyOrder::class)]
final readonly class SearchPickups
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchPickups::$facilityId must be a UUID (IC §3).');
        }

        if ($status !== null && ! in_array(strtoupper(trim($status)), PickupStatus::ALL, true)) {
            throw new InvalidArgumentException('SearchPickups::$status must be a valid PickupStatus or null.');
        }
    }
}
