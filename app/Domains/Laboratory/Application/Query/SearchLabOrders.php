<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The active (non-terminal) laboratory orders at a facility — the laboratory
 * work queue (14-laboratory-domain.md §71, §133; ADR-157 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class SearchLabOrders
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchLabOrders::$facilityId must be a UUID (IC §3).');
        }
    }
}
