<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Controller;

use App\Domains\Scheduling\Application\Query\GetAvailableSlots;
use App\Domains\Scheduling\Contracts\Dto\SlotSummary;
use App\Models\User;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppointmentAvailabilityController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('sch.access'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'resource_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'appointment_type_id' => ['nullable', 'uuid'],
        ]);

        $day = CarbonImmutable::createFromFormat('Y-m-d', $validated['date']);
        if (! $day instanceof CarbonImmutable) {
            abort(422, 'Invalid date.');
        }
        $day = $day->startOfDay();

        /** @var list<SlotSummary> $slots */
        $slots = $queryBus->dispatch(new GetAvailableSlots(
            facilityId: $facilityId,
            startDate: $day,
            endDate: $day,
            resourceId: $validated['resource_id'],
            appointmentTypeId: $validated['appointment_type_id'] ?? null,
        ));

        return response()->json([
            'date' => $day->toDateString(),
            'slots' => array_map(static fn (SlotSummary $slot): array => [
                'id' => $slot->id,
                'resourceId' => $slot->resourceId,
                'startTime' => $slot->startTime,
                'endTime' => $slot->endTime,
                'capacity' => $slot->capacity,
                'bookedCount' => $slot->bookedCount,
                'status' => $slot->status,
            ], $slots),
        ]);
    }
}
