<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Price-list admin root props (Bill.2 + Bill.3a worklist). Tariff rows are
 * immutable history — the page says "new price", never "edit price".
 * Permission flags drive show/disable only; every writer re-authorizes.
 */
#[TypeScript]
final readonly class CatalogDashboardProps
{
    /**
     * @param  list<CatalogServiceItem>  $services
     * @param  list<CatalogPendingEventItem>  $pendingEvents
     * @param  list<CatalogTariffHistoryItem>  $tariffHistory
     * @param  list<CatalogItemMapItem>  $medicationMaps
     * @param  list<CatalogItemMapHistoryItem>  $itemMapHistory
     */
    public function __construct(
        public string $facilityId,
        public bool $canManageCatalog,
        public bool $canRetryCharges,
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CatalogServiceItem>')]
        public array $services = [],
        public ?string $category = null,
        public ?string $status = null,
        public ?string $selectedServiceId = null,
        public ?string $selectedCode = null,
        public ?string $selectedName = null,
        public ?string $selectedCategory = null,
        public ?string $selectedStatus = null,
        public ?string $facilityAmount = null,
        public ?string $facilityEffectiveFrom = null,
        public ?string $defaultAmount = null,
        public ?string $defaultEffectiveFrom = null,
        public string $currency = 'TZS',
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CatalogPendingEventItem>')]
        public array $pendingEvents = [],
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CatalogTariffHistoryItem>')]
        public array $tariffHistory = [],
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CatalogItemMapItem>')]
        public array $medicationMaps = [],
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CatalogItemMapHistoryItem>')]
        public array $itemMapHistory = [],
        public ?string $searchError = null,
    ) {}
}
