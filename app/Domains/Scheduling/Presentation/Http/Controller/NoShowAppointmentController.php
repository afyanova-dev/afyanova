<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NoShowAppointmentController extends BaseAppointmentActionController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        SchedulingApi $schedulingApi,
        string $appointment,
    ): RedirectResponse {
        $user = $this->actor($request);
        abort_unless($user->can('sch.appointment.manage'), 403);
        $facilityId = $this->facilityId($context);

        $item = $this->appointment($schedulingApi, $facilityId, $appointment);

        try {
            $schedulingApi->markAppointmentNoShow($item->id);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'no_show', 'Recording the no-show failed', ['appointment_id' => $item->id]);
        }

        return $this->done($request, "Appointment {$item->appointmentNumber} marked as no-show.");
    }
}
