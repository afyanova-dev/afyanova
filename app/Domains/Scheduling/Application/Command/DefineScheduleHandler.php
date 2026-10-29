<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Model\Schedule;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ScheduleRepository;
use App\Shared\Tenancy\TenantContext;

final class DefineScheduleHandler
{
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly SchedulableResourceRepository $resources,
        private readonly AppointmentTypeRepository $appointmentTypes,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineSchedule $command): void
    {
        $this->resources->getById($command->resourceId);

        if ($command->appointmentTypeId !== null) {
            $this->appointmentTypes->getById($command->appointmentTypeId);
        }

        $schedule = Schedule::define(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            resourceId: $command->resourceId,
            dayOfWeek: $command->dayOfWeek,
            startTime: $command->startTime,
            endTime: $command->endTime,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
            appointmentTypeId: $command->appointmentTypeId,
            isActive: $command->isActive,
            scheduleId: $command->scheduleId,
        );

        $this->schedules->save($schedule);
    }
}
