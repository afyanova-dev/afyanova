<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\Model\ScheduleException;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ScheduleExceptionRepository;
use App\Shared\Tenancy\TenantContext;

final class AddScheduleExceptionHandler
{
    public function __construct(
        private readonly ScheduleExceptionRepository $exceptions,
        private readonly SchedulableResourceRepository $resources,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddScheduleException $command): void
    {
        $this->resources->getById($command->resourceId);

        $exception = ScheduleException::create(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            resourceId: $command->resourceId,
            exceptionDate: $command->exceptionDate,
            reason: $command->reason,
            isAvailable: $command->isAvailable,
            startTime: $command->startTime,
            endTime: $command->endTime,
            exceptionId: $command->exceptionId,
        );

        $this->exceptions->save($exception);
    }
}
