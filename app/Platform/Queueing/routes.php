<?php

declare(strict_types=1);

use App\Platform\Queueing\Presentation\Http\Controller\CallTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\ClinicianEncounterController;
use App\Platform\Queueing\Presentation\Http\Controller\ClinicianWorkspaceController;
use App\Platform\Queueing\Presentation\Http\Controller\CloseQueueController;
use App\Platform\Queueing\Presentation\Http\Controller\CompleteTicketServiceController;
use App\Platform\Queueing\Presentation\Http\Controller\HoldTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\LeaveTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\ManageProcedureQueueMappingsController;
use App\Platform\Queueing\Presentation\Http\Controller\ManageQueueRoomsController;
use App\Platform\Queueing\Presentation\Http\Controller\ManageQueueRoomStaffController;
use App\Platform\Queueing\Presentation\Http\Controller\NoResponseTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\NurseStationController;
use App\Platform\Queueing\Presentation\Http\Controller\NurseStationEntryController;
use App\Platform\Queueing\Presentation\Http\Controller\NurseStationHandoffController;
use App\Platform\Queueing\Presentation\Http\Controller\NurseStationStartServiceController;
use App\Platform\Queueing\Presentation\Http\Controller\PauseQueueController;
use App\Platform\Queueing\Presentation\Http\Controller\PlaceQueueEntryController;
use App\Platform\Queueing\Presentation\Http\Controller\QueueDetailController;
use App\Platform\Queueing\Presentation\Http\Controller\RecallTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\ReleaseHoldTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\ResumeQueueController;
use App\Platform\Queueing\Presentation\Http\Controller\ServiceQueuesController;
use App\Platform\Queueing\Presentation\Http\Controller\SkipTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\StartTicketServiceController;
use App\Platform\Queueing\Presentation\Http\Controller\TransferTicketController;
use App\Platform\Queueing\Presentation\Http\Controller\UpdateTicketPriorityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/workspaces/service-queues', ServiceQueuesController::class)->name('service-queues.index');
    Route::get('/workspaces/service-queues/{queue}', QueueDetailController::class)->name('service-queues.show');
    Route::get('/workspaces/nurse-station', NurseStationController::class)->name('nurse-station.index');
    Route::get('/workspaces/nurse-station/entries/{entry}', NurseStationEntryController::class)->name('nurse-station.entries.show');
    Route::post('/workspaces/nurse-station/entries/{entry}/start', NurseStationStartServiceController::class)->name('nurse-station.entries.start');
    Route::post('/workspaces/nurse-station/entries/{entry}/handoff', NurseStationHandoffController::class)->name('nurse-station.entries.handoff');
    Route::get('/workspaces/clinician', ClinicianWorkspaceController::class)->name('clinician.index');
    Route::get('/workspaces/clinician/entries/{entry}', ClinicianEncounterController::class)->name('clinician.entries.show');

    Route::post('/workspaces/service-queues/entries', PlaceQueueEntryController::class)->name('service-queues.entries.store');
    Route::post('/workspaces/service-queues/entries/{entry}/call', CallTicketController::class)->name('service-queues.entries.call');
    Route::post('/workspaces/service-queues/entries/{entry}/recall', RecallTicketController::class)->name('service-queues.entries.recall');
    Route::post('/workspaces/service-queues/entries/{entry}/start', StartTicketServiceController::class)->name('service-queues.entries.start');
    Route::post('/workspaces/service-queues/entries/{entry}/complete', CompleteTicketServiceController::class)->name('service-queues.entries.complete');
    Route::post('/workspaces/service-queues/entries/{entry}/hold', HoldTicketController::class)->name('service-queues.entries.hold');
    Route::post('/workspaces/service-queues/entries/{entry}/release', ReleaseHoldTicketController::class)->name('service-queues.entries.release');
    Route::post('/workspaces/service-queues/entries/{entry}/skip', SkipTicketController::class)->name('service-queues.entries.skip');
    Route::post('/workspaces/service-queues/entries/{entry}/no-response', NoResponseTicketController::class)->name('service-queues.entries.no-response');
    Route::post('/workspaces/service-queues/entries/{entry}/leave', LeaveTicketController::class)->name('service-queues.entries.leave');
    Route::post('/workspaces/service-queues/entries/{entry}/transfer', TransferTicketController::class)->name('service-queues.entries.transfer');
    Route::patch('/workspaces/service-queues/entries/{entry}/priority', UpdateTicketPriorityController::class)->name('service-queues.entries.priority');

    Route::post('/workspaces/service-queues/queues/{queue}/pause', PauseQueueController::class)->name('service-queues.queues.pause');
    Route::post('/workspaces/service-queues/queues/{queue}/resume', ResumeQueueController::class)->name('service-queues.queues.resume');
    Route::post('/workspaces/service-queues/queues/{queue}/close', CloseQueueController::class)->name('service-queues.queues.close');
    Route::put('/workspaces/service-queues/queues/{queue}/rooms', ManageQueueRoomsController::class)->name('service-queues.queues.rooms');
    Route::put('/workspaces/service-queues/queues/{queue}/rooms/{room}/staff', ManageQueueRoomStaffController::class)->name('service-queues.queues.rooms.staff');
    Route::put('/workspaces/service-queues/procedure-routing', ManageProcedureQueueMappingsController::class)->name('service-queues.procedure-routing');
});
