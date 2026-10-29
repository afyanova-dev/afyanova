<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The active (non-terminal) imaging orders at a facility — the radiology work
 * queue (15-radiology-domain.md §76, §133; ADR-165 D-H).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class SearchImagingOrders
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchImagingOrders::$facilityId must be a UUID (IC §3).');
        }
    }
}
