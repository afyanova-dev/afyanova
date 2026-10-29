<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Adjustment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * REQUESTED adjustments — the approver worklist (20-billing-and-payments-domain.md
 * §165; ADR-191 G-3), optionally scoped to a facility. `view` gates on the
 * coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Adjustment::class)]
final readonly class GetPendingAdjustments
{
    public function __construct(public ?string $facilityId = null)
    {
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPendingAdjustments::$facilityId must be a UUID (IC §3).');
        }
    }
}
