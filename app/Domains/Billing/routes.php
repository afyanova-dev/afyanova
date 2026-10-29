<?php

declare(strict_types=1);

use App\Domains\Billing\Presentation\Http\Controller\BillableEventController;
use App\Domains\Billing\Presentation\Http\Controller\CashierController;
use App\Domains\Billing\Presentation\Http\Controller\CashSessionController;
use App\Domains\Billing\Presentation\Http\Controller\CatalogController;
use App\Domains\Billing\Presentation\Http\Controller\InvoiceController;
use App\Domains\Billing\Presentation\Http\Controller\PaymentController;
use Illuminate\Support\Facades\Route;

/*
 | Billing module web routes (loaded by BillingServiceProvider).
 | Grouped and pathed by workspace (IMPLEMENTATION-CONTRACTS.md §15).
 | Cashier phase 1: session → account → invoice → payment → allocate.
 */

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/billing', CashierController::class)->name('billing.index');
    Route::post('/workspaces/billing/cash-sessions/open', [CashSessionController::class, 'open'])->name('billing.cash-sessions.open');
    Route::post('/workspaces/billing/cash-sessions/{cashSessionId}/close', [CashSessionController::class, 'close'])->name('billing.cash-sessions.close');
    Route::post('/workspaces/billing/invoices', [InvoiceController::class, 'store'])->name('billing.invoices.store');
    Route::post('/workspaces/billing/invoices/{invoiceId}/issue', [InvoiceController::class, 'issue'])->name('billing.invoices.issue');
    Route::post('/workspaces/billing/payments', [PaymentController::class, 'store'])->name('billing.payments.store');
    Route::post('/workspaces/billing/payments/allocate', [PaymentController::class, 'allocate'])->name('billing.payments.allocate');
    Route::get('/workspaces/billing/catalog', [CatalogController::class, 'index'])->name('billing.catalog.index');
    Route::post('/workspaces/billing/catalog/services', [CatalogController::class, 'store'])->name('billing.catalog.services.store');
    Route::patch('/workspaces/billing/catalog/services/{billingServiceId}', [CatalogController::class, 'update'])->name('billing.catalog.services.update');
    Route::post('/workspaces/billing/catalog/services/{billingServiceId}/status', [CatalogController::class, 'setStatus'])->name('billing.catalog.services.status');
    Route::post('/workspaces/billing/catalog/tariffs', [CatalogController::class, 'setTariff'])->name('billing.catalog.tariffs.store');
    Route::post('/workspaces/billing/catalog/tariffs/end', [CatalogController::class, 'endTariff'])->name('billing.catalog.tariffs.end');
    Route::post('/workspaces/billing/catalog/item-maps', [CatalogController::class, 'mapItem'])->name('billing.catalog.item-maps.store');
    Route::post('/workspaces/billing/catalog/item-maps/deactivate', [CatalogController::class, 'deactivateItemMap'])->name('billing.catalog.item-maps.deactivate');
    Route::post('/workspaces/billing/catalog/events/{billableEventId}/retry', [BillableEventController::class, 'retry'])->name('billing.catalog.events.retry');
});
