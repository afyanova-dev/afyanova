<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RecordArrivalController extends BaseAppointmentActionController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        SchedulingApi $schedulingApi,
        string $appointment,
    ): RedirectResponse {
        $user = $this->actor($request);
        abort_unless($user->can('sch.appointment.record_arrival'), 403);
        $facilityId = $this->facilityId($context);

        $item = $this->appointment($schedulingApi, $facilityId, $appointment);

        try {
            $schedulingApi->recordArrival($item->id);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'arrival', 'Appointment arrival failed', ['appointment_id' => $item->id]);
        }

        return $this->done($request, "Arrival recorded for appointment {$item->appointmentNumber}.");
    }
}
