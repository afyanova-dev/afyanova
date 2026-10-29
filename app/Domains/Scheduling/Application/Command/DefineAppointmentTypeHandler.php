<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Exception\DuplicateAppointmentTypeCodeException;
use App\Domains\Scheduling\Domain\Model\AppointmentType;
use App\Shared\Tenancy\TenantContext;

final class DefineAppointmentTypeHandler
{
    public function __construct(
        private readonly AppointmentTypeRepository $appointmentTypes,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineAppointmentType $command): void
    {
        if ($this->appointmentTypes->existsByCode($command->code)) {
            throw DuplicateAppointmentTypeCodeException::forCode($command->code);
        }

        $type = AppointmentType::define(
            tenantId: $this->context->tenantId(),
            code: $command->code,
            name: $command->name,
            durationMinutes: $command->durationMinutes,
            bufferBeforeMinutes: $command->bufferBeforeMinutes,
            bufferAfterMinutes: $command->bufferAfterMinutes,
            isVirtual: $command->isVirtual,
            allowPatientBooking: $command->allowPatientBooking,
            isActive: $command->isActive,
            facilityId: $command->facilityId,
            appointmentTypeId: $command->appointmentTypeId,
        );

        $this->appointmentTypes->save($type);
    }
}
