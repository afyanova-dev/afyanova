<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one billing-service catalog entry (20-billing-and-payments-domain.md
 * §236; ADR-188 D-D). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: BillingService::class)]
final readonly class GetBillingService
{
    public function __construct(public string $billingServiceId)
    {
        if (! Str::isUuid($billingServiceId)) {
            throw new InvalidArgumentException('GetBillingService::$billingServiceId must be a UUID (IC §3).');
        }
    }
}
