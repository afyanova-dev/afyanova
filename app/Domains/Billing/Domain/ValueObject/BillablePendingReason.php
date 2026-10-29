<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * Why a captured billable event is still PENDING (not charged)
 * (20-billing-and-payments-domain.md §106 / §130 / §131; ADR-189 D-B):
 *
 *   ITEM_UNMAPPED     — no active bill_item_billing_map for the item.
 *   SERVICE_UNPRICED  — the mapped service has no effective tariff (§28 / §106 —
 *                       unpriced is NOT no-charge).
 *   SERVICE_INACTIVE  — the mapped service is INACTIVE.
 *
 * Backend-only string-constant holder.
 */
final class BillablePendingReason
{
    public const ITEM_UNMAPPED = 'ITEM_UNMAPPED';

    public const SERVICE_UNPRICED = 'SERVICE_UNPRICED';

    public const SERVICE_INACTIVE = 'SERVICE_INACTIVE';

    /** @var list<string> */
    public const ALL = [self::ITEM_UNMAPPED, self::SERVICE_UNPRICED, self::SERVICE_INACTIVE];
}
