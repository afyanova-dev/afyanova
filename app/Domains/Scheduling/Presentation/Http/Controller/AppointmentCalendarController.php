<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Application\Query\GetFacilityAppointmentCalendarQuery;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AppointmentCalendarController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        SchedulingApi $schedulingApi,
        PatientApi $patientApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('sch.access'), 403, 'You do not have access to appointments.');

        $monthInput = $request->query('month');
        $month = CarbonImmutable::now()->startOfMonth();
        if (is_string($monthInput) && preg_match('/^(\d{4})-(\d{2})$/', $monthInput, $m) === 1
            && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $month = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0) ?? $month;
        }

        $viewInput = $request->query('view');
        $view = in_array($viewInput, ['day', 'week', 'month'], true) ? $viewInput : 'month';
        $typeInput = $request->query('type');
        $appointmentTypeFilter = is_string($typeInput) && trim($typeInput) !== ''
            ? mb_substr(trim($typeInput), 0, 120)
            : null;

        $focusDate = null;
        $focusDateInput = $request->query('date');
        if (is_string($focusDateInput)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $focusDateInput, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $parsedFocusDate = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0);
            if ($parsedFocusDate instanceof CarbonImmutable) {
                $focusDate = $parsedFocusDate;
                $month = $parsedFocusDate->startOfMonth();
            }
        }

        $selectedDay = null;
        $dayInput = $request->query('day');
        if (is_string($dayInput)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dayInput, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $parsedDay = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0);
            if ($parsedDay instanceof CarbonImmutable) {
                $selectedDay = $parsedDay;
                $focusDate ??= $parsedDay;
                $month = $focusDate->startOfMonth();
            }
        }

        if ($view === 'day' && $focusDate === null) {
            $focusDate = CarbonImmutable::now()->startOfDay();
            $month = $focusDate->startOfMonth();
        }

        $weekInput = $request->query('week');
        $requestedWeekIndex = is_string($weekInput) && preg_match('/^[0-5]$/', $weekInput) === 1
            ? (int) $weekInput
            : null;

        // Full 6-week grid starting Sunday, like the reception week strip.
        $gridStart = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $gridEnd = $gridStart->addDays(42);
        if ($focusDate !== null) {
            $weekIndex = min(5, intdiv((int) $gridStart->diffInDays($focusDate), 7));
        } elseif ($requestedWeekIndex !== null) {
            $weekIndex = $requestedWeekIndex;
        } else {
            $today = CarbonImmutable::now()->startOfDay();
            $referenceDay = $today >= $gridStart && $today < $gridEnd ? $today : $month;
            $weekIndex = min(5, intdiv((int) $gridStart->diffInDays($referenceDay), 7));
        }

        $calendarError = false;
        try {
            /** @var list<array{date: string, total: int, services: list<array{type: string, count: int}>}> $days */
            $days = $queryBus->dispatch(new GetFacilityAppointmentCalendarQuery($facilityId, $gridStart, $gridEnd));
        } catch (\Throwable) {
            $days = [];
            $calendarError = true;
        }

        $appointments = [];
        $appointmentsError = false;
        if ($selectedDay !== null) {
            try {
                $summaries = $schedulingApi->getDailyFacilityAppointments($facilityId, $selectedDay);
            } catch (\Throwable) {
                $summaries = [];
                $appointmentsError = true;
            }

            $typeNames = [];
            $resourceNames = [];
            try {
                $names = $schedulingApi->getSchedulingDisplayNames($facilityId);
                $typeNames = $names['types'];
                $resourceNames = $names['resources'];
            } catch (\Throwable) {
                // The agenda still renders without optional display labels.
            }

            $patientSummaries = [];
            if ($user->can('patient.patients.read') && $summaries !== []) {
                $patientIds = array_values(array_unique(array_map(
                    static fn ($appointment): string => $appointment->patientId,
                    $summaries,
                )));
                try {
                    $patientSummaries = $patientApi->getPatientSummaries($patientIds);
                } catch (\Throwable) {
                    // Keep the appointment roster available if patient details are restricted.
                }
            }

            $appointments = array_map(static function ($appointment) use ($patientSummaries, $typeNames, $resourceNames): array {
                $patient = $patientSummaries[$appointment->patientId] ?? null;

                return [
                    'id' => $appointment->id,
                    'appointmentNumber' => $appointment->appointmentNumber,
                    'scheduledStart' => $appointment->scheduledStart,
                    'scheduledEnd' => $appointment->scheduledEnd,
                    'status' => $appointment->status,
                    'reason' => $appointment->reason,
                    'patient' => $patient === null ? null : [
                        'patientId' => $patient->patientId,
                        'patientNumber' => $patient->patientNumber,
                        'displayName' => $patient->displayName,
                    ],
                    'appointmentTypeName' => $typeNames[$appointment->appointmentTypeId] ?? null,
                    'resourceName' => $resourceNames[$appointment->resourceId] ?? null,
                ];
            }, $summaries);
        }

        return Inertia::render('Appointments/Calendar', [
            'month' => $month->format('Y-m'),
            'gridStart' => $gridStart->toDateString(),
            'days' => $days,
            'calendarError' => $calendarError,
            'view' => $view,
            'weekIndex' => $weekIndex,
            'appointmentTypeFilter' => $appointmentTypeFilter,
            'focusDate' => $focusDate?->toDateString(),
            'selectedDay' => $selectedDay?->toDateString(),
            'appointments' => $appointments,
            'appointmentsError' => $appointmentsError,
        ]);
    }
}
