<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Presentation\Http\Request\RescheduleAppointmentRequest;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

final class RescheduleAppointmentController extends BaseAppointmentActionController
{
    public function __invoke(
        RescheduleAppointmentRequest $request,
        TenantContext $context,
        SchedulingApi $schedulingApi,
        string $appointment,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $item = $this->appointment($schedulingApi, $facilityId, $appointment);
        $validated = $request->validated();

        try {
            $start = CarbonImmutable::parse($validated['new_start']);
            $end = CarbonImmutable::parse($validated['new_end']);
        } catch (\Throwable) {
            return $this->fail('reschedule', 'The new date and time are invalid.');
        }

        try {
            $schedulingApi->rescheduleAppointment(
                $item->id,
                $start->toIso8601String(),
                $end->toIso8601String(),
                $request->filled('slot_id') ? (string) $request->input('slot_id') : null,
            );
        } catch (\Throwable $e) {
            return $this->domainError($e, 'reschedule', 'Appointment rescheduling failed', ['appointment_id' => $item->id]);
        }

        return $this->done($request, "Appointment {$item->appointmentNumber} rescheduled.");
    }
}
