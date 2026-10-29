<?php

declare(strict_types=1);

use App\Domains\Inventory\Presentation\Http\Controller\AddStockCountLineController;
use App\Domains\Inventory\Presentation\Http\Controller\ApproveStockAdjustmentController;
use App\Domains\Inventory\Presentation\Http\Controller\AttachGoodsReceiptInvoiceController;
use App\Domains\Inventory\Presentation\Http\Controller\CloseStockCountController;
use App\Domains\Inventory\Presentation\Http\Controller\CreateStockAdjustmentController;
use App\Domains\Inventory\Presentation\Http\Controller\DefineInventoryItemController;
use App\Domains\Inventory\Presentation\Http\Controller\DefineStockLocationController;
use App\Domains\Inventory\Presentation\Http\Controller\DefineStockLocationTypeController;
use App\Domains\Inventory\Presentation\Http\Controller\ExportGoodsReceiptInvoiceMatchController;
use App\Domains\Inventory\Presentation\Http\Controller\InventoryWorkspaceController;
use App\Domains\Inventory\Presentation\Http\Controller\MatchGoodsReceiptInvoiceController;
use App\Domains\Inventory\Presentation\Http\Controller\PrintGoodsReceiptController;
use App\Domains\Inventory\Presentation\Http\Controller\RecordGoodsReceiptController;
use App\Domains\Inventory\Presentation\Http\Controller\RejectStockAdjustmentController;
use App\Domains\Inventory\Presentation\Http\Controller\ReturnGoodsToSupplierController;
use App\Domains\Inventory\Presentation\Http\Controller\SaveStockCountCountsController;
use App\Domains\Inventory\Presentation\Http\Controller\SearchOpenPurchaseOrderLinesController;
use App\Domains\Inventory\Presentation\Http\Controller\SearchOpenPurchaseOrdersController;
use App\Domains\Inventory\Presentation\Http\Controller\SetInventoryItemActiveController;
use App\Domains\Inventory\Presentation\Http\Controller\SetItemLevelController;
use App\Domains\Inventory\Presentation\Http\Controller\SetStockLocationActiveController;
use App\Domains\Inventory\Presentation\Http\Controller\StartStockCountController;
use App\Domains\Inventory\Presentation\Http\Controller\TransferStockController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('workspaces/inventory')->group(function (): void {
    Route::get('/', [InventoryWorkspaceController::class, 'index'])->name('inventory.index');
    Route::post('/items', DefineInventoryItemController::class)->name('inventory.items.store');
    Route::patch('/items/{itemId}/status', SetInventoryItemActiveController::class)->name('inventory.items.status');
    Route::post('/item-levels', SetItemLevelController::class)->name('inventory.item-levels.store');
    Route::post('/locations', DefineStockLocationController::class)->name('inventory.locations.store');
    Route::patch('/locations/{locationId}/status', SetStockLocationActiveController::class)->name('inventory.locations.status');
    Route::post('/location-types', DefineStockLocationTypeController::class)->name('inventory.location-types.store');
    // P0-03: receiving picker searches (JSON, not Inertia pages).
    Route::get('/receipts/open-orders', SearchOpenPurchaseOrdersController::class)->name('inventory.receipts.open-orders');
    Route::get('/receipts/open-orders/{purchaseOrderId}/lines', SearchOpenPurchaseOrderLinesController::class)->name('inventory.receipts.open-orders.lines');
    Route::post('/goods-receipts', RecordGoodsReceiptController::class)->name('inventory.goods-receipts.store');
    Route::post('/goods-receipts/{goodsReceiptId}/invoice', AttachGoodsReceiptInvoiceController::class)->name('inventory.goods-receipts.invoice');
    Route::post('/goods-receipts/{goodsReceiptId}/match', MatchGoodsReceiptInvoiceController::class)->name('inventory.goods-receipts.match');
    Route::post('/goods-receipts/{goodsReceiptId}/returns', ReturnGoodsToSupplierController::class)->name('inventory.goods-receipts.returns');
    Route::get('/goods-receipts/{goodsReceiptId}/invoice-match/export', ExportGoodsReceiptInvoiceMatchController::class)->name('inventory.goods-receipts.match.export');
    Route::get('/goods-receipts/{goodsReceiptId}/print', PrintGoodsReceiptController::class)->name('inventory.goods-receipts.print');
    Route::post('/transfers', TransferStockController::class)->name('inventory.transfers.store');
    Route::post('/stock-counts', StartStockCountController::class)->name('inventory.stock-counts.store');
    Route::post('/stock-counts/{stockCountId}/counts', SaveStockCountCountsController::class)->name('inventory.stock-counts.counts');
    Route::post('/stock-counts/{stockCountId}/lines', AddStockCountLineController::class)->name('inventory.stock-counts.lines');
    Route::post('/stock-counts/{stockCountId}/close', CloseStockCountController::class)->name('inventory.stock-counts.close');
    Route::post('/adjustments', CreateStockAdjustmentController::class)->name('inventory.adjustments.store');
    Route::post('/adjustments/{adjustmentId}/approve', ApproveStockAdjustmentController::class)->name('inventory.adjustments.approve');
    Route::post('/adjustments/{adjustmentId}/reject', RejectStockAdjustmentController::class)->name('inventory.adjustments.reject');
});
