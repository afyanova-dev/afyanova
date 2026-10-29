<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\HR\Contracts\HRApi;
use App\Domains\Scheduling\Contracts\Event\SlotsGenerated;
use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ScheduleExceptionRepository;
use App\Domains\Scheduling\Domain\ScheduleRepository;
use App\Domains\Scheduling\Domain\SlotRepository;
use App\Domains\Scheduling\Domain\ValueObject\ResourceType;
use App\Shared\Events\OutboxWriter;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class GenerateSlotsHandler
{
    public function __construct(
        private readonly SlotRepository $slots,
        private readonly SchedulableResourceRepository $resources,
        private readonly ScheduleRepository $schedules,
        private readonly ScheduleExceptionRepository $exceptions,
        private readonly AppointmentTypeRepository $appointmentTypes,
        private readonly HRApi $hrApi,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GenerateSlots $command): void
    {
        $resource = $this->resources->getById($command->resourceId);

        $defaultDuration = $command->slotDurationMinutes;
        if ($defaultDuration === null && $command->appointmentTypeId !== null) {
            $aptType = $this->appointmentTypes->getById($command->appointmentTypeId);
            $defaultDuration = (int) $aptType->duration_minutes;
        }
        $defaultDuration ??= 30;

        $createdSlots = [];
        $currentDate = $command->startDate->startOfDay();
        $endDate = $command->endDate->startOfDay();

        while ($currentDate->lessThanOrEqualTo($endDate)) {
            // Check schedule exception for this date
            $exception = $this->exceptions->findForDate($resource->id, $currentDate);
            if ($exception !== null && ! $exception->is_available) {
                $currentDate = $currentDate->addDay();

                continue;
            }

            // Cross-reference HRApi: if provider resource is on approved leave, skip!
            if ($resource->resource_type === ResourceType::PROVIDER
                && $this->hrApi->isStaffOnLeave((string) $resource->resource_ref, $currentDate)) {
                $currentDate = $currentDate->addDay();

                continue;
            }

            // ISO-8601 day of week: 1 (Monday) to 7 (Sunday)
            $dayOfWeek = (int) $currentDate->dayOfWeekIso;
            $daySchedules = $this->schedules->getActiveForResource($resource->id, $dayOfWeek);

            foreach ($daySchedules as $schedule) {
                // Check if effective date window matches
                if ($currentDate->isBefore($schedule->effective_from)) {
                    continue;
                }
                if ($schedule->effective_to !== null && $currentDate->isAfter($schedule->effective_to)) {
                    continue;
                }

                $duration = $defaultDuration;
                $aptTypeId = $command->appointmentTypeId ?? $schedule->appointment_type_id;

                if ($schedule->appointment_type_id !== null && $command->slotDurationMinutes === null) {
                    $schAptType = $this->appointmentTypes->getById($schedule->appointment_type_id);
                    $duration = (int) $schAptType->duration_minutes;
                }

                $slotStart = CarbonImmutable::parse($currentDate->toDateString().' '.$schedule->start_time);
                $scheduleEnd = CarbonImmutable::parse($currentDate->toDateString().' '.$schedule->end_time);

                while ($slotStart->addMinutes($duration)->lessThanOrEqualTo($scheduleEnd)) {
                    $slotEnd = $slotStart->addMinutes($duration);

                    if (! $this->slots->existsAtTime($resource->id, $slotStart)) {
                        $slot = Slot::create(
                            tenantId: $this->context->tenantId(),
                            facilityId: $command->facilityId,
                            resourceId: $resource->id,
                            slotDate: $currentDate,
                            startTime: $slotStart,
                            endTime: $slotEnd,
                            capacity: $command->capacity,
                            appointmentTypeId: $aptTypeId,
                        );

                        $createdSlots[] = $slot;
                    }

                    $slotStart = $slotEnd;
                }
            }

            $currentDate = $currentDate->addDay();
        }

        if ($createdSlots !== []) {
            $this->slots->saveMany($createdSlots);
        }

        $slotIds = array_map(fn (Slot $s): string => (string) $s->id, $createdSlots);

        // Record outbox event via TenantContext
        $event = new SlotsGenerated(
            eventId: (string) Str::uuid(),
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            resourceId: $command->resourceId,
            slotIds: $slotIds,
            startDate: $command->startDate->toDateString(),
            endDate: $command->endDate->toDateString(),
            count: count($createdSlots),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        );

        $outbox = app(OutboxWriter::class);
        $outbox->write($event);
    }
}
