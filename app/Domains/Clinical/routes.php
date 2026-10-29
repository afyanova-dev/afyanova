<?php

declare(strict_types=1);

use App\Domains\Clinical\Presentation\Http\Controller\AuthorizeEncounterProcedureController;
use App\Domains\Clinical\Presentation\Http\Controller\CreateClinicianPlanController;
use App\Domains\Clinical\Presentation\Http\Controller\ManageProcedureProtocolApprovalsController;
use App\Domains\Clinical\Presentation\Http\Controller\ProcedureWorkController;
use App\Domains\Clinical\Presentation\Http\Controller\RecordClinicianDiagnosisController;
use App\Domains\Clinical\Presentation\Http\Controller\RecordClinicianNoteController;
use App\Domains\Clinical\Presentation\Http\Controller\RecordEncounterTriageController;
use App\Domains\Clinical\Presentation\Http\Controller\RecordEncounterVitalsController;
use App\Domains\Clinical\Presentation\Http\Controller\RequestEncounterProcedureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->prefix('/workspaces/clinical/encounters/{encounter}')
    ->whereUuid('encounter')
    ->group(function (): void {
        Route::post('/vitals', RecordEncounterVitalsController::class)->name('clinical.encounters.vitals.record');
        Route::post('/triage', RecordEncounterTriageController::class)->name('clinical.encounters.triage.record');
        Route::post('/notes', RecordClinicianNoteController::class)->name('clinical.encounters.notes.record');
        Route::post('/diagnoses', RecordClinicianDiagnosisController::class)->name('clinical.encounters.diagnoses.record');
        Route::post('/plans', CreateClinicianPlanController::class)->name('clinical.encounters.plans.create');
        Route::post('/procedures', RequestEncounterProcedureController::class)->name('clinical.encounters.procedures.request');
        Route::post('/procedures/{procedure}/authorize', AuthorizeEncounterProcedureController::class)
            ->whereUuid('procedure')
            ->name('clinical.procedures.authorize');
    });

Route::middleware(['web', 'auth', 'verified'])->prefix('/workspaces/clinical/procedure-tickets/{entry}')
    ->whereUuid('entry')
    ->group(function (): void {
        Route::get('/', [ProcedureWorkController::class, 'show'])->name('clinical.procedure-work.show');
        Route::post('/', [ProcedureWorkController::class, 'update'])->name('clinical.procedure-work.update');
    });

Route::middleware(['web', 'auth', 'verified'])->prefix('/workspaces/clinical/procedure-protocol-approvals')
    ->group(function (): void {
        Route::post('/', [ManageProcedureProtocolApprovalsController::class, 'approve'])
            ->name('clinical.procedure-protocol-approvals.approve');
        Route::post('/{approval}/revoke', [ManageProcedureProtocolApprovalsController::class, 'revoke'])
            ->whereUuid('approval')
            ->name('clinical.procedure-protocol-approvals.revoke');
    });
