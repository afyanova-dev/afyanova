<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The active `inv_items.id → billing_service` mapping for an item, or null
 * (ADR-189 D-A). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: ItemBillingMap::class)]
final readonly class GetItemBillingMap
{
    public function __construct(public string $invItemId)
    {
        if (! Str::isUuid($invItemId)) {
            throw new InvalidArgumentException('GetItemBillingMap::$invItemId must be a UUID (IC §3).');
        }
    }
}
