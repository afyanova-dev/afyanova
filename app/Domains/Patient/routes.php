<?php

declare(strict_types=1);

use App\Domains\Patient\Presentation\Http\Controller\MarkPatientDeceasedController;
use App\Domains\Patient\Presentation\Http\Controller\PatientChartController;
use App\Domains\Patient\Presentation\Http\Controller\PatientGlanceController;
use App\Domains\Patient\Presentation\Http\Controller\PatientPhotoController;
use App\Domains\Patient\Presentation\Http\Controller\PatientRegistrationController;
use App\Domains\Patient\Presentation\Http\Controller\PatientSearchApiController;
use App\Domains\Patient\Presentation\Http\Controller\PatientSearchBrowseController;
use App\Domains\Patient\Presentation\Http\Controller\PatientStickyNoteController;
use App\Domains\Patient\Presentation\Http\Controller\PatientUpdateController;
use Illuminate\Support\Facades\Route;

/*
 | Patient module web routes (loaded by PatientServiceProvider).
 | Grouped and pathed by workspace (IMPLEMENTATION-CONTRACTS.md §15).
 */

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/patient', PatientSearchBrowseController::class)->name('patient.index');
    Route::get('/workspaces/patient/api/search', PatientSearchApiController::class)->name('patient.api.search');
    Route::get('/workspaces/patient/register', [PatientRegistrationController::class, 'create'])->name('patient.register');
    Route::post('/workspaces/patient/register', [PatientRegistrationController::class, 'store'])->name('patient.store');
    Route::post('/workspaces/patient/register/draft', [PatientRegistrationController::class, 'saveDraft'])->name('patient.register.draft.store');
    Route::delete('/workspaces/patient/register/draft', [PatientRegistrationController::class, 'deleteDraft'])->name('patient.register.draft.destroy');
    Route::get('/workspaces/patient/{patientId}/glance', PatientGlanceController::class)->name('patient.glance');
    Route::get('/workspaces/patient/{patientId}/photo', PatientPhotoController::class)->name('patient.workspace.photo');
    // Legacy alias — serves the SAME composed chart as the canonical URL so
    // old bookmarks and any page still using the workspace URL show identical
    // contents. New pages must use patientChartUrl() in TS which points at the
    // canonical /patient/{uuid}/chart/{tab} route below.
    // Single controller (PatientChartController) — no second profile controller
    // exists, so the two URLs cannot diverge again.
    Route::get('/workspaces/patient/{patientId}', PatientChartController::class)->name('patient.profile');
    // OpenMRS-style chart: /patient/{uuid}/chart[/{tab}].
    // Composed chart read model (profile + per-dashboard domain feeds).
    Route::get('/patient/{patientId}/chart/{tab?}', PatientChartController::class)->name('patient.chart');
    Route::get('/patient/{patientId}/sticky-note', [PatientStickyNoteController::class, 'show'])->name('patient.sticky-note.show');
    Route::post('/patient/{patientId}/sticky-note', [PatientStickyNoteController::class, 'store'])->name('patient.sticky-note.store');
    Route::patch('/patient/{patientId}/sticky-note/{stickyNoteId}', [PatientStickyNoteController::class, 'update'])->name('patient.sticky-note.update');
    Route::delete('/patient/{patientId}/sticky-note/{stickyNoteId}', [PatientStickyNoteController::class, 'destroy'])->name('patient.sticky-note.destroy');
    Route::get('/patient/{patientId}/photo', PatientPhotoController::class)->name('patient.photo');
    Route::patch('/workspaces/patient/{patientId}', PatientUpdateController::class)->name('patient.update');
    Route::post('/workspaces/patient/{patientId}/mark-deceased', MarkPatientDeceasedController::class)->name('patient.mark-deceased');
});
