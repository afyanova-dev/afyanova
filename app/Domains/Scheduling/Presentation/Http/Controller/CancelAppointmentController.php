<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Presentation\Http\Request\CancelAppointmentRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class CancelAppointmentController extends BaseAppointmentActionController
{
    public function __invoke(
        CancelAppointmentRequest $request,
        TenantContext $context,
        SchedulingApi $schedulingApi,
        string $appointment,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $item = $this->appointment($schedulingApi, $facilityId, $appointment);
        $validated = $request->validated();

        try {
            $schedulingApi->cancelAppointment($item->id, $validated['reason'] ?? null);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'cancel', 'Appointment cancellation failed', ['appointment_id' => $item->id]);
        }

        return $this->done($request, "Appointment {$item->appointmentNumber} cancelled.");
    }
}
