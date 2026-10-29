<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Adjustment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The adjustments on one Financial Account, newest first
 * (20-billing-and-payments-domain.md §81; ADR-191 D7-D). `view` gates on the
 * coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Adjustment::class)]
final readonly class GetAccountAdjustments
{
    public function __construct(public string $financialAccountId)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetAccountAdjustments::$financialAccountId must be a UUID (IC §3).');
        }
    }
}
