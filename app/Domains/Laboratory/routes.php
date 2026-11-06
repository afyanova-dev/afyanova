<?php

declare(strict_types=1);

use App\Domains\Laboratory\Presentation\Http\Controller\CreateClinicianLabOrderController;
use App\Domains\Laboratory\Presentation\Http\Controller\LaboratoryCatalogController;
use App\Domains\Laboratory\Presentation\Http\Controller\LaboratoryCatalogEditorController;
use App\Domains\Laboratory\Presentation\Http\Controller\LaboratoryExecutionController;
use App\Domains\Laboratory\Presentation\Http\Controller\LaboratoryInstrumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('/workspaces/laboratory')
    ->group(function (): void {
        Route::get('/catalog', [LaboratoryCatalogController::class, 'index'])
            ->name('laboratory.catalog.index');
        Route::post('/catalog/tests', [LaboratoryCatalogController::class, 'storeTest'])
            ->name('laboratory.catalog.tests.store');
        Route::put('/catalog/tests/{definition}', [LaboratoryCatalogController::class, 'updateTest'])
            ->whereUuid('definition')
            ->name('laboratory.catalog.tests.update');
        Route::post('/catalog/tests/{definition}/methods', [LaboratoryCatalogController::class, 'storeMethod'])
            ->whereUuid('definition')
            ->name('laboratory.catalog.tests.methods.store');
        Route::put('/catalog/tests/{definition}/methods/{method}', [LaboratoryCatalogController::class, 'updateMethod'])
            ->whereUuid('definition')
            ->whereUuid('method')
            ->name('laboratory.catalog.tests.methods.update');
        Route::post('/catalog/panels', [LaboratoryCatalogController::class, 'storePanel'])
            ->name('laboratory.catalog.panels.store');
        Route::put('/catalog/panels/{panel}', [LaboratoryCatalogController::class, 'updatePanel'])
            ->whereUuid('panel')
            ->name('laboratory.catalog.panels.update');

        Route::get('/catalog-editors', [LaboratoryCatalogEditorController::class, 'index'])
            ->name('laboratory.catalog-editors.index');
        Route::post('/catalog-editors/{user}', [LaboratoryCatalogEditorController::class, 'grant'])
            ->whereUuid('user')
            ->name('laboratory.catalog-editors.grant');
        Route::delete('/catalog-editors/{user}', [LaboratoryCatalogEditorController::class, 'revoke'])
            ->whereUuid('user')
            ->name('laboratory.catalog-editors.revoke');

        Route::get('/instruments', [LaboratoryInstrumentController::class, 'index'])
            ->name('laboratory.instruments.index');
        Route::post('/instruments', [LaboratoryInstrumentController::class, 'store'])
            ->name('laboratory.instruments.store');
        Route::put('/instruments/{instrument}', [LaboratoryInstrumentController::class, 'update'])
            ->whereUuid('instrument')
            ->name('laboratory.instruments.update');

        Route::get('/executions', [LaboratoryExecutionController::class, 'index'])
            ->name('laboratory.executions.index');
        Route::post('/orders/{order}/items/{item}/executions', [LaboratoryExecutionController::class, 'store'])
            ->whereUuid('order')
            ->whereUuid('item')
            ->name('laboratory.executions.store');
        Route::get('/executions/{execution}', [LaboratoryExecutionController::class, 'show'])
            ->whereUuid('execution')
            ->name('laboratory.executions.show');
        Route::post('/executions/{execution}/materials/{requirement}/uses', [LaboratoryExecutionController::class, 'recordMaterialUse'])
            ->whereUuid('execution')
            ->whereUuid('requirement')
            ->name('laboratory.executions.material-uses.store');
        Route::post('/executions/{execution}/complete', [LaboratoryExecutionController::class, 'complete'])
            ->whereUuid('execution')
            ->name('laboratory.executions.complete');
        Route::post('/executions/{execution}/abort', [LaboratoryExecutionController::class, 'abort'])
            ->whereUuid('execution')
            ->name('laboratory.executions.abort');
    });

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('/workspaces/clinical/encounters/{encounter}')
    ->whereUuid('encounter')
    ->group(function (): void {
        Route::post('/lab-orders', CreateClinicianLabOrderController::class)
            ->name('clinical.encounters.lab-orders.create');
    });
