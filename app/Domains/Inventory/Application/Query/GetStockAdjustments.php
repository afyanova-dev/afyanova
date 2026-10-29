<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the stock adjustments at a facility — the approver worklist
 * (§94 / §95 / §250; ADR-170 D-M). Optionally filtered by status.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockAdjustment::class)]
final readonly class GetStockAdjustments
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetStockAdjustments::$facilityId must be a UUID (IC §3).');
        }
    }
}
