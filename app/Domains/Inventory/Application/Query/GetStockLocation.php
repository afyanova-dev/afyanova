<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one Stock Location catalog entry (ADR-170 D-M).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockLocation
{
    public function __construct(public string $locationId)
    {
        if (! Str::isUuid($locationId)) {
            throw new InvalidArgumentException('GetStockLocation::$locationId must be a UUID (IC §3).');
        }
    }
}
