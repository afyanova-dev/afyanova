<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Query\GetExpiringStock;
use App\Domains\Inventory\Application\Query\GetGoodsReceipt;
use App\Domains\Inventory\Application\Query\GetItemLevels;
use App\Domains\Inventory\Application\Query\GetItems;
use App\Domains\Inventory\Application\Query\GetLowStock;
use App\Domains\Inventory\Application\Query\GetStockAdjustments;
use App\Domains\Inventory\Application\Query\GetStockBalance;
use App\Domains\Inventory\Application\Query\GetStockByFacility;
use App\Domains\Inventory\Application\Query\GetStockByLocation;
use App\Domains\Inventory\Application\Query\GetStockCounts;
use App\Domains\Inventory\Application\Query\GetStockCountSheet;
use App\Domains\Inventory\Application\Query\GetStockLedger;
use App\Domains\Inventory\Application\Query\GetStockLocations;
use App\Domains\Inventory\Application\Query\GetStockLocationTypes;
use App\Domains\Inventory\Application\Query\GetStockTransfers;
use App\Domains\Inventory\Application\Query\GetTransferSourceStock;
use App\Domains\Inventory\Application\Query\SearchGoodsReceipts;
use App\Domains\Inventory\Application\Query\SearchStockReservations;
use App\Domains\Inventory\Contracts\Dto\ExpiringStockSummary;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptDetail;
use App\Domains\Inventory\Contracts\Dto\GoodsReceiptSearchPage;
use App\Domains\Inventory\Contracts\Dto\ItemLevelSummary;
use App\Domains\Inventory\Contracts\Dto\LocationItemStockSummary;
use App\Domains\Inventory\Contracts\Dto\LowStockItemSummary;
use App\Domains\Inventory\Contracts\Dto\StockAdjustmentSummary;
use App\Domains\Inventory\Contracts\Dto\StockBalanceSummary;
use App\Domains\Inventory\Contracts\Dto\StockCountLineSummary;
use App\Domains\Inventory\Contracts\Dto\StockCountSummary;
use App\Domains\Inventory\Contracts\Dto\StockLedgerEntrySummary;
use App\Domains\Inventory\Contracts\Dto\StockLocationTypeSummary;
use App\Domains\Inventory\Contracts\Dto\StockReservationSummary;
use App\Domains\Inventory\Contracts\Dto\StockTransferSummary;
use App\Domains\Inventory\Domain\ValueObject\ItemCategory;
use App\Domains\Inventory\Domain\ValueObject\ItemType;
use App\Domains\Inventory\Domain\ValueObject\UnitOfMeasure;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class InventoryWorkspaceController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly TenantContext $context,
        private readonly ScopeChecker $scope,
        private readonly OrganizationApi $organization,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('inventory.access');
        $facilityId = $this->context->facilityId();
        abort_unless($this->context->isGeneral() || ($facilityId !== null && $facilityId !== ''), 403, 'Select General or an active facility context.');

        $countPartialResponse = $this->countPartialResponse($request, $facilityId);
        if ($countPartialResponse !== null) {
            return $countPartialResponse;
        }

        $section = $request->query('section');
        if (! is_string($section) && ($request->query('item_id') || $request->query('location_id'))) {
            $section = 'stock';
        }
        $section = is_string($section) && in_array($section, [
            'overview', 'stock', 'stock-on-hand', 'receipts', 'expiry', 'low-stock',
            'transfers', 'counts', 'adjustments', 'reservations', 'catalogue', 'locations',
        ], true) ? $section : 'overview';
        $canManageItems = $this->context->isGeneral() && ($request->user()?->can('inventory.items.manage') ?? false);
        $needsItems = in_array($section, [
            'overview', 'stock', 'receipts', 'expiry', 'low-stock', 'transfers', 'counts',
            'adjustments', 'reservations', 'catalogue',
        ], true) || $canManageItems;
        $needsLocations = in_array($section, [
            'overview', 'stock', 'stock-on-hand', 'receipts', 'expiry', 'transfers',
            'counts', 'adjustments', 'reservations', 'locations',
        ], true);

        $canManageLocations = $facilityId !== null && ($request->user()?->can('inventory.locations.manage') ?? false);
        $canRequestAdjustment = $facilityId !== null && ($request->user()?->can('inventory.adjustments.create') ?? false);
        $canReceive = $facilityId !== null && ($request->user()?->can('inventory.receipts.create') ?? false);
        // Moving stock between the facility's own locations (Phase 8).
        $canTransfer = $facilityId !== null && ($request->user()?->can('inventory.transfers.create') ?? false);
        // Minimum levels are a facility decision, so the item-master permission
        // is checked in a facility context (not the General context the item
        // catalogue itself requires).
        $canManageLevels = $facilityId !== null && ($request->user()?->can('inventory.items.manage') ?? false);
        $items = $needsItems
            ? $this->queries->dispatch(new GetItems(active: $canManageItems ? null : true))
            : [];
        $locations = ! $needsLocations || $facilityId === null
            ? []
            : $this->queries->dispatch(new GetStockLocations($facilityId, active: $canManageLocations ? null : true));
        $departmentIds = $facilityId !== null && $request->user() instanceof User
            ? $this->scope->facilityDepartmentScope($request->user(), $facilityId, 'inventory.access')
            : null;
        $departmentScoped = $departmentIds !== null;
        $departmentName = null;
        if (is_array($departmentIds) && count($departmentIds) === 1 && $facilityId !== null) {
            $structure = $this->organization->getFacilityStructure($facilityId);
            foreach ($structure?->departments ?? [] as $department) {
                if ((string) ($department['id'] ?? '') === $departmentIds[0]) {
                    $departmentName = (string) ($department['name'] ?? '');
                    break;
                }
            }
        }
        $locationTypes = collect([
            new StockLocationTypeSummary('WAREHOUSE', 'Warehouse', 'BUILT_IN'),
            new StockLocationTypeSummary('STORE', 'Store', 'BUILT_IN'),
            new StockLocationTypeSummary('QUARANTINE', 'Quarantine', 'BUILT_IN'),
        ])->concat($section === 'locations'
            ? $this->queries->dispatch(new GetStockLocationTypes($facilityId))
            : []);
        $adjustmentStatus = $request->query('adjustment_status');
        $adjustmentStatus = is_string($adjustmentStatus)
            && in_array($adjustmentStatus, ['PENDING_APPROVAL', 'APPROVED', 'REJECTED'], true)
                ? $adjustmentStatus
                : null;
        $adjustments = $facilityId === null || ! in_array($section, ['adjustments', 'expiry'], true)
            ? []
            : $this->queries->dispatch(new GetStockAdjustments($facilityId, $adjustmentStatus));
        $reservationStatus = $request->query('reservation_status');
        $reservationStatus = is_string($reservationStatus)
            && in_array($reservationStatus, ['ACTIVE', 'FULFILLED', 'RELEASED', 'EXPIRED', 'CANCELLED'], true)
                ? $reservationStatus
                : null;
        $reservations = $facilityId === null || $section !== 'reservations'
            ? []
            : $this->queries->dispatch(new SearchStockReservations($facilityId, status: $reservationStatus));
        $expiringStock = $facilityId === null || $section !== 'expiry'
            ? []
            : $this->queries->dispatch(new GetExpiringStock($facilityId));
        $lowStock = $facilityId === null || $section !== 'low-stock'
            ? []
            : $this->queries->dispatch(new GetLowStock($facilityId));
        $itemLevels = $facilityId === null || $section !== 'low-stock'
            ? []
            : $this->queries->dispatch(new GetItemLevels($facilityId));
        $transfers = $facilityId === null || $section !== 'transfers'
            ? []
            : $this->queries->dispatch(new GetStockTransfers($facilityId));
        // Physical stock counts at this facility (Phase 8).
        $canManageCounts = $facilityId !== null && ($request->user()?->can('inventory.counts.manage') ?? false);
        $stockCounts = $facilityId === null || $section !== 'counts'
            ? []
            : $this->queries->dispatch(new GetStockCounts($facilityId));

        // The move-stock panel's source list: loaded only when a source
        // location is chosen (`?section=transfers&transfer_from=<id>`), so the
        // ordinary inventory page does not carry every balance at once.
        $transferFromId = $request->query('transfer_from');
        $transferFromLocationId = null;
        $transferSource = [];
        if ($facilityId !== null && $section === 'transfers' && is_string($transferFromId)
            && collect($locations)->contains(fn ($location): bool => $location->locationId === $transferFromId)) {
            $transferFromLocationId = $transferFromId;
            $transferSource = $this->queries->dispatch(new GetTransferSourceStock($transferFromId));
        }
        // The count sheet is loaded only when a count is chosen
        // (`?section=counts&count_id=<id>`), so the ordinary inventory page does
        // not carry every line of every count.
        $stockCountSheet = null;
        $countId = $request->query('count_id');
        if ($facilityId !== null && $section === 'counts'
            && is_string($countId) && Str::isUuid($countId)) {
            try {
                $stockCountSheet = $this->queries->dispatch(new GetStockCountSheet($countId));
            } catch (NotFound|AuthorizationException) {
                $stockCountSheet = null;
            }
        }

        $itemId = $request->query('item_id');
        $locationId = $request->query('location_id');
        if ($section === 'stock' && $departmentScoped && ! is_string($locationId) && count($locations) === 1) {
            $locationId = $locations[0]->locationId;
        }

        $locationStockFilters = [
            'page' => 1,
            'per_page' => 15,
            'sort_by' => 'code',
            'sort_direction' => 'asc',
            'q' => '',
        ];

        $selectedItem = $section === 'stock' && $facilityId !== null && is_string($itemId)
            ? collect($items)->first(fn ($item): bool => $item->itemId === $itemId)
            : null;
        $selectedLocation = $section === 'stock' && $facilityId !== null && is_string($locationId)
            ? collect($locations)->first(fn ($location): bool => $location->locationId === $locationId)
            : null;
        if ($selectedItem !== null && $selectedLocation !== null) {
            $balances = $this->queries->dispatch(new GetStockBalance($selectedItem->itemId, $selectedLocation->locationId));
            $locationItems = [];
        } elseif ($selectedLocation !== null && $selectedItem === null) {
            $validatedFilters = $request->validate([
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:15,30,50,100'],
                'sort_by' => ['sometimes', 'in:code,name,on_hand,reserved,available'],
                'sort_direction' => ['sometimes', 'in:asc,desc'],
                'q' => ['nullable', 'string', 'max:100'],
            ]);
            $locationStockFilters = array_merge($locationStockFilters, $validatedFilters);
            $locationStockFilters['q'] = trim((string) ($locationStockFilters['q'] ?? ''));
            $locationItems = $this->queries->dispatch(new GetStockByLocation(
                $selectedLocation->locationId,
                (int) $locationStockFilters['page'],
                (int) $locationStockFilters['per_page'],
                (string) $locationStockFilters['sort_by'],
                (string) $locationStockFilters['sort_direction'],
                (string) $locationStockFilters['q'],
            ));
            $balances = [];
        } else {
            $balances = [];
            $locationItems = [];
        }
        $locationItemRows = $locationItems instanceof LengthAwarePaginator
            ? $locationItems->getCollection()
            : collect();

        $ledger = $request->query('view') === 'ledger' && $selectedItem !== null && $selectedLocation !== null
            ? $this->queries->dispatch(new GetStockLedger($selectedItem->itemId, $selectedLocation->locationId))
            : null;

        $facilityStock = null;
        $facilityStockFilters = [
            'page' => 1,
            'per_page' => 15,
            'sort_by' => 'code',
            'sort_direction' => 'asc',
            'q' => '',
            'location_id' => null,
        ];
        if ($facilityId !== null && $section === 'stock-on-hand') {
            $facilityStockFilters = array_merge($facilityStockFilters, $request->validate([
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:15,30,50,100'],
                'sort_by' => ['sometimes', 'in:code,location,on_hand,reserved,available'],
                'sort_direction' => ['sometimes', 'in:asc,desc'],
                'q' => ['nullable', 'string', 'max:100'],
                'location_id' => ['nullable', 'uuid'],
            ]));
            $facilityStockFilters['q'] = trim((string) ($facilityStockFilters['q'] ?? ''));
            $facilityStockFilters['location_id'] = is_string($facilityStockFilters['location_id'])
                ? $facilityStockFilters['location_id']
                : null;
            $facilityStock = $this->queries->dispatch(new GetStockByFacility(
                $facilityId,
                (int) $facilityStockFilters['page'],
                (int) $facilityStockFilters['per_page'],
                (string) $facilityStockFilters['sort_by'],
                (string) $facilityStockFilters['sort_direction'],
                (string) $facilityStockFilters['q'],
                $facilityStockFilters['location_id'],
            ));
        }
        $facilityStockRows = $facilityStock instanceof LengthAwarePaginator
            ? $facilityStock->getCollection()
            : collect();

        // Phase 1 — goods-receipt register + opened detail. The register is
        // paginated and searched server-side; the detail is loaded only when a
        // receipt is chosen (`?section=receipts&receipt_id=…`).
        $goodsReceipts = null;
        $goodsReceiptDetail = null;
        $receiptPage = 1;
        $receiptSearch = '';
        if ($facilityId !== null && $section === 'receipts') {
            $receiptSearch = trim((string) $request->query('q', ''));
            $receiptSearch = mb_substr($receiptSearch, 0, 100);
            $receiptPage = max(1, (int) $request->query('page', 1));
            $goodsReceipts = $this->queries->dispatch(new SearchGoodsReceipts(
                $facilityId,
                $receiptSearch,
                $receiptPage,
                25,
            ));
            $receiptId = $request->query('receipt_id');
            if (is_string($receiptId) && Str::isUuid($receiptId)) {
                try {
                    $goodsReceiptDetail = $this->queries->dispatch(new GetGoodsReceipt($receiptId));
                    if ($goodsReceiptDetail->facilityId !== $facilityId) {
                        $goodsReceiptDetail = null;
                    }
                } catch (NotFound|AuthorizationException) {
                    $goodsReceiptDetail = null;
                }
            }
        }

        // P0-03: open POs are searched on demand through the JSON endpoints in
        // `Inventory/routes.php`; they are deliberately not injected into the
        // page. The receiving picker only needs the coarse access flag here.
        $canReceiveAgainstPurchaseOrders = $canReceive
            && ($request->user()?->can('procurement.access') ?? false);

        return Inertia::render('Inventory/Index', [
            'items' => collect($items)->map(fn ($item): array => [
                'id' => $item->itemId,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'description' => $item->description,
                'itemType' => $item->itemType,
                'unitOfMeasure' => $item->uom,
                'packUnit' => $item->packUnit,
                'packSize' => $item->packSize,
                'tracksBatch' => $item->trackBatch,
                'tracksExpiry' => $item->trackExpiry,
                'tracksSerial' => $item->trackSerial,
                'isControlled' => $item->isControlled,
                'active' => $item->active,
            ])->values(),
            'locations' => collect($locations)->map(fn ($location): array => [
                'id' => $location->locationId,
                'code' => $location->code,
                'name' => $location->name,
                'locationType' => $location->locationType,
                'active' => $location->active,
            ])->values(),
            'locationTypes' => $locationTypes->map(fn (StockLocationTypeSummary $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'scope' => $type->scope,
            ])->values(),
            'goodsReceipts' => $goodsReceipts instanceof GoodsReceiptSearchPage
                ? collect($goodsReceipts->receipts)->map(fn ($receipt): array => [
                    'goodsReceiptId' => $receipt->goodsReceiptId,
                    'receiptNumber' => $receipt->receiptNumber,
                    'receivedAt' => $receipt->receivedAt,
                    'sourceType' => $receipt->sourceType,
                    'poRef' => $receipt->poRef,
                    'supplierRef' => $receipt->supplierRef,
                    'locationId' => $receipt->locationId,
                    'locationCode' => $receipt->locationCode,
                    'locationName' => $receipt->locationName,
                    'deliveryNoteNumber' => $receipt->deliveryNoteNumber,
                    'invoiceNumber' => $receipt->invoiceNumber,
                    'invoiceStatus' => $receipt->invoiceStatus,
                    'invoiceTotal' => $receipt->invoiceTotal,
                    'lineCount' => $receipt->lineCount,
                    'rejectedLineCount' => $receipt->rejectedLineCount,
                ])->values()
                : [],
            'goodsReceiptPagination' => $goodsReceipts instanceof GoodsReceiptSearchPage
                ? [
                    'currentPage' => $goodsReceipts->currentPage,
                    'lastPage' => $goodsReceipts->lastPage,
                    'perPage' => $goodsReceipts->perPage,
                    'total' => $goodsReceipts->total,
                    'search' => $goodsReceipts->search,
                ]
                : [
                    'currentPage' => 1,
                    'lastPage' => 1,
                    'perPage' => 25,
                    'total' => 0,
                    'search' => $receiptSearch,
                ],
            'goodsReceiptDetail' => $goodsReceiptDetail instanceof GoodsReceiptDetail
                ? [
                    'goodsReceiptId' => $goodsReceiptDetail->goodsReceiptId,
                    'receiptNumber' => $goodsReceiptDetail->receiptNumber,
                    'receivedAt' => $goodsReceiptDetail->receivedAt,
                    'recordedAt' => $goodsReceiptDetail->recordedAt,
                    'sourceType' => $goodsReceiptDetail->sourceType,
                    'poRef' => $goodsReceiptDetail->poRef,
                    'supplierRef' => $goodsReceiptDetail->supplierRef,
                    'locationId' => $goodsReceiptDetail->locationId,
                    'locationCode' => $goodsReceiptDetail->locationCode,
                    'locationName' => $goodsReceiptDetail->locationName,
                    'deliveryNoteNumber' => $goodsReceiptDetail->deliveryNoteNumber,
                    'deliveryNoteMissingReason' => $goodsReceiptDetail->deliveryNoteMissingReason,
                    'invoiceNumber' => $goodsReceiptDetail->invoiceNumber,
                    'invoiceDate' => $goodsReceiptDetail->invoiceDate,
                    'invoiceStatus' => $goodsReceiptDetail->invoiceStatus,
                    'invoiceCurrency' => $goodsReceiptDetail->invoiceCurrency,
                    'invoiceTotal' => $goodsReceiptDetail->invoiceTotal,
                    'status' => $goodsReceiptDetail->status,
                    'matchStatus' => $goodsReceiptDetail->matchStatus,
                    'remarks' => $goodsReceiptDetail->remarks,
                    'receivedByUserId' => $goodsReceiptDetail->receivedByUserId,
                    'lines' => collect($goodsReceiptDetail->lines)->map(fn ($line): array => [
                        'receiptLineId' => $line->receiptLineId,
                        'itemId' => $line->itemId,
                        'itemCode' => $line->itemCode,
                        'itemName' => $line->itemName,
                        'poLineRef' => $line->poLineRef,
                        'enteredUnit' => $line->enteredUnit,
                        'enteredQuantity' => $line->enteredQuantity,
                        'packSize' => $line->packSize,
                        'quantityDelivered' => $line->quantityDelivered,
                        'quantityRejected' => $line->quantityRejected,
                        'quantityAccepted' => $line->quantityAccepted,
                        'poUnit' => $line->poUnit,
                        'poUnitPrice' => $line->poUnitPrice,
                        'invoiceQuantity' => $line->invoiceQuantity,
                        'invoiceUnitPrice' => $line->invoiceUnitPrice,
                        'uom' => $line->uom,
                        'batchNumber' => $line->batchNumber,
                        'expiryDate' => $line->expiryDate,
                        'manufactureDate' => $line->manufactureDate,
                        'condition' => $line->condition,
                        'substitutedForItemId' => $line->substitutedForItemId,
                        'conditionNotes' => $line->conditionNotes,
                        'rejectionReason' => $line->rejectionReason,
                        'movementNumber' => $line->movementNumber,
                    ])->values(),
                ]
                : null,
            'selectedItemId' => $selectedItem?->itemId,
            'selectedLocationId' => $selectedLocation?->locationId,
            'departmentScoped' => $departmentScoped,
            'departmentName' => $departmentName,
            'balances' => collect($balances)->map(fn ($balance): array => [
                'onHand' => $balance->onHandQty,
                'reserved' => $balance->reservedQty,
                'available' => $balance->availableQty,
                'batchId' => $balance->batchId,
                'batchNumber' => $balance->batchNumber,
                'expiryDate' => $balance->expiryDate,
                'unitOfMeasure' => $balance->uom,
            ])->values(),
            'canReceive' => $canReceive,
            'canMatchInvoices' => $facilityId !== null && ($request->user()?->can('procurement.invoices.match') ?? false),
            'canReceiveAgainstPurchaseOrders' => $canReceiveAgainstPurchaseOrders,
            'canRequestAdjustment' => $canRequestAdjustment,
            'canApproveAdjustments' => $facilityId !== null && ($request->user()?->can('inventory.adjustments.approve') ?? false),
            'currentUserId' => (string) $request->user()?->getKey(),
            'canManageItems' => $canManageItems,
            'canManageLocations' => $canManageLocations,
            'canManageLocationTypes' => ($this->context->isGeneral() || $facilityId !== null)
                && ($request->user()?->can('inventory.location_types.manage') ?? false),
            'contextMode' => $this->context->isGeneral() ? 'general' : 'facility',
            // Controlled vocabularies for the Add-item pickers
            // (inventory-item-master-spec.md §4).
            'itemTypes' => ItemType::ALL,
            'itemCategories' => ItemCategory::ALL,
            'unitsOfMeasure' => UnitOfMeasure::ALL,
            'errors' => session('errors')?->getBag('default')->getMessages() ?? [],
            'locationItems' => $locationItemRows->map(fn ($it): array => [
                'itemId' => $it->itemId,
                'itemCode' => $it->itemCode,
                'itemName' => $it->itemName,
                'onHand' => $it->onHandQty,
                'reserved' => $it->reservedQty,
                'available' => $it->availableQty,
                'uom' => $it->uom,
                'batches' => collect($it->batches)->map(fn ($batch): array => [
                    'batchId' => $batch->batchId,
                    'batchNumber' => $batch->batchNumber,
                    'expiryDate' => $batch->expiryDate,
                    'onHand' => $batch->onHandQty,
                    'reserved' => $batch->reservedQty,
                    'available' => $batch->availableQty,
                    'uom' => $batch->uom,
                ])->values(),
            ])->values(),
            'locationItemPagination' => [
                'currentPage' => $locationItems instanceof LengthAwarePaginator
                    ? $locationItems->currentPage()
                    : 1,
                'lastPage' => $locationItems instanceof LengthAwarePaginator
                    ? $locationItems->lastPage()
                    : 1,
                'perPage' => (int) $locationStockFilters['per_page'],
                'total' => $locationItems instanceof LengthAwarePaginator
                    ? $locationItems->total()
                    : 0,
                'sortBy' => $locationStockFilters['sort_by'],
                'sortDirection' => $locationStockFilters['sort_direction'],
                'search' => $locationStockFilters['q'],
            ],
            'ledger' => $ledger === null ? null : [
                'itemId' => $ledger->itemId,
                'locationId' => $ledger->locationId,
                'currentOnHandQty' => $ledger->currentOnHandQty,
                'ledgerSum' => $ledger->ledgerSum,
                'drift' => $ledger->drift,
                'entries' => array_map(fn (StockLedgerEntrySummary $entry): array => [
                    'movementId' => $entry->movementId,
                    'movementNumber' => $entry->movementNumber,
                    'movementType' => $entry->movementType,
                    'direction' => $entry->direction,
                    'quantity' => $entry->quantity,
                    'uom' => $entry->uom,
                    'enteredUnit' => $entry->enteredUnit,
                    'enteredQuantity' => $entry->enteredQuantity,
                    'packSize' => $entry->packSize,
                    'runningBalance' => $entry->runningBalance,
                    'sourceType' => $entry->sourceType,
                    'sourceRef' => $entry->sourceRef,
                    'poRef' => $entry->poRef,
                    'batchId' => $entry->batchId,
                    'reasonText' => $entry->reasonText,
                    'actorUserId' => $entry->actorUserId,
                    'occurredAt' => $entry->occurredAt,
                ], $ledger->entries),
            ],
            'adjustments' => array_map(fn (StockAdjustmentSummary $adjustment): array => [
                'adjustmentId' => $adjustment->adjustmentId,
                'itemId' => $adjustment->itemId,
                'locationId' => $adjustment->locationId,
                'batchId' => $adjustment->batchId,
                'direction' => $adjustment->direction,
                'quantity' => $adjustment->quantity,
                'reasonCode' => $adjustment->reasonCode,
                'reasonText' => $adjustment->reasonText,
                'witnessName' => $adjustment->witnessName,
                'status' => $adjustment->status,
                'requestedByUserId' => $adjustment->requestedByUserId,
                'requestedAt' => $adjustment->requestedAt,
                'approvedByUserId' => $adjustment->approvedByUserId,
                'approvedAt' => $adjustment->approvedAt,
                'rejectionReason' => $adjustment->rejectionReason,
                'resultingMovementId' => $adjustment->resultingMovementId,
            ], $adjustments),
            'adjustmentStatusFilter' => $adjustmentStatus,
            'reservations' => array_map(fn (StockReservationSummary $reservation): array => [
                'reservationId' => $reservation->reservationId,
                'itemId' => $reservation->itemId,
                'locationId' => $reservation->locationId,
                'batchId' => $reservation->batchId,
                'reservedQty' => $reservation->reservedQty,
                'consumerType' => $reservation->consumerType,
                'consumerRef' => $reservation->consumerRef,
                'reason' => $reservation->reason,
                'holdUntil' => $reservation->holdUntil,
                'status' => $reservation->status,
                'releasedReason' => $reservation->releasedReason,
                'fulfilledByMovementId' => $reservation->fulfilledByMovementId,
                'requestedByUserId' => $reservation->requestedByUserId,
                'createdAt' => $reservation->createdAt,
            ], $reservations),
            'reservationStatusFilter' => $reservationStatus,
            'expiringStock' => array_map(fn (ExpiringStockSummary $row): array => [
                'balanceId' => $row->balanceId,
                'itemId' => $row->itemId,
                'itemCode' => $row->itemCode,
                'itemName' => $row->itemName,
                'locationId' => $row->locationId,
                'locationCode' => $row->locationCode,
                'batchId' => $row->batchId,
                'batchNumber' => $row->batchNumber,
                'expiryDate' => $row->expiryDate,
                'daysRemaining' => $row->daysRemaining,
                'onHand' => $row->onHandQty,
                'uom' => $row->uom,
                'expired' => $row->expired,
                'controlled' => $row->controlled,
            ], $expiringStock),
            'expiryWindowDays' => GetExpiringStock::DEFAULT_WINDOW_DAYS,
            'lowStock' => array_map(fn (LowStockItemSummary $row): array => [
                'itemId' => $row->itemId,
                'itemCode' => $row->itemCode,
                'itemName' => $row->itemName,
                'uom' => $row->uom,
                'packUnit' => $row->packUnit,
                'packSize' => $row->packSize,
                'minLevel' => $row->minLevel,
                'onHand' => $row->onHandQty,
                'expired' => $row->expiredQty,
                'shortfall' => $row->shortfall,
                'outOfStock' => $row->outOfStock,
            ], $lowStock),
            'itemLevels' => array_map(fn (ItemLevelSummary $row): array => [
                'itemId' => $row->itemId,
                'minLevel' => $row->minLevel,
            ], $itemLevels),
            'canManageLevels' => $canManageLevels,
            'transfers' => array_map(fn (StockTransferSummary $transfer): array => [
                'transferId' => $transfer->transferId,
                'transferNumber' => $transfer->transferNumber,
                'itemId' => $transfer->itemId,
                'itemCode' => $transfer->itemCode,
                'itemName' => $transfer->itemName,
                'fromLocationId' => $transfer->fromLocationId,
                'fromLocationCode' => $transfer->fromLocationCode,
                'toLocationId' => $transfer->toLocationId,
                'toLocationCode' => $transfer->toLocationCode,
                'batchId' => $transfer->batchId,
                'batchNumber' => $transfer->batchNumber,
                'quantity' => $transfer->quantity,
                'uom' => $transfer->uom,
                'reasonText' => $transfer->reasonText,
                'movedByUserId' => $transfer->movedByUserId,
                'movedAt' => $transfer->movedAt,
            ], $transfers),
            'transferSource' => array_map(fn (LocationItemStockSummary $row): array => [
                'itemId' => $row->itemId,
                'itemCode' => $row->itemCode,
                'itemName' => $row->itemName,
                'onHand' => $row->onHandQty,
                'reserved' => $row->reservedQty,
                'available' => $row->availableQty,
                'uom' => $row->uom,
                'batches' => array_map(fn ($batch): array => [
                    'batchId' => $batch->batchId,
                    'batchNumber' => $batch->batchNumber,
                    'expiryDate' => $batch->expiryDate,
                    'onHand' => $batch->onHandQty,
                    'reserved' => $batch->reservedQty,
                    'available' => $batch->availableQty,
                    'uom' => $batch->uom,
                    'eligible' => $batch->eligible,
                ], $row->batches),
            ], $transferSource),
            'transferFromLocationId' => $transferFromLocationId,
            'canTransfer' => $canTransfer,
            'stockCounts' => array_map(fn (StockCountSummary $count): array => $this->countPayload($count), $stockCounts),
            'canManageCounts' => $canManageCounts,
            'stockCountSheet' => $stockCountSheet === null ? null : [
                'count' => $this->countPayload($stockCountSheet->count),
                'lines' => array_map(
                    fn (StockCountLineSummary $line): array => $this->sheetLinePayload($line),
                    $stockCountSheet->lines,
                ),
            ],
            'facilityStock' => $facilityStockRows->map(fn (StockBalanceSummary $row): array => [
                'balanceId' => $row->balanceId,
                'itemId' => $row->itemId,
                'itemCode' => $row->itemCode,
                'itemName' => $row->itemName,
                'locationId' => $row->locationId,
                'locationCode' => $row->locationCode,
                'batchId' => $row->batchId,
                'batchNumber' => $row->batchNumber,
                'expiryDate' => $row->expiryDate,
                'onHand' => $row->onHandQty,
                'reserved' => $row->reservedQty,
                'available' => $row->availableQty,
                'uom' => $row->uom,
            ])->values(),
            'facilityStockPagination' => [
                'currentPage' => $facilityStock instanceof LengthAwarePaginator
                    ? $facilityStock->currentPage()
                    : 1,
                'lastPage' => $facilityStock instanceof LengthAwarePaginator
                    ? $facilityStock->lastPage()
                    : 1,
                'perPage' => (int) $facilityStockFilters['per_page'],
                'total' => $facilityStock instanceof LengthAwarePaginator
                    ? $facilityStock->total()
                    : 0,
                'sortBy' => $facilityStockFilters['sort_by'],
                'sortDirection' => $facilityStockFilters['sort_direction'],
                'search' => $facilityStockFilters['q'],
                'locationId' => $facilityStockFilters['location_id'],
            ],
            'status' => session('status'),
        ]);
    }

    private function countPartialResponse(Request $request, ?string $facilityId): ?Response
    {
        $partialComponent = $request->header('X-Inertia-Partial-Component');
        $partialData = array_filter(array_map(
            'trim',
            explode(',', (string) $request->header('X-Inertia-Partial-Data')),
        ));
        $countProps = ['stockCounts', 'stockCountSheet', 'status', 'errors'];

        if ($partialComponent !== 'Inventory/Index'
            || $partialData === []
            || array_diff($partialData, $countProps) !== []
            || array_intersect($partialData, ['stockCounts', 'stockCountSheet']) === []) {
            return null;
        }

        $stockCounts = in_array('stockCounts', $partialData, true)
            ? ($facilityId === null ? [] : $this->queries->dispatch(new GetStockCounts($facilityId)))
            : [];

        $stockCountSheet = null;
        $countId = $request->query('count_id');
        if ($facilityId !== null && $request->query('section') === 'counts'
            && is_string($countId) && Str::isUuid($countId)
            && in_array('stockCountSheet', $partialData, true)) {
            try {
                $stockCountSheet = $this->queries->dispatch(new GetStockCountSheet($countId));
            } catch (NotFound|AuthorizationException) {
                $stockCountSheet = null;
            }
        }

        $props = [];
        if (in_array('stockCounts', $partialData, true)) {
            $props['stockCounts'] = array_map(
                fn (StockCountSummary $count): array => $this->countPayload($count),
                $stockCounts,
            );
        }
        if (in_array('stockCountSheet', $partialData, true)) {
            $props['stockCountSheet'] = $stockCountSheet === null ? null : [
                'count' => $this->countPayload($stockCountSheet->count),
                'lines' => array_map(
                    fn (StockCountLineSummary $line): array => $this->sheetLinePayload($line),
                    $stockCountSheet->lines,
                ),
            ];
        }
        if (in_array('status', $partialData, true)) {
            $props['status'] = session('status');
        }
        if (in_array('errors', $partialData, true)) {
            $props['errors'] = session('errors')?->getBag('default')->getMessages() ?? [];
        }

        return Inertia::render('Inventory/Index', $props);
    }

    /**
     * @return array<string, mixed>
     */
    private function countPayload(StockCountSummary $count): array
    {
        return [
            'countId' => $count->countId,
            'countNumber' => $count->countNumber,
            'facilityId' => $count->facilityId,
            'locationId' => $count->locationId,
            'locationCode' => $count->locationCode,
            'locationName' => $count->locationName,
            'status' => $count->status,
            'notes' => $count->notes,
            'startedByUserId' => $count->startedByUserId,
            'startedAt' => $count->startedAt,
            'closedAt' => $count->closedAt,
            'lineCount' => $count->lineCount,
            'countedCount' => $count->countedCount,
            'varianceCount' => $count->varianceCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetLinePayload(StockCountLineSummary $line): array
    {
        return [
            'lineId' => $line->lineId,
            'itemId' => $line->itemId,
            'itemCode' => $line->itemCode,
            'itemName' => $line->itemName,
            'uom' => $line->uom,
            'packUnit' => $line->packUnit,
            'packSize' => $line->packSize,
            'batchId' => $line->batchId,
            'batchNumber' => $line->batchNumber,
            'systemQty' => $line->systemQty,
            'countedQty' => $line->countedQty,
            'variance' => $line->variance,
            'isControlled' => $line->isControlled,
            'adjustmentId' => $line->adjustmentId,
            'adjustmentStatus' => $line->adjustmentStatus,
            'varianceSkippedReason' => $line->varianceSkippedReason,
        ];
    }
}
