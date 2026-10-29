<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the stock locations at a facility (ADR-170 D-M).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockLocations
{
    public function __construct(
        public string $facilityId,
        public ?bool $active = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetStockLocations::$facilityId must be a UUID (IC §3).');
        }
    }
}
