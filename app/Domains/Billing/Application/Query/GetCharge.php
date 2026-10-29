<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Charge;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one facility charge (20-billing-and-payments-domain.md §14 / §299;
 * ADR-189 D-G). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Charge::class)]
final readonly class GetCharge
{
    public function __construct(public string $chargeId)
    {
        if (! Str::isUuid($chargeId)) {
            throw new InvalidArgumentException('GetCharge::$chargeId must be a UUID (IC §3).');
        }
    }
}
