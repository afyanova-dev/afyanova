<?php

declare(strict_types=1);

use App\Domains\Laboratory\Presentation\Http\Controller\CreateClinicianLabOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('/workspaces/clinical/encounters/{encounter}')
    ->whereUuid('encounter')
    ->group(function (): void {
        Route::post('/lab-orders', CreateClinicianLabOrderController::class)
            ->name('clinical.encounters.lab-orders.create');
    });
