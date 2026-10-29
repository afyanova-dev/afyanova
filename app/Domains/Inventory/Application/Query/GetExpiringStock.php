<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Expiry worklist for one facility: every batch with stock on hand that is
 * already expired or expires inside the near-expiry window, soonest first
 * (frontend-foundation-inventory-operations.md Phase 0 disposition policy: a
 * 90-day near-expiry window, and removal by a two-person `DECREASE` adjustment).
 *
 * Read-only. `view` gates on `inventory.access`; the handler re-checks the
 * actor's scope at the facility.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetExpiringStock
{
    /** The decided rollout window (Phase 0): 90 days before expiry. */
    public const DEFAULT_WINDOW_DAYS = 90;

    public const MAX_WINDOW_DAYS = 365;

    public function __construct(
        public string $facilityId,
        public int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetExpiringStock::$facilityId must be a UUID (IC §3).');
        }

        if ($windowDays < 1 || $windowDays > self::MAX_WINDOW_DAYS) {
            throw new InvalidArgumentException('GetExpiringStock::$windowDays is outside the supported range.');
        }
    }
}
