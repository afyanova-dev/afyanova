<?php

declare(strict_types=1);

namespace App\Domains\Inventory;

use App\Domains\Inventory\Application\Command\AddStockCountLine;
use App\Domains\Inventory\Application\Command\AddStockCountLineHandler;
use App\Domains\Inventory\Application\Command\ApproveStockAdjustment;
use App\Domains\Inventory\Application\Command\ApproveStockAdjustmentHandler;
use App\Domains\Inventory\Application\Command\AttachGoodsReceiptInvoice;
use App\Domains\Inventory\Application\Command\AttachGoodsReceiptInvoiceHandler;
use App\Domains\Inventory\Application\Command\CancelStockReservation;
use App\Domains\Inventory\Application\Command\CancelStockReservationHandler;
use App\Domains\Inventory\Application\Command\CloseStockCount;
use App\Domains\Inventory\Application\Command\CloseStockCountHandler;
use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Application\Command\CreateStockAdjustmentHandler;
use App\Domains\Inventory\Application\Command\DefineInventoryItem;
use App\Domains\Inventory\Application\Command\DefineInventoryItemHandler;
use App\Domains\Inventory\Application\Command\DefineStockLocation;
use App\Domains\Inventory\Application\Command\DefineStockLocationHandler;
use App\Domains\Inventory\Application\Command\DefineStockLocationType;
use App\Domains\Inventory\Application\Command\DefineStockLocationTypeHandler;
use App\Domains\Inventory\Application\Command\ExpireStockReservation;
use App\Domains\Inventory\Application\Command\ExpireStockReservationHandler;
use App\Domains\Inventory\Application\Command\MatchGoodsReceiptInvoice;
use App\Domains\Inventory\Application\Command\MatchGoodsReceiptInvoiceHandler;
use App\Domains\Inventory\Application\Command\ReceiveStock;
use App\Domains\Inventory\Application\Command\ReceiveStockHandler;
use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Application\Command\RecordConsumptionHandler;
use App\Domains\Inventory\Application\Command\RecordGoodsReceipt;
use App\Domains\Inventory\Application\Command\RecordGoodsReceiptHandler;
use App\Domains\Inventory\Application\Command\RejectStockAdjustment;
use App\Domains\Inventory\Application\Command\RejectStockAdjustmentHandler;
use App\Domains\Inventory\Application\Command\ReleaseStockReservation;
use App\Domains\Inventory\Application\Command\ReleaseStockReservationHandler;
use App\Domains\Inventory\Application\Command\ReserveStock;
use App\Domains\Inventory\Application\Command\ReserveStockHandler;
use App\Domains\Inventory\Application\Command\ReturnGoodsToSupplier;
use App\Domains\Inventory\Application\Command\ReturnGoodsToSupplierHandler;
use App\Domains\Inventory\Application\Command\ReverseConsumption;
use App\Domains\Inventory\Application\Command\ReverseConsumptionHandler;
use App\Domains\Inventory\Application\Command\SaveStockCountCounts;
use App\Domains\Inventory\Application\Command\SaveStockCountCountsHandler;
use App\Domains\Inventory\Application\Command\SetInventoryItemActive;
use App\Domains\Inventory\Application\Command\SetInventoryItemActiveHandler;
use App\Domains\Inventory\Application\Command\SetItemLevel;
use App\Domains\Inventory\Application\Command\SetItemLevelHandler;
use App\Domains\Inventory\Application\Command\SetStockLocationActive;
use App\Domains\Inventory\Application\Command\SetStockLocationActiveHandler;
use App\Domains\Inventory\Application\Command\StartStockCount;
use App\Domains\Inventory\Application\Command\StartStockCountHandler;
use App\Domains\Inventory\Application\Command\TransferStock;
use App\Domains\Inventory\Application\Command\TransferStockHandler;
use App\Domains\Inventory\Application\InventoryApiService;
use App\Domains\Inventory\Application\Query\GetAvailableStock;
use App\Domains\Inventory\Application\Query\GetAvailableStockHandler;
use App\Domains\Inventory\Application\Query\GetExpiringStock;
use App\Domains\Inventory\Application\Query\GetExpiringStockHandler;
use App\Domains\Inventory\Application\Query\GetGoodsReceipt;
use App\Domains\Inventory\Application\Query\GetGoodsReceiptHandler;
use App\Domains\Inventory\Application\Query\GetItem;
use App\Domains\Inventory\Application\Query\GetItemHandler;
use App\Domains\Inventory\Application\Query\GetItemLevels;
use App\Domains\Inventory\Application\Query\GetItemLevelsHandler;
use App\Domains\Inventory\Application\Query\GetItems;
use App\Domains\Inventory\Application\Query\GetItemsHandler;
use App\Domains\Inventory\Application\Query\GetLowStock;
use App\Domains\Inventory\Application\Query\GetLowStockHandler;
use App\Domains\Inventory\Application\Query\GetStockAdjustments;
use App\Domains\Inventory\Application\Query\GetStockAdjustmentsHandler;
use App\Domains\Inventory\Application\Query\GetStockBalance;
use App\Domains\Inventory\Application\Query\GetStockBalanceHandler;
use App\Domains\Inventory\Application\Query\GetStockByFacility;
use App\Domains\Inventory\Application\Query\GetStockByFacilityHandler;
use App\Domains\Inventory\Application\Query\GetStockByLocation;
use App\Domains\Inventory\Application\Query\GetStockByLocationHandler;
use App\Domains\Inventory\Application\Query\GetStockCounts;
use App\Domains\Inventory\Application\Query\GetStockCountsHandler;
use App\Domains\Inventory\Application\Query\GetStockCountSheet;
use App\Domains\Inventory\Application\Query\GetStockCountSheetHandler;
use App\Domains\Inventory\Application\Query\GetStockLedger;
use App\Domains\Inventory\Application\Query\GetStockLedgerHandler;
use App\Domains\Inventory\Application\Query\GetStockLocation;
use App\Domains\Inventory\Application\Query\GetStockLocationHandler;
use App\Domains\Inventory\Application\Query\GetStockLocations;
use App\Domains\Inventory\Application\Query\GetStockLocationsHandler;
use App\Domains\Inventory\Application\Query\GetStockLocationTypes;
use App\Domains\Inventory\Application\Query\GetStockLocationTypesHandler;
use App\Domains\Inventory\Application\Query\GetStockReservation;
use App\Domains\Inventory\Application\Query\GetStockReservationHandler;
use App\Domains\Inventory\Application\Query\GetStockTransfers;
use App\Domains\Inventory\Application\Query\GetStockTransfersHandler;
use App\Domains\Inventory\Application\Query\GetTransferSourceStock;
use App\Domains\Inventory\Application\Query\GetTransferSourceStockHandler;
use App\Domains\Inventory\Application\Query\SearchGoodsReceipts;
use App\Domains\Inventory\Application\Query\SearchGoodsReceiptsHandler;
use App\Domains\Inventory\Application\Query\SearchStockReservations;
use App\Domains\Inventory\Application\Query\SearchStockReservationsHandler;
use App\Domains\Inventory\Authorization\GoodsReceiptPolicy;
use App\Domains\Inventory\Authorization\InventoryCatalogPolicy;
use App\Domains\Inventory\Authorization\StockAdjustmentPolicy;
use App\Domains\Inventory\Authorization\StockBalancePolicy;
use App\Domains\Inventory\Authorization\StockCountPolicy;
use App\Domains\Inventory\Authorization\StockReservationPolicy;
use App\Domains\Inventory\Console\DefineItemCommand;
use App\Domains\Inventory\Console\DefineStockLocationCommand;
use App\Domains\Inventory\Console\ExpireStockReservationsCommand;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Inventory\Domain\BatchRepository;
use App\Domains\Inventory\Domain\GoodsReceiptRepository;
use App\Domains\Inventory\Domain\ItemReader;
use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockCount;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\Model\StockLocationType;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\StockAdjustmentReader;
use App\Domains\Inventory\Domain\StockAdjustmentRepository;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockCountReader;
use App\Domains\Inventory\Domain\StockCountRepository;
use App\Domains\Inventory\Domain\StockLedgerReader;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Domains\Inventory\Domain\StockLocationRepository;
use App\Domains\Inventory\Domain\StockLocationTypeReader;
use App\Domains\Inventory\Domain\StockLocationTypeRepository;
use App\Domains\Inventory\Domain\StockReservationReader;
use App\Domains\Inventory\Domain\StockReservationRepository;
use App\Domains\Inventory\Domain\StockTransferReader;
use App\Domains\Inventory\Domain\StockTransferRepository;
use App\Domains\Inventory\Infrastructure\Reader\EloquentItemReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockAdjustmentReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockBalanceReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockCountReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockLedgerReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockLocationReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockLocationTypeReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockReservationReader;
use App\Domains\Inventory\Infrastructure\Reader\EloquentStockTransferReader;
use App\Domains\Inventory\Infrastructure\Repository\EloquentBatchRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentGoodsReceiptRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentItemRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockAdjustmentRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockBalanceRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockCountRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockLocationRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockLocationTypeRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockReservationRepository;
use App\Domains\Inventory\Infrastructure\Repository\EloquentStockTransferRepository;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Inventory & Warehousing module service provider
 * (18-inventory-and-warehousing-domain.md; ADR-170).
 *
 * Inv.1: the Item Master + Stock Location catalogs (console-authored), the
 * Batch / Lot record, the materialised Stock Balance, the immutable Stock
 * Movement ledger, and the Stock Adjustment workflow — receipt / consumption /
 * adjustment.
 *
 * Inv.2 (ADR-180): the Stock Reservation aggregate — `inv_stock_reservations`
 * writes `inv_stock_balances.reserved_qty`; `ReserveStock` / `Release` /
 * `Cancel` / `ExpireStockReservation` (+ the `inventory:expire-reservations`
 * hourly sweep); `RecordConsumption(?reservationId)` fulfilment;
 * `StockReserved` / `StockReservationReleased` events (no Inv.2 consumer).
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ItemRepository::class, EloquentItemRepository::class);
        $this->app->bind(StockLocationRepository::class, EloquentStockLocationRepository::class);
        $this->app->bind(StockLocationTypeRepository::class, EloquentStockLocationTypeRepository::class);
        $this->app->bind(BatchRepository::class, EloquentBatchRepository::class);
        $this->app->bind(GoodsReceiptRepository::class, EloquentGoodsReceiptRepository::class);
        $this->app->bind(StockBalanceRepository::class, EloquentStockBalanceRepository::class);
        $this->app->bind(StockAdjustmentRepository::class, EloquentStockAdjustmentRepository::class);
        $this->app->bind(StockReservationRepository::class, EloquentStockReservationRepository::class);
        $this->app->bind(StockTransferRepository::class, EloquentStockTransferRepository::class);
        $this->app->bind(StockCountRepository::class, EloquentStockCountRepository::class);

        $this->app->bind(ItemReader::class, EloquentItemReader::class);
        $this->app->bind(StockLocationReader::class, EloquentStockLocationReader::class);
        $this->app->bind(StockLocationTypeReader::class, EloquentStockLocationTypeReader::class);
        $this->app->bind(StockBalanceReader::class, EloquentStockBalanceReader::class);
        $this->app->bind(StockLedgerReader::class, EloquentStockLedgerReader::class);
        $this->app->bind(StockAdjustmentReader::class, EloquentStockAdjustmentReader::class);
        $this->app->bind(StockReservationReader::class, EloquentStockReservationReader::class);
        $this->app->bind(StockTransferReader::class, EloquentStockTransferReader::class);
        $this->app->bind(StockCountReader::class, EloquentStockCountReader::class);

        // ADR-171 D-N — the first published `InventoryApi` method, driven by
        // Procurement Proc.1a as the concrete caller (the `governance-rulings`
        // additive-wiring exception; no other Inv.1 file changed).
        $this->app->bind(InventoryApi::class, InventoryApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Presentation/Http/routes.php');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DefineItemCommand::class,
                DefineStockLocationCommand::class,
                ExpireStockReservationsCommand::class,
            ]);
        }

        CommandBus::map([
            DefineInventoryItem::class => DefineInventoryItemHandler::class,
            DefineStockLocation::class => DefineStockLocationHandler::class,
            DefineStockLocationType::class => DefineStockLocationTypeHandler::class,
            SetInventoryItemActive::class => SetInventoryItemActiveHandler::class,
            SetItemLevel::class => SetItemLevelHandler::class,
            SetStockLocationActive::class => SetStockLocationActiveHandler::class,
            ReceiveStock::class => ReceiveStockHandler::class,
            RecordGoodsReceipt::class => RecordGoodsReceiptHandler::class, // Phase 1 — multi-line GRN
            AttachGoodsReceiptInvoice::class => AttachGoodsReceiptInvoiceHandler::class,
            MatchGoodsReceiptInvoice::class => MatchGoodsReceiptInvoiceHandler::class,
            ReturnGoodsToSupplier::class => ReturnGoodsToSupplierHandler::class,
            RecordConsumption::class => RecordConsumptionHandler::class,
            TransferStock::class => TransferStockHandler::class,
            StartStockCount::class => StartStockCountHandler::class,
            SaveStockCountCounts::class => SaveStockCountCountsHandler::class,
            AddStockCountLine::class => AddStockCountLineHandler::class,
            CloseStockCount::class => CloseStockCountHandler::class,
            ReverseConsumption::class => ReverseConsumptionHandler::class, // Pharm.3b — ADR-185 D-C
            CreateStockAdjustment::class => CreateStockAdjustmentHandler::class,
            ApproveStockAdjustment::class => ApproveStockAdjustmentHandler::class,
            RejectStockAdjustment::class => RejectStockAdjustmentHandler::class,

            // Inv.2 — the Stock Reservation aggregate (ADR-180 D-E).
            ReserveStock::class => ReserveStockHandler::class,
            ReleaseStockReservation::class => ReleaseStockReservationHandler::class,
            CancelStockReservation::class => CancelStockReservationHandler::class,
            ExpireStockReservation::class => ExpireStockReservationHandler::class,
        ]);

        QueryBus::map([
            GetItem::class => GetItemHandler::class,
            GetItems::class => GetItemsHandler::class,
            GetGoodsReceipt::class => GetGoodsReceiptHandler::class,
            SearchGoodsReceipts::class => SearchGoodsReceiptsHandler::class,
            GetStockLocation::class => GetStockLocationHandler::class,
            GetStockLocations::class => GetStockLocationsHandler::class,
            GetStockLocationTypes::class => GetStockLocationTypesHandler::class,
            GetStockBalance::class => GetStockBalanceHandler::class,
            GetStockByFacility::class => GetStockByFacilityHandler::class,
            GetStockByLocation::class => GetStockByLocationHandler::class,
            GetAvailableStock::class => GetAvailableStockHandler::class,
            GetStockLedger::class => GetStockLedgerHandler::class,
            GetStockAdjustments::class => GetStockAdjustmentsHandler::class,
            GetExpiringStock::class => GetExpiringStockHandler::class,
            GetLowStock::class => GetLowStockHandler::class,
            GetItemLevels::class => GetItemLevelsHandler::class,
            GetStockTransfers::class => GetStockTransfersHandler::class,
            GetTransferSourceStock::class => GetTransferSourceStockHandler::class,
            GetStockCounts::class => GetStockCountsHandler::class,
            GetStockCountSheet::class => GetStockCountSheetHandler::class,

            // Inv.2 (ADR-180 D-G).
            GetStockReservation::class => GetStockReservationHandler::class,
            SearchStockReservations::class => SearchStockReservationsHandler::class,
        ]);

        Gate::policy(StockBalance::class, StockBalancePolicy::class);
        Gate::policy(GoodsReceipt::class, GoodsReceiptPolicy::class);
        Gate::policy(Item::class, InventoryCatalogPolicy::class);
        Gate::policy(StockLocation::class, InventoryCatalogPolicy::class);
        Gate::policy(StockLocationType::class, InventoryCatalogPolicy::class);
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);
        Gate::policy(StockCount::class, StockCountPolicy::class);
        Gate::policy(StockReservation::class, StockReservationPolicy::class);
    }
}
