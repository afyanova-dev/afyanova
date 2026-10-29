<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Presentation\Http\Controller\DispensingController;
use App\Domains\Pharmacy\Presentation\Http\Controller\OrderController;
use App\Domains\Pharmacy\Presentation\Http\Controller\PharmacyController;
use App\Domains\Pharmacy\Presentation\Http\Controller\PrescriptionController;
use App\Domains\Pharmacy\Presentation\Http\Controller\TranscriptionController;
use Illuminate\Support\Facades\Route;

/*
 | Pharmacy module web routes (loaded by PharmacyServiceProvider).
 | Grouped and pathed by workspace (IMPLEMENTATION-CONTRACTS.md §15).
 | Phase 1: transcribe → accept → order → validate → dispense → pickup.
 */

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/pharmacy', PharmacyController::class)->name('pharmacy.index');
    Route::post('/workspaces/pharmacy/external-patients', [TranscriptionController::class, 'registerExternalPatient'])->name('pharmacy.external-patients.store');
    Route::post('/workspaces/pharmacy/prescriptions/transcribe', [TranscriptionController::class, 'store'])->name('pharmacy.prescriptions.transcribe');
    Route::post('/workspaces/pharmacy/prescriptions/{prescriptionId}/accept', [PrescriptionController::class, 'accept'])->name('pharmacy.prescriptions.accept');
    Route::post('/workspaces/pharmacy/prescriptions/{prescriptionId}/cancel', [PrescriptionController::class, 'cancel'])->name('pharmacy.prescriptions.cancel');
    Route::post('/workspaces/pharmacy/orders', [OrderController::class, 'store'])->name('pharmacy.orders.store');
    Route::post('/workspaces/pharmacy/orders/{pharmacyOrderId}/validate', [OrderController::class, 'validate'])->name('pharmacy.orders.validate');
    Route::post('/workspaces/pharmacy/orders/{pharmacyOrderId}/reject', [OrderController::class, 'reject'])->name('pharmacy.orders.reject');
    Route::post('/workspaces/pharmacy/orders/{pharmacyOrderId}/hold', [OrderController::class, 'hold'])->name('pharmacy.orders.hold');
    Route::post('/workspaces/pharmacy/orders/{pharmacyOrderId}/release', [OrderController::class, 'releaseHold'])->name('pharmacy.orders.release');
    Route::post('/workspaces/pharmacy/orders/{pharmacyOrderId}/cancel', [OrderController::class, 'cancel'])->name('pharmacy.orders.cancel');
    Route::post('/workspaces/pharmacy/dispensings', [DispensingController::class, 'store'])->name('pharmacy.dispensings.store');
    Route::post('/workspaces/pharmacy/dispensings/{dispensingId}/reverse', [DispensingController::class, 'reverse'])->name('pharmacy.dispensings.reverse');
    Route::post('/workspaces/pharmacy/pickups', [DispensingController::class, 'pickup'])->name('pharmacy.pickups.store');
    Route::post('/workspaces/pharmacy/pickups/not-collected', [DispensingController::class, 'markNotCollected'])->name('pharmacy.pickups.not-collected');
    Route::post('/workspaces/pharmacy/pickups/cancel', [DispensingController::class, 'cancelPickup'])->name('pharmacy.pickups.cancel');
});
