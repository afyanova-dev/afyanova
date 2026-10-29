<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All `inv_items.id → billing_service` mappings (active + inactive), newest
 * first, optionally filtered by billing service (ADR-189 D-A). `view` gates on
 * the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: ItemBillingMap::class)]
final readonly class SearchItemBillingMap
{
    public function __construct(public ?string $billingServiceId = null)
    {
        if ($billingServiceId !== null && ! Str::isUuid($billingServiceId)) {
            throw new InvalidArgumentException('SearchItemBillingMap::$billingServiceId must be a UUID or null (IC §3).');
        }
    }
}
