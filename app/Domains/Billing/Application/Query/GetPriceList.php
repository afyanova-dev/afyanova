<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The effective price of every catalog service (20-billing-and-payments-domain.md
 * §17, §231; ADR-188 D-D). `facilityId` null → the tenant-default list; a
 * facility → that facility's price per service (override-or-default). One entry
 * per service (ACTIVE or INACTIVE — the caller sees `serviceStatus`). `view`
 * gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: BillingService::class)]
final readonly class GetPriceList
{
    public ?string $onDate;

    public function __construct(
        public ?string $facilityId = null,
        ?string $onDate = null,
    ) {
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPriceList::$facilityId must be a UUID or null (IC §3).');
        }
        if ($onDate !== null) {
            try {
                $onDate = CarbonImmutable::parse($onDate)->toDateString();
            } catch (\Throwable) {
                throw new InvalidArgumentException('GetPriceList::$onDate must be a valid date or null.');
            }
        }

        $this->onDate = $onDate;
    }
}
