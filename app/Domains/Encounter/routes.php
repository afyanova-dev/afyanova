<?php

declare(strict_types=1);

use App\Domains\Encounter\Presentation\Http\Controller\EncounterTransferController;
use App\Domains\Encounter\Presentation\Http\Controller\ReceptionCheckInController;
use App\Domains\Encounter\Presentation\Http\Controller\ReceptionCompleteEncounterController;
use App\Domains\Encounter\Presentation\Http\Controller\ReceptionController;
use App\Domains\Encounter\Presentation\Http\Controller\ReceptionDispositionController;
use App\Domains\Encounter\Presentation\Http\Controller\ReceptionStartEncounterController;
use Illuminate\Support\Facades\Route;

/*
 | Encounter module web routes.
 |
 | Only the visit-action endpoints the patient chart posts to. The Reception
 | board itself stays unwired on purpose (no GET route here).
 */
Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/reception', ReceptionController::class)->name('reception.index');
    Route::post('/workspaces/reception/check-in', ReceptionCheckInController::class)->name('reception.check-in');
    Route::post('/workspaces/reception/transfer', EncounterTransferController::class)->name('reception.transfer');
    Route::post('/workspaces/reception/encounters/{encounter}/start', ReceptionStartEncounterController::class)->name('reception.encounters.start')->whereUuid('encounter');
    Route::post('/workspaces/reception/encounters/{encounter}/complete', ReceptionCompleteEncounterController::class)->name('reception.encounters.complete')->whereUuid('encounter');
    Route::post('/workspaces/reception/encounters/{encounter}/disposition', ReceptionDispositionController::class)->name('reception.encounters.disposition')->whereUuid('encounter');
});
