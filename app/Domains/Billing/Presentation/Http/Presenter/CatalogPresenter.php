<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Presenter;

use App\Domains\Billing\Application\Query\GetPendingBillableEvents;
use App\Domains\Billing\Application\Query\GetServiceTariffs;
use App\Domains\Billing\Application\Query\SearchBillingServices;
use App\Domains\Billing\Application\Query\SearchItemBillingMap;
use App\Domains\Billing\Contracts\Dto\BillingServiceSummary;
use App\Domains\Billing\Contracts\Dto\ItemBillingMapSummary;
use App\Domains\Billing\Contracts\Dto\PendingBillableEventSummary;
use App\Domains\Billing\Contracts\Dto\TariffHistoryEntry;
use App\Domains\Billing\Contracts\Dto\TariffResolution;
use App\Domains\Billing\Domain\BillingCatalogReader;
use App\Domains\Billing\Presentation\Http\DTO\CatalogDashboardProps;
use App\Domains\Billing\Presentation\Http\DTO\CatalogItemMapHistoryItem;
use App\Domains\Billing\Presentation\Http\DTO\CatalogItemMapItem;
use App\Domains\Billing\Presentation\Http\DTO\CatalogPendingEventItem;
use App\Domains\Billing\Presentation\Http\DTO\CatalogServiceItem;
use App\Domains\Billing\Presentation\Http\DTO\CatalogTariffHistoryItem;
use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Shared\Bus\QueryBus;
use Carbon\CarbonImmutable;

/**
 * Price-list admin read model (Bill.2 + Bill.3a worklist). Composes Billing's
 * own catalog queries + the Patient published contract for worklist identity.
 * Tariff history is immutable — the presenter shows current rows only; past
 * rows stay in the database, never on screen as editable figures.
 */
