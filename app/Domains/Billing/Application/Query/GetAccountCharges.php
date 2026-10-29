<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Charge;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The charges on one Financial Account, newest first
 * (20-billing-and-payments-domain.md §14 / §295; ADR-189 D-G). `view` gates on
 * the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Charge::class)]
final readonly class GetAccountCharges
{
    public function __construct(public string $financialAccountId)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetAccountCharges::$financialAccountId must be a UUID (IC §3).');
        }
    }
}
