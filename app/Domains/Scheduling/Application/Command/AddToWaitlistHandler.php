<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Domains\Scheduling\Domain\AppointmentTypeRepository;
use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Domains\Scheduling\Domain\SchedulableResourceRepository;
use App\Domains\Scheduling\Domain\ValueObject\WaitlistPriority;
use App\Domains\Scheduling\Domain\WaitlistEntryRepository;
use App\Domains\Scheduling\Infrastructure\Sequence\WaitlistNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

final readonly class AddToWaitlistHandler
{
    public function __construct(
        private TenantContext $tenantContext,
        private WaitlistNumberSequence $sequence,
        private AppointmentTypeRepository $appointmentTypeRepository,
        private SchedulableResourceRepository $schedulableResourceRepository,
        private WaitlistEntryRepository $repository,
    ) {}

    public function __invoke(AddToWaitlist $command): string
    {
        $tenantId = $this->tenantContext->tenantId();
        $facilityId = $command->facilityId ?? $this->tenantContext->facilityId()
            ?? throw new \InvalidArgumentException('Facility ID is required to add to waitlist.');

        // Validate appointment type existence
        $this->appointmentTypeRepository->getById($command->appointmentTypeId);

        // Validate resource existence if specified
        if ($command->resourceId !== null) {
            $this->schedulableResourceRepository->getById($command->resourceId);
        }

        $waitlistNumber = $this->sequence->next($tenantId);

        $preferredStart = $command->preferredDateStart !== null
            ? CarbonImmutable::parse($command->preferredDateStart)
            : null;
        $preferredEnd = $command->preferredDateEnd !== null
            ? CarbonImmutable::parse($command->preferredDateEnd)
            : null;

        $entry = WaitlistEntry::add(
            tenantId: $tenantId,
            facilityId: $facilityId,
            waitlistNumber: $waitlistNumber,
            patientId: $command->patientId,
            appointmentTypeId: $command->appointmentTypeId,
            resourceId: $command->resourceId,
            priority: WaitlistPriority::from($command->priority),
            preferredDateStart: $preferredStart,
            preferredDateEnd: $preferredEnd,
            notes: $command->notes,
            id: $command->waitlistEntryId,
        );

        $this->repository->save($entry);

        return (string) $entry->id;
    }
}
