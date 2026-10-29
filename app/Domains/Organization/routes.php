<?php

declare(strict_types=1);

use App\Domains\Organization\Presentation\Http\Controller\FacilityStructureController;
use Illuminate\Support\Facades\Route;

/*
 | Organization module web routes (loaded by OrganizationServiceProvider).
 | Read-only JSON for operational dropdowns (IMPLEMENTATION-CONTRACTS.md §15).
 */

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/organization/api/structure', FacilityStructureController::class)->name('organization.api.structure');
});
