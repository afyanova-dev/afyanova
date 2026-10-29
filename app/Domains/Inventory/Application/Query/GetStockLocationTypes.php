<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockLocationTypes
{
    public function __construct(public ?string $facilityId)
    {
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetStockLocationTypes::$facilityId must be a UUID or null.');
        }
    }
}
