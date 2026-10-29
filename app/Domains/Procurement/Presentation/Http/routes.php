<?php

declare(strict_types=1);

use App\Domains\Procurement\Presentation\Http\Controller\ApprovePurchaseOrderController;
use App\Domains\Procurement\Presentation\Http\Controller\ApprovePurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\CancelPurchaseOrderController;
use App\Domains\Procurement\Presentation\Http\Controller\CancelPurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\ChangeSupplierStatusController;
use App\Domains\Procurement\Presentation\Http\Controller\ClosePurchaseOrderShortController;
use App\Domains\Procurement\Presentation\Http\Controller\ExportPurchaseOrdersController;
use App\Domains\Procurement\Presentation\Http\Controller\ExportPurchaseRequisitionsController;
use App\Domains\Procurement\Presentation\Http\Controller\IssuePurchaseOrderController;
use App\Domains\Procurement\Presentation\Http\Controller\ItemPriceReferencesController;
use App\Domains\Procurement\Presentation\Http\Controller\PrintPurchaseOrderController;
use App\Domains\Procurement\Presentation\Http\Controller\PrintPurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\ProcurementWorkspaceController;
use App\Domains\Procurement\Presentation\Http\Controller\RegisterSupplierController;
use App\Domains\Procurement\Presentation\Http\Controller\RejectPurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\StorePurchaseOrderController;
use App\Domains\Procurement\Presentation\Http\Controller\StorePurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\SubmitPurchaseOrderController;
use App\Domains\Procurement\Presentation\Http\Controller\SubmitPurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\UpdateDraftPurchaseRequisitionController;
use App\Domains\Procurement\Presentation\Http\Controller\UpdateSupplierLicenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('workspaces/procurement')->group(function (): void {
    Route::get('/', [ProcurementWorkspaceController::class, 'index'])->name('procurement.index');

    Route::post('/requests', StorePurchaseRequisitionController::class)->name('procurement.requests.store');
    Route::patch('/requests/{requisitionId}', UpdateDraftPurchaseRequisitionController::class)->name('procurement.requests.update');
    Route::get('/requests/export.xlsx', ExportPurchaseRequisitionsController::class)->name('procurement.requests.export');
    Route::get('/requests/{requisitionId}/pdf', PrintPurchaseRequisitionController::class)->name('procurement.requests.pdf');
    Route::post('/requests/{requisitionId}/submit', SubmitPurchaseRequisitionController::class)->name('procurement.requests.submit');
    Route::post('/requests/{requisitionId}/approve', ApprovePurchaseRequisitionController::class)->name('procurement.requests.approve');
    Route::post('/requests/{requisitionId}/reject', RejectPurchaseRequisitionController::class)->name('procurement.requests.reject');
    Route::post('/requests/{requisitionId}/cancel', CancelPurchaseRequisitionController::class)->name('procurement.requests.cancel');

    Route::post('/orders', StorePurchaseOrderController::class)->name('procurement.orders.store');
    Route::get('/orders/item-prices', ItemPriceReferencesController::class)->name('procurement.orders.item-prices');
    Route::get('/orders/export.xlsx', ExportPurchaseOrdersController::class)->name('procurement.orders.export');
    Route::get('/orders/{purchaseOrderId}/pdf', PrintPurchaseOrderController::class)->name('procurement.orders.pdf');
    Route::post('/orders/{purchaseOrderId}/submit', SubmitPurchaseOrderController::class)->name('procurement.orders.submit');
    Route::post('/orders/{purchaseOrderId}/approve', ApprovePurchaseOrderController::class)->name('procurement.orders.approve');
    Route::post('/orders/{purchaseOrderId}/issue', IssuePurchaseOrderController::class)->name('procurement.orders.issue');
    Route::post('/orders/{purchaseOrderId}/cancel', CancelPurchaseOrderController::class)->name('procurement.orders.cancel');
    Route::post('/orders/{purchaseOrderId}/close-short', ClosePurchaseOrderShortController::class)->name('procurement.orders.close-short');

    Route::post('/suppliers', RegisterSupplierController::class)->name('procurement.suppliers.store');
    Route::patch('/suppliers/{supplierId}/licence', UpdateSupplierLicenceController::class)->name('procurement.suppliers.licence');
    Route::patch('/suppliers/{supplierId}/status', ChangeSupplierStatusController::class)->name('procurement.suppliers.status');
});