final class CatalogPresenter
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly BillingCatalogReader $catalog,
        private readonly PatientApi $patientApi,
        private readonly InventoryApi $inventoryApi,
    ) {}

    /**
     * @param  array<string, bool>  $permissions
     */
    public function present(string $facilityId, ?string $category, ?string $status, ?string $selectedServiceId, array $permissions): CatalogDashboardProps
    {
        /** @var list<BillingServiceSummary> $services */
        $services = $this->queryBus->dispatch(new SearchBillingServices($category, $status));
        /** @var list<TariffResolution> $prices */
        $prices = $this->catalog->priceList($facilityId, null);

        $byService = [];
        foreach ($prices as $price) {
            $byService[$price->billingServiceId] = $price;
        }

        $items = array_map(static function (BillingServiceSummary $s) use ($byService): CatalogServiceItem {
            $price = $byService[$s->billingServiceId] ?? null;

            return new CatalogServiceItem(
                billingServiceId: $s->billingServiceId,
                code: $s->code,
                name: $s->name,
                category: $s->category,
                status: $s->status,
                amount: $price !== null ? $price->amount : null,
                resolvedScope: $price !== null ? $price->resolvedScope : 'NONE',
                currency: $price !== null ? $price->currency : 'TZS',
                effectiveFrom: $price !== null ? $price->effectiveFrom : null,
            );
        }, $services);

        $selected = null;
        $facilityAmount = null;
        $facilityFrom = null;
        $defaultAmount = null;
        $defaultFrom = null;
        if ($selectedServiceId !== null) {
            foreach ($services as $s) {
                if ($s->billingServiceId === $selectedServiceId) {
                    $selected = $s;
                    break;
                }
            }
            if ($selected !== null) {
                try {
                    $atFacility = $this->catalog->resolveTariff($selectedServiceId, $facilityId, null);
                    if ($atFacility->resolvedScope === 'FACILITY') {
                        $facilityAmount = $atFacility->amount;
                        $facilityFrom = $atFacility->effectiveFrom;
                    }
                } catch (\Throwable) {
                    // Unpriced service — shown as data, never an error (§28).
                }
                try {
                    $tenantDefault = $this->catalog->resolveTariff($selectedServiceId, null, null);
                    if ($tenantDefault->resolvedScope === 'TENANT_DEFAULT') {
                        $defaultAmount = $tenantDefault->amount;
                        $defaultFrom = $tenantDefault->effectiveFrom;
                    }
                } catch (\Throwable) {
                    // Unpriced at default — same rule.
                }
            }
        }

        /** @var list<PendingBillableEventSummary> $pending */
        $pending = $this->queryBus->dispatch(new GetPendingBillableEvents($facilityId));
        /** @var array<string, PatientSummary> $patients */
        $patients = $this->patientApi->getPatientSummaries(
            array_values(array_unique(array_map(static fn (PendingBillableEventSummary $e): string => $e->patientId, $pending)))
        );

        $pendingItems = array_map(static function (PendingBillableEventSummary $e) use ($patients): CatalogPendingEventItem {
            $patient = $patients[$e->patientId] ?? null;

            return new CatalogPendingEventItem(
                billableEventId: $e->billableEventId,
                patientDisplayName: $patient !== null ? $patient->displayName : 'Unknown patient',
                patientNumber: $patient !== null ? $patient->patientNumber : '—',
                quantity: $e->quantity,
                pendingReason: $e->pendingReason,
                occurredAt: $e->occurredAt,
            );
        }, $pending);

        /** @var list<TariffHistoryEntry> $history */
        $history = $selected !== null
            ? $this->queryBus->dispatch(new GetServiceTariffs($selected->billingServiceId))
            : [];

        // "Current" means in force TODAY — not row status. An open row with a
        // future effective_from is scheduled, not current.
        $today = CarbonImmutable::now()->toDateString();
        $historyItems = array_map(static function (TariffHistoryEntry $t) use ($today): CatalogTariffHistoryItem {
            $started = $t->effectiveFrom <= $today;
            $notEnded = $t->effectiveTo === null || $t->effectiveTo > $today;

            return new CatalogTariffHistoryItem(
                tariffId: $t->tariffId,
                resolvedScope: $t->resolvedScope,
                amount: $t->amount,
                currency: $t->currency,
                effectiveFrom: $t->effectiveFrom,
                effectiveTo: $t->effectiveTo,
                status: $t->status,
                current: $t->status === 'CURRENT' && $started && $notEnded,
            );
        }, $history);

        // The item → billing-service mapping worklist. Medication identity comes
        // from Inventory's narrow `InventoryItemRef` (unprotected item master
        // read); service identity + tariff come from Billing. An inactive
        // mapping row is immutable history — the rail groups by item.
        /** @var list<InventoryItemRef> $medications */
        $medications = $this->inventoryApi->getItems('MEDICATION');
        /** @var list<ItemBillingMapSummary> $mapRows */
        $mapRows = $this->queryBus->dispatch(new SearchItemBillingMap(null));
        /** @var list<BillingServiceSummary> $allServices */
        $allServices = $this->queryBus->dispatch(new SearchBillingServices(null, null));
        $serviceIndex = [];
        foreach ($allServices as $service) {
            $serviceIndex[$service->billingServiceId] = $service;
        }
        $activeByItem = [];
        foreach ($mapRows as $row) {
            if ($row->active) {
                $activeByItem[$row->invItemId] = $row;
            }
        }

        $medicationMaps = array_map(function (InventoryItemRef $item) use ($activeByItem, $serviceIndex, $byService): CatalogItemMapItem {
            $active = $activeByItem[$item->itemId] ?? null;
            $service = $active !== null ? ($serviceIndex[$active->billingServiceId] ?? null) : null;
            $price = $active !== null ? ($byService[$active->billingServiceId] ?? null) : null;

            return new CatalogItemMapItem(
                invItemId: $item->itemId,
                code: $item->code,
                name: $item->name,
                itemType: $item->itemType,
                itemActive: $item->active,
                mapped: $active !== null,
                billingServiceId: $active?->billingServiceId,
                serviceCode: $service?->code,
                serviceName: $service?->name,
                serviceStatus: $service?->status,
                amount: $price?->amount,
                resolvedScope: $price?->resolvedScope,
                currency: $price?->currency,
                mappedAt: $active?->occurredAt,
            );
        }, $medications);

        $itemMapHistory = array_map(static function (ItemBillingMapSummary $row) use ($serviceIndex): CatalogItemMapHistoryItem {
            return new CatalogItemMapHistoryItem(
                mapId: $row->mapId,
                invItemId: $row->invItemId,
                billingServiceId: $row->billingServiceId,
                serviceName: $serviceIndex[$row->billingServiceId]->name ?? null,
                active: $row->active,
                occurredAt: $row->occurredAt,
            );
        }, $mapRows);

        return new CatalogDashboardProps(
            facilityId: $facilityId,
            canManageCatalog: $permissions['catalog'] ?? false,
            canRetryCharges: $permissions['retry'] ?? false,
            services: $items,
            category: $category,
            status: $status,
            selectedServiceId: $selected?->billingServiceId,
            selectedCode: $selected?->code,
            selectedName: $selected?->name,
            selectedCategory: $selected?->category,
            selectedStatus: $selected?->status,
            facilityAmount: $facilityAmount,
            facilityEffectiveFrom: $facilityFrom,
            defaultAmount: $defaultAmount,
            defaultEffectiveFrom: $defaultFrom,
            pendingEvents: $pendingItems,
            tariffHistory: $historyItems,
            medicationMaps: $medicationMaps,
            itemMapHistory: $itemMapHistory,
        );
    }
}
