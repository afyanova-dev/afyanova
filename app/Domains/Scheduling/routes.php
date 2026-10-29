<?php

declare(strict_types=1);

use App\Domains\Scheduling\Presentation\Http\Controller\AppointmentAvailabilityController;
use App\Domains\Scheduling\Presentation\Http\Controller\AppointmentBookingController;
use App\Domains\Scheduling\Presentation\Http\Controller\AppointmentCalendarController;
use App\Domains\Scheduling\Presentation\Http\Controller\AppointmentCalendarDataController;
use App\Domains\Scheduling\Presentation\Http\Controller\AppointmentOptionsController;
use App\Domains\Scheduling\Presentation\Http\Controller\CancelAppointmentController;
use App\Domains\Scheduling\Presentation\Http\Controller\ConfirmAppointmentController;
use App\Domains\Scheduling\Presentation\Http\Controller\NoShowAppointmentController;
use App\Domains\Scheduling\Presentation\Http\Controller\RecordArrivalController;
use App\Domains\Scheduling\Presentation\Http\Controller\RescheduleAppointmentController;
use Illuminate\Support\Facades\Route;

/*
| Scheduling module web routes (loaded by SchedulingServiceProvider).
| Front-desk booking surface for Domain 22 (22-scheduling-and-appointments-domain.md §47, §49).
*/

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    // Merged into the Front Desk board: the standalone daily board is gone.
    // Old links (and the calendar's day links during transition) land on the
    // merged roster with the requested date preserved (validated there).
    Route::get('/workspaces/appointments', function () {
        $date = request()->query('date');

        return redirect()->route('reception.index', is_string($date) && $date !== '' ? ['date' => $date] : []);
    })->name('appointments.index');
    Route::get('/workspaces/appointments/calendar', AppointmentCalendarController::class)->name('appointments.calendar');
    Route::get('/workspaces/appointments/api/calendar', AppointmentCalendarDataController::class)->name('appointments.api.calendar');
    Route::post('/workspaces/appointments/{appointment}/arrive', RecordArrivalController::class)->name('appointments.arrive');
    Route::post('/workspaces/appointments/{appointment}/cancel', CancelAppointmentController::class)->name('appointments.cancel');
    Route::post('/workspaces/appointments/{appointment}/reschedule', RescheduleAppointmentController::class)->name('appointments.reschedule');
    Route::post('/workspaces/appointments/{appointment}/confirm', ConfirmAppointmentController::class)->name('appointments.confirm');
    Route::post('/workspaces/appointments/{appointment}/no-show', NoShowAppointmentController::class)->name('appointments.no-show');
    Route::get('/workspaces/scheduling/appointment-options', AppointmentOptionsController::class)->name('scheduling.appointment-options');
    Route::get('/workspaces/scheduling/available-slots', AppointmentAvailabilityController::class)->name('scheduling.available-slots');
    Route::post('/patient/{patientId}/appointments', AppointmentBookingController::class)->name('scheduling.appointments.book');
});
