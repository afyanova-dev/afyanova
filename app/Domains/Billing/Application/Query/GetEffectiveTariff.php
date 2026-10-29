<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolve the base price for a service at a facility on a date
 * (20-billing-and-payments-domain.md §17, §233–§235; ADR-188 D-D).
 *
 * `facilityId` null → the tenant default only. `onDate` null → today. Returns a
 * `TariffResolution`: an unpriced service is `resolvedScope = NONE`, `amount =
 * null` — NOT an error (F6). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: BillingService::class)]
final readonly class GetEffectiveTariff
{
    public ?string $onDate;

    public function __construct(
        public string $billingServiceId,
        public ?string $facilityId = null,
        ?string $onDate = null,
    ) {
        if (! Str::isUuid($billingServiceId)) {
            throw new InvalidArgumentException('GetEffectiveTariff::$billingServiceId must be a UUID (IC §3).');
        }
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetEffectiveTariff::$facilityId must be a UUID or null (IC §3).');
        }
        if ($onDate !== null) {
            try {
                $onDate = CarbonImmutable::parse($onDate)->toDateString();
            } catch (\Throwable) {
                throw new InvalidArgumentException('GetEffectiveTariff::$onDate must be a valid date or null.');
            }
        }

        $this->onDate = $onDate;
    }
}
