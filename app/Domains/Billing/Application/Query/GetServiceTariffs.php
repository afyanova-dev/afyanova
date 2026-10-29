<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\TariffHistoryEntry;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every tariff row ever written for one service, newest first
 * (20-billing-and-payments-domain.md §19–§24). `view` gates on the coarse
 * `billing.access` — history is read-only by construction (rows immutable).
 */
#[ProtectedQuery(ability: 'view', subjectType: BillingService::class)]
final readonly class GetServiceTariffs
{
    public function __construct(public string $billingServiceId)
    {
        if (! Str::isUuid($billingServiceId)) {
            throw new InvalidArgumentException('GetServiceTariffs::$billingServiceId must be a UUID (IC §3).');
        }
    }
}

final class GetServiceTariffsHandler
{
    public function __construct(private readonly BillingCatalogReader $reader) {}

    /**
     * @return list<TariffHistoryEntry>
     */
    public function __invoke(GetServiceTariffs $query): array
    {
        return $this->reader->tariffHistory($query->billingServiceId);
    }
}
