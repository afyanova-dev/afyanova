<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Domain\AppointmentTypeReader;
use App\Domains\Scheduling\Presentation\Http\Request\BookAppointmentRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

final class AppointmentBookingController
{
    public function __invoke(
        BookAppointmentRequest $request,
        string $patientId,
        TenantContext $context,
        CommandBus $commandBus,
        AppointmentTypeReader $typeReader,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $typeId = (string) $request->input('appointment_type_id');
        $type = $typeReader->findById($typeId);
        if ($type === null) {
            return back()->withErrors(['booking' => 'Appointment type was not found.']);
        }

        try {
            $start = CarbonImmutable::createFromFormat(
                'Y-m-d H:i',
                $request->input('scheduled_date').' '.$request->input('scheduled_time'),
            );
        } catch (\Throwable) {
            return back()->withErrors(['booking' => 'Scheduled date and time are invalid.']);
        }
        if ($start === false) {
            return back()->withErrors(['booking' => 'Scheduled date and time are invalid.']);
        }

        $end = $start->addMinutes($type->durationMinutes);

        try {
            $commandBus->dispatch(new BookAppointment(
                patientId: $patientId,
                resourceId: (string) $request->input('resource_id'),
                appointmentTypeId: $typeId,
                scheduledStart: $start->toIso8601String(),
                scheduledEnd: $end->toIso8601String(),
                slotId: $request->filled('slot_id') ? (string) $request->input('slot_id') : null,
                reason: $request->filled('reason') ? (string) $request->input('reason') : null,
                facilityId: $facilityId,
            ));
        } catch (\Throwable $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        $msg = "Appointment booked for {$start->format('d-M-Y H:i')}.";

        if ($request->filled('return_to')) {
            $returnTo = (string) $request->input('return_to');
            if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
                return redirect($returnTo)->with('status', $msg);
            }
        }

        return redirect("/patient/{$patientId}/chart/appointments")->with('status', $msg);
    }
}
